<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function duplicate_post_settings_resource( $resource, $identifier, $operation, array $body ) {
	$class = '\Yoast\WP\Duplicate_Post\Admin\Options';
	if ( ! class_exists( $class ) || ! current_user_can( 'manage_options' ) ) {
		return admin_error( 'Duplicate Post settings require manage_options.', 'mcp_admin_forbidden' );
	}
	if ( '' !== $identifier && 'site' !== $identifier ) {
		return admin_error( 'Use site to manage Duplicate Post settings.' );
	}
	$settings = array();
	foreach ( ( new $class() )->get_options() as $key => $definition ) {
		$type = $definition['type'] ?? null;
		if ( 'checkbox' === $type ) {
			$settings[ $key ] = 'bool';
		} elseif ( 'text' === $type ) {
			$settings[ $key ] = 'text';
		} elseif ( 'number' === $type ) {
			$settings[ $key ] = 'integer';
		}
	}
	$settings['duplicate_post_types_enabled'] = 'keys';
	$settings['duplicate_post_taxonomies_blacklist'] = 'keys';
	// WordPress stores a checked settings-form checkbox as a 1.
	$store = static function ( $key, $value ) {
		update_option( $key, is_bool( $value ) ? (int) $value : $value );
	};
	return named_plugin_options( $settings, $operation, $body, 'get_option', $store );
}

function duplicate_post_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! preg_match( '/^[1-9][0-9]*$/D', $identifier ) || ! defined( 'DUPLICATE_POST_PATH' ) ) {
		return admin_error( 'Supply a post ID with Yoast Duplicate Post active.' );
	}
	$id = (int) $identifier;
	$post = get_post( $id );
	$post_type = $post ? get_post_type_object( $post->post_type ) : null;
	if ( ! $post_type || ! current_user_can( 'edit_post', $id ) ||
		( ! mcp_is_administrator() && ! is_content_type( $post_type ) ) ) {
		return admin_error( 'The post is unavailable to the current user.', 'mcp_admin_forbidden' );
	}
	if ( 'read' === $operation ) {
		return array( 'id' => $id, 'original_id' => (int) get_post_meta( $id, '_dp_original', true ) );
	}

	if ( ! current_user_can( 'copy_posts' ) || ! current_user_can( $post_type->cap->create_posts ) ||
		! duplicate_post_is_post_type_enabled( $post->post_type ) ) {
		return admin_error( 'Duplicate Post does not allow this user to copy the post.', 'mcp_admin_forbidden' );
	}
	if ( $body !== array( 'action' => 'new_draft' ) ) {
		return admin_error( 'Use action new_draft to copy a post.' );
	}
	require_once DUPLICATE_POST_PATH . 'admin-functions.php';
	$copy = duplicate_post_create_duplicate( $post, 'draft' );
	if ( is_wp_error( $copy ) || ! $copy ) {
		return admin_error( 'Duplicate Post could not create the draft.' );
	}
	return array( 'source_id' => $id, 'draft_id' => (int) $copy );
}

function ewww_attachment_resource( $resource, $identifier, $operation, array $body ) {
	$id = preg_match( '/^[1-9][0-9]*$/D', $identifier ) ? (int) $identifier : 0;
	if ( ! $id || ! function_exists( 'ewww_image_optimizer_optimize_by_id' ) || ! wp_attachment_is_image( $id ) ||
		! current_user_can( 'edit_post', $id ) || ! current_user_can( 'upload_files' ) ) {
		return admin_error( 'Select an editable image attachment with EWWW Image Optimizer active.' );
	}
	if ( 'update' === $operation ) {
		if ( $body !== array( 'action' => 'optimize' ) ) {
			return admin_error( 'Use action optimize for a Media Library image.' );
		}
		ewww_image_optimizer_optimize_by_id( $id );
	}
	$metadata = wp_get_attachment_metadata( $id );
	return array(
		'id' => $id,
		'width' => $metadata['width'] ?? null,
		'height' => $metadata['height'] ?? null,
		'filesize' => $metadata['filesize'] ?? null,
	);
}

function syncport_connections_resource( $resource, $identifier, $operation, array $body ) {
	$class = '\JustDev\SyncPort\Infrastructure\ConnectionRepository';
	if ( ! class_exists( $class ) || ! current_user_can( 'manage_options' ) ) {
		return admin_error( 'SyncPort connections require manage_options.', 'mcp_admin_forbidden' );
	}
	$connections = new $class();
	$public = static function ( array $values ) {
		$values['key_configured'] = ! empty( $values['key'] );
		unset( $values['key'] );
		return $values;
	};
	if ( 'read' === $operation && '' === $identifier ) {
		return array( 'connections' => array_map( $public, $connections->all() ) );
	}
	if ( ! preg_match( '/^[a-z0-9_-]+$/D', $identifier ) ) {
		return admin_error( 'Use a stable connection identifier.' );
	}
	$connection = $connections->find( $identifier );
	if ( 'delete' === $operation ) {
		$connections->delete( $identifier );
		return array( 'deleted' => ! $connections->find( $identifier ) );
	}
	if ( 'update' === $operation ) {
		$spec = array( 'url' => 'text', 'name' => 'text', 'api_key' => 'secret', 'allow_push' => 'bool', 'allow_pull' => 'bool' );
		foreach ( $body as $key => $value ) {
			$clean = isset( $spec[ $key ] ) ? sanitize_plugin_setting( $key, $value, $spec[ $key ] ) : admin_error( 'Unknown connection property: ' . $key );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$connection[ 'api_key' === $key ? 'key' : $key ] = $clean;
		}
		$url = wp_parse_url( $connection['url'] ?? '' );
		if ( empty( $url['host'] ) || ! in_array( $url['scheme'] ?? null, array( 'http', 'https' ), true ) ||
			isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) || empty( $connection['key'] ) ) {
			return admin_error( 'A connection needs a site URL and an API key.' );
		}
		$connections->save( array( 'id' => $identifier ) + $connection );
		$connection = $connections->find( $identifier );
	}
	return $connection ? $public( $connection ) : admin_error( 'SyncPort connection not found.' );
}

function syncport_operations_resource( $resource, $identifier, $operation, array $body ) {
	$class = '\JustDev\SyncPort\Infrastructure\OperationRepository';
	if ( ! class_exists( $class ) || ! current_user_can( 'manage_options' ) ) {
		return admin_error( 'SyncPort operation history requires manage_options.', 'mcp_admin_forbidden' );
	}
	if ( 'read' !== $operation ) {
		return admin_error( 'SyncPort operation history is read-only.' );
	}
	$repository = new $class();
	$fields = array_flip( array( 'operation_uuid', 'direction', 'scope', 'status', 'connection_id', 'created_at', 'updated_at' ) );
	if ( '' !== $identifier ) {
		$job = $repository->find( $identifier );
		return $job ? array_intersect_key( $job, $fields ) : admin_error( 'SyncPort operation not found.' );
	}
	return array( 'operations' => array_map( static function ( $row ) use ( $fields ) {
		return array_intersect_key( (array) $row, $fields );
	}, $repository->recent() ) );
}

function yoast_ability_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		return admin_error( 'The WordPress Abilities API is unavailable.' );
	}
	$abilities = array_filter( wp_get_abilities(), static function ( $key ) {
		return 0 === strpos( $key, 'yoast-seo/' );
	}, ARRAY_FILTER_USE_KEY );
	if ( '' === $identifier ) {
		return array( 'abilities' => array_keys( $abilities ) );
	}
	if ( ! isset( $abilities[ $identifier ] ) ) {
		return admin_error( 'Yoast does not expose this ability on this site.' );
	}
	$ability = $abilities[ $identifier ];
	$meta = $ability->get_meta();
	$read_only = true === ( $meta['annotations']['readonly'] ?? $meta['annotations']['readOnly'] ?? false );
	if ( 'read' === $operation && ! array_key_exists( 'input', $body ) ) {
		return array( 'description' => $ability->get_description(), 'input_schema' => $ability->get_input_schema(), 'read_only' => $read_only );
	}
	if ( $read_only !== ( 'read' === $operation ) || array_diff_key( $body, array( 'input' => true ) ) ) {
		return admin_error( 'Use a matching read or update operation with an input property.' );
	}
	return $ability->execute( $body['input'] ?? null );
}
