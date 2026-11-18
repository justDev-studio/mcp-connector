<?php

namespace WordPressMcpConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PROJECT_PROFILE_GUARD = "<?php exit; __halt_compiler();\n";
const PROJECT_PROFILE_FILE = '.wp-mcp/profiles.php';
const PROJECT_LEGACY_PROFILE_FILE = '.wp-mcp/profiles.json';
const PROJECT_IGNORE_RULE = '/.wp-mcp/';

function project_setup_bridge() {
	$plugin = plugin_basename( dirname( __DIR__ ) . '/mcp-connector.php' );
	return wp_normalize_path( WP_PLUGIN_DIR . '/' . dirname( $plugin ) . '/client/bridge.mjs' );
}

function project_setup_root() {
	if ( defined( 'WP_MCP_PROJECT_ROOT' ) ) {
		$root = realpath( WP_MCP_PROJECT_ROOT );
		if ( $root && is_dir( $root ) ) {
			return untrailingslashit( wp_normalize_path( $root ) );
		}
		throw new \RuntimeException( 'WP_MCP_PROJECT_ROOT must point to the shared development project.' );
	}

	$bridge = project_setup_bridge();
	$directory = realpath( ABSPATH );
	while ( $directory && dirname( $directory ) !== $directory ) {
		$root = untrailingslashit( wp_normalize_path( $directory ) );
		if ( 0 === strpos( $bridge, $root . '/' ) && ( file_exists( $root . '/.git' ) || is_file( $root . '/.ddev/config.yaml' ) ) ) {
			return $root;
		}
		$directory = dirname( $directory );
	}
	throw new \RuntimeException( 'The project root could not be detected. Set WP_MCP_PROJECT_ROOT in wp-config.php.' );
}

function project_setup_path( $root, $relative ) {
	$path = $root;
	foreach ( explode( '/', $relative ) as $part ) {
		$path .= '/' . $part;
		if ( is_link( $path ) ) {
			throw new \RuntimeException( 'Setup requires project-local files and directories without symlinks: ' . $relative );
		}
	}
	return $path;
}

function project_setup_read( $root, $relative ) {
	$path = project_setup_path( $root, $relative );
	if ( ! file_exists( $path ) ) {
		return null;
	}
	if ( ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > 2 * 1024 * 1024 ) {
		throw new \RuntimeException( 'Cannot read the project file: ' . $relative );
	}
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		throw new \RuntimeException( 'Cannot read the project file: ' . $relative );
	}
	return $contents;
}

function project_setup_profile( $root, $endpoint ) {
	$legacy = project_setup_path( $root, PROJECT_LEGACY_PROFILE_FILE );
	if ( is_file( $legacy ) ) {
		$document_roots = array( ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? '' );
		foreach ( $document_roots as $document_root ) {
			$public = '' !== $document_root ? realpath( $document_root ) : false;
			if ( $public && 0 === strpos( $legacy, trailingslashit( wp_normalize_path( $public ) ) ) ) {
				throw new \RuntimeException( 'A legacy JSON profile is inside the web root. Revoke its Application Password and move the file outside the web root before running setup.' );
			}
		}
	}
	foreach ( array( PROJECT_PROFILE_FILE, PROJECT_LEGACY_PROFILE_FILE ) as $relative ) {
		$contents = project_setup_read( $root, $relative );
		if ( null === $contents ) {
			continue;
		}
		if ( PROJECT_PROFILE_FILE === $relative ) {
			if ( 0 !== strpos( $contents, PROJECT_PROFILE_GUARD ) ) {
				throw new \RuntimeException( 'The existing development profiles file has an invalid PHP guard.' );
			}
			$contents = substr( $contents, strlen( PROJECT_PROFILE_GUARD ) );
		}
		$profiles = json_decode( $contents, true );
		$profile_name = $profiles['defaultProfile'] ?? null;
		$profile = is_string( $profile_name ) ? ( $profiles['profiles'][ $profile_name ] ?? null ) : null;
		if (
			! is_array( $profile ) || 'development' !== ( $profile['environment'] ?? null ) ||
			! is_string( $profile['endpoint'] ?? null ) || untrailingslashit( $endpoint ) !== untrailingslashit( $profile['endpoint'] ) ||
			! is_string( $profile['username'] ?? null ) || '' === trim( $profile['username'] ) ||
			! is_string( $profile['applicationPassword'] ?? null ) || '' === trim( $profile['applicationPassword'] )
		) {
			throw new \RuntimeException( 'The existing default profile must use this site’s MCP endpoint and the development environment.' );
		}
		if ( 'Windows' !== PHP_OS_FAMILY && ( fileperms( project_setup_path( $root, $relative ) ) & 0077 ) ) {
			throw new \RuntimeException( 'The existing profiles file must use private permissions (chmod 600).' );
		}
		return $relative;
	}
	return null;
}

function project_setup_codex_entry( $contents, $name, array $server ) {
	$contents = $contents ?? '';
	$root = '(?:mcp_servers|"mcp_servers"|\'mcp_servers\')';
	$quoted_name = preg_quote( $name, '~' );
	$key  = '(?:' . $quoted_name . '|"' . $quoted_name . '"|\'' . $quoted_name . '\')';
	$entry = '~^[ \t]*\[?[ \t]*' . $root . '[ \t]*\.[ \t]*' . $key . '[ \t]*(?:[.\]=]|$)~m';
	$parent = '~^[ \t]*\[[ \t]*' . $root . '[ \t]*\][ \t]*(?:#.*)?$~';
	$in_parent = false;
	foreach ( preg_split( '/\r?\n/', $contents ) as $line ) {
		if ( preg_match( $entry, $line ) || ( $in_parent && preg_match( '~^[ \t]*' . $key . '[ \t]*[.=]~', $line ) ) ) {
			return null;
		}
		if ( 0 === strpos( ltrim( $line ), '[' ) ) {
			$in_parent = (bool) preg_match( $parent, $line );
		}
	}
	if ( preg_match( '~^[ \t]*' . $root . '[ \t]*=~m', $contents ) ) {
		throw new \RuntimeException( 'The existing Codex config uses an inline mcp_servers table. Add the project entry there before continuing.' );
	}

	$args = wp_json_encode( $server['args'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$profile = wp_json_encode( $server['env']['WP_MCP_CONFIG'], JSON_UNESCAPED_SLASHES );
	return rtrim( $contents ) . ( '' === $contents ? '' : "\n\n" ) .
		"[mcp_servers.$name]\ncommand = \"node\"\nargs = $args\ntool_timeout_sec = 125\n\n" .
		"[mcp_servers.$name.env]\nWP_MCP_CONFIG = $profile\nNODE_USE_SYSTEM_CA = \"1\"\n";
}

function project_setup_claude_entry( $contents, $name, array $server ) {
	$config = null === $contents ? new \stdClass() : json_decode( $contents );
	if ( ! is_object( $config ) || ( property_exists( $config, 'mcpServers' ) && ! is_object( $config->mcpServers ) ) ) {
		throw new \RuntimeException( 'The existing .mcp.json must contain a valid mcpServers object.' );
	}
	$config->mcpServers = $config->mcpServers ?? new \stdClass();
	if ( property_exists( $config->mcpServers, $name ) ) {
		if ( ! is_object( $config->mcpServers->$name ) ) {
			throw new \RuntimeException( 'The existing Claude Code server entry must be an object.' );
		}
		return null;
	}
	$config->mcpServers->$name = array( 'type' => 'stdio' ) + $server;
	return wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
}

function project_setup_plan( $root ) {
	$endpoint = rest_url( REST_NAMESPACE . '/mcp' );
	if ( 'https' !== wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) {
		throw new \RuntimeException( 'The development MCP endpoint requires HTTPS.' );
	}
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$label = sanitize_title( explode( '.', $host )[0] );
	$name = 'wordpress-' . ( preg_match( '/^[a-z0-9_-]+$/D', $label ) ? $label : 'development' );
	$bridge = project_setup_bridge();
	if ( 0 !== strpos( $bridge, $root . '/' ) || ! is_file( $bridge ) ) {
		throw new \RuntimeException( 'Install MCP Connector inside the shared development project first.' );
	}

	$profile = project_setup_profile( $root, $endpoint );
	$server = array(
		'command' => 'node',
		'args'    => array( substr( $bridge, strlen( $root ) + 1 ) ),
		'env'     => array(
			'WP_MCP_CONFIG'      => $profile ?? PROJECT_PROFILE_FILE,
			'NODE_USE_SYSTEM_CA' => '1',
		),
	);
	$ignore = project_setup_read( $root, '.gitignore' ) ?? '';
	$changes = array(
		'.gitignore'         => in_array( PROJECT_IGNORE_RULE, preg_split( '/\r?\n/', $ignore ), true ) ?
			null : rtrim( $ignore ) . ( '' === $ignore ? '' : "\n" ) . PROJECT_IGNORE_RULE . "\n",
		'.codex/config.toml' => project_setup_codex_entry( project_setup_read( $root, '.codex/config.toml' ), $name, $server ),
		'.mcp.json'          => project_setup_claude_entry( project_setup_read( $root, '.mcp.json' ), $name, $server ),
	);
	if ( ! $profile && ! wp_is_application_passwords_available_for_user( wp_get_current_user() ) ) {
		throw new \RuntimeException( 'Application Passwords must be available for your WordPress user.' );
	}
	return array( 'name' => $name, 'endpoint' => $endpoint, 'profile' => $profile, 'changes' => array_filter( $changes ) );
}

function project_setup_write( $root, $relative, $contents, $mode ) {
	$file = project_setup_path( $root, $relative );
	if ( ! wp_mkdir_p( dirname( $file ) ) ) {
		throw new \RuntimeException( 'Cannot create the directory for ' . $relative );
	}
	// Keep credential temporary files under a PHP extension, too.
	$suffix = PROJECT_PROFILE_FILE === $relative ? '.php' : '.tmp';
	$temp = dirname( $file ) . '/.mcp-setup-' . wp_generate_uuid4() . $suffix;
	$handle = @fopen( $temp, 'xb' );
	if ( false === $handle ) {
		throw new \RuntimeException( 'Cannot prepare the project file: ' . $relative );
	}
	try {
		$saved = @chmod( $temp, $mode ) && strlen( $contents ) === fwrite( $handle, $contents );
	} finally {
		fclose( $handle );
	}
	if ( ! $saved || ! @rename( $temp, $file ) ) {
		@unlink( $temp );
		throw new \RuntimeException( 'Cannot save the project file: ' . $relative );
	}
}

function project_setup_create_profile( $root, $endpoint ) {
	$user = wp_get_current_user();
	$result = create_mcp_application_password( $user, 'MCP Connector (development)' );
	if ( is_wp_error( $result ) ) {
		throw new \RuntimeException( $result->get_error_message() );
	}
	list( $password, $credential ) = $result;
	$profiles = array(
		'defaultProfile' => 'development',
		'profiles'       => array(
			'development' => array(
				'endpoint'            => $endpoint,
				'environment'         => 'development',
				'username'            => $user->user_login,
				'applicationPassword' => $password,
			),
		),
	);
	try {
		project_setup_write(
			$root,
			PROJECT_PROFILE_FILE,
			PROJECT_PROFILE_GUARD . wp_json_encode( $profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n",
			0600
		);
	} catch ( \Throwable $error ) {
		\WP_Application_Passwords::delete_application_password( $user->ID, $credential['uuid'] );
		throw $error;
	}
}

function project_setup_apply() {
	if ( 'development' !== wp_get_environment_type() || ! mcp_is_administrator() || ! current_user_can( 'manage_options' ) ) {
		throw new \RuntimeException( 'Project setup is available to administrators in development.' );
	}
	$root = project_setup_root();
	// Validate the existing documents before creating any setup files.
	$plan = project_setup_plan( $root );
	if ( $plan['profile'] && ! $plan['changes'] ) {
		return;
	}
	$lock_file = project_setup_path( $root, '.wp-mcp/setup.lock' );
	if ( ! wp_mkdir_p( dirname( $lock_file ) ) ) {
		throw new \RuntimeException( 'Cannot create the private project profile directory.' );
	}
	$lock = @fopen( $lock_file, 'c' );
	if ( false === $lock ) {
		throw new \RuntimeException( 'Cannot lock the project setup directory.' );
	}
	try {
		if ( ! flock( $lock, LOCK_EX ) ) {
			throw new \RuntimeException( 'Cannot lock the project setup directory.' );
		}
		$plan = project_setup_plan( $root );
		if ( isset( $plan['changes']['.gitignore'] ) ) {
			$path = project_setup_path( $root, '.gitignore' );
			project_setup_write( $root, '.gitignore', $plan['changes']['.gitignore'], is_file( $path ) ? fileperms( $path ) & 0777 : 0644 );
		}
		if ( ! $plan['profile'] ) {
			project_setup_create_profile( $root, $plan['endpoint'] );
		}
		foreach ( array( '.codex/config.toml', '.mcp.json' ) as $relative ) {
			if ( isset( $plan['changes'][ $relative ] ) ) {
				$path = project_setup_path( $root, $relative );
				project_setup_write( $root, $relative, $plan['changes'][ $relative ], is_file( $path ) ? fileperms( $path ) & 0777 : 0644 );
			}
		}
	} finally {
		fclose( $lock );
	}
}

function handle_project_setup() {
	if ( 'development' !== wp_get_environment_type() || ! mcp_is_administrator() || ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Project setup is available to administrators in development.', 'mcp-connector' ), '', array( 'response' => 403 ) );
	}
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( esc_html__( 'Use the project setup form.', 'mcp-connector' ), '', array( 'response' => 405 ) );
	}
	check_admin_referer( 'mcp_connector_project_setup' );

	try {
		project_setup_apply();
	} catch ( \RuntimeException $error ) {
		wp_die( esc_html( $error->getMessage() ), esc_html__( 'MCP Connector setup', 'mcp-connector' ), array( 'response' => 500, 'back_link' => true ) );
	}
	wp_safe_redirect( admin_url( 'tools.php?page=mcp-connector' ) );
	exit;
}

function render_project_setup() {
	if ( 'development' !== wp_get_environment_type() ) {
		return;
	}
	?>
	<h2><?php esc_html_e( 'Project connection check and setup', 'mcp-connector' ); ?></h2>
	<?php
	try {
		$plan = project_setup_plan( project_setup_root() );
	} catch ( \RuntimeException $error ) {
		?>
		<p><?php echo esc_html( $error->getMessage() ); ?></p>
		<?php
		return;
	}
	if ( $plan['profile'] && ! $plan['changes'] ) {
		?>
		<p><strong><?php esc_html_e( 'Project configuration is ready.', 'mcp-connector' ); ?></strong> <code><?php echo esc_html( $plan['name'] ); ?></code></p>
		<?php
		return;
	}
	?>
	<p><?php esc_html_e( 'Set up Codex and Claude Code for this development project.', 'mcp-connector' ); ?></p>
	<ul class="ul-disc">
		<?php foreach ( array_merge( array_keys( $plan['changes'] ), $plan['profile'] ? array() : array( PROJECT_PROFILE_FILE ) ) as $path ) : ?>
			<li><code><?php echo esc_html( $path ); ?></code></li>
		<?php endforeach; ?>
	</ul>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="mcp_connector_project_setup">
		<?php wp_nonce_field( 'mcp_connector_project_setup' ); ?>
		<?php submit_button( __( 'Set up project', 'mcp-connector' ) ); ?>
	</form>
	<?php
}
