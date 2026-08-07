<?php

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

function spacefast_accept( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
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
	10 === has_action( 'admin_post_spacefast_wordpress_change_space', array( 'Spacefast_Plugin', 'change_space' ) ),
	'Space change handler is not registered.'
);
spacefast_accept(
	array( 'teams:read', 'spaces:read', 'spaces:publish', 'offline_access' ) === Spacefast_OAuth::scopes( Spacefast_Settings::MODE_STATIC ),
	'Static mode requested unexpected OAuth scopes.'
);
spacefast_accept(
	array( 'teams:read', 'spaces:read', 'builds:trigger', 'offline_access' ) === Spacefast_OAuth::scopes( Spacefast_Settings::MODE_HEADLESS ),
	'Headless mode requested unexpected OAuth scopes.'
);

Spacefast_Settings::merge(
	array(
		'client_id' => 'acceptance-client',
		'access_token' => 'acceptance-access',
		'refresh_token' => 'acceptance-refresh',
		'expires_at' => time() + 900,
		'team_id' => 'team_acceptance',
		'space_id' => 'spc_acceptance',
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

$plugin = get_plugin_data( WP_PLUGIN_DIR . '/spacefast-wordpress/spacefast-wordpress.php', false, false );
spacefast_accept( '0.3.2' === $plugin['Version'], 'Unexpected plugin version.' );
spacefast_accept( 'https://github.com/spacefast/wordpress' === $plugin['UpdateURI'], 'Update URI is missing.' );

Spacefast_Settings::disconnect();
fwrite( STDOUT, "Spacefast WordPress container acceptance: PASS\n" );
