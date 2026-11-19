<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MCP_APPLICATION_ID = '8803631b-80fb-4787-bc59-102732ccde20';
// Recognize credentials issued by earlier versions in each environment.
const MCP_APPLICATION_IDS = array(
	MCP_APPLICATION_ID,
	'd4a360d2-ad74-4a50-9549-a5d64259c810',
	'172cf179-c191-439e-87b6-7d973210f251',
	'95ec7c86-674b-4726-b786-4a763f5fb717',
);

function restrict_mcp_application_password( \WP_Error $error, \WP_User $user, array $credential ) {
	if ( ! in_array( $credential['app_id'] ?? '', MCP_APPLICATION_IDS, true ) ) {
		return;
	}

	$route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || ! is_string( $route ) ||
		'/' . REST_NAMESPACE . '/mcp' !== untrailingslashit( $route ) ) {
		$error->add( 'mcp_application_password_scope', 'This Application Password is scoped to the MCP endpoint.', array( 'status' => 403 ) );
	}
}

function create_mcp_application_password( \WP_User $user, $name ) {
	if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
		return new \WP_Error( 'mcp_application_password_unavailable', __( 'Application Passwords are unavailable for this user.', 'mcp-connector' ) );
	}
	return \WP_Application_Passwords::create_new_application_password(
		$user->ID,
		array( 'name' => $name, 'app_id' => MCP_APPLICATION_ID )
	);
}

function mcp_can_issue_password_for_user( \WP_User $user ) {
	return current_user_can( 'edit_user', $user->ID ) &&
		( ! is_multisite() || is_user_member_of_blog( $user->ID ) || is_super_admin( $user->ID ) );
}

function render_mcp_credential_form() {
	$current = wp_get_current_user();
	$users   = array( $current->ID => $current );
	foreach ( get_users( array( 'orderby' => 'login' ) ) as $user ) {
		$users[ $user->ID ] = $user;
	}
	$users = array_filter( $users, static function ( \WP_User $user ) {
		return mcp_can_issue_password_for_user( $user ) && wp_is_application_passwords_available_for_user( $user );
	} );
	?>
	<h2><?php esc_html_e( 'MCP Application Password', 'mcp-connector' ); ?></h2>
	<?php if ( ! $users ) : ?>
		<p><?php esc_html_e( 'No editable users can create an Application Password on this site.', 'mcp-connector' ); ?></p>
		<?php return; ?>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="mcp_connector_create_credential">
		<?php wp_nonce_field( 'mcp_connector_create_credential' ); ?>
		<p>
			<label for="mcp-credential-user"><?php esc_html_e( 'WordPress user', 'mcp-connector' ); ?></label>
			<select id="mcp-credential-user" name="mcp_credential_user" required>
				<option value="" disabled <?php selected( ! isset( $users[ $current->ID ] ) ); ?>><?php esc_html_e( 'Select a user', 'mcp-connector' ); ?></option>
				<?php foreach ( $users as $user ) : ?>
					<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $user->ID, $current->ID ); ?>><?php echo esc_html( $user->user_login ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php submit_button( __( 'Create MCP Application Password', 'mcp-connector' ), 'secondary' ); ?>
	</form>
	<?php
}

function handle_mcp_create_credential() {
	if ( ! mcp_is_administrator() || ! current_user_can( 'manage_options' ) || ! mcp_transport_allowed() ) {
		wp_die( esc_html__( 'Creating an MCP Application Password requires administrator access and a secure connection.', 'mcp-connector' ), '', array( 'response' => 403 ) );
	}
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( esc_html__( 'Use the MCP Application Password form.', 'mcp-connector' ), '', array( 'response' => 405 ) );
	}
	check_admin_referer( 'mcp_connector_create_credential' );

	$selection = $_POST['mcp_credential_user'] ?? '';
	$user_id   = is_string( $selection ) ? filter_var( $selection, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
	$user      = $user_id ? get_user_by( 'id', $user_id ) : false;
	if ( ! $user || ! mcp_can_issue_password_for_user( $user ) ) {
		wp_die( esc_html__( 'Select a WordPress user you can edit on this site.', 'mcp-connector' ), '', array( 'response' => 403, 'back_link' => true ) );
	}

	$environment = wp_get_environment_type();
	$result      = create_mcp_application_password( $user, 'MCP Connector ' . wp_generate_password( 8, false ) );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) );
	}
	$profile = array(
		'endpoint'            => rest_url( REST_NAMESPACE . '/mcp' ),
		'environment'         => $environment,
		'username'            => $user->user_login,
		'applicationPassword' => $result[0],
	);

	add_filter( 'nocache_headers', static function ( array $headers ) {
		$headers['Cache-Control'] = 'no-store, private';
		return $headers;
	} );
	nocache_headers();
	header( 'Referrer-Policy: no-referrer' );
	$message  = '<h1>' . esc_html__( 'MCP Application Password created', 'mcp-connector' ) . '</h1>';
	$message .= '<p>' . esc_html__( 'Copy this connection into your private local profile. The password is shown once. You can revoke it from the user’s WordPress profile.', 'mcp-connector' ) . '</p>';
	$message .= '<p>' . esc_html__( 'For a database copy, use the same credentials and its own endpoint and environment.', 'mcp-connector' ) . '</p>';
	$message .= '<pre>' . esc_html( wp_json_encode( $profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
	$message .= '<p><a href="' . esc_url( admin_url( 'tools.php?page=mcp-connector' ) ) . '">' . esc_html__( 'Return to MCP Connector', 'mcp-connector' ) . '</a></p>';
	wp_die( $message, esc_html__( 'MCP Application Password', 'mcp-connector' ), array( 'response' => 200 ) );
}
