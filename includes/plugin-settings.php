<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function plugin_settings_specs() {
	return array(
		'media_cloud' => array( 'class', '\MediaCloud\Plugin\Utilities\Environment', array(
			'mcloud-tool-enabled-storage' => 'bool', 'mcloud-storage-provider' => 'key',
			'mcloud-storage-s3-bucket' => 'text', 'mcloud-storage-s3-region' => 'text',
			'mcloud-storage-s3-access-key' => 'secret', 'mcloud-storage-s3-secret' => 'secret',
			'mcloud-storage-upload-images' => 'bool', 'mcloud-storage-upload-video' => 'bool',
			'mcloud-storage-upload-audio' => 'bool', 'mcloud-storage-upload-documents' => 'bool',
			'mcloud-storage-cache-control' => 'text', 'mcloud-storage-expires' => 'uint',
			'mcloud-storage-overwrite-existing' => 'bool', 'mcloud-storage-force-webp' => 'bool',
			'mcloud-storage-filter-content' => 'bool', 'mcloud-storage-replace-hrefs' => 'bool',
			'mcloud-storage-replace-all-image-urls' => 'bool', 'mcloud-storage-display-badge' => 'bool',
			'mcloud-storage-display-media-list' => 'bool', 'mcloud-storage-exclude-presigned-urls-rest' => 'bool',
		) ),
		'ewww_settings' => array( 'function', 'ewww_image_optimizer_optimize_by_id', array(
			'ewww_image_optimizer_metadata_remove' => 'bool', 'ewww_image_optimizer_auto' => 'bool',
			'ewww_image_optimizer_webp' => 'bool', 'ewww_image_optimizer_lazy_load' => 'bool',
			'ewww_image_optimizer_add_missing_dims' => 'bool', 'ewww_image_optimizer_preserve_originals' => 'bool',
			'ewww_image_optimizer_resize_existing' => 'bool', 'ewww_image_optimizer_include_originals' => 'bool',
			'ewww_image_optimizer_maxmediawidth' => 'uint', 'ewww_image_optimizer_maxmediaheight' => 'uint',
			'ewww_image_optimizer_jpg_quality' => 'quality', 'ewww_image_optimizer_webp_quality' => 'quality',
			'ewww_image_optimizer_jpg_level' => 'uint', 'ewww_image_optimizer_png_level' => 'uint',
			'ewww_image_optimizer_gif_level' => 'uint', 'ewww_image_optimizer_pdf_level' => 'uint',
			'ewww_image_optimizer_svg_level' => 'uint', 'ewww_image_optimizer_webp_level' => 'uint',
		) ),
		'justdev_support' => array( 'class', '\JdSupport\Admin\Controllers\AdminController', array(
			'jd_permissions_mode' => 'toggle',
			'jd_remove_wp' => 'toggle', 'jd_remove_wp_core' => 'toggle', 'jd_remove_comm' => 'toggle',
			'jd_add_logo' => 'toggle', 'jd_custom_footer' => 'toggle', 'jd_svg_support' => 'toggle',
			'jd_dark_mode' => 'toggle', 'jd_hide_update' => 'toggle',
		) ),
		'syncport_settings' => array( 'class', '\JustDev\SyncPort\Infrastructure\ConnectionRepository', array(
			'syncport_allow_push' => 'bool', 'syncport_allow_pull' => 'bool',
		) ),
	);
}

function plugin_settings_available( array $spec ) {
	return 'class' === $spec[0] ? class_exists( $spec[1] ) : function_exists( $spec[1] );
}

function sanitize_plugin_setting( $name, $value, $type ) {
	if ( 'bool' === $type && is_bool( $value ) ) {
		return $value;
	}
	if ( 'toggle' === $type && ( is_bool( $value ) || in_array( $value, array( 'yes', 'no' ), true ) ) ) {
		return true === $value || 'yes' === $value ? 'yes' : 'no';
	}
	if ( 'uint' === $type && is_int( $value ) && $value >= 0 ) {
		return $value;
	}
	if ( 'integer' === $type && is_int( $value ) ) {
		return $value;
	}
	if ( 'quality' === $type && is_int( $value ) && $value >= 1 && $value <= 100 ) {
		return $value;
	}
	if ( 'keys' === $type && is_array( $value ) && count( $value ) === count( array_filter( $value, 'is_string' ) ) ) {
		return array_values( array_unique( array_map( 'sanitize_key', $value ) ) );
	}
	if ( 'user_ids' === $type && is_array( $value ) && count( $value ) === count( array_filter( $value, 'is_int' ) ) ) {
		return $value;
	}
	if ( 'textarea' === $type && is_string( $value ) ) {
		return sanitize_textarea_field( $value );
	}
	if ( 'url' === $type && is_string( $value ) ) {
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === trim( $value ) || wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $url;
		}
	}
	if ( in_array( $type, array( 'text', 'key', 'secret' ), true ) && is_string( $value ) ) {
		return 'key' === $type ? sanitize_key( $value ) : sanitize_text_field( $value );
	}
	return admin_error( 'Invalid value type for ' . $name . '.' );
}

function named_plugin_options( array $specs, $operation, array $body, $reader = 'get_option', $writer = 'update_option' ) {
	if ( 'update' === $operation ) {
		$changes = array();
		foreach ( $body as $key => $value ) {
			if ( ! isset( $specs[ $key ] ) ) {
				return admin_error( 'Unknown plugin setting: ' . $key );
			}
			$changes[ $key ] = sanitize_plugin_setting( $key, $value, $specs[ $key ] );
			if ( is_wp_error( $changes[ $key ] ) ) {
				return $changes[ $key ];
			}
		}
		foreach ( $changes as $key => $value ) {
			$writer( $key, $value );
		}
	}

	$values = array();
	foreach ( $specs as $key => $type ) {
		$value = $reader( $key );
		$values[ $key ] = 'secret' === $type ? array( 'configured' => ! empty( $value ) ) : $value;
	}
	return $values;
}

function plugin_settings_resource( $resource, $identifier, $operation, array $body ) {
	$spec = plugin_settings_specs()[ $resource ];
	if ( ! plugin_settings_available( $spec ) || ! current_user_can( 'manage_options' ) ) {
		return admin_error( 'This plugin’s settings require an active plugin and manage_options.', 'mcp_admin_forbidden' );
	}
	if ( '' !== $identifier && 'site' !== $identifier ) {
		return admin_error( 'Use site to manage this plugin’s settings.' );
	}

	if ( 'media_cloud' === $resource ) {
		if ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
			return admin_error( 'Media Cloud settings on multisite require manage_network_options.' );
		}
		return named_plugin_options( $spec[2], $operation, $body, array( $spec[1], 'Option' ), array( $spec[1], 'UpdateOption' ) );
	}
	if ( 'ewww_settings' === $resource ) {
		if ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
			return admin_error( 'EWWW settings on multisite require manage_network_options.' );
		}
		$reader = function_exists( 'ewww_image_optimizer_get_option' ) ? 'ewww_image_optimizer_get_option' : 'get_option';
		$writer = function_exists( 'ewww_image_optimizer_set_option' ) ? 'ewww_image_optimizer_set_option' : 'update_option';
		return named_plugin_options( $spec[2], $operation, $body, $reader, $writer );
	}
	if ( 'justdev_support' === $resource ) {
		return justdev_support_settings( $spec[2], $operation, $body );
	}
	return named_plugin_options( $spec[2], $operation, $body );
}

function justdev_support_settings( array $settings, $operation, array $body ) {
	$container = new \JdSupport\Core\Container\Container();
	$container->registerServices();
	if ( ! $container->has( 'content_lock' ) || ! $container->get( 'content_lock' )->canManageLockSettings() ) {
		return admin_error( 'justDev Support requires the plugin’s settings permission.', 'mcp_admin_forbidden' );
	}

	$controller = new \JdSupport\Admin\Controllers\AdminController( $container );
	$controller->addPluginPageSettings();
	$native = get_registered_settings();
	$restricted = array(
		'jd_content_lock' => 'toggle', 'jd_environment_notice' => 'toggle',
		'jd_environment_notice_message' => 'textarea', 'jd_environment_notice_live_url' => 'url',
		'jd_content_lock_allowed_users' => 'user_ids',
	);
	foreach ( $restricted as $key => $type ) {
		if ( ! empty( $native[ $key ]['sanitize_callback'] ) ) {
			$settings[ $key ] = $type;
		}
	}
	return named_plugin_options( $settings, $operation, $body );
}

function snootl_settings_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! class_exists( '\Snootl\Admin\Settings' ) || ! current_user_can( 'manage_options' ) ) {
		return admin_error( 'Snootl settings require the Feedback Widget and manage_options.', 'mcp_admin_forbidden' );
	}
	if ( '' !== $identifier && 'site' !== $identifier ) {
		return admin_error( 'Use site to manage the Snootl widget.' );
	}
	$stored = (array) get_option( 'snootl_options', array() );
	$spec = array( 'api_key' => 'secret', 'visibility_mode' => 'key', 'min_role' => 'key', 'show_in_admin' => 'bool' );
	if ( isset( $body['visibility_mode'] ) && ! in_array( $body['visibility_mode'], array( 'disabled', 'everyone', 'logged_in', 'role_level' ), true ) ) {
		return admin_error( 'Choose a supported Snootl visibility mode.' );
	}
	if ( isset( $body['min_role'] ) && ( ! is_string( $body['min_role'] ) || ! wp_roles()->is_role( $body['min_role'] ) ) ) {
		return admin_error( 'Choose an existing WordPress role.' );
	}
	$reader = static function ( $key ) use ( &$stored ) {
		return $stored[ $key ] ?? null;
	};
	$writer = static function ( $key, $value ) use ( &$stored ) {
		$stored[ $key ] = $value;
	};
	$result = named_plugin_options( $spec, $operation, $body, $reader, $writer );
	if ( ! is_wp_error( $result ) && 'update' === $operation ) {
		update_option( 'snootl_options', $stored );
	}
	return $result;
}

function complianz_settings_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! function_exists( 'cmplz_user_can_manage' ) || ! cmplz_user_can_manage() ) {
		return admin_error( 'Complianz settings require the plugin’s manage capability.', 'mcp_admin_forbidden' );
	}
	if ( '' !== $identifier && 'site' !== $identifier ) {
		return admin_error( 'Use site to manage Complianz settings.' );
	}

	$settings = array();
	$fields = \COMPLIANZ::$config->fields;
	foreach ( $fields as $field ) {
		if ( empty( $field['id'] ) || empty( $field['type'] ) || audit_sensitive_key( $field['id'] ) || preg_match( '/license|password|script|custom.?code|html/i', $field['id'] ) ) {
			continue;
		}
		$settings[ $field['id'] ] = array( 'type' => $field['type'], 'value' => cmplz_get_option( $field['id'] ) );
	}
	if ( 'update' === $operation ) {
		// Complianz loads these helpers only on its own administration requests.
		if ( ! function_exists( 'cmplz_sanitize_field' ) && defined( 'cmplz_path' ) && is_readable( cmplz_path . 'settings/settings.php' ) ) {
			require_once cmplz_path . 'settings/settings.php';
		}
		if ( ! function_exists( 'cmplz_sanitize_field' ) || ! function_exists( 'cmplz_maybe_add_source_option' ) ) {
			return admin_error( 'The Complianz settings service is unavailable.' );
		}
		$owner = (int) get_transient( 'cmplz_wizard_locked_by_user' );
		if ( $owner && $owner !== get_current_user_id() ) {
			return admin_error( 'Another user is editing the Complianz wizard.' );
		}

		$updates = array();
		$config = array_column( $fields, null, 'id' );
		foreach ( $body as $key => $value ) {
			if ( ! isset( $settings[ $key ] ) || ( ! is_scalar( $value ) && ! is_array( $value ) ) ) {
				return admin_error( 'Unknown or invalid Complianz field: ' . $key );
			}
			$field = $config[ $key ];
			$type = $field['type'];
			if ( in_array( $type, array( 'processors', 'thirdparties', 'colorpicker', 'multicheckbox' ), true ) !== is_array( $value ) ) {
				return admin_error( 'Invalid value type for Complianz field: ' . $key );
			}
			$updates[ $key ] = cmplz_sanitize_field( $value, $type, $key );
		}

		$options = (array) get_option( 'cmplz_options', array() );
		$saved = array();
		foreach ( $updates as $key => $value ) {
			$field = $config[ $key ];
			$type = $field['type'];
			$previous = $options[ $key ] ?? false;
			do_action( 'cmplz_before_save_option', $key, $value, $previous, $type );
			$options[ $key ] = $value;
			$options = cmplz_maybe_add_source_option( $options, $value, $field );
			$options = apply_filters( 'cmplz_before_save_options', $options, $key, $value, $previous, $type );
			$saved[] = array( 'id' => $key, 'type' => $type, 'value' => $value, 'prev_value' => $previous );
		}
		update_option( 'cmplz_options', $options );
		foreach ( $saved as $field ) {
			if ( ! empty( $config[ $field['id'] ]['translatable'] ) && is_string( $field['value'] ) ) {
				cmplz_register_translation( $field['value'], $field['id'] );
			}
			do_action( 'cmplz_after_save_field', $field['id'], $field['value'], $field['prev_value'], $field['type'] );
		}
		do_action( 'cmplz_after_saved_fields', $saved );
		cmplz_delete_transient( 'cmplz_blocked_scripts' );
		return complianz_settings_resource( $resource, $identifier, 'read', array() );
	}
	return $settings;
}

function complianz_banner_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! function_exists( 'cmplz_user_can_manage' ) || ! cmplz_user_can_manage() || ! function_exists( 'cmplz_get_cookiebanners' ) ) {
		return admin_error( 'Complianz banners require the plugin’s manage capability.', 'mcp_admin_forbidden' );
	}
	if ( ! get_option( 'cmplz_cbdb_version' ) ) {
		return 'read' === $operation && '' === $identifier ? array( 'banners' => array() ) :
			admin_error( 'Complete the Complianz banner setup first.' );
	}
	if ( '' === $identifier ) {
		return array( 'banners' => array_map( static function ( $row ) {
			return array( 'id' => (int) $row->ID, 'title' => $row->title, 'default' => (bool) $row->default );
		}, cmplz_get_cookiebanners() ?: array() ) );
	}
	if ( ! preg_match( '/^[1-9][0-9]*$/D', $identifier ) || ! cmplz_get_cookiebanners( array( 'ID' => (int) $identifier ) ) ) {
		return admin_error( 'Supply an existing Complianz banner ID.' );
	}

	$banner = new \CMPLZ_COOKIEBANNER( (int) $identifier );
	$settings = array();
	foreach ( wp_list_pluck( cmplz_add_cookiebanner_settings( array() ), 'id' ) as $key ) {
		if ( isset( $banner->{$key} ) && ! in_array( $key, array( 'ID', 'banner_version', 'custom_css', 'use_custom_cookie_css' ), true ) ) {
			$settings[ $key ] = $banner->{$key};
		}
	}
	if ( 'update' === $operation ) {
		foreach ( $body as $key => $value ) {
			if ( ! isset( $settings[ $key ] ) || ( is_array( $settings[ $key ] ) ? ! is_array( $value ) : ! is_scalar( $value ) ) ) {
				return admin_error( 'Unknown or invalid Complianz banner field: ' . $key );
			}
			$banner->{$key} = $value;
		}
		if ( ! function_exists( 'cmplz_update_option_no_hooks' ) && is_readable( cmplz_path . 'settings/settings.php' ) ) {
			require_once cmplz_path . 'settings/settings.php';
		}
		if ( ! function_exists( 'cmplz_update_option_no_hooks' ) ) {
			return admin_error( 'The Complianz banner settings service is unavailable.' );
		}
		$banner->save();
		return complianz_banner_resource( $resource, $identifier, 'read', array() );
	}
	return $settings;
}
