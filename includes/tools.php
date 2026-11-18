<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function tool_catalog() {
	$object = array( 'type' => 'object' );
	$string = array( 'type' => 'string' );
	$tools  = array(
		'wordpress_site_info' => array(
			'Show the connected site URL, environment, WordPress user, REST collections and native admin resources.',
			array(),
			array(),
			true,
		),
		'wordpress_read' => array(
			'Read a WordPress REST collection or item. Use site_info for routes. Use query for search, per_page, page, context and _fields. Use OPTIONS to discover the route schema.',
			array(
				'route'  => $string,
				'method' => array( 'type' => 'string', 'enum' => array( 'GET', 'OPTIONS' ) ),
				'query'  => $object,
			),
			array( 'route' ),
			true,
		),
		'wordpress_write' => array(
			'Create, update, trash or delete WordPress objects through core REST routes. Use POST to create or update, DELETE to trash, and body.force=true to permanently delete where required. Install plugins by posting a WordPress.org slug to /wp/v2/plugins. Change activation via status on an item route.',
			array(
				'route'  => $string,
				'method' => array( 'type' => 'string', 'enum' => array( 'POST', 'DELETE' ) ),
				'body'   => $object,
			),
			array( 'route', 'method' ),
			false,
		),
		'wordpress_upload_media' => array(
			'Upload a media file (maximum 8 MiB and within WordPress limits). Supply file_path with the local bridge, or filename, mime_type and base64 for direct MCP. Edit metadata through the returned attachment ID.',
			array(
				'file_path' => $string,
				'filename'  => $string,
				'mime_type' => $string,
				'base64'    => $string,
			),
			array(),
			false,
		),
		'wordpress_update_plugin' => array(
			'Apply the available WordPress update for an installed plugin. Use the plugin identifier returned by /wp/v2/plugins, without .php.',
			array( 'plugin' => $string ),
			array( 'plugin' ),
			false,
		),
		'wordpress_activate_theme' => array(
			'Activate an installed theme using its stylesheet identifier from /wp/v2/themes.',
			array( 'stylesheet' => $string ),
			array( 'stylesheet' ),
			false,
		),
		'wordpress_admin_read' => array(
			'Read native admin resources. Use wordpress_site_info for available resources. Omit identifier to list ACF definitions, options pages, theme settings or Yoast groups.',
			array( 'resource' => array( 'type' => 'string', 'enum' => array_keys( admin_resources() ) ), 'identifier' => $string ),
			array( 'resource' ),
			true,
		),
		'wordpress_admin_write' => array(
			'Update an ACF definition or options page, Customizer setting, theme mod, Global Styles, configured site option, or Yoast settings/metadata. Use wordpress_admin_read before writing. delete is supported for ACF definitions, theme mods and configured site options.',
			array(
				'resource'   => array( 'type' => 'string', 'enum' => array_keys( admin_resources() ) ),
				'identifier' => $string,
				'operation'  => array( 'type' => 'string', 'enum' => array( 'update', 'delete' ) ),
				'body'       => $object,
			),
			array( 'resource', 'identifier', 'operation' ),
			false,
		),
	);

	$catalog = array();
	foreach ( $tools as $name => list( $description, $properties, $required, $read_only ) ) {
		$catalog[ $name ] = array(
			'name'        => $name,
			'description' => $description,
			'inputSchema' => array(
				'type'                 => 'object',
				'properties'           => (object) $properties,
				'required'             => $required,
				'additionalProperties' => false,
			),
			'annotations' => array(
				'readOnlyHint'    => $read_only,
				'destructiveHint' => ! $read_only,
			),
		);
	}
	return $catalog;
}

function rest_collections() {
	$collections = array(
		'/wp/v2/posts'      => 'numeric',
		'/wp/v2/pages'      => 'numeric',
		'/wp/v2/media'      => 'numeric',
		'/wp/v2/categories' => 'numeric',
		'/wp/v2/tags'       => 'numeric',
		'/wp/v2/comments'   => 'numeric',
		'/wp/v2/users'      => 'numeric',
		'/wp/v2/menus'      => 'numeric',
		'/wp/v2/menu-items' => 'numeric',
		'/wp/v2/blocks'     => 'numeric',
		'/wp/v2/navigation' => 'numeric',
		'/wp/v2/templates'  => 'templates',
		'/wp/v2/template-parts' => 'templates',
		'/wp/v2/global-styles' => 'global_styles',
		'/wp/v2/widgets'    => 'named',
		'/wp/v2/sidebars'   => 'named',
		'/wp/v2/menu-locations' => 'named',
		'/wp/v2/settings'   => 'settings',
		'/wp/v2/plugins'    => 'plugins',
		'/wp/v2/themes'     => 'themes',
	);

	$objects = array_merge( array_values( get_post_types( array( 'show_in_rest' => true ), 'objects' ) ), array_values( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ) ) );
	$routes  = rest_get_server()->get_routes();
	foreach ( $objects as $object ) {
		$base  = $object->rest_base ?: $object->name;
		$route = '/' . ( $object->rest_namespace ?: 'wp/v2' ) . '/' . $base;
		if ( 0 !== strpos( $route, '/' . REST_NAMESPACE . '/' ) && ! isset( $collections[ $route ] ) && isset( $routes[ $route ] ) ) {
			$collections[ $route ] = 'numeric';
		}
	}

	foreach ( (array) ( defined( 'WP_MCP_EXTRA_REST_ROUTES' ) ? WP_MCP_EXTRA_REST_ROUTES : array() ) as $route ) {
		if (
			is_string( $route ) &&
			preg_match( '~^(/[a-z0-9_-]+){3,}$~iD', $route ) &&
			0 !== stripos( $route, '/' . REST_NAMESPACE . '/' ) &&
			! isset( $collections[ $route ] ) &&
			isset( $routes[ $route ] )
		) {
			$collections[ $route ] = 'settings';
		}
	}

	return $collections;
}

function plugin_identifier( $value ) {
	return is_string( $value ) && preg_match( '~^[a-z0-9_-]+(?:/[a-z0-9_-]+)?$~iD', $value );
}

function resolve_route( $route ) {
	if ( ! is_string( $route ) ) {
		return null;
	}

	$collections = rest_collections();
	if ( isset( $collections[ $route ] ) ) {
		return array( $route, $collections[ $route ], '' );
	}

	foreach ( $collections as $base => $kind ) {
		if ( 0 !== strpos( $route, $base . '/' ) ) {
			continue;
		}
		$id = substr( $route, strlen( $base ) + 1 );
		if (
			( 'numeric' === $kind && preg_match( '/^[1-9][0-9]*$/D', $id ) ) ||
			( 'plugins' === $kind && plugin_identifier( $id ) ) ||
			( 'themes' === $kind && preg_match( '~^[a-z0-9_-]+(?:/[a-z0-9_-]+)?$~iD', $id ) ) ||
			( 'named' === $kind && preg_match( '/^[a-z0-9_-]+$/iD', $id ) ) ||
			( 'templates' === $kind && preg_match( '~^[a-z0-9_-]+(?:/[a-z0-9_-]+)?//[a-z0-9_-]+$~iD', $id ) ) ||
			( 'global_styles' === $kind && preg_match( '~^(?:[1-9][0-9]*|themes/[a-z0-9_-]+(?:/[a-z0-9_-]+)?(?:/variations)?)$~iD', $id ) )
		) {
			return array( $base, $kind, $id );
		}
	}
	return null;
}

function call_tool( $name, $arguments ) {
	$schema = tool_catalog()[ $name ]['inputSchema'];
	$args   = (array) $arguments;
	foreach ( $schema['required'] as $key ) {
		if ( ! array_key_exists( $key, $args ) ) {
			return new \WP_Error( 'mcp_required_argument', 'Missing argument: ' . $key );
		}
	}
	foreach ( $args as $key => $value ) {
		$property = $schema['properties']->$key ?? null;
		if (
			! $property ||
			( 'string' === $property['type'] && ! is_string( $value ) ) ||
			( 'object' === $property['type'] && ! is_object( $value ) ) ||
			( isset( $property['enum'] ) && ! in_array( $value, $property['enum'], true ) )
		) {
			return new \WP_Error( 'mcp_invalid_argument', 'Invalid argument: ' . $key );
		}
	}

	switch ( $name ) {
		case 'wordpress_site_info':
			return array(
				'site'        => home_url(),
				'environment' => wp_get_environment_type(),
				'user_id'     => get_current_user_id(),
				'user'        => wp_get_current_user()->user_login,
				'collections' => rest_collections(),
				'admin_resources' => available_admin_resources(),
			);
		case 'wordpress_admin_read':
		case 'wordpress_admin_write':
			return call_admin_tool( $name, $args );
		case 'wordpress_upload_media':
			return upload_media( $args );
		case 'wordpress_update_plugin':
			return update_plugin( $args['plugin'] );
		case 'wordpress_activate_theme':
			return activate_theme( $args['stylesheet'] );
	}

	$route   = $args['route'];
	$matched = resolve_route( $route );
	if ( ! $matched ) {
		return new \WP_Error( 'mcp_route_denied', 'This route is outside the supported collections. Call wordpress_site_info to list them.' );
	}
	list( $base, $kind, $id ) = $matched;
	$read   = 'wordpress_read' === $name;
	$method = $read ? ( $args['method'] ?? 'GET' ) : $args['method'];
	$data   = (array) ( $args[ $read ? 'query' : 'body' ] ?? array() );
	$forbidden = array( 'id', 'plugin', 'stylesheet', 'rest_route' );
	foreach ( array_keys( $data ) as $key ) {
		if ( in_array( $key, $forbidden, true ) || ( 0 === strpos( (string) $key, '_' ) && ! ( $read && '_fields' === $key ) ) ) {
			return new \WP_Error( 'mcp_invalid_parameter', 'Reserved REST parameter: ' . $key );
		}
	}

	if ( $read ) {
		return rest_result( dispatch_rest( $method, $route, $data ) );
	}
	if ( 'themes' === $kind || ( 'DELETE' === $method && '' === $id ) || ( '/wp/v2/media' === $base && '' === $id ) ) {
		return new \WP_Error( 'mcp_method_denied', 'Use an item route for deletion, wordpress_activate_theme for theme changes, and wordpress_upload_media to create media.' );
	}
	if ( 'plugins' === $kind && '' !== $id && protected_plugin( $id ) ) {
		return new \WP_Error( 'mcp_self_management', 'Manage this connector from WordPress administration.' );
	}

	return audit_mutation(
		$name,
		$method . ' ' . $route,
		$data,
		'' !== $id || 'settings' === $kind ? $route : null,
		static function () use ( $method, $route, $data ) {
			return rest_result( dispatch_rest( $method, $route, $data ) );
		},
		static function ( $result ) use ( $route, $base, $id, $kind ) {
			if ( '' !== $id || 'settings' === $kind ) {
				return $route;
			}
			if ( 'plugins' === $kind && plugin_identifier( $result['plugin'] ?? null ) ) {
				return $base . '/' . $result['plugin'];
			}
			if ( isset( $result['id'] ) && ( is_string( $result['id'] ) || is_int( $result['id'] ) ) ) {
				$item = $base . '/' . $result['id'];
				return resolve_route( $item ) ? $item : null;
			}
			return null;
		}
	);
}

function dispatch_rest( $method, $route, array $data = array() ) {
	$request = new \WP_REST_Request( $method, $route );
	if ( in_array( $method, array( 'GET', 'OPTIONS' ), true ) ) {
		$request->set_query_params( $data );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( (object) $data ) );
	}
	return rest_do_request( $request );
}

function rest_result( \WP_REST_Response $response ) {
	$data = $response->get_data();
	if ( $response->is_error() ) {
		return $response->as_error();
	}
	$headers = $response->get_headers();
	if ( isset( $headers['X-WP-TotalPages'] ) ) {
		return array( 'items' => $data, 'total' => (int) $headers['X-WP-Total'], 'total_pages' => (int) $headers['X-WP-TotalPages'] );
	}
	return $data;
}

function upload_media( array $args ) {
	$encoded  = $args['base64'] ?? '';
	$filename = sanitize_file_name( $args['filename'] ?? '' );
	$mime     = $args['mime_type'] ?? '';

	if (
		! $filename || preg_match( '/[\x00-\x1f"\\\\]/', $filename ) ||
		! preg_match( '~^[a-z0-9.+-]+/[a-z0-9.+-]+$~iD', $mime ) ||
		strlen( $encoded ) > 4 * ceil( MAX_MEDIA_BYTES / 3 )
	) {
		return new \WP_Error( 'mcp_invalid_media', 'Provide a valid filename, MIME type and Base64 payload within 8 MiB.' );
	}

	$bytes = base64_decode( $encoded, true );
	if ( false === $bytes || '' === $bytes || strlen( $bytes ) > min( MAX_MEDIA_BYTES, wp_max_upload_size() ) ) {
		return new \WP_Error( 'mcp_invalid_media', 'The media payload is empty, invalid or above the upload limit.' );
	}

	return audit_mutation(
		'wordpress_upload_media',
		'POST /wp/v2/media',
		array( 'filename' => $filename, 'mime_type' => $mime, 'bytes' => strlen( $bytes ) ),
		null,
		static function () use ( $filename, $mime, $bytes ) {
			$request = new \WP_REST_Request( 'POST', '/wp/v2/media' );
			$request->set_header( 'Content-Type', $mime );
			$request->set_header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
			$request->set_header( 'Content-MD5', md5( $bytes ) );
			$request->set_body( $bytes );
			return rest_result( rest_do_request( $request ) );
		},
		static function ( $result ) {
			return isset( $result['id'] ) ? '/wp/v2/media/' . (int) $result['id'] : null;
		}
	);
}

function protected_plugin( $plugin ) {
	$self = plugin_basename( dirname( __DIR__ ) . '/mcp-connector.php' );
	return 0 === strcasecmp( $self, $plugin . '.php' );
}

function update_plugin( $plugin ) {
	if ( ! plugin_identifier( $plugin ) || ! current_user_can( 'update_plugins' ) || protected_plugin( $plugin ) ) {
		return new \WP_Error( 'mcp_plugin_update_denied', 'Plugin update is unavailable for this identifier or user.' );
	}
	$route  = '/wp/v2/plugins/' . $plugin;
	$file   = $plugin . '.php';
	return audit_mutation(
		'wordpress_update_plugin',
		$file,
		array(),
		$route,
		static function () use ( $file, $route ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			if ( ! isset( get_plugins()[ $file ] ) ) {
				return new \WP_Error( 'mcp_plugin_missing', 'The plugin is not installed.' );
			}
			if ( 'direct' !== get_filesystem_method() ) {
				return new \WP_Error( 'mcp_filesystem_unavailable', 'Plugin updates require direct WordPress filesystem access.' );
			}

			wp_update_plugins();
			$updates = get_site_transient( 'update_plugins' );
			if ( empty( $updates->response[ $file ] ) ) {
				return new \WP_Error( 'mcp_plugin_up_to_date', 'WordPress has no update available for this plugin.' );
			}

			$skin     = new \WP_Ajax_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$results  = $upgrader->bulk_upgrade( array( $file ) );
			$result   = $results[ $file ] ?? null;
			if ( is_wp_error( $skin->result ) ) {
				return $skin->result;
			}
			if ( $skin->get_errors()->has_errors() ) {
				return $skin->get_errors();
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! is_array( $result ) || empty( $result['destination_name'] ) ) {
				return new \WP_Error( 'mcp_plugin_update_failed', 'WordPress could not update the plugin.' );
			}
			wp_clean_plugins_cache( false );
			return rest_result( dispatch_rest( 'GET', $route ) );
		},
		static function () use ( $route ) {
			return $route;
		}
	);
}

function activate_theme( $stylesheet ) {
	$route = '/wp/v2/themes/' . $stylesheet;
	if ( ! preg_match( '~^[a-z0-9_-]+(?:/[a-z0-9_-]+)?$~iD', $stylesheet ) || ! current_user_can( 'switch_themes' ) ) {
		return new \WP_Error( 'mcp_theme_activation_denied', 'Theme activation is unavailable for this identifier or user.' );
	}
	$theme = wp_get_theme( $stylesheet );
	if ( ! $theme->exists() || ! $theme->is_allowed() || $theme->errors() ) {
		return new \WP_Error( 'mcp_theme_unavailable', 'The theme is unavailable on this site.' );
	}
	$previous = get_stylesheet();
	return audit_mutation(
		'wordpress_activate_theme',
		$route,
		array( 'stylesheet' => $stylesheet ),
		'/wp/v2/themes/' . $previous,
		static function () use ( $stylesheet ) {
			$requirements = validate_theme_requirements( $stylesheet );
			if ( is_wp_error( $requirements ) ) {
				return $requirements;
			}
			switch_theme( $stylesheet );
			if ( get_stylesheet() !== $stylesheet ) {
				return new \WP_Error( 'mcp_theme_activation_failed', 'WordPress did not activate the requested theme.' );
			}
			return array( 'stylesheet' => get_stylesheet() );
		},
		static function () use ( $route ) {
			return $route;
		}
	);
}
