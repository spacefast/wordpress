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
	array( 'teams:read', 'spaces:read', 'spaces:write', 'publish:write', 'offline_access' ) === Spacefast_OAuth::scopes( Spacefast_Settings::MODE_STATIC ),
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

foreach ( array( 'go_live', 'rollback', 'discard', 'preview' ) as $action ) {
	spacefast_accept( 10 === has_action( 'admin_post_spacefast_wordpress_' . $action, array( 'Spacefast_Plugin', $action ) ), 'Version action is not registered: ' . $action );
}
spacefast_accept( false === Spacefast_Settings::get()['review_before_live'], 'Review must default to off.' );
Spacefast_Settings::merge( array( 'review_before_live' => true, 'scope' => 'teams:read spaces:read spaces:write publish:write offline_access' ) );
Spacefast_Sync_State::save( array_merge( Spacefast_Sync_State::defaults(), array(
	'staged_version_id' => 'ver_review',
	'staged_publish_mode' => 'snapshot',
	'last_status' => 'staged',
	'version_sources' => array( 'ver_review' => 'manual', 'ver_live' => 'auto' ),
) ) );
$version_http = static function ( $preempt, array $args, string $url ) {
	if ( ! str_contains( $url, '/versions' ) ) return $preempt;
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array(
		array( 'id' => 'ver_review', 'ref' => 'v3', 'status' => 'ready', 'createdAt' => '2026-11-16T12:00:00Z', 'fileCount' => 12, 'filesAddedCount' => 2, 'filesChangedCount' => 3, 'filesRemovedCount' => 1, 'isCurrentProduction' => false ),
		array( 'id' => 'ver_live', 'ref' => 'v2', 'status' => 'ready', 'createdAt' => '2026-11-15T12:00:00Z', 'fileCount' => 11, 'isCurrentProduction' => true ),
		array( 'id' => 'ver_old', 'ref' => 'v1', 'status' => 'ready', 'createdAt' => '2026-11-14T12:00:00Z', 'fileCount' => 10, 'isCurrentProduction' => false ),
	) ) ), 'headers' => array() );
};
add_filter( 'pre_http_request', $version_http, 10, 3 );
ob_start();
Spacefast_Plugin::render_admin();
$review_markup = (string) ob_get_clean();
foreach ( array( 'Review before going live', 'Staged version', '2 files added, 3 changed, 1 removed.', 'Open preview', 'Copy preview link', 'Go live', 'Discard', 'Versions', 'Make live', 'Manual', 'Auto', 'Live' ) as $label ) {
	spacefast_accept( str_contains( $review_markup, $label ), 'Review UI is missing: ' . $label );
}
spacefast_accept( str_contains( $review_markup, 'name="version_id" value="ver_old"' ), 'Rollback form omits the old version ID.' );
spacefast_accept( str_contains( $review_markup, wp_create_nonce( 'spacefast_wordpress_go_live' ) ), 'Go-live form lacks its nonce.' );
spacefast_accept( ! str_contains( $review_markup, 'acceptance-access' ), 'Review UI exposes an OAuth token.' );
$editor_id = wp_insert_user( array( 'user_login' => 'review-editor', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
spacefast_accept( ! is_wp_error( $editor_id ), 'Could not create the review editor.' );
wp_set_current_user( $editor_id );
ob_start();
Spacefast_Plugin::render_admin();
$editor_markup = (string) ob_get_clean();
spacefast_accept( str_contains( $editor_markup, 'Stage now' ) && str_contains( $editor_markup, 'Open preview' ), 'Editor cannot stage and review.' );
foreach ( array( 'Go live', 'Make live', 'Discard', 'Save and sync', 'Change connection' ) as $label ) {
	spacefast_accept( ! str_contains( $editor_markup, '>' . $label . '<' ), 'Editor sees an administrator action: ' . $label );
}
spacefast_accept( ! Spacefast_Plugin::can_promote(), 'Editor has promotion capability by default.' );
wp_set_current_user( 1 );
$deny_promote = static fn( bool $allowed ): bool => false;
add_filter( 'spacefast_wordpress_can_promote', $deny_promote );
spacefast_accept( ! Spacefast_Plugin::can_promote(), 'Promotion capability filter is ignored.' );
remove_filter( 'spacefast_wordpress_can_promote', $deny_promote );
Spacefast_Settings::merge( array( 'scope' => 'teams:read spaces:read spaces:write spaces:publish offline_access' ) );
ob_start();
Spacefast_Plugin::render_admin();
$legacy_markup = (string) ob_get_clean();
spacefast_accept( str_contains( $legacy_markup, 'Reconnect Spacefast to enable previews, promotion, and rollback.' ), 'Existing installs do not get a reconnect notice.' );
spacefast_accept( ! str_contains( $legacy_markup, 'value="spacefast_wordpress_go_live"' ), 'Existing grant exposes an unusable promotion action.' );
remove_filter( 'pre_http_request', $version_http, 10 );

Spacefast_Settings::disconnect();
fwrite( STDOUT, "Spacefast WordPress container acceptance: PASS\n" );
