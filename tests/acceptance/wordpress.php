<?php

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

function spacefast_accept( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( 'cli' === ( $args[0] ?? '' ) ) {
	spacefast_accept_cli();
	return;
}

global $wp_version;
spacefast_accept( str_starts_with( $wp_version, '6.5.' ), 'Acceptance is not running WordPress 6.5.' );
spacefast_accept( is_plugin_active( 'spacefast-wordpress/spacefast-wordpress.php' ), 'Plugin is not active.' );
spacefast_accept(
	10 === has_action( 'admin_post_spacefast_wordpress_oauth_start', array( 'Spacefast_Plugin', 'oauth_start' ) ),
	'OAuth start handler is not registered.'
);
spacefast_accept(
	10 === has_action( 'admin_post_spacefast_wordpress_select_space', array( 'Spacefast_Plugin', 'select_space' ) ),
	'Space selection handler is not registered.'
);
spacefast_accept(
	10 === has_action( 'admin_post_spacefast_wordpress_create_space', array( 'Spacefast_Plugin', 'create_space' ) ),
	'Space creation handler is not registered.'
);
spacefast_accept(
	10 === has_action( 'admin_post_spacefast_wordpress_prepare_static', array( 'Spacefast_Plugin', 'prepare_static' ) ),
	'Simply Static setup handler is not registered.'
);
spacefast_accept(
	10 === has_action( 'admin_post_spacefast_wordpress_save_settings', array( 'Spacefast_Plugin', 'save_settings' ) ),
	'WordPress settings sync handler is not registered.'
);
spacefast_accept(
	10 === has_action( 'admin_post_spacefast_wordpress_change_space', array( 'Spacefast_Plugin', 'change_space' ) ),
	'Space change handler is not registered.'
);
spacefast_accept(
	array( 'teams:read', 'spaces:read', 'spaces:write', 'spaces:publish', 'offline_access' ) === Spacefast_OAuth::scopes( Spacefast_Settings::MODE_STATIC ),
	'Static mode requested unexpected OAuth scopes.'
);
spacefast_accept(
	array( 'teams:read', 'spaces:read', 'spaces:write', 'builds:trigger', 'offline_access' ) === Spacefast_OAuth::scopes( Spacefast_Settings::MODE_HEADLESS ),
	'Headless mode requested unexpected OAuth scopes.'
);

wp_set_current_user( 1 );
ob_start();
Spacefast_Plugin::render_admin();
$first_use_markup = (string) ob_get_clean();
spacefast_accept( str_contains( $first_use_markup, 'Publish with Spacefast' ), 'First use does not lead with publishing.' );
spacefast_accept( str_contains( $first_use_markup, 'Install Simply Static and continue' ), 'First use does not offer exporter setup.' );
spacefast_accept( str_contains( $first_use_markup, '<details' ), 'Repository CMS setup is not progressively disclosed.' );
spacefast_accept( ! str_contains( $first_use_markup, 'Choose how WordPress publishes' ), 'The old mode-first wizard is still rendered.' );

Spacefast_Settings::merge(
	array(
		'client_id' => 'acceptance-client',
		'access_token' => 'acceptance-access',
		'refresh_token' => 'acceptance-refresh',
		'expires_at' => time() + 900,
		'team_id' => 'team_acceptance',
		'team_name' => 'Acceptance Team',
		'space_id' => 'spc_acceptance',
		'space_name' => 'Acceptance Space',
		'live_url' => 'https://acceptance.example.test',
		'scope' => 'teams:read spaces:read spaces:write builds:trigger offline_access',
		'verified_at' => time(),
	)
);
global $wpdb;
$autoload = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
		Spacefast_Settings::OPTION
	)
);
spacefast_accept( ! in_array( $autoload, array( 'yes', 'on', 'auto-on' ), true ), 'OAuth tokens are autoloaded.' );

$changed_at = time();
$post_id = wp_insert_post(
	array(
		'post_title' => 'Debounce acceptance',
		'post_status' => 'publish',
		'post_type' => 'post',
	)
);
spacefast_accept( ! is_wp_error( $post_id ), 'Could not publish acceptance content.' );
$scheduled_at = wp_next_scheduled( Spacefast_Sync_State::HOOK );
spacefast_accept( false !== $scheduled_at, 'Publishing public content did not schedule a build.' );
spacefast_accept(
	$scheduled_at >= $changed_at + 59 && $scheduled_at <= time() + 61,
	'Public content did not receive the 60-second quiet window.'
);

ob_start();
Spacefast_Plugin::render_admin();
$connected_markup = (string) ob_get_clean();
spacefast_accept( str_contains( $connected_markup, 'WordPress sync' ), 'Connected UI does not expose WordPress-owned sync settings.' );
spacefast_accept( str_contains( $connected_markup, 'Open live site' ), 'Connected UI does not expose the live result.' );
spacefast_accept( str_contains( $connected_markup, 'Technical details' ), 'Connected UI cannot disclose its receipt on demand.' );

Spacefast_Settings::merge( array( 'mode' => Spacefast_Settings::MODE_STATIC ) );
$change_recorded = new ReflectionProperty( Spacefast_Plugin::class, 'change_recorded' );
$change_recorded->setValue( null, false );
wp_clear_scheduled_hook( Spacefast_Sync_State::HOOK );
wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Static debounce acceptance' ) );
spacefast_accept(
	false !== wp_next_scheduled( Spacefast_Sync_State::HOOK ),
	'Updating public content did not schedule a static publish.'
);

$plugin = get_plugin_data( WP_PLUGIN_DIR . '/spacefast-wordpress/spacefast-wordpress.php', false, false );
spacefast_accept( '0.6.0' === $plugin['Version'], 'Unexpected plugin version.' );
spacefast_accept( 'https://github.com/spacefast/wordpress' === $plugin['UpdateURI'], 'Update URI is missing.' );

Spacefast_Settings::disconnect();
fwrite( STDOUT, "Spacefast WordPress container acceptance: PASS\n" );

/** Run real WP-CLI dispatch and Simply Static with an injected Spacefast transport. */
function spacefast_accept_cli(): void {
	spacefast_accept( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'CLI acceptance must run without WP-Cron.' );
	spacefast_accept( Spacefast_Plugin::simply_static_available(), 'Simply Static is not installed.' );
	$run = static fn( string $command ) => WP_CLI::runcommand(
		$command,
		array( 'launch' => false, 'return' => 'all', 'exit_error' => false )
	);
	$status = $run( 'spacefast status --format=json' );
	spacefast_accept( 0 === $status->return_code, 'Disconnected status failed.' );
	spacefast_accept( 'disconnected' === json_decode( $status->stdout, true )['connection'], 'Disconnected status is not JSON.' );
	$invalid = $run( 'spacefast publish --wait --timeout=0' );
	spacefast_accept( 0 !== $invalid->return_code, 'Invalid timeout must exit non-zero.' );

	Spacefast_Settings::merge(
		array(
			'mode' => Spacefast_Settings::MODE_STATIC,
			'client_id' => 'acceptance-client',
			'access_token' => 'acceptance-access',
			'refresh_token' => 'acceptance-refresh',
			'expires_at' => time() + 900,
			'team_id' => 'team_acceptance',
			'team_name' => 'Acceptance Team',
			'space_id' => 'spc_acceptance',
			'space_name' => 'Acceptance Space',
			'live_url' => 'https://acceptance.example.test',
			'scope' => 'spaces:read spaces:write spaces:publish builds:trigger offline_access',
		)
	);
	Spacefast_Sync_State::save( Spacefast_Sync_State::defaults() );
	$requests = array();
	$failed = false;
	$transport = static function ( $preempt, array $args, string $url ) use ( &$requests, &$failed ) {
		if ( ! str_starts_with( $url, Spacefast_Settings::api_url() . '/' ) ) return $preempt;
		$requests[] = array( 'url' => $url, 'method' => $args['method'], 'body' => $args['body'] ?? '' );
		$data = array();
		$code = 200;
		if ( str_ends_with( $url, '/versions' ) && 'POST' === $args['method'] ) {
			$body = json_decode( $args['body'], true );
			spacefast_accept( 'snapshot' === $body['publishMode'], 'CLI did not request a full snapshot.' );
			spacefast_accept( count( $body['files'] ) > 0, 'Simply Static exported no files.' );
			// Model a server which already has the declared bytes. This exercises
			// manifest creation and activation without an external upload service.
			$data = array( 'versionId' => 'ver_acceptance', 'upload' => array( 'targets' => array() ) );
			$code = 201;
		} elseif ( str_ends_with( $url, '/uploads/resume' ) ) {
			$data = array( 'upload' => array( 'targets' => array() ) );
		} elseif ( str_ends_with( $url, '/versions/ver_acceptance' ) ) {
			$data = array( 'status' => $failed ? 'failed' : 'ready', 'isCurrentProduction' => ! $failed );
		} elseif ( str_ends_with( $url, '/builds/wordpress' ) && 'POST' === $args['method'] ) {
			$data = array( 'build' => array( 'id' => 'bld_acceptance' ) );
		} elseif ( str_ends_with( $url, '/v1/builds/bld_acceptance' ) ) {
			$data = array( 'status' => $failed ? 'failed' : 'succeeded' );
		} elseif ( str_ends_with( $url, '/spc_acceptance' ) ) {
			$data = array( 'config' => array(), 'settingsDigest' => 'acceptance-digest' );
		}
		return array( 'headers' => array(), 'response' => array( 'code' => $code ), 'body' => wp_json_encode( array( 'data' => $data ) ) );
	};
	add_filter( 'pre_http_request', $transport, 10, 3 );
	try {
		$publish = $run( 'spacefast publish --wait --timeout=120' );
		spacefast_accept( 0 === $publish->return_code, 'Inline publish failed: ' . $publish->stderr );
		$status = $run( 'spacefast status --format=json' );
		$receipt = json_decode( $status->stdout, true, 512, JSON_THROW_ON_ERROR );
		spacefast_accept( 'live' === $receipt['state'] && 'ver_acceptance' === $receipt['last_version_id'], 'CLI status did not record the live version.' );
		spacefast_accept( ! str_contains( $status->stdout, 'acceptance-access' ) && ! str_contains( $status->stdout, 'acceptance-refresh' ), 'Status leaked credentials.' );
		spacefast_accept( ! \Simply_Static\Plugin::instance()->get_archive_creation_job()->is_queued(), 'Inline export left cleanup queued.' );
		$table = $run( 'spacefast status' );
		spacefast_accept( 0 === $table->return_code && str_contains( $table->stdout, 'ver_acceptance' ), 'Table status is missing the version.' );
		$sync = $run( 'spacefast sync-settings' );
		spacefast_accept( 0 === $sync->return_code && ! Spacefast_Sync_State::get()['settings_pending'], 'CLI settings sync failed.' );

		Spacefast_Settings::merge( array( 'mode' => Spacefast_Settings::MODE_HEADLESS ) );
		$build = $run( 'spacefast build --wait' );
		spacefast_accept( 0 === $build->return_code && 'live' === Spacefast_Sync_State::get()['last_status'], 'CLI build wait failed.' );
		$failed = true;
		$build = $run( 'spacefast build --wait' );
		spacefast_accept( 0 !== $build->return_code, 'Failed build must exit non-zero.' );
		$disconnect = $run( 'spacefast disconnect --yes' );
		spacefast_accept( 0 === $disconnect->return_code && ! Spacefast_Settings::authorized(), 'CLI disconnect failed.' );
		spacefast_accept( count( $requests ) > 0, 'CLI commands did not use the injected transport.' );
	} finally {
		remove_filter( 'pre_http_request', $transport, 10 );
		Spacefast_Settings::disconnect();
	}
	fwrite( STDOUT, "Spacefast WP-CLI container acceptance: PASS\n" );
}
