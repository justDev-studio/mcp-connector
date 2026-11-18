<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function yoast_settings_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! class_exists( '\WPSEO_Options' ) ) {
		return admin_error( 'Yoast SEO is unavailable.', 'mcp_yoast_unavailable' );
	}
	if ( ! current_user_can( 'wpseo_manage_options' ) ) {
		return admin_error( 'You are not allowed to manage Yoast SEO settings.', 'mcp_admin_forbidden' );
	}

	$groups = array_intersect( array( 'wpseo', 'wpseo_titles', 'wpseo_social' ), \WPSEO_Options::get_option_names() );
	if ( '' === $identifier ) {
		return array( 'groups' => array_values( $groups ) );
	}
	if ( ! in_array( $identifier, $groups, true ) ) {
		return admin_error( 'Use a Yoast SEO settings group from the resource list.' );
	}

	$option   = \WPSEO_Options::get_option_instance( $identifier );
	$stored   = (array) \WPSEO_Options::get_option( $identifier );
	$settings = array_filter(
		$stored + $option->get_defaults(),
		static function ( $key ) use ( $identifier ) {
			if ( audit_sensitive_key( $key ) ) {
				return false;
			}
			if ( 'wpseo' !== $identifier ) {
				return true;
			}
			return preg_match( '/^(enable_|remove_|clean_|deny_|search_)|verify$|_analysis_active$|^disableadvanced_meta$|^redirect_search_pretty_urls$/D', $key );
		},
		ARRAY_FILTER_USE_KEY
	);

	if ( 'update' === $operation ) {
		if ( array_diff_key( $body, $settings ) ) {
			return admin_error( 'Use only settings keys returned for this Yoast group.' );
		}
		// Yoast registers the same sanitize_option callback that its admin settings use.
		update_option( $identifier, array_replace( $stored, $body ) );
		\WPSEO_Options::clear_cache();
		return yoast_settings_resource( $resource, $identifier, 'read', array() );
	}
	return $settings;
}

function yoast_object_resource( $resource, $identifier, $operation, array $body ) {
	if ( ! class_exists( '\WPSEO_Options' ) || ! class_exists( '\WPSEO_Meta' ) || ! class_exists( '\WPSEO_Taxonomy_Meta' ) ) {
		return admin_error( 'Yoast SEO is unavailable.', 'mcp_yoast_unavailable' );
	}
	if ( ! preg_match( '/^[1-9][0-9]*$/D', $identifier ) ) {
		return admin_error( 'Supply a post or term ID as the identifier.' );
	}
	$id     = (int) $identifier;
	$is_post = 'yoast_post' === $resource;
	$object = $is_post ? get_post( $id ) : get_term( $id );
	if ( ! $object || is_wp_error( $object ) ) {
		return admin_error( 'Post or term not found.', 'mcp_admin_not_found' );
	}
	if ( ! current_user_can( $is_post ? 'edit_post' : 'edit_term', $id ) ) {
		return admin_error( 'You are not allowed to edit this object.', 'mcp_admin_forbidden' );
	}
	if ( ! mcp_is_administrator() && ! is_content_type( $is_post ? get_post_type_object( $object->post_type ) : get_taxonomy( $object->taxonomy ) ) ) {
		return admin_error( 'This object type is outside the MCP content scope.', 'mcp_admin_forbidden' );
	}

	$metadata = array();
	if ( $is_post ) {
		$prefix = \WPSEO_Meta::$meta_prefix;
		foreach ( array_keys( \WPSEO_Meta::$defaults ) as $key ) {
			$field = substr( $key, strlen( $prefix ) );
			$metadata[ $field ] = \WPSEO_Meta::get_value( $field, $id );
		}
	} else {
		foreach ( (array) \WPSEO_Taxonomy_Meta::get_term_meta( $object, $object->taxonomy ) as $key => $value ) {
			$metadata[ substr( $key, strlen( 'wpseo_' ) ) ] = $value;
		}
	}

	if ( 'update' === $operation ) {
		foreach ( $body as $key => $value ) {
			if ( ! array_key_exists( $key, $metadata ) || ( ! is_scalar( $value ) && null !== $value ) ) {
				return admin_error( 'Unknown or invalid Yoast metadata field: ' . $key );
			}
			if ( preg_match( '/^(canonical|breadcrumb|bctitle|meta-robots|noindex|schema_)/', $key ) &&
				\WPSEO_Options::get( 'disableadvanced_meta' ) &&
				! \WPSEO_Capability_Utils::current_user_can( 'wpseo_edit_advanced_metadata' ) ) {
				return admin_error( 'You are not allowed to edit advanced Yoast SEO metadata.', 'mcp_admin_forbidden' );
			}
		}
		if ( $is_post ) {
			foreach ( $body as $key => $value ) {
				if ( null === $value ) {
					\WPSEO_Meta::delete( $key, $id );
					continue;
				}
				\WPSEO_Meta::set_value( $key, (string) $value, $id );
			}
		} else {
			$values = array();
			foreach ( $body as $key => $value ) {
				$values[ 'wpseo_' . $key ] = null === $value ? '' : (string) $value;
			}
			\WPSEO_Taxonomy_Meta::set_values( $id, $object->taxonomy, $values );
		}

		rebuild_yoast_indexable( $id, $is_post ? 'post' : 'term' );
		return yoast_object_resource( $resource, $identifier, 'read', array() );
	}
	return $metadata;
}

function rebuild_yoast_indexable( $id, $type ) {
	$repository_class = \Yoast\WP\SEO\Repositories\Indexable_Repository::class;
	$builder_class    = \Yoast\WP\SEO\Builders\Indexable_Builder::class;
	if ( function_exists( 'YoastSEO' ) && class_exists( $repository_class ) && class_exists( $builder_class ) ) {
		$yoast = \YoastSEO();
		$indexable = $yoast->classes->get( $repository_class )->find_by_id_and_type( $id, $type, false );
		$yoast->classes->get( $builder_class )->build_for_id_and_type( $id, $type, $indexable );
	}
}
