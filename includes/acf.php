<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function acf_definition_types() {
	return array(
		'acf_field_group'  => 'acf-field-group',
		'acf_options_page' => 'acf-ui-options-page',
		'acf_post_type'    => 'acf-post-type',
		'acf_taxonomy'     => 'acf-taxonomy',
	);
}

function acf_definition_instance( $resource ) {
	return function_exists( 'acf_get_internal_post_type_instance' ) ?
		acf_get_internal_post_type_instance( acf_definition_types()[ $resource ] ) : null;
}

function acf_definition_resource( $resource, $identifier, $operation, array $body ) {
	$instance = acf_definition_instance( $resource );
	if ( ! $instance ) {
		return admin_error( 'This ACF definition type is unavailable. ACF Options Page definitions require ACF PRO 6.2 or later.', 'mcp_acf_unavailable' );
	}
	if ( ! current_user_can( acf_get_setting( 'capability' ) ?: 'manage_options' ) ) {
		return admin_error( 'You are not allowed to manage ACF definitions.', 'mcp_admin_forbidden' );
	}
	$type = acf_definition_types()[ $resource ];
	if ( '' === $identifier ) {
		$definitions = array();
		foreach ( $instance->get_posts() as $definition ) {
			$definitions[] = array_intersect_key( $definition, array_flip( array( 'key', 'title', 'local', 'active', 'menu_slug', 'post_type', 'taxonomy' ) ) );
		}
		return $definitions;
	}

	if ( ! preg_match( '/^[a-z0-9_]+$/D', $identifier ) || ! acf_is_internal_post_type_key( $identifier, $type ) ) {
		return admin_error( 'Use the stable ACF definition key from the resource list.' );
	}
	$stored = $instance->get_raw_post( $identifier );
	$local  = acf_get_local_internal_post_type( $identifier, $type );
	$json   = $local['local_file'] ?? '';
	if ( 'read' === $operation ) {
		$definition = $instance->get_post( $identifier );
		if ( $stored && 'trash' === get_post_status( $stored['ID'] ) && ! $local ) {
			$definition = false;
		}
		if ( ! $definition ) {
			return admin_error( 'ACF definition not found.', 'mcp_admin_not_found' );
		}
		if ( 'acf-field-group' === $type ) {
			$definition['fields'] = acf_get_fields( $definition ) ?: array();
		}
		$export = $instance->prepare_post_for_export( $definition );
		unset( $export['local_file'], $export['modified'] );
		return $export;
	}

	if ( $local && ( ! $json || ! $stored || 'json' !== ( $local['local'] ?? null ) ) ) {
		return admin_error( 'This definition is managed in local PHP or unsynced Local JSON.', 'mcp_acf_source_managed' );
	}
	if ( 'delete' === $operation ) {
		if ( ! $stored ) {
			return admin_error( 'ACF definition not found.', 'mcp_admin_not_found' );
		}
		if ( $json || ! EMPTY_TRASH_DAYS ) {
			return admin_error( 'Use ACF administration to remove this definition. MCP trash requires recoverable database storage.' );
		}
		// Use ACF's trash action to keep the definition recoverable.
		if ( ! acf_trash_internal_post_type( $stored['ID'], $type ) || 'trash' !== get_post_status( $stored['ID'] ) ) {
			return admin_error( 'ACF did not trash the definition.' );
		}
		return array( 'key' => $identifier, 'trashed' => true );
	}

	foreach ( array_keys( $body ) as $key ) {
		if ( in_array( $key, array( 'ID', 'local', 'local_file', 'modified' ), true ) || 0 === strpos( (string) $key, '_' ) || ( 'key' === $key && $identifier !== $body['key'] ) ) {
			return admin_error( 'Only editable ACF definition properties are accepted.' );
		}
	}
	$record = array_replace( $stored ?: array(), $body, array( 'key' => $identifier ) );
	if ( ! is_string( $record['title'] ?? null ) || '' === trim( $record['title'] ) ) {
		return admin_error( 'A nonempty ACF definition title is required.' );
	}
	if ( isset( $record['active'] ) && ! is_bool( $record['active'] ) && ! in_array( $record['active'], array( 0, 1 ), true ) ) {
		return admin_error( 'active must be a boolean.' );
	}
	if ( 'acf-field-group' === $type && array_key_exists( 'location', $body ) && ! valid_acf_locations( $body['location'] ) ) {
		return admin_error( 'location must contain groups of ACF rules with param, operator and value.' );
	}
	if ( isset( $body['fields'] ) && 'acf-field-group' !== $type ) {
		return admin_error( 'Only field groups accept fields.' );
	}
	if ( 'acf-ui-options-page' === $type ) {
		$slug = $record['menu_slug'] ?? null;
		if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9_-]+$/D', $slug ) ) {
			return admin_error( 'Supply a valid menu_slug for this ACF Options Page.' );
		}
		if ( $slug !== ( $stored['menu_slug'] ?? null ) && function_exists( 'acf_get_options_page' ) && acf_get_options_page( $slug ) ) {
			return admin_error( 'An ACF Options Page already uses this menu_slug.' );
		}
		if ( empty( $record['page_title'] ) ) {
			$record['page_title'] = $record['title'];
		}
	}
	if ( $json && ! acf_can_update_local_json( $record, $local, $json ) ) {
		return admin_error( 'Sync Local JSON first and verify that ACF can write to the loaded file.', 'mcp_acf_source_managed' );
	}
	$local_fields = $json && 'acf-field-group' === $type ?
		acf_prepare_fields_for_import( acf_get_fields( $local ) ?: array() ) : array();
	if ( 'acf-field-group' === $type && array_key_exists( 'fields', $body ) ) {
		$allowed_keys = array();
		if ( $stored ) {
			$filters = acf_disable_filters();
			try {
				$allowed_keys = wp_list_pluck( acf_prepare_fields_for_import( acf_get_fields( $stored ) ?: array() ), 'key' );
			} finally {
				acf_enable_filters( $filters );
			}
		}
		$seen = array();
		$valid = validate_acf_fields( $body['fields'], $allowed_keys, $seen );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$result = acf_import_field_group( wp_slash( $record ) );
	} else {
		$filters = acf_disable_filters();
		try {
			$result = $instance->update_post( wp_slash( $record ) );
		} finally {
			acf_enable_filters( $filters );
		}
	}
	if ( empty( $result['ID'] ) || ! $instance->get_raw_post( $identifier ) ) {
		return admin_error( 'ACF did not save the definition.' );
	}
	if ( $json ) {
		$local_json = acf_get_instance( 'ACF_Local_JSON' );
		if ( is_callable( array( $local_json, 'has_save_file_failure' ) ) && $local_json->has_save_file_failure() ) {
			return admin_error( 'The database changed, but ACF could not update Local JSON. Check both copies before retrying.', 'mcp_acf_json_save_failed' );
		}
		// Drop the copy loaded before the write so the response and audit reflect saved fields.
		acf_remove_local_internal_post_type( $identifier, $type );
		foreach ( $local_fields as $field ) {
			acf_remove_local_field( $field['key'] );
			acf_flush_field_cache( $field );
		}
	}
	return acf_definition_resource( $resource, $identifier, 'read', array() );
}

function acf_can_update_local_json( array $record, array $local, $file ) {
	$local_json = acf_get_instance( 'ACF_Local_JSON' );
	if ( ! $local_json || ! $local_json->is_enabled() || ! is_writable( $file ) ||
		! is_callable( array( $local_json, 'get_filename' ) ) || ! is_callable( array( $local_json, 'get_save_paths' ) ) ||
		(int) ( $local['modified'] ?? 0 ) > (int) get_post_modified_time( 'U', true, $record['ID'] ) ) {
		return false;
	}
	$filename = $local_json->get_filename( $record['key'], $record );
	$targets  = array();
	foreach ( $local_json->get_save_paths( $record['key'], $record ) as $path ) {
		if ( is_string( $path ) && is_writable( $path ) ) {
			$target = realpath( trailingslashit( $path ) . $filename );
			if ( $target ) {
				$targets[ $target ] = true;
			}
		}
	}
	return array_keys( $targets ) === array( realpath( $file ) );
}

function valid_acf_locations( $groups ) {
	if ( ! is_array( $groups ) || array_values( $groups ) !== $groups ) {
		return false;
	}
	foreach ( $groups as $rules ) {
		if ( ! is_array( $rules ) || ! $rules || array_values( $rules ) !== $rules ) {
			return false;
		}
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! is_string( $rule['param'] ?? null ) ||
				! is_string( $rule['operator'] ?? null ) || ! is_scalar( $rule['value'] ?? null ) ) {
				return false;
			}
		}
	}
	return true;
}

function validate_acf_fields( $fields, array $allowed_keys, array &$seen ) {
	if ( ! is_array( $fields ) || array_values( $fields ) !== $fields ) {
		return admin_error( 'fields and sub_fields must be arrays of ACF field definitions.' );
	}
	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) || ! is_string( $field['key'] ?? null ) || ! preg_match( '/^field_[a-z0-9_]+$/D', $field['key'] ) ||
			! is_string( $field['type'] ?? null ) || ! acf_get_field_type( $field['type'] ) ||
			! is_string( $field['label'] ?? null ) || ! is_string( $field['name'] ?? null ) ) {
			return admin_error( 'Each field needs a stable field_ key, a registered type, a label and a name.' );
		}
		$key = $field['key'];
		if ( isset( $seen[ $key ] ) || ( ! in_array( $key, $allowed_keys, true ) && acf_get_field( $key ) ) ) {
			return admin_error( 'Duplicate ACF field key: ' . $key );
		}
		foreach ( array_keys( $field ) as $property ) {
			if ( in_array( $property, array( 'ID', 'parent', 'local' ), true ) || 0 === strpos( (string) $property, '_' ) ) {
				return admin_error( 'Use exported fields without internal IDs or parent overrides.' );
			}
		}
		$seen[ $key ] = true;
		if ( array_key_exists( 'sub_fields', $field ) ) {
			$result = validate_acf_fields( $field['sub_fields'], $allowed_keys, $seen );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( array_key_exists( 'layouts', $field ) ) {
			if ( ! is_array( $field['layouts'] ) ) {
				return admin_error( 'Flexible Content layouts must be an array or keyed map.' );
			}
			foreach ( $field['layouts'] as $layout ) {
				if ( ! is_array( $layout ) || ! is_string( $layout['key'] ?? null ) || ! is_string( $layout['name'] ?? null ) ) {
					return admin_error( 'Each layout needs a key and name.' );
				}
				$result = validate_acf_fields( $layout['sub_fields'] ?? array(), $allowed_keys, $seen );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
	}
	return true;
}

function acf_options_values_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! function_exists( 'acf_get_options_page' ) ) {
		return admin_error( 'ACF PRO Options Pages are unavailable.', 'mcp_acf_unavailable' );
	}
	if ( '' === $identifier ) {
		$pages = array();
		foreach ( acf_get_options_pages() ?: array() as $page ) {
			if ( ! empty( $page['capability'] ) && current_user_can( $page['capability'] ) ) {
				$pages[ $page['menu_slug'] ] = $page['page_title'];
			}
		}
		return $pages;
	}

	$page = acf_get_options_page( $identifier );
	if ( empty( $page['capability'] ) || ! current_user_can( $page['capability'] ) ) {
		return admin_error( 'The ACF Options Page is unavailable to this user.', 'mcp_admin_forbidden' );
	}
	$post_id = acf_get_valid_post_id( $page['post_id'] );
	$storage = acf_decode_post_id( $post_id );
	$caps    = array( 'post' => 'edit_post', 'user' => 'edit_user', 'term' => 'edit_term', 'comment' => 'edit_comment' );
	if ( 'option' !== $storage['type'] && ( ! isset( $caps[ $storage['type'] ] ) || ! current_user_can( $caps[ $storage['type'] ], (int) $storage['id'] ) ) ) {
		return admin_error( 'You are not allowed to edit the Options Page storage object.', 'mcp_admin_forbidden' );
	}

	$fields = array();
	foreach ( acf_get_field_groups( array( 'options_page' => $identifier ) ) as $group ) {
		foreach ( acf_get_fields( $group ) ?: array() as $field ) {
			$fields[ $field['key'] ] = $field;
		}
	}

	if ( 'update' === $operation ) {
		if ( ! is_array( $body['values'] ?? null ) || ! $body['values'] || 1 !== count( $body ) ) {
			return admin_error( 'Supply a values object keyed by the field keys of this Options Page.' );
		}
		$unknown = array_diff_key( $body['values'], $fields );
		if ( $unknown ) {
			return admin_error( 'Field is not assigned to this Options Page: ' . array_key_first( $unknown ) );
		}
		acf_reset_validation_errors();
		foreach ( $body['values'] as $key => $value ) {
			acf_validate_value( $value, $fields[ $key ], (string) $key );
		}
		$errors = acf_get_validation_errors();
		acf_reset_validation_errors();
		if ( $errors ) {
			return admin_error( implode( ' ', wp_list_pluck( $errors, 'message' ) ), 'mcp_acf_validation_failed' );
		}
		$autoload      = acf_get_setting( 'autoload' );
		$previous_data = $_POST['acf'] ?? null;
		$previous_id   = acf_get_form_data( 'post_id' );
		acf_update_setting( 'autoload', ! empty( $page['autoload'] ) );
		try {
			if ( ! acf_save_post( $post_id, wp_slash( $body['values'] ) ) ) {
				return admin_error( 'ACF did not save the Options Page values.' );
			}
			do_action( 'acf/options_page/save', $post_id, $identifier );
		} finally {
			acf_update_setting( 'autoload', $autoload );
			acf_set_form_data( 'post_id', $previous_id );
			if ( null === $previous_data ) {
				unset( $_POST['acf'] );
			} else {
				$_POST['acf'] = $previous_data;
			}
		}
	}

	$values = array();
	foreach ( $fields as $key => $field ) {
		$values[ $key ] = array(
			'name'  => $field['name'],
			'label' => $field['label'],
			'type'  => $field['type'],
			'value' => acf_get_value( $post_id, $field ),
		);
	}
	return $values;
}
