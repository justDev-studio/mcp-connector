<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function mcp_is_administrator() {
	$user = wp_get_current_user();
	return in_array( 'administrator', (array) $user->roles, true ) ||
		( is_multisite() && is_super_admin( $user->ID ) );
}

function content_rest_collections() {
	return array(
		'/wp/v2/posts',
		'/wp/v2/pages',
		'/wp/v2/categories',
		'/wp/v2/tags',
		'/wp/v2/menus',
		'/wp/v2/menu-items',
		'/wp/v2/menu-locations',
		'/wp/v2/navigation',
	);
}

function is_content_type( $object ) {
	if ( $object instanceof \WP_Post_Type ) {
		if ( $object->_builtin ) {
			return in_array( $object->name, array( 'post', 'page', 'nav_menu_item', 'wp_navigation' ), true );
		}
		return $object->show_in_rest && $object->show_ui &&
			! in_array( $object->name, array_merge( array_values( acf_definition_types() ), array( 'acf-field' ) ), true );
	}
	if ( $object instanceof \WP_Taxonomy ) {
		return in_array( $object->name, array( 'category', 'post_tag', 'nav_menu' ), true ) ||
			( ! $object->_builtin && $object->show_in_rest && $object->show_ui );
	}
	return false;
}

function content_admin_resources() {
	return array( 'acf_options_page_values', 'duplicate_post', 'wpml_content', 'yoast_post', 'yoast_term' );
}

function content_mcp_tools() {
	return array( 'wordpress_site_info', 'wordpress_read', 'wordpress_write', 'wordpress_admin_read', 'wordpress_admin_write' );
}
