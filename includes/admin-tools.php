<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function admin_resources() {
	return array(
		'acf_field_group'          => 'acf_definition_resource',
		'acf_options_page'         => 'acf_definition_resource',
		'acf_post_type'            => 'acf_definition_resource',
		'acf_taxonomy'             => 'acf_definition_resource',
		'acf_options_page_values'  => 'acf_options_values_resource',
		'customizer'               => 'customizer_resource',
		'theme_mod'                => 'theme_mod_resource',
		'global_styles'            => 'global_styles_resource',
		'site_option'              => 'site_option_resource',
		'yoast_settings'           => 'yoast_settings_resource',
		'yoast_post'               => 'yoast_object_resource',
		'yoast_term'               => 'yoast_object_resource',
	);
}

function available_admin_resources() {
	$resources = array( 'customizer', 'theme_mod', 'site_option' );
	if ( wp_theme_has_theme_json() ) {
		$resources[] = 'global_styles';
	}
	foreach ( array_keys( acf_definition_types() ) as $type ) {
		if ( acf_definition_instance( $type ) ) {
			$resources[] = $type;
		}
	}
	if ( function_exists( 'acf_get_options_pages' ) ) {
		$resources[] = 'acf_options_page_values';
	}
	if ( class_exists( '\WPSEO_Options' ) ) {
		$resources = array_merge( $resources, array( 'yoast_settings', 'yoast_post', 'yoast_term' ) );
	}
	return $resources;
}

function admin_error( $message, $code = 'mcp_admin_invalid' ) {
	return new \WP_Error( $code, $message );
}

function call_admin_tool( $name, array $args ) {
	$resource   = $args['resource'];
	$identifier = $args['identifier'] ?? '';
	$handler    = __NAMESPACE__ . '\\' . admin_resources()[ $resource ];
	$snapshot   = static function () use ( $handler, $resource, $identifier ) {
		return $handler( $resource, $identifier, 'read', array() );
	};

	if ( 'wordpress_admin_read' === $name ) {
		return $snapshot();
	}
	if ( '' === $identifier || strlen( $identifier ) > 190 || preg_match( '/[\x00-\x1f]/', $identifier ) ) {
		return admin_error( 'Supply a valid resource identifier.' );
	}
	$operation = $args['operation'];
	$body      = isset( $args['body'] ) ? json_decode( wp_json_encode( $args['body'] ), true ) : array();
	if ( 'delete' === $operation && ! in_array( $resource, array_merge( array_keys( acf_definition_types() ), array( 'theme_mod', 'site_option' ) ), true ) ) {
		return admin_error( 'This resource does not support deletion.' );
	}
	if ( 'update' === $operation && ! $body ) {
		return admin_error( 'Supply a nonempty body for the update.' );
	}
	if ( 'delete' === $operation && $body ) {
		return admin_error( 'The delete operation does not accept a body.' );
	}
	$audit_input = 'acf_options_page_values' === $resource ?
		array( 'field_keys' => array_keys( (array) ( $body['values'] ?? array() ) ) ) : $body;

	return audit_mutation(
		$name,
		$operation . ' ' . $resource . '/' . $identifier,
		array( 'resource' => $resource, 'identifier' => $identifier ) + $audit_input,
		$snapshot,
		static function () use ( $handler, $resource, $identifier, $operation, $body ) {
			return $handler( $resource, $identifier, $operation, $body );
		},
		static function () use ( $snapshot ) {
			return $snapshot;
		}
	);
}

function customizer_manager() {
	global $wp_customize;

	if ( ! $wp_customize instanceof \WP_Customize_Manager ) {
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		$wp_customize = new \WP_Customize_Manager(
			array( 'theme' => get_stylesheet(), 'settings_previewed' => false )
		);
		$wp_customize->wp_loaded();
	}
	return $wp_customize;
}

function customizer_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! current_user_can( 'customize' ) ) {
		return admin_error( 'You are not allowed to customize this theme.', 'mcp_admin_forbidden' );
	}

	$manager = customizer_manager();
	if ( '' === $identifier ) {
		$settings = array();
		foreach ( $manager->settings() as $id => $setting ) {
			if ( $setting->check_capabilities() && ! audit_sensitive_key( $id ) ) {
				$settings[ $id ] = $setting->type;
			}
		}
		return array( 'stylesheet' => get_stylesheet(), 'settings' => $settings );
	}

	$setting = $manager->get_setting( $identifier );
	if ( ! $setting || ! $setting->check_capabilities() || audit_sensitive_key( $identifier ) ) {
		return admin_error( 'The Customizer setting is unavailable to this user.', 'mcp_admin_forbidden' );
	}
	if ( 'update' === $operation ) {
		if ( ! isset( $body['value'] ) || 1 !== count( $body ) ) {
			return admin_error( 'Supply a non-null value for this setting.' );
		}
		$validities = $manager->validate_setting_values(
			array( $identifier => $body['value'] ),
			array( 'validate_capability' => true, 'validate_existence' => true )
		);
		if ( is_wp_error( $validities[ $identifier ] ?? null ) ) {
			return $validities[ $identifier ];
		}
		$manager->set_post_value( $identifier, $body['value'] );
		if ( false === $setting->save() ) {
			return admin_error( 'WordPress did not save the Customizer setting.' );
		}
	}

	return array( 'stylesheet' => get_stylesheet(), 'type' => $setting->type, $identifier => $setting->value() );
}

function theme_mod_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return admin_error( 'You are not allowed to manage theme options.', 'mcp_admin_forbidden' );
	}
	$mods    = (array) get_theme_mods();
	$allowed = array_filter( (array) ( defined( 'WP_MCP_THEME_MODS' ) ? WP_MCP_THEME_MODS : array() ), 'is_string' );
	if ( '' === $identifier ) {
		$keys = array_unique( array_merge( array_keys( $mods ), $allowed ) );
		return array( 'stylesheet' => get_stylesheet(), 'keys' => array_values( array_filter( $keys, static function ( $key ) {
			return ! audit_sensitive_key( $key );
		} ) ) );
	}
	if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $identifier ) || audit_sensitive_key( $identifier ) ) {
		return admin_error( 'The theme mod is unavailable.' );
	}
	if ( 'read' === $operation ) {
		return array_intersect_key( $mods, array( $identifier => true ) );
	}

	// Keep existing registered settings on the Customizer path, including their sanitizers.
	$manager = current_user_can( 'customize' ) ? customizer_manager() : null;
	if ( $manager && $manager->get_setting( $identifier ) ) {
		return 'update' === $operation ?
			customizer_resource( $resource, $identifier, $operation, $body ) :
			admin_error( 'Use the Customizer setting to reset this value.' );
	}
	if ( ! in_array( $identifier, $allowed, true ) ) {
		return admin_error( 'Configure this mod in WP_MCP_THEME_MODS before changing it.' );
	}
	if ( 'delete' === $operation ) {
		remove_theme_mod( $identifier );
		return array( 'deleted' => ! array_key_exists( $identifier, (array) get_theme_mods() ) );
	}
	if ( ! array_key_exists( 'value', $body ) || 1 !== count( $body ) ) {
		return admin_error( 'Supply a value for this theme mod.' );
	}
	set_theme_mod( $identifier, $body['value'] );
	return theme_mod_resource( $resource, $identifier, 'read', array() );
}

function global_styles_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! wp_theme_has_theme_json() || ! current_user_can( 'edit_theme_options' ) ) {
		return admin_error( 'Global Styles are unavailable to this theme or user.', 'mcp_admin_forbidden' );
	}
	if ( '' !== $identifier && 'active' !== $identifier ) {
		return admin_error( 'Use active to target the current theme’s Global Styles.' );
	}
	if ( 'update' === $operation ) {
		if ( array_diff_key( $body, array( 'settings' => true, 'styles' => true ) ) ) {
			return admin_error( 'Supply styles and/or settings for the active theme.' );
		}
		$id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( ! $id ) {
			return admin_error( 'WordPress did not initialize the Global Styles record.' );
		}
		return rest_result( dispatch_rest( 'POST', '/wp/v2/global-styles/' . $id, $body ) );
	}

	$stored = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
	if ( empty( $stored['ID'] ) ) {
		return array( 'stylesheet' => get_stylesheet(), 'settings' => array(), 'styles' => array() );
	}
	return rest_result( dispatch_rest( 'GET', '/wp/v2/global-styles/' . $stored['ID'], array( 'context' => 'edit' ) ) );
}

function site_option_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return admin_error( 'You are not allowed to manage site options.', 'mcp_admin_forbidden' );
	}
	$allowed = array_values( array_filter(
		(array) ( defined( 'WP_MCP_SITE_OPTIONS' ) ? WP_MCP_SITE_OPTIONS : array() ),
		static function ( $key ) {
			return is_string( $key ) && preg_match( '/^[a-zA-Z0-9_-]+$/D', $key ) && ! audit_sensitive_key( $key ) &&
				! preg_match( '/(?:^|_)user_roles$|^active_plugins$|^uninstall_plugins$|^siteurl$|^home$|^template$|^stylesheet$|^wpseo/i', $key );
		}
	) );
	if ( '' === $identifier ) {
		return array( 'keys' => $allowed );
	}
	if ( ! in_array( $identifier, $allowed, true ) ) {
		return admin_error( 'This option is not configured in WP_MCP_SITE_OPTIONS.' );
	}

	$missing = new \stdClass();
	if ( 'delete' === $operation ) {
		delete_option( $identifier );
		return array( 'deleted' => $missing === get_option( $identifier, $missing ) );
	}
	if ( 'update' === $operation ) {
		if ( ! array_key_exists( 'value', $body ) || 1 !== count( $body ) ) {
			return admin_error( 'Supply a value for this site option.' );
		}
		update_option( $identifier, $body['value'] );
	}
	$value = get_option( $identifier, $missing );
	return $missing === $value ? array() : array( $identifier => $value );
}
