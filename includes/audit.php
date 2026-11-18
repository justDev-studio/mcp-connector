<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function audit_table() {
	global $wpdb;
	return $wpdb->prefix . 'mcp_connector_audit';
}

function install_audit_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = audit_table();
	$collate = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE $table (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			environment varchar(20) NOT NULL,
			user_id bigint unsigned NOT NULL,
			user_login varchar(60) NOT NULL,
			credential varchar(36) NOT NULL,
			tool varchar(64) NOT NULL,
			target varchar(255) NOT NULL,
			outcome varchar(16) NOT NULL,
			details longtext NOT NULL,
			PRIMARY KEY  (id)
		) $collate;"
	);
}

function audit_snapshot( $source ) {
	if ( ! $source ) {
		return null;
	}
	try {
		if ( $source instanceof \Closure ) {
			$data = $source();
			return is_array( $data ) ? $data : null;
		}
		$response = dispatch_rest( 'GET', $source, array( 'context' => 'edit' ) );
		if ( $response->is_error() ) {
			$response = dispatch_rest( 'GET', $source );
		}
	} catch ( \Throwable $error ) {
		return null;
	}
	if ( $response->is_error() ) {
		return null;
	}
	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return null;
	}

	unset( $data['_links'], $data['_embedded'] );
	return $data;
}

function credential_key( $key ) {
	return preg_match( '/password|secret|token|nonce|credential|(?:api|access|private|signing|client|license).?key|authorization|oauth|session.?cookie/i', $key );
}

function audit_sensitive_key( $key ) {
	return credential_key( $key ) || preg_match( '/cookie|hash|email|^meta$|^base64$/i', $key );
}

function audit_safe_value( $value, $key = '', $depth = 0 ) {
	if ( audit_sensitive_key( $key ) ) {
		return '[redacted]';
	}
	if ( 0 === strpos( $key, 'field_' ) && function_exists( 'acf_get_field' ) ) {
		$field = acf_get_field( $key );
		if ( $field && ( 'password' === ( $field['type'] ?? '' ) || audit_sensitive_key( $field['name'] ?? '' ) ) ) {
			return '[redacted]';
		}
	}
	if ( is_string( $value ) && strlen( $value ) > 1200 ) {
		return substr( $value, 0, 1200 ) . ' [truncated]';
	}
	if ( ! is_array( $value ) && ! is_object( $value ) ) {
		return $value;
	}
	if ( $depth >= 5 ) {
		return '[nested data omitted]';
	}
	$data = array();
	$properties = (array) $value;
	$sensitive_field = 'password' === ( $properties['type'] ?? '' ) ||
		( is_string( $properties['name'] ?? null ) && audit_sensitive_key( $properties['name'] ) );
	foreach ( array_slice( (array) $value, 0, 20, true ) as $child_key => $child ) {
		$data[ $child_key ] = $sensitive_field && in_array( $child_key, array( 'value', 'default_value' ), true ) ?
			'[redacted]' : audit_safe_value( $child, (string) $child_key, $depth + 1 );
	}
	if ( count( (array) $value ) > count( $data ) ) {
		$data['…'] = '[additional fields omitted]';
	}
	return $data;
}

function audit_encode( array $data ) {
	$json = wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( ! $json || strlen( $json ) > 32768 ) {
		return wp_json_encode(
			array(
				'request_fields' => array_keys( $data['request'] ?? array() ),
				'changed_fields' => array_slice( array_keys( $data['changes'] ?? array() ), 0, 50 ),
				'error_code'     => $data['error_code'] ?? null,
				'details'        => 'Values omitted due to audit size limit.',
			)
		);
	}
	return $json;
}

function audit_changes( array $before, array $after ) {
	$changes = array();
	foreach ( array_keys( $before + $after ) as $key ) {
		$old_exists = array_key_exists( $key, $before );
		$new_exists = array_key_exists( $key, $after );
		if ( $old_exists === $new_exists && $before[ $key ] === $after[ $key ] ) {
			continue;
		}
		$changes[ $key ] = array();
		if ( $old_exists ) {
			$changes[ $key ]['before'] = audit_safe_value( $before[ $key ], (string) $key );
		}
		if ( $new_exists ) {
			$changes[ $key ]['after'] = audit_safe_value( $after[ $key ], (string) $key );
		}
	}
	return $changes;
}

function audit_mutation( $tool, $target, array $input, $before_route, callable $perform, callable $result_route ) {
	global $wpdb;

	$table   = audit_table();
	$user    = wp_get_current_user();
	$before  = audit_snapshot( $before_route );
	$details = array( 'request' => audit_safe_value( $input ) );
	$record  = array(
		'created_at'  => current_time( 'mysql', true ),
		'environment' => wp_get_environment_type(),
		'user_id'     => $user->ID,
		'user_login'  => $user->user_login,
		'credential'  => rest_get_authenticated_app_password(),
		'tool'        => $tool,
		'target'      => substr( $target, 0, 255 ),
		'outcome'     => 'pending',
		'details'     => audit_encode( $details + array( 'before' => audit_safe_value( $before ) ) ),
	);
	$formats = array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		install_audit_table();
	}
	if ( ! $wpdb->insert( $table, $record, $formats ) ) {
		return new \WP_Error( 'mcp_audit_unavailable', 'The change was not started because the audit entry could not be created.' );
	}

	$id = (int) $wpdb->insert_id;
	try {
		$result = $perform();
	} catch ( \Throwable $error ) {
		$result = new \WP_Error( 'mcp_operation_exception', 'The operation was interrupted. Check the current state before retrying.' );
	}

	$after_route = is_wp_error( $result ) ? $before_route : $result_route( $result );
	$after       = audit_snapshot( $after_route );
	$details['result_route']    = is_string( $after_route ) ? $after_route : null;
	$details['before_captured'] = is_array( $before );
	$details['after_captured']  = is_array( $after );
	$details['changes']         = audit_changes( $before ?? array(), $after ?? array() );
	if ( is_wp_error( $result ) ) {
		$details['error_code'] = $result->get_error_code();
	}

	$saved = $wpdb->update(
		$table,
		array(
			'outcome' => is_wp_error( $result ) ? 'error' : 'success',
			'details' => audit_encode( $details ),
		),
		array( 'id' => $id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	if ( 1 !== $saved ) {
		return new \WP_Error( 'mcp_audit_incomplete', 'The operation ran, but its audit entry could not be finalized. Check the current state before retrying.' );
	}
	return $result;
}

function register_audit_page() {
	if ( ! mcp_is_administrator() ) {
		return;
	}
	add_management_page(
		'MCP Connector',
		'MCP Connector',
		'manage_options',
		'mcp-connector',
		__NAMESPACE__ . '\\render_audit_page'
	);
}

function plugin_action_links( array $links ) {
	if ( mcp_is_administrator() && current_user_can( 'manage_options' ) ) {
		$label = 'development' === wp_get_environment_type() ? __( 'Settings', 'mcp-connector' ) : __( 'Change history', 'mcp-connector' );
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'tools.php?page=mcp-connector' ) ) . '">' . esc_html( $label ) . '</a>'
		);
	}
	return $links;
}

function render_audit_page() {
	global $wpdb;

	if ( ! mcp_is_administrator() || ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to access this page.', 'mcp-connector' ), '', array( 'response' => 403 ) );
	}

	$table = audit_table();
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		install_audit_table();
	}

	$size  = 25;
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	$pages = max( 1, (int) ceil( $total / $size ) );
	$page  = min( $pages, max( 1, absint( $_GET['mcp_page'] ?? 1 ) ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $size, ( $page - 1 ) * $size )
	);
	?>
	<div class="wrap">
		<h1>MCP Connector</h1>
		<p><strong><?php esc_html_e( 'Environment', 'mcp-connector' ); ?></strong> <?php echo esc_html( wp_get_environment_type() ); ?></p>
		<h2><?php esc_html_e( 'MCP endpoint', 'mcp-connector' ); ?></h2>
		<pre><?php echo esc_html( rest_url( REST_NAMESPACE . '/mcp' ) ); ?></pre>
		<?php render_mcp_credential_form(); ?>
		<?php render_project_setup(); ?>
		<h2><?php esc_html_e( 'Change history', 'mcp-connector' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'ID / time (UTC)', 'mcp-connector' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Environment', 'mcp-connector' ); ?></th>
				<th scope="col"><?php esc_html_e( 'User / Application Password ID', 'mcp-connector' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Operation', 'mcp-connector' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Outcome', 'mcp-connector' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'mcp-connector' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No recorded changes.', 'mcp-connector' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( '#' . $row->id . ' · ' . $row->created_at ); ?></td>
					<td><?php echo esc_html( $row->environment ); ?></td>
					<td><?php echo esc_html( $row->user_login . ' (#' . $row->user_id . ')' ); ?><br><code><?php echo esc_html( $row->credential ); ?></code></td>
					<td><code><?php echo esc_html( $row->tool ); ?></code><br><?php echo esc_html( $row->target ); ?></td>
					<td><?php echo esc_html( $row->outcome ); ?></td>
					<td>
						<details>
							<summary><?php esc_html_e( 'View changes', 'mcp-connector' ); ?></summary>
							<pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html( wp_json_encode( json_decode( $row->details ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
						</details>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => admin_url( 'tools.php?page=mcp-connector&mcp_page=%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => $pages,
				)
			)
		);
		?>
	</div>
	<?php
}
