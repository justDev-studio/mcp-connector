<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lomnio_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! class_exists( '\LomnioApiConnector\Pages\PageSettings' ) ||
		! current_user_can( (string) apply_filters( 'lomnio_api_connector_manage_capability', 'manage_options' ) ) ) {
		return admin_error( 'Lomnio API Connector is unavailable to this user.', 'mcp_admin_forbidden' );
	}
	if ( '' === $identifier ) {
		return array( 'resources' => array( 'pages', 'endpoints', 'credentials', 'sync', 'project', 'units', 'floors', 'logs' ) );
	}
	$parts = explode( ':', $identifier, 2 );
	$section = $parts[0];
	$item = $parts[1] ?? '';
	if ( '' !== $item && ! in_array( $section, array( 'units', 'floors', 'logs' ), true ) ) {
		return admin_error( 'This Lomnio resource does not take an item identifier.' );
	}
	switch ( $section ) {
		case 'pages':
			$pages = new \LomnioApiConnector\Pages\PageSettings();
			$settings = $pages->all();
			if ( 'update' === $operation ) {
				foreach ( $body as $key => $value ) {
					if ( ! array_key_exists( $key, $settings ) || ! is_scalar( $value ) ) {
						return admin_error( 'Unknown or invalid Lomnio page setting: ' . $key );
					}
				}
				$pages->update( array_replace( $settings, $body ) );
			}
			return $pages->all();
		case 'endpoints':
			return lomnio_endpoints_resource( $operation, $body );
		case 'credentials':
			return lomnio_credentials_resource( $operation, $body );
		case 'sync':
			return lomnio_sync_resource( $operation, $body );
	}

	if ( 'read' !== $operation ) {
		return admin_error( 'This Lomnio resource supports reading.' );
	}
	switch ( $section ) {
		case 'project':
			return (array) \LomnioProject::get();
		case 'units':
			return '' === $item ? lomnio_page( \LomnioUnits::all(), $body ) : (array) \LomnioUnits::find( $item );
		case 'floors':
			return '' === $item ? lomnio_page( \LomnioFloors::all(), $body ) : (array) \LomnioFloors::find( $item );
		case 'logs':
			$logs = new \LomnioApiConnector\Database\LogRepository();
			$result = $logs->entries( '', '', ctype_digit( $item ) ? (int) $item : 1 );
			foreach ( $result['items'] as &$entry ) {
				$details = json_decode( $entry['details'] ?? '', true );
				$entry['details'] = is_array( $details ) ? audit_safe_value( $details ) : array();
			}
			return $result;
		default:
			return admin_error( 'Unknown Lomnio resource.' );
	}
}

function lomnio_page( array $items, array $query ) {
	$offset = $query['offset'] ?? 0;
	$limit  = $query['limit'] ?? 100;
	if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $limit ) || $limit < 1 || $limit > 100 ) {
		return admin_error( 'Use an offset of zero or greater and a limit between 1 and 100.' );
	}
	return array( 'total' => count( $items ), 'items' => array_slice( $items, $offset, $limit ) );
}

function lomnio_endpoints_resource( $operation, array $body ) {
	$endpoints = new \LomnioApiConnector\Admin\EndpointsPage();
	$settings = $endpoints->settings();
	if ( 'read' === $operation ) {
		return array( 'settings' => $settings, 'status' => $endpoints->meta() );
	}
	foreach ( $body as $name => $values ) {
		if ( ! isset( $settings[ $name ] ) || ! is_array( $values ) || array_diff_key( $values, $settings[ $name ] ) ) {
			return admin_error( 'Unknown Lomnio endpoint or endpoint setting: ' . $name );
		}
		foreach ( $values as $key => $value ) {
			if ( 'active' === $key && ! is_bool( $value ) ) {
				return admin_error( 'Endpoint active must be a boolean.' );
			}
			if ( 'schedule' === $key && ! in_array( $value, array( 'every_ten_minutes', 'every_thirty_minutes', 'hourly', 'twicedaily', 'daily' ), true ) ) {
				return admin_error( 'Select a supported Lomnio sync schedule.' );
			}
			if ( 'allowed_envs' === $key && ( ! is_array( $value ) || ! $value ||
				! in_array( $name, array( 'leads', 'tracking' ), true ) ||
				count( $value ) !== count( array_filter( $value, 'is_string' ) ) ||
				array_diff( $value, array( 'all', 'local', 'development', 'staging', 'production' ) ) ) ) {
				return admin_error( 'Select valid environments for leads or tracking.' );
			}
			if ( 'schedule' === $key && in_array( $name, array( 'leads', 'tracking' ), true ) ) {
				return admin_error( 'The leads and tracking endpoints do not have a schedule.' );
			}
			$settings[ $name ][ $key ] = 'allowed_envs' === $key && in_array( 'all', $value, true ) ? array( 'all' ) : $value;
		}
	}
	update_option( 'lomnio_api_connector_endpoint_settings', $settings, false );
	do_action( 'lomnio_api_connector_endpoint_settings_saved' );
	return lomnio_endpoints_resource( 'read', array() );
}

function lomnio_credentials_resource( $operation, array $body ) {
	$storage = new \LomnioApiConnector\Security\SecretStorage();
	if ( 'update' === $operation ) {
		$fields = array( 'api_token' => 'set_api_token', 'webhook_secret' => 'set_webhook_secret' );
		if ( 1 !== count( $body ) || array_diff_key( $body, $fields ) ) {
			return admin_error( 'Provide one API token or webhook secret at a time.' );
		}
		foreach ( $body as $name => $value ) {
			if ( null === $value ) {
				$clear = 'api_token' === $name ? 'clear_api_token' : 'clear_webhook_secret';
				$storage->{$clear}();
				continue;
			}
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return admin_error( 'Supply a nonempty Lomnio credential or null to clear it.' );
			}
			$result = $storage->{$fields[ $name ]}( $value );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( 'api_token' === $name ) {
				do_action( 'lomnio_api_connector_api_token_saved' );
			}
		}
	}
	return array( 'api_token_configured' => $storage->has_api_token(), 'webhook_secret_configured' => $storage->has_webhook_secret() );
}

function lomnio_sync_resource( $operation, array $body ) {
	if ( 'read' === $operation ) {
		return ( new \LomnioApiConnector\Admin\EndpointsPage() )->meta();
	}
	$target = $body['target'] ?? null;
	if ( 1 !== count( $body ) || ! in_array( $target, array( 'project', 'units', 'floors', 'all' ), true ) ) {
		return admin_error( 'Select project, units, floors or all as the sync target.' );
	}
	$storage = new \LomnioApiConnector\Security\SecretStorage();
	$jobs = array(
		'project' => array( \LomnioApiConnector\Sync\ProjectSync::class, \LomnioApiConnector\Database\ProjectRepository::class ),
		'units'   => array( \LomnioApiConnector\Sync\UnitsSync::class, \LomnioApiConnector\Database\UnitRepository::class ),
		'floors'  => array( \LomnioApiConnector\Sync\FloorsSync::class, \LomnioApiConnector\Database\FloorRepository::class ),
	);
	$selected = 'all' === $target ? $jobs : array( $target => $jobs[ $target ] );
	$errors = array();
	foreach ( $selected as $name => list( $job, $repository ) ) {
		$result = ( new $job( $storage, new $repository() ) )->run();
		if ( is_wp_error( $result ) ) {
			if ( 'all' !== $target ) {
				return $result;
			}
			$errors[ $name ] = $result->get_error_code();
		}
	}
	if ( $errors ) {
		return new \WP_Error( 'mcp_lomnio_sync_failed', 'Lomnio sync failed for ' . implode( ', ', array_keys( $errors ) ) . '. Read the sync status for details.' );
	}
	$status = ( new \LomnioApiConnector\Admin\EndpointsPage() )->meta();
	return array( 'target' => $target, 'status' => 'all' === $target ? array_intersect_key( $status, $selected ) : ( $status[ $target ] ?? null ) );
}
