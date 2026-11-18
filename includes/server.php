<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

function register_mcp_route() {
	register_rest_route(
		REST_NAMESPACE,
		'/mcp',
		array(
			'methods'             => array( 'GET', 'POST', 'DELETE' ),
			'callback'            => __NAMESPACE__ . '\\handle_mcp',
			'permission_callback' => __NAMESPACE__ . '\\authorize_mcp',
		)
	);
}

function authorize_mcp( \WP_REST_Request $request ) {
	if ( $request->get_header( 'origin' ) ) {
		return new \WP_Error( 'mcp_origin_denied', 'This endpoint accepts server clients only.', array( 'status' => 403 ) );
	}

	$environment = wp_get_environment_type();
	if ( ! is_ssl() && 'local' !== $environment ) {
		return new \WP_Error( 'mcp_https_required', 'Connect to this WordPress environment over HTTPS.', array( 'status' => 403 ) );
	}

	if ( ! get_current_user_id() || ! rest_get_authenticated_app_password() ) {
		return new \WP_Error( 'mcp_application_password_required', 'A WordPress Application Password is required.', array( 'status' => 401 ) );
	}

	if ( $environment !== $request->get_header( 'x-wp-mcp-environment' ) ) {
		return new \WP_Error( 'mcp_environment_mismatch', 'The expected environment does not match WP_ENVIRONMENT_TYPE.', array( 'status' => 409 ) );
	}

	return true;
}

function handle_mcp( \WP_REST_Request $request ) {
	if ( 'POST' !== $request->get_method() ) {
		return new \WP_REST_Response( null, 405, array( 'Allow' => 'POST' ) );
	}

	$protocol = $request->get_header( 'mcp-protocol-version' );
	if ( $protocol && ! in_array( $protocol, PROTOCOL_VERSIONS, true ) ) {
		return new \WP_Error( 'mcp_unsupported_protocol', 'Unsupported MCP protocol version.', array( 'status' => 400 ) );
	}

	$message = json_decode( $request->get_body() );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return rpc_error( null, -32700, 'Invalid JSON.' );
	}

	if ( ! is_object( $message ) || '2.0' !== ( $message->jsonrpc ?? null ) || ! is_string( $message->method ?? null ) ) {
		return rpc_error( null, -32600, 'Invalid request.' );
	}

	if ( ! property_exists( $message, 'id' ) ) {
		return new \WP_REST_Response( null, 202 );
	}

	$id = $message->id;
	if ( ! is_int( $id ) && ! is_string( $id ) ) {
		return rpc_error( null, -32600, 'Invalid request ID.' );
	}

	$params = $message->params ?? (object) array();
	if ( ! is_object( $params ) ) {
		return rpc_error( $id, -32602, 'Expected a params object.' );
	}

	switch ( $message->method ) {
		case 'initialize':
			$result   = array(
				'protocolVersion' => in_array( $params->protocolVersion ?? null, PROTOCOL_VERSIONS, true ) ? $params->protocolVersion : PROTOCOL_VERSIONS[0],
				'capabilities'    => array( 'tools' => (object) array() ),
				'serverInfo'      => array( 'name' => 'wordpress-' . wp_get_environment_type(), 'version' => VERSION ),
				'instructions'    => 'Use wordpress_site_info before changing site data. WordPress capabilities apply to every operation.',
			);
			break;
		case 'ping':
			$result = (object) array();
			break;
		case 'tools/list':
			$result = array( 'tools' => array_values( tool_catalog() ) );
			break;
		case 'tools/call':
			if ( ! is_string( $params->name ?? null ) || ! isset( tool_catalog()[ $params->name ] ) ) {
				return rpc_error( $id, -32602, 'Unknown tool.' );
			}
			$arguments = $params->arguments ?? (object) array();
			if ( ! is_object( $arguments ) ) {
				return rpc_error( $id, -32602, 'Expected an arguments object.' );
			}
			$value  = call_tool( $params->name, $arguments );
			$result = array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => is_wp_error( $value ) ? $value->get_error_message() : wp_json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE ),
					),
				),
			);
			if ( is_wp_error( $value ) ) {
				$result['isError'] = true;
				$result['content'][0]['text'] = $value->get_error_code() . ': ' . $value->get_error_message();
			}
			break;
		default:
			return rpc_error( $id, -32601, 'Unknown method.' );
	}

	return new \WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ), 200, array( 'Cache-Control' => 'no-store' ) );
}

function serve_empty_mcp_response( $served, \WP_REST_Response $response, \WP_REST_Request $request ) {
	return $served || (
		'/' . REST_NAMESPACE . '/mcp' === untrailingslashit( $request->get_route() ) &&
		in_array( $response->get_status(), array( 202, 405 ), true )
	);
}

function rpc_error( $id, $code, $message ) {
	return new \WP_REST_Response(
		array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array( 'code' => $code, 'message' => $message ),
		)
	);
}
