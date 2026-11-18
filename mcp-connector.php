<?php
/**
 * Plugin Name:       WordPress MCP Connector
 * Plugin URI:        https://justdev.org
 * Description:       Manage WordPress through MCP with authenticated environments and an admin audit log.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            justDev
 * Author URI:        https://justdev.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcp-connector
 */

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';
const REST_NAMESPACE = 'mcp-connector/v1';
const MAX_MEDIA_BYTES = 8388608;

require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/admin-tools.php';
require_once __DIR__ . '/includes/acf.php';
require_once __DIR__ . '/includes/yoast.php';
require_once __DIR__ . '/includes/tools.php';
require_once __DIR__ . '/includes/server.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\install_audit_table' );
add_action( 'admin_menu', __NAMESPACE__ . '\\register_audit_page' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\plugin_action_links' );
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_mcp_route' );
add_filter( 'rest_pre_serve_request', __NAMESPACE__ . '\\serve_empty_mcp_response', 10, 3 );
