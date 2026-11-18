<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wpml_available() {
	return isset( $GLOBALS['sitepress'] ) && is_callable( array( $GLOBALS['sitepress'], 'get_active_languages' ) );
}

function wpml_languages_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! wpml_available() || ! current_user_can( 'manage_options' ) ) {
		return admin_error( 'WPML language settings require WPML and manage_options.', 'mcp_admin_forbidden' );
	}
	if ( '' !== $identifier && 'site' !== $identifier ) {
		return admin_error( 'Use site for the WPML language configuration.' );
	}

	$sitepress = $GLOBALS['sitepress'];
	$available = $sitepress->get_languages( false, false, true );
	$active = array_keys( $sitepress->get_active_languages( true ) );
	$default = apply_filters( 'wpml_default_language', null );
	if ( 'read' === $operation ) {
		return array( 'default_language' => $default, 'active_languages' => $active, 'available_languages' => array_keys( $available ) );
	}

	if ( array_diff_key( $body, array( 'active_languages' => true, 'default_language' => true ) ) ) {
		return admin_error( 'Supply active_languages and/or default_language.' );
	}
	$requested = $body['active_languages'] ?? $active;
	$new_default = $body['default_language'] ?? $default;
	if ( ! is_array( $requested ) || ! $requested || ! is_string( $new_default ) || ! in_array( $new_default, $requested, true ) ) {
		return admin_error( 'The default language must be included in the active language list.' );
	}
	foreach ( $requested as $code ) {
		if ( ! is_string( $code ) || ! isset( $available[ $code ] ) ) {
			return admin_error( 'An unknown WPML language code was supplied.' );
		}
	}

	$requested = array_values( array_unique( $requested ) );
	if ( array_diff( $requested, $active ) || array_diff( $active, $requested ) ) {
		if ( ! function_exists( 'wpml_get_setup_instance' ) ) {
			return admin_error( 'WPML does not expose a language setup service on this site.' );
		}
		$setup = wpml_get_setup_instance();
		if ( ! in_array( $new_default, $active, true ) && ! $setup->set_active_languages( array_unique( array_merge( $active, $requested ) ) ) ) {
			return admin_error( 'WPML could not enable the requested default language.' );
		}
		if ( $default !== $new_default ) {
			$sitepress->set_default_language( $new_default );
		}
		if ( ! $setup->set_active_languages( $requested ) ) {
			return admin_error( 'WPML could not save the active languages.' );
		}
	} elseif ( $default !== $new_default ) {
		$sitepress->set_default_language( $new_default );
	}
	return wpml_languages_resource( $resource, $identifier, 'read', array() );
}

function wpml_element( $kind, $id ) {
	$is_post = 'post' === $kind;
	$object  = $is_post ? get_post( $id ) : get_term( $id );
	if ( ! $object || is_wp_error( $object ) || ! current_user_can( $is_post ? 'edit_post' : 'edit_term', $id ) ) {
		return admin_error( 'The WordPress object is unavailable to the current user.', 'mcp_admin_forbidden' );
	}

	$type = $is_post ? $object->post_type : $object->taxonomy;
	if ( ! mcp_is_administrator() && ! is_content_type( $is_post ? get_post_type_object( $type ) : get_taxonomy( $type ) ) ) {
		return admin_error( 'This object type is outside the MCP content scope.', 'mcp_admin_forbidden' );
	}
	$translated = $is_post ? 'wpml_is_translated_post_type' : 'wpml_is_translated_taxonomy';
	if ( ! apply_filters( $translated, false, $type ) ) {
		return admin_error( 'WPML is not configured to translate this object type.' );
	}
	$args = array(
		'element_id'   => $is_post ? $id : (int) $object->term_taxonomy_id,
		'element_type' => apply_filters( 'wpml_element_type', $type ),
	);
	return $args;
}

function wpml_content_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! wpml_available() ) {
		return admin_error( 'WPML is unavailable.', 'mcp_wpml_unavailable' );
	}
	if ( ! preg_match( '/^(post|term):([1-9][0-9]*)$/D', $identifier, $matches ) ) {
		return admin_error( 'Use a WordPress identifier such as post:42 or term:15.' );
	}
	$kind = $matches[1];
	$id   = (int) $matches[2];
	$element = wpml_element( $kind, $id );
	if ( is_wp_error( $element ) ) {
		return $element;
	}
	$details = apply_filters( 'wpml_element_language_details', null, $element );
	$group = empty( $details->trid ) ? array() :
		(array) apply_filters( 'wpml_get_element_translations', array(), (int) $details->trid, $element['element_type'], true, true, true );

	if ( 'read' === $operation ) {
		$translations = array();
		foreach ( $group as $language => $item ) {
			$translated_id = (int) $item->element_id;
			if ( 'term' === $kind ) {
				$term = get_term_by( 'term_taxonomy_id', $translated_id );
				$translated_id = $term ? (int) $term->term_id : 0;
			}
			if ( $translated_id && current_user_can( 'post' === $kind ? 'edit_post' : 'edit_term', $translated_id ) ) {
				$translations[ $language ] = $translated_id;
			}
		}
		return array(
			'id' => $id, 'language' => $details->language_code ?? null,
			'source_language' => $details->source_language_code ?? null, 'translations' => $translations,
		);
	}

	$language = $body['language'] ?? null;
	if ( array_diff_key( $body, array( 'language' => true, 'translation_of' => true ) ) ||
		! is_string( $language ) || ! array_key_exists( $language, $GLOBALS['sitepress']->get_active_languages() ) ) {
		return admin_error( 'Supply an active WPML language code.' );
	}
	$trid = empty( $details->trid ) ? false : (int) $details->trid;
	$source_language = $details->source_language_code ?? null;
	if ( array_key_exists( 'translation_of', $body ) ) {
		$source_id = $body['translation_of'];
		if ( ! is_int( $source_id ) || $source_id < 1 || $source_id === $id ) {
			return admin_error( 'translation_of must be the positive ID of another object of the same type.' );
		}
		$source = wpml_element( $kind, $source_id );
		if ( is_wp_error( $source ) || $source['element_type'] !== $element['element_type'] ) {
			return admin_error( 'The source object must be editable and have the same type.' );
		}
		$source_details = apply_filters( 'wpml_element_language_details', null, $source );
		if ( empty( $source_details->trid ) || $language === $source_details->language_code ||
			$language === $source_details->source_language_code ) {
			return admin_error( 'The source must belong to a WPML group and have a different language.' );
		}
		if ( count( $group ) > 1 && (int) $source_details->trid !== $trid ) {
			return admin_error( 'The target already belongs to a translation group.' );
		}
		$trid = (int) $source_details->trid;
		$source_language = $source_details->source_language_code ?: $source_details->language_code;
		$group = (array) apply_filters( 'wpml_get_element_translations', array(), $trid, $element['element_type'], true, true, true );
	}
	if ( isset( $group[ $language ] ) && (int) $group[ $language ]->element_id !== $element['element_id'] ) {
		return admin_error( 'This translation group already contains an object in the requested language.' );
	}
	if ( count( $group ) > 1 && empty( $details->source_language_code ) &&
		(int) ( $details->trid ?? 0 ) === $trid && $language !== ( $details->language_code ?? null ) ) {
		return admin_error( 'Changing the original language would also affect existing translations.' );
	}
	do_action( 'wpml_set_element_language_details', $element + array(
		'trid' => $trid, 'language_code' => $language, 'source_language_code' => $source_language,
	) );
	$resolved = wpml_content_resource( $resource, $identifier, 'read', array() );
	return is_array( $resolved ) && $language !== $resolved['language'] ?
		admin_error( 'WPML did not save the requested language.' ) : $resolved;
}

function wpml_in_language( $language, callable $callback ) {
	if ( ! wpml_available() || ! array_key_exists( $language, $GLOBALS['sitepress']->get_active_languages() ) ) {
		return admin_error( 'Select an active WPML language.' );
	}
	if ( is_callable( array( $GLOBALS['sitepress'], 'is_setup_complete' ) ) && ! $GLOBALS['sitepress']->is_setup_complete() ) {
		return admin_error( 'Complete WPML setup before requesting language-specific content.' );
	}
	$previous = apply_filters( 'wpml_current_language', null );
	do_action( 'wpml_switch_language', $language );
	try {
		return $callback();
	} finally {
		do_action( 'wpml_switch_language', $previous );
	}
}

function wpml_strings_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! wpml_available() || ! function_exists( 'icl_get_string_by_id' ) || ! current_user_can( 'manage_options' ) ) {
		return admin_error( 'WPML String Translation requires the add-on and manage_options.', 'mcp_admin_forbidden' );
	}
	if ( '' === $identifier && 'read' === $operation && function_exists( 'icl_get_string_id' ) ) {
		$context = $body['context'] ?? null;
		$value = $body['source'] ?? null;
		$name = $body['name'] ?? false;
		if ( ! is_string( $context ) || '' === $context || ! is_string( $value ) || ! is_string( $name ) ) {
			return admin_error( 'Supply a WPML string ID or use query.context, query.name and query.source.' );
		}
		$identifier = (string) icl_get_string_id( $value, $context, $name );
	}
	if ( ! preg_match( '/^[1-9][0-9]*$/D', $identifier ) ) {
		return admin_error( 'Supply an existing WPML string ID.' );
	}
	$id = (int) $identifier;
	$source = icl_get_string_by_id( $id );
	if ( false === $source ) {
		return admin_error( 'WPML string not found.' );
	}
	if ( 'update' === $operation ) {
		$language = $body['language'] ?? null;
		$value = $body['value'] ?? null;
		if ( 2 !== count( $body ) || ! is_string( $language ) || ! is_string( $value ) ||
			! array_key_exists( $language, $GLOBALS['sitepress']->get_active_languages() ) ||
			! function_exists( 'icl_add_string_translation' ) || ! defined( 'ICL_TM_COMPLETE' ) ) {
			return admin_error( 'Supply a translation value and an active WPML language.' );
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$value = wp_kses_post( $value );
		}
		$translation_id = icl_add_string_translation( $id, $language, $value, ICL_TM_COMPLETE, get_current_user_id() );
		if ( ! $translation_id || is_wp_error( $translation_id ) ) {
			return admin_error( 'WPML could not save the string translation.' );
		}
		if ( is_callable( array( '\WPML_Non_Persistent_Cache', 'flush_group' ) ) ) {
			\WPML_Non_Persistent_Cache::flush_group( 'icl_get_string_translations_by_id' );
		}
	}
	return array( 'id' => $id, 'source' => $source, 'translations' => icl_get_string_translations_by_id( $id ) );
}
