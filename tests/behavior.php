<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SPACEFAST_WORDPRESS_VERSION', '0.1.0-test' );

$GLOBALS['spacefast_options'] = array();
$GLOBALS['spacefast_cached_options'] = array();
$GLOBALS['spacefast_cache_enabled'] = array();
$GLOBALS['spacefast_scheduled'] = array();
$GLOBALS['spacefast_before_query'] = null;
$GLOBALS['spacefast_post_types'] = array();
$GLOBALS['spacefast_hooks'] = array();
$GLOBALS['spacefast_remote_handler'] = null;

final class WP_Post {
	public string $post_type;
	public string $post_status;

	public function __construct( string $post_type, string $post_status ) {
		$this->post_type   = $post_type;
		$this->post_status = $post_status;
	}
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, "/\\" );
}
function __( string $value ): string {
	return $value;
}
function esc_html__( string $value ): string {
	return htmlspecialchars( $value );
}
function esc_html( string $value ): string {
	return htmlspecialchars( $value );
}
function esc_html_e( string $value ): void {
	echo esc_html( $value );
}
function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES );
}
function esc_attr_e( string $value ): void {
	echo esc_attr( $value );
}
function esc_url( string $value ): string {
	return esc_attr( $value );
}
function wp_nonce_field( string $action ): void {
	echo '<input name="_wpnonce" value="' . esc_attr( $action ) . '">';
}
function wp_date( string $format, int $timestamp ): string {
	return date( $format, $timestamp );
}
function current_user_can( string $capability ): bool {
	return in_array( $capability, $GLOBALS['spacefast_test_caps'] ?? array( 'manage_options' ), true );
}
function apply_filters( string $hook, $value ) {
	return isset( $GLOBALS['spacefast_test_filters'][ $hook ] ) ? $GLOBALS['spacefast_test_filters'][ $hook ]( $value ) : $value;
}
function check_admin_referer( string $action ): void {
	if ( ( $_POST['_wpnonce'] ?? '' ) !== $action ) throw new RuntimeException( 'Invalid nonce' );
}
function wp_unslash( string $value ): string {
	return stripslashes( $value );
}
function wp_die( string $message, $title = '' ): void {
	throw new RuntimeException( $message );
}
function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}
function get_bloginfo( string $field ): string {
	return 'description' === $field ? 'Demo description' : 'Demo WordPress';
}
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['spacefast_hooks'][ $hook ] = array( $callback, $priority, $accepted_args );
}
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['spacefast_hooks'][ $hook ] = array( $callback, $priority, $accepted_args );
}
function wp_parse_url( string $value, int $component = -1 ) {
	return parse_url( $value, $component );
}
function wp_check_filetype( string $path ): array {
	return array(
		'ext' => pathinfo( $path, PATHINFO_EXTENSION ),
		'type' => match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html' => 'text/html',
			'js' => 'text/javascript',
			'png' => 'image/png',
			default => false,
		},
	);
}
function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $value ) ) ?? '';
}
function get_option( string $name, $default = false ) {
	if ( ! empty( $GLOBALS['spacefast_cache_enabled'][ $name ] ) ) {
		if ( array_key_exists( $name, $GLOBALS['spacefast_cached_options'] ) ) {
			return $GLOBALS['spacefast_cached_options'][ $name ];
		}
		$value = $GLOBALS['spacefast_options'][ $name ] ?? $default;
		$GLOBALS['spacefast_cached_options'][ $name ] = $value;
		return $value;
	}
	return $GLOBALS['spacefast_options'][ $name ] ?? $default;
}
function update_option( string $name, $value ): bool {
	$GLOBALS['spacefast_options'][ $name ] = $value;
	return true;
}
function add_option( string $name, $value ): bool {
	if ( array_key_exists( $name, $GLOBALS['spacefast_options'] ) ) {
		return false;
	}
	$GLOBALS['spacefast_options'][ $name ] = $value;
	return true;
}
function delete_option( string $name ): bool {
	unset( $GLOBALS['spacefast_options'][ $name ] );
	return true;
}
function wp_clear_scheduled_hook( string $hook ): void {
	unset( $GLOBALS['spacefast_scheduled'][ $hook ] );
}
function wp_next_scheduled( string $hook ) {
	return $GLOBALS['spacefast_scheduled'][ $hook ] ?? false;
}
function wp_schedule_single_event( int $timestamp, string $hook ): bool {
	$GLOBALS['spacefast_scheduled'][ $hook ] = $timestamp;
	return true;
}
function wp_unschedule_event( int $timestamp, string $hook ): bool {
	if ( ( $GLOBALS['spacefast_scheduled'][ $hook ] ?? null ) === $timestamp ) {
		unset( $GLOBALS['spacefast_scheduled'][ $hook ] );
	}
	return true;
}
function maybe_serialize( $value ): string {
	return serialize( $value );
}
function wp_cache_delete( string $name ): void {
	unset( $GLOBALS['spacefast_cached_options'][ $name ] );
}
function wp_json_encode( $value ): string {
	return (string) json_encode( $value, JSON_UNESCAPED_SLASHES );
}
function get_post_type_object( string $post_type ) {
	return $GLOBALS['spacefast_post_types'][ $post_type ] ?? null;
}
function get_post_types( array $args = array(), string $output = 'names' ): array {
	$post_types = array_filter(
		$GLOBALS['spacefast_post_types'],
		static fn( object $post_type ): bool => ! isset( $args['public'] ) || $post_type->public === $args['public']
	);
	return 'objects' === $output ? $post_types : array_keys( $post_types );
}
function get_post_type_archive_link( string $post_type ) {
	return $GLOBALS['spacefast_post_types'][ $post_type ]->archive_link ?? false;
}
function wp_generate_uuid4(): string {
	return '00000000-0000-4000-8000-000000000001';
}
function wp_generate_password( int $length ): string {
	return str_repeat( 'a', $length );
}
function admin_url( string $path = '' ): string {
	return 'https://wp.example.test/wp-admin/' . ltrim( $path, '/' );
}
function add_query_arg( array $args, string $url ): string {
	// WordPress build_query() leaves values unencoded. This matters when a
	// query argument is itself a URL with its own query string.
	$pairs = array();
	foreach ( $args as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', $pairs );
}
function home_url(): string {
	return 'https://wp.example.test';
}
function is_wp_error( $value ): bool {
	return $value instanceof Spacefast_Test_Wp_Error;
}
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) $response['response']['code'];
}
function wp_remote_retrieve_body( array $response ): string {
	return (string) $response['body'];
}
function wp_safe_remote_request( string $url, array $args ) {
	$handler = $GLOBALS['spacefast_remote_handler'];
	return is_callable( $handler ) ? $handler( $url, $args ) : new Spacefast_Test_Wp_Error();
}

final class Spacefast_Test_Wp_Error {}
final class WP_Error {
	public function __construct( string $code, string $message ) {
		throw new RuntimeException( $code . ': ' . $message );
	}
}
final class Spacefast_Test_Wpdb {
	public string $options = 'wp_options';

	public function prepare( string $sql, ...$args ): array {
		return array( $sql, $args );
	}

	public function query( array $prepared ): int {
		if ( is_callable( $GLOBALS['spacefast_before_query'] ) ) {
			$before = $GLOBALS['spacefast_before_query'];
			$GLOBALS['spacefast_before_query'] = null;
			$before();
		}
		list( $sql, $args ) = $prepared;
		if ( str_starts_with( trim( $sql ), 'UPDATE' ) ) {
			list( $next, $option, $expected ) = $args;
			$current = $GLOBALS['spacefast_options'][ $option ] ?? false;
			if ( maybe_serialize( $current ) !== $expected ) {
				return 0;
			}
			if ( $next === $expected ) {
				return 0;
			}
			$GLOBALS['spacefast_options'][ $option ] = unserialize( $next );
			return 1;
		}
		list( $option, $expected ) = $args;
		$current = $GLOBALS['spacefast_options'][ $option ] ?? false;
		if ( maybe_serialize( $current ) !== $expected ) {
			return 0;
		}
		unset( $GLOBALS['spacefast_options'][ $option ] );
		return 1;
	}
}
$wpdb = new Spacefast_Test_Wpdb();

require_once dirname( __DIR__ ) . '/includes/class-spacefast-sync-state.php';
require_once dirname( __DIR__ ) . '/includes/class-spacefast-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-spacefast-site-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-spacefast-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-spacefast-client.php';
require_once dirname( __DIR__ ) . '/includes/class-spacefast-static-publisher.php';
require_once dirname( __DIR__ ) . '/includes/class-spacefast-plugin.php';

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function response( int $status, array $data = array() ): array {
	return array(
		'response' => array( 'code' => $status ),
		'body' => json_encode( array( 'data' => $data ) ),
	);
}

function skip_publish_retry_wait(): void {
	$state = get_option( Spacefast_Static_Publisher::OPTION, array() );
	$state['retry_at'] = 0;
	update_option( Spacefast_Static_Publisher::OPTION, $state );
}

function paginated_response( array $data, ?string $next_cursor = null ): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body' => json_encode(
			array(
				'data' => $data,
				'pagination' => array(
					'nextCursor' => $next_cursor,
					'hasMore' => null !== $next_cursor,
				),
			)
		),
	);
}

function raw_response( int $status, array $data = array() ): array {
	return array(
		'response' => array( 'code' => $status ),
		'body' => json_encode( $data ),
	);
}

Spacefast_Plugin::boot();
check( isset( $GLOBALS['spacefast_hooks']['update_option_home'] ), 'changing the WordPress Site Address queues settings sync' );

check(
	array( 'teams:read', 'spaces:read', 'spaces:write', 'publish:write', 'offline_access' ) === Spacefast_OAuth::scopes( Spacefast_Settings::MODE_STATIC ),
	'static OAuth asks for Team discovery, Space creation and discovery, publishing, and refresh access'
);
check(
	array( 'teams:read', 'spaces:read', 'spaces:write', 'builds:trigger', 'offline_access' ) === Spacefast_OAuth::scopes( Spacefast_Settings::MODE_HEADLESS ),
	'headless OAuth can sync the WordPress source before triggering builds'
);
Spacefast_Settings::merge( array( 'scope' => 'teams:read spaces:read spaces:publish offline_access' ) );
check( ! Spacefast_OAuth::has_scope( 'spaces:write' ), 'upgraded static authorization does not imply Space management access' );
Spacefast_Settings::merge( array( 'scope' => 'teams:read spaces:read spaces:write spaces:publish offline_access' ) );
check( Spacefast_OAuth::has_scope( 'spaces:write' ), 'recognizes granted Space management access' );
check(
	strlen( Spacefast_OAuth::pkce_challenge( str_repeat( 'v', 64 ) ) ) === 43,
	'PKCE challenge uses an unpadded SHA-256 base64url value'
);

$oauth_requests = array();
$oauth_transport = static function ( string $url, array $args ) use ( &$oauth_requests ): array {
	$oauth_requests[] = array( $url, $args );
	if ( str_ends_with( $url, '/oauth2/register' ) ) {
		return raw_response( 201, array( 'client_id' => 'client_wordpress' ) );
	}
	if ( str_ends_with( $url, '/oauth2/token' ) ) {
		return raw_response(
			200,
			array(
				'access_token' => 'oauth_access',
				'refresh_token' => 'oauth_refresh',
				'expires_in' => 900,
				'scope' => 'teams:read spaces:read spaces:write builds:trigger offline_access',
			)
		);
	}
	if ( str_contains( $url, '/v1/teams' ) ) {
		return paginated_response( array( array( 'id' => 'team_demo', 'name' => 'Demo Team', 'slug' => 'demo-team' ) ) );
	}
	if ( str_contains( $url, 'cursor=space%20cursor%2F2' ) ) {
		return paginated_response(
			array(
				array(
					'id' => 'spc_second',
					'teamId' => 'team_demo',
					'teamSlug' => 'demo-team',
					'slug' => 'second-space',
					'title' => 'Second Space',
					'liveUrl' => 'https://second.spacefast.site',
				),
			)
		);
	}
	return paginated_response(
		array(
			array(
				'id' => 'spc_demo',
				'teamId' => 'team_demo',
				'teamSlug' => 'demo-team',
				'slug' => 'demo-space',
				'title' => 'Demo Space',
				'liveUrl' => 'https://demo.spacefast.site',
			),
		),
		'space cursor/2'
	);
};
$oauth = new Spacefast_OAuth( $oauth_transport );
$authorization = $oauth->begin( Spacefast_Settings::MODE_HEADLESS );
check( true === $authorization['ok'], 'dynamic client registration starts OAuth' );
$registration_body = json_decode( $oauth_requests[0][1]['body'], true );
check( 'none' === $registration_body['token_endpoint_auth_method'], 'registers a public PKCE client' );
check( 'Spacefast for WordPress (wp.example.test)' === $registration_body['client_name'], 'identifies this WordPress installation in Connected Apps' );
check(
	array( Spacefast_Settings::api_url() . '/v1' ) === $registration_body['resources'],
	'binds tokens to the Spacefast API resource'
);
check( str_contains( (string) $authorization['url'], 'code_challenge_method=S256' ), 'authorization uses PKCE S256' );
$authorization_query = array();
parse_str( (string) parse_url( (string) $authorization['url'], PHP_URL_QUERY ), $authorization_query );
check(
	Spacefast_OAuth::callback_url() === ( $authorization_query['redirect_uri'] ?? '' ),
	'authorization preserves the complete query-bearing WordPress callback URI'
);
$pending = get_option( Spacefast_OAuth::PENDING_OPTION );
$finished = $oauth->finish( 'authorization-code', (string) $pending['state'] );
check( true === $finished['ok'], 'valid callback exchanges its code and loads Team-scoped choices' );
check( 'oauth_refresh' === Spacefast_Settings::get()['refresh_token'], 'stores the rotating refresh token' );
check( 2 === count( get_option( Spacefast_OAuth::CHOICES_OPTION )['spaces'] ), 'loads every paginated Space choice into WordPress' );
check(
	str_contains( implode( ' ', array_column( $oauth_requests, 0 ) ), 'cursor=space%20cursor%2F2' ),
	'encodes and follows the opaque Space cursor'
);
check( ! Spacefast_Settings::configured(), 'authorization alone is not presented as Connected' );

$create_requests = array();
$creating_client = new Spacefast_Client(
	static function ( string $url, array $args ) use ( &$create_requests ): array {
		$create_requests[] = array( $url, $args );
		return response(
			201,
			array(
				'space' => array(
					'id' => 'spc_created',
					'teamId' => 'team_demo',
					'slug' => 'spacefast-launchpad',
					'title' => 'Spacefast Launchpad',
					'liveUrl' => 'https://spacefast-launchpad.view.fast',
				),
			)
		);
	}
);
$created_space = $creating_client->create_space( 'team_demo', 'Spacefast Launchpad', 'wordpress-space-operation' );
check( true === $created_space['ok'], 'creates a Space with the authorized OAuth token' );
check( '/v1/spaces' === parse_url( $create_requests[0][0], PHP_URL_PATH ), 'uses the canonical Space creation endpoint' );
check( 'wordpress-space-operation' === $create_requests[0][1]['headers']['Idempotency-Key'], 'makes Space creation safe to retry' );
check(
	array( 'teamId' => 'team_demo', 'title' => 'Spacefast Launchpad' ) === json_decode( $create_requests[0][1]['body'], true ),
	'creates the Space in the authorized Team with the WordPress site name'
);
$valid_space_creation_identity = new ReflectionMethod( Spacefast_Plugin::class, 'valid_space_creation_identity' );
check(
	true === $valid_space_creation_identity->invoke( null, 'A7bc9DeF2gHi3JkLmN4pQrStUvWxYz01', 'Spacefast Launchpad' ),
	'accepts the opaque Team identifiers returned by Spacefast when creating a Space'
);
check(
	false === $valid_space_creation_identity->invoke( null, '', 'Spacefast Launchpad' ),
	'rejects Space creation when the authorized Team is missing'
);
$choices_before_creation = get_option( Spacefast_OAuth::CHOICES_OPTION );
Spacefast_OAuth::remember_space_choice( $created_space['data']['space'] );
Spacefast_OAuth::remember_space_choice( $created_space['data']['space'] );
$remembered_choices = get_option( Spacefast_OAuth::CHOICES_OPTION );
check( count( $choices_before_creation['spaces'] ) + 1 === count( $remembered_choices['spaces'] ), 'keeps a created Space selectable without adding duplicates' );
check( 'spc_created' === end( $remembered_choices['spaces'] )['id'], 'persists the created Space receipt before connection verification' );

$reauthorization = $oauth->begin( Spacefast_Settings::MODE_HEADLESS );
$reauthorization_pending = get_option( Spacefast_OAuth::PENDING_OPTION );
$reauthorized = $oauth->finish( 'replacement-code', (string) $reauthorization_pending['state'] );
check( true === $reauthorized['ok'], 'reauthorization replaces a connection only after the callback succeeds' );
check(
	2 === count( array_filter( $oauth_requests, static fn( array $request ): bool => str_ends_with( $request[0], '/oauth2/revoke' ) ) ),
	'reauthorization revokes the replaced refresh and access tokens'
);

Spacefast_Settings::merge( array( 'expires_at' => 1 ) );
$refresh_requests = array();
$refreshing_oauth = new Spacefast_OAuth(
	static function ( string $url, array $args ) use ( &$refresh_requests ): array {
		$refresh_requests[] = array( $url, $args );
		return raw_response(
			200,
			array(
				'access_token' => 'rotated_access',
				'refresh_token' => 'rotated_refresh',
				'expires_in' => 900,
			)
		);
	}
);
check( 'rotated_access' === $refreshing_oauth->access_token(), 'refreshes before an access token expires' );
check( 'rotated_refresh' === Spacefast_Settings::get()['refresh_token'], 'persists refresh-token rotation' );
$revoke_requests = array();
( new Spacefast_OAuth(
	static function ( string $url, array $args ) use ( &$revoke_requests ): array {
		$revoke_requests[] = array( $url, $args );
		return raw_response( 200 );
	}
) )->revoke();
check( 2 === count( $revoke_requests ), 'disconnect can revoke refresh and access tokens remotely' );

$connection = array(
	'mode' => Spacefast_Settings::MODE_HEADLESS,
	'client_id' => 'client_wordpress',
	'access_token' => 'access_secret',
	'refresh_token' => 'refresh_secret',
	'expires_at' => time() + 3600,
	'scope' => 'teams:read spaces:read spaces:write builds:trigger offline_access',
	'team_id' => 'team_demo',
	'team_name' => 'Demo Team',
	'team_slug' => 'demo-team',
	'space_id' => 'spc_demo',
	'space_name' => 'Demo Space',
	'space_slug' => 'demo-space',
	'live_url' => 'https://demo.spacefast.site',
	'verified_at' => time(),
);
Spacefast_Settings::merge( $connection );
$resume_authorization = $oauth->begin( Spacefast_Settings::MODE_HEADLESS );
check( true === $resume_authorization['ok'], 'connected installations can reauthorize in place' );
$resume_pending = get_option( Spacefast_OAuth::PENDING_OPTION );
$resumed = $oauth->finish( 'resume-code', (string) $resume_pending['state'] );
check( true === ( $resumed['resumed'] ?? false ), 'reauthorization resumes the existing accessible Space' );
check( 'spc_demo' === Spacefast_Settings::get()['space_id'], 'reauthorization does not force another Space selection' );

Spacefast_Settings::merge(
	array_merge(
		$connection,
		array( 'scope' => 'teams:read spaces:read builds:trigger offline_access' )
	)
);
Spacefast_Sync_State::save(
	array_merge(
		Spacefast_Sync_State::defaults(),
		array(
			'desired' => 8,
			'delivered' => 8,
			'last_status' => 'building',
			'last_build_id' => 'bld_previous_space',
		)
	)
);
$connection_requests = array();
$GLOBALS['spacefast_remote_handler'] = static function ( string $url, array $args ) use ( &$connection_requests ): array {
	$connection_requests[] = array( $url, $args );
	if ( 'POST' === $args['method'] && str_ends_with( $url, '/builds/wordpress' ) ) {
		return response( 201, array( 'build' => array( 'id' => 'bld_new_space', 'status' => 'queued' ) ) );
	}
	return response( 200, array( 'ready' => true ) );
};
$change_recorded = new ReflectionProperty( Spacefast_Plugin::class, 'change_recorded' );
$change_recorded->setValue( null, false );
$connect_space = new ReflectionMethod( Spacefast_Plugin::class, 'connect_space' );
$connected_new_space = $connect_space->invoke(
	null,
	array(
		'id' => 'spc_new',
		'teamId' => 'team_demo',
		'title' => 'New Space',
		'slug' => 'new-space',
		'liveUrl' => 'https://new.spacefast.site',
	),
	array( 'id' => 'team_demo', 'name' => 'Demo Team', 'slug' => 'demo-team' )
);
check( true === $connected_new_space['ok'], 'an upgraded token can select a Space before settings reauthorization' );
$new_space_state = Spacefast_Sync_State::get();
check( 'bld_new_space' === $new_space_state['last_build_id'], 'a new Space starts its own first build instead of polling the previous Space' );
check( 1 === $new_space_state['desired'], 'switching Spaces resets the previous delivery generation' );
check(
	! str_contains( implode( ' ', array_column( $connection_requests, 0 ) ), 'bld_previous_space' ),
	'switching Spaces discards the previous active build receipt'
);
check( true === $new_space_state['settings_pending'], 'settings remain queued until the upgraded token is reauthorized' );

Spacefast_Settings::merge( $connection );
$GLOBALS['spacefast_remote_handler'] = null;
Spacefast_Sync_State::save( Spacefast_Sync_State::defaults() );

$state = Spacefast_Sync_State::record_change(
	Spacefast_Sync_State::defaults(),
	'Post Published',
	'event-one'
);
$state = Spacefast_Sync_State::record_change( $state, 'taxonomy', 'event-two' );
check( 2 === $state['desired'], 'increments desired generation' );
check( 'event-two' === $state['event_id'], 'keeps latest stable event id' );
check( array( 'postpublished', 'taxonomy' ) === $state['reasons'], 'coalesces reasons' );
check( 0 < $state['last_change_at'], 'records when the latest public content change happened' );
$state['desired'] = 3;
$ack = Spacefast_Sync_State::acknowledge( $state, 2, 'bld_test' );
check( 2 === $ack['delivered'], 'acknowledges only observed generation' );
check( 'building' === $ack['last_status'], 'keeps the active build visible until it reaches a terminal state' );
$terminal = Spacefast_Sync_State::acknowledge_build_terminal( $ack, 'succeeded' );
check( 'pending' === $terminal['last_status'], 'queues one successor when an edit arrived during the build' );
check( '' === $terminal['last_build_id'], 'terminal builds clear their active polling receipt' );
check( 60 === Spacefast_Sync_State::retry_delay( 1 ), 'starts bounded backoff' );
check( DAY_IN_SECONDS === Spacefast_Sync_State::retry_delay( 99 ), 'caps backoff' );

Spacefast_Sync_State::save(
	array_merge(
		Spacefast_Sync_State::defaults(),
		array(
			'desired' => 1,
			'event_id' => 'event-one',
		)
	)
);
$GLOBALS['spacefast_before_query'] = static function (): void {
	$current = $GLOBALS['spacefast_options'][ Spacefast_Sync_State::OPTION ];
	$current['desired'] = 2;
	$current['event_id'] = 'event-two';
	$GLOBALS['spacefast_options'][ Spacefast_Sync_State::OPTION ] = $current;
};
$GLOBALS['spacefast_cache_enabled'][ Spacefast_Sync_State::OPTION ] = true;
$GLOBALS['spacefast_cached_options'][ Spacefast_Sync_State::OPTION ] = Spacefast_Sync_State::get();
$cas_ack = Spacefast_Sync_State::mutate(
	static fn( array $current ): array => Spacefast_Sync_State::acknowledge(
		$current,
		1,
		'bld_first'
	)
);
check( 2 === $cas_ack['desired'], 'CAS preserves a concurrent content generation' );
check( 1 === $cas_ack['delivered'], 'CAS acknowledges only the delivered generation' );
check( 'event-two' === $cas_ack['event_id'], 'CAS preserves the successor event id' );
check( 'building' === $cas_ack['last_status'], 'CAS keeps the accepted build active while preserving its successor' );
unset( $GLOBALS['spacefast_cache_enabled'][ Spacefast_Sync_State::OPTION ] );
unset( $GLOBALS['spacefast_cached_options'][ Spacefast_Sync_State::OPTION ] );
$unchanged_state = Spacefast_Sync_State::mutate( static fn( array $current ): array => $current );
check( $cas_ack === $unchanged_state, 'an unchanged journal transition succeeds without becoming a false CAS conflict' );
$journal_before_upgrade = $GLOBALS['spacefast_options'][ Spacefast_Sync_State::OPTION ];
// State saved by 0.5.x has none of the review keys added since.
$GLOBALS['spacefast_options'][ Spacefast_Sync_State::OPTION ] = array_diff_key(
	$cas_ack,
	array_flip( array( 'staged_version_id', 'staged_publish_mode', 'active_review', 'active_source', 'pending_source', 'version_sources' ) )
);
$upgraded = Spacefast_Sync_State::mutate(
	static fn( array $current ): array => Spacefast_Sync_State::record_change( $current, 'post_publish', 'event-upgrade' )
);
check( 3 === $upgraded['desired'] && 'auto' === $upgraded['pending_source'], 'state saved by an earlier release still accepts transitions' );
check( 'event-upgrade' === $GLOBALS['spacefast_options'][ Spacefast_Sync_State::OPTION ]['event_id'], 'upgraded state is written back with the new keys' );
$GLOBALS['spacefast_options'][ Spacefast_Sync_State::OPTION ] = $journal_before_upgrade;

$invalid_response = new Spacefast_Client(
	static fn(): array => array(
		'response' => array( 'code' => 200 ),
		'body' => '{"data":{"config":',
	)
);
$invalid_space = $invalid_response->get_space();
check( ! $invalid_space['ok'], 'a truncated successful Space response is never patched as empty config' );
check( 'invalid_response_body' === $invalid_space['code'], 'truncated JSON has a stable retryable error' );

$large_response = new Spacefast_Client(
	static function ( string $url, array $args ): array {
		unset( $url );
		$body = json_encode( array( 'data' => array( 'receipt' => str_repeat( 'x', 2 * 1024 * 1024 ) ) ) );
		$limit = (int) ( $args['limit_response_size'] ?? 0 );
		return array(
			'response' => array( 'code' => 200 ),
			'body' => substr( (string) $body, 0, $limit ),
		);
	}
);
$large_space = $large_response->get_space();
check( true === $large_space['ok'], 'large upload-instruction responses remain complete JSON' );
check(
	2 * 1024 * 1024 === strlen( (string) ( $large_space['data']['receipt'] ?? '' ) ),
	'keeps the complete large upload receipt available to the publisher'
);

$poll_build = new ReflectionMethod( Spacefast_Plugin::class, 'poll_headless_build' );
$GLOBALS['spacefast_scheduled'] = array();
$failed_build_state = array_merge(
	Spacefast_Sync_State::defaults(),
	array(
		'desired' => 2,
		'delivered' => 1,
		'event_id' => 'event-successor',
		'last_status' => 'building',
		'last_build_id' => 'bld_failed',
		'last_change_at' => time() - 120,
	)
);
Spacefast_Sync_State::save( $failed_build_state );
$GLOBALS['spacefast_remote_handler'] = static fn(): array => response(
	200,
	array( 'id' => 'bld_failed', 'status' => 'failed', 'diagnostics' => array() )
);
$poll_build->invoke( null, $failed_build_state );
check( 'blocked' === Spacefast_Sync_State::get()['last_status'], 'a failed build keeps its safe failure state visible' );
check( false !== wp_next_scheduled( Spacefast_Sync_State::HOOK ), 'a content change queued during a failed build schedules one successor' );

$GLOBALS['spacefast_scheduled'] = array();
$unknown_build_state = array_merge(
	Spacefast_Sync_State::defaults(),
	array(
		'desired' => 1,
		'delivered' => 1,
		'last_status' => 'building',
		'last_build_id' => 'bld_unknown',
	)
);
Spacefast_Sync_State::save( $unknown_build_state );
$GLOBALS['spacefast_remote_handler'] = static fn(): array => response(
	200,
	array( 'id' => 'bld_unknown', 'status' => 'observing' )
);
$poll_build->invoke( null, $unknown_build_state );
check( 'building' === Spacefast_Sync_State::get()['last_status'], 'an unknown build status does not become a false failure' );
check( false !== wp_next_scheduled( Spacefast_Sync_State::HOOK ), 'an unknown build status is polled again' );
$GLOBALS['spacefast_remote_handler'] = null;

$mapping = Spacefast_Site_Settings::patch(
	array(
		'title' => 'Old title',
		'noindex' => false,
		'settingsDigest' => str_repeat( 'a', 64 ),
		'config' => array(
			'cleanUrls' => true,
			'dataSources' => array(
				'catalog' => array( 'kind' => 'wordpress', 'url' => 'https://catalog.example.test' ),
			),
		),
	),
	array( 'sync_title' => true, 'sync_visibility' => true, 'sync_source' => true ),
	array(
		'title' => 'New WordPress title',
		'description' => 'A synced description.',
		'noindex' => true,
		'source_url' => 'https://wp.example.test',
	),
	Spacefast_Settings::MODE_HEADLESS
);
check( true === $mapping['config']['cleanUrls'], 'settings sync preserves unrelated Space config' );
check( isset( $mapping['config']['dataSources']['catalog'] ), 'settings sync preserves unrelated data sources' );
check(
	array( 'kind' => 'wordpress', 'url' => 'https://wp.example.test' ) === $mapping['config']['dataSources']['wordpress'],
	'settings sync configures this WordPress site as a source'
);
check( 'wordpress' === $mapping['config']['defaultDataSource'], 'settings sync makes the connected WordPress source the build default' );
check( true === $mapping['noindex'], 'settings sync mirrors WordPress search visibility' );
check( str_repeat( 'a', 64 ) === $mapping['baseSettingsDigest'], 'settings sync uses the current compare-and-swap base' );

Spacefast_Sync_State::save( Spacefast_Sync_State::defaults() );
check(
	Spacefast_Sync_State::claim_lock( 'spacefast_test_lock', 'owner-a', 100 ),
	'first worker claims lock'
);
check(
	! Spacefast_Sync_State::claim_lock( 'spacefast_test_lock', 'owner-b', 101 ),
	'second worker cannot steal live lock'
);
Spacefast_Sync_State::release_lock( 'spacefast_test_lock', 'owner-b' );
check(
	'owner-a' === get_option( 'spacefast_test_lock' )['owner'],
	'non-owner cannot release lock'
);
Spacefast_Sync_State::release_lock( 'spacefast_test_lock', 'owner-a' );
check( false === get_option( 'spacefast_test_lock', false ), 'owner releases lock' );

$debounce = new ReflectionMethod( Spacefast_Plugin::class, 'schedule_after_change' );
$changed_at = time();
$GLOBALS['spacefast_scheduled'][ Spacefast_Sync_State::HOOK ] = $changed_at + 10;
$debounce->invoke( null, $changed_at );
check(
	$changed_at + MINUTE_IN_SECONDS === $GLOBALS['spacefast_scheduled'][ Spacefast_Sync_State::HOOK ],
	'public content waits for a 60-second quiet window'
);
$later_change = $changed_at + 20;
$debounce->invoke( null, $later_change );
check(
	$later_change + MINUTE_IN_SECONDS === $GLOBALS['spacefast_scheduled'][ Spacefast_Sync_State::HOOK ],
	'a later content change restarts the quiet window'
);

Spacefast_Sync_State::save(
	array_merge(
		Spacefast_Sync_State::defaults(),
		array(
			'desired' => 3,
			'delivered' => 2,
			'event_id' => 'event-reactivate',
		)
	)
);
Spacefast_Plugin::deactivate();
check(
	false === wp_next_scheduled( Spacefast_Sync_State::HOOK ),
	'deactivation clears the worker'
);
Spacefast_Plugin::activate();
check(
	false !== wp_next_scheduled( Spacefast_Sync_State::HOOK ),
	'reactivation recovers pending work'
);

$GLOBALS['spacefast_scheduled'] = array();
Spacefast_Sync_State::save(
	array_merge(
		Spacefast_Sync_State::defaults(),
		array(
			'desired' => 1,
			'delivered' => 1,
			'last_status' => 'building',
			'last_build_id' => 'bld_reactivate',
		)
	)
);
Spacefast_Plugin::activate();
check( false !== wp_next_scheduled( Spacefast_Sync_State::HOOK ), 'reactivation resumes terminal polling for an accepted build' );

Spacefast_Settings::disconnect();
Spacefast_Settings::merge( $connection );
$reconnected = Spacefast_Sync_State::mutate(
	static fn( array $current ): array => Spacefast_Sync_State::record_change(
		$current,
		'manual',
		'event-reconnected'
	)
);
check( 1 === $reconnected['desired'], 'reconnect restores durable sync state' );

$GLOBALS['spacefast_post_types']['internal'] = (object) array(
	'public'       => false,
	'show_in_rest' => false,
);
$GLOBALS['spacefast_post_types']['post']     = (object) array(
	'public'       => true,
	'show_in_rest' => true,
);
$GLOBALS['spacefast_post_types']['legacy_public'] = (object) array(
	'public'       => true,
	'show_in_rest' => false,
);
$before_delete = Spacefast_Sync_State::get();
Spacefast_Plugin::post_deleted( 1, new WP_Post( 'internal', 'publish' ) );
check(
	$before_delete['desired'] === Spacefast_Sync_State::get()['desired'],
	'ignores deletion of non-public post types'
);
$change_recorded = new ReflectionProperty( Spacefast_Plugin::class, 'change_recorded' );
$change_recorded->setValue( null, false );
Spacefast_Plugin::post_deleted( 2, new WP_Post( 'post', 'publish' ) );
check(
	$before_delete['desired'] + 1 === Spacefast_Sync_State::get()['desired'],
	'rebuilds after deleting a public REST-visible post'
);
$headless_generation = Spacefast_Sync_State::get()['desired'];
$change_recorded->setValue( null, false );
Spacefast_Plugin::post_deleted( 3, new WP_Post( 'legacy_public', 'publish' ) );
check(
	$headless_generation === Spacefast_Sync_State::get()['desired'],
	'headless mode ignores public content unavailable through the REST API'
);
Spacefast_Settings::merge( array( 'mode' => Spacefast_Settings::MODE_STATIC ) );
$change_recorded->setValue( null, false );
Spacefast_Plugin::post_deleted( 4, new WP_Post( 'legacy_public', 'publish' ) );
check(
	$headless_generation + 1 === Spacefast_Sync_State::get()['desired'],
	'static mode republishes public content even when it is not REST-visible'
);

Spacefast_Settings::merge( $connection );
$requests = array();
$transport = static function ( string $url, array $args ) use ( &$requests ) {
	$requests[] = array( $url, $args );
	if ( 'GET' === $args['method'] ) {
		return response( 200, array( 'ready' => true ) );
	}
	return response( 201, array( 'build' => array( 'id' => 'bld_demo' ) ) );
};
$result = ( new Spacefast_Client( $transport ) )->trigger_build( 'wp-event-123' );
check( true === $result['ok'], 'healthy connection triggers a build' );
check( 2 === count( $requests ), 'verifies the source before the build' );
$build_request = $requests[1][1];
check(
	'wp-event-123' === $build_request['headers']['Idempotency-Key'],
	'reuses the stable event as idempotency key'
);
$build_body = json_decode( $build_request['body'], true );
check(
	'https://wp.example.test' === $build_body['sourceUrl'],
	'sends only the configured WordPress source'
);
check(
	str_ends_with( $requests[1][0], '/builds/wordpress' ),
	'uses the constrained server-side trigger'
);
check(
	'Bearer access_secret' === $build_request['headers']['Authorization'],
	'authenticates with the current OAuth access token'
);

$mismatch = new Spacefast_Client(
	static fn(): array => array(
		'response' => array( 'code' => 422 ),
		'body' => json_encode(
			array(
				'type' => 'https://spacefast.com/docs/errors/wordpress_source_mismatch',
				'title' => 'WordPress source mismatch',
				'status' => 422,
				'detail' => 'mismatch',
				'code' => 'wordpress_source_mismatch',
			)
		),
	)
);
$mismatch_result = $mismatch->health();
check(
	'wordpress_source_mismatch' === $mismatch_result['code'],
	'blocks a different WordPress origin'
);
check( false === $mismatch_result['retryable'], 'does not hot-loop configuration errors' );

$network = new Spacefast_Client( static fn(): Spacefast_Test_Wp_Error => new Spacefast_Test_Wp_Error() );
$network_result = $network->health();
check( true === $network_result['retryable'], 'retries transport failures' );

$invalid_receipt_requests = 0;
$invalid_receipt = new Spacefast_Client(
	static function ( string $url ) use ( &$invalid_receipt_requests ): array {
		++$invalid_receipt_requests;
		return 1 === $invalid_receipt_requests
			? response( 200, array( 'ready' => true ) )
			: response( 204 );
	}
);
$invalid_receipt_result = $invalid_receipt->trigger_build( 'wp-event-invalid' );
check( false === $invalid_receipt_result['ok'], 'rejects an empty success response' );
check( true === $invalid_receipt_result['retryable'], 'retries invalid build receipts' );

Spacefast_Settings::merge(
	array(
		'mode' => Spacefast_Settings::MODE_STATIC,
		'scope' => 'teams:read spaces:read spaces:publish offline_access',
		'verified_at' => time(),
	)
);
check( 'static' === Spacefast_Settings::mode(), 'stores the selected static publishing mode' );
check(
	array( 'setup', 'fetch_urls', 'spacefast_publish', 'wrapup' ) === Spacefast_Plugin::simply_static_tasks(
		array( 'setup', 'fetch_urls', 'wrapup' ),
		'zip'
	),
	'publishes after export delivery and before cleanup'
);
$GLOBALS['spacefast_post_types']['case_study'] = (object) array(
	'public' => true,
	'has_archive' => 'case-studies',
	'archive_link' => 'https://wp.example.test/case-studies/',
);
$GLOBALS['spacefast_post_types']['private_story'] = (object) array(
	'public' => false,
	'has_archive' => 'private-stories',
	'archive_link' => 'https://wp.example.test/private-stories/',
);
check(
	array( 'https://wp.example.test/manual/', 'https://wp.example.test/case-studies/' )
		=== Spacefast_Plugin::simply_static_archive_urls(
			array( 'https://wp.example.test/manual/', 'https://wp.example.test/case-studies/' )
		),
	'includes public custom post archives once in static exports'
);
$GLOBALS['spacefast_options']['simply-static'] = array(
	'post_types' => array( 'post' ),
	'post_types_configured' => true,
);
check(
	array( 'https://wp.example.test/manual/' )
		=== Spacefast_Plugin::simply_static_archive_urls( array( 'https://wp.example.test/manual/' ) ),
	'respects the Simply Static post type selection for archives'
);
unset( $GLOBALS['spacefast_options']['simply-static'] );

$archive = sys_get_temp_dir() . '/spacefast-wordpress-static-' . uniqid();
mkdir( $archive );
mkdir( $archive . '/assets' );
file_put_contents( $archive . '/index.html', '<h1>Spacefast</h1>' );
file_put_contents( $archive . '/assets/app.js', 'console.log("spacefast")' );
$manifest = Spacefast_Static_Publisher::manifest( $archive );
check(
	array( 'assets/app.js', 'index.html', 'sf.jsonc' ) === array_column( $manifest, 'path' ),
	'builds a sorted snapshot manifest from generated files'
);
check(
	hash_file( 'sha256', $archive . '/index.html' ) === $manifest[1]['sha256'],
	'hashes the generated bytes instead of trusting exporter metadata'
);
check( 'text/javascript' === $manifest[0]['contentType'], 'declares generated asset media types in the publish manifest' );
check( 'application/json' === $manifest[2]['contentType'], 'declares the generated Spacefast config as JSON' );
check(
	'public' === ( json_decode( file_get_contents( $archive . '/sf.jsonc' ), true )['access'] ?? '' ),
	'makes a static WordPress export publicly readable by default'
);
$custom_archive = sys_get_temp_dir() . '/spacefast-wordpress-custom-config-' . uniqid();
mkdir( $custom_archive );
file_put_contents( $custom_archive . '/index.html', '<h1>Custom</h1>' );
file_put_contents( $custom_archive . '/sf.jsonc', '{"access":{"public":["/docs/**"]}}' );
Spacefast_Static_Publisher::manifest( $custom_archive );
check(
	'{"access":{"public":["/docs/**"]}}' === file_get_contents( $custom_archive . '/sf.jsonc' ),
	'preserves an explicit Spacefast access configuration'
);
check( 'image/png' === Spacefast_Client::content_type_for_file( '/tmp/preview.png' ), 'sends binary uploads with their media type' );
check(
	'application/octet-stream' === Spacefast_Client::content_type_for_file( '/tmp/unknown.custom' ),
	'uses a safe binary media type when WordPress does not recognize the extension'
);

$static_requests = array();
$static_client = new Spacefast_Client(
	static function ( string $url, array $args ) use ( &$static_requests ): array {
		$static_requests[] = array( $url, $args );
		if ( 'GET' === $args['method'] ) {
			return response(
				200,
				array(
					'id' => 'ver_static',
					'status' => 'ready',
					'isCurrentProduction' => true,
				)
			);
		}
		if ( str_contains( $url, '/uploads/resume' ) ) {
			// The completion check after the last page finds nothing missing.
			if ( count( array_filter( $static_requests, static fn ( array $request ): bool => str_contains( $request[0], '/uploads/resume' ) ) ) > 1 ) {
				return response( 200, array( 'upload' => null ) );
			}
			return response(
				200,
				array(
					'upload' => array(
						'id' => 'upl_static',
						'mode' => 'dist',
						'expiresAt' => '2030-01-01T00:00:00.000Z',
					'summary' => array( 'upload' => 1, 'reused' => 1, 'ignored' => 0 ),
						'targets' => array(
							array(
								'path' => 'assets/app.js',
								'method' => 'PUT',
								'url' => 'https://uploads.example.test/file-2',
								'headers' => array( 'X-Upload' => 'signed-2' ),
							),
						),
						'links' => array( 'resume' => '/resume', 'finalize' => '/finalize' ),
					),
				)
			);
		}
		return response(
			201,
			array(
				'versionId' => 'ver_static',
				'upload' => array(
					'id' => 'upl_static',
					'mode' => 'dist',
					'expiresAt' => '2030-01-01T00:00:00.000Z',
				'summary' => array( 'upload' => 2, 'reused' => 1, 'ignored' => 0 ),
				'targets' => array(
					array(
						'path' => 'sf.jsonc',
							'method' => 'PUT',
							'url' => 'https://uploads.example.test/file',
							'headers' => array( 'X-Upload' => 'signed' ),
						),
					),
					'links' => array( 'resume' => '/resume', 'finalize' => '/finalize' ),
				),
			)
		);
	},
	static function ( string $url, string $method, array $headers, string $file ) use ( &$static_requests ): array {
		$static_requests[] = array( $url, $method, $headers, $file );
		return response( 204 );
	}
);
$publish_step = Spacefast_Static_Publisher::step( $archive, $static_client );
check( false === $publish_step['done'], 'uploads a bounded target per background step' );
check( 1 === $publish_step['uploaded'], 'records completed generated-file uploads' );
$publish_done = Spacefast_Static_Publisher::step( $archive, $static_client );
check( false === $publish_done['done'], 'resumes a paged upload after the first instruction page' );
$publish_done = Spacefast_Static_Publisher::step( $archive, $static_client );
check( 2 === $publish_done['uploaded'], 'uploads the file from the resumed instruction page' );
$publish_done = Spacefast_Static_Publisher::step( $archive, $static_client );
check( false === $publish_done['done'], 'waits for Spacefast activation after every target lands' );
$publish_done = Spacefast_Static_Publisher::step( $archive, $static_client );
check( true === $publish_done['done'], 'finishes only after the version is ready' );
check( 'ver_static' === $publish_done['version_id'], 'retains the Spacefast version receipt' );
check( 'live' === $publish_done['status'], 'distinguishes live activation from upload acceptance' );
$create_body = json_decode( $static_requests[0][1]['body'], true );
check( 'snapshot' === $create_body['publishMode'], 'publishes the export as an exact snapshot' );
check( array( 'channel' => 'live' ) === $create_body['finalize'], 'requests live auto-finalize up front' );
check(
	'https://wp.example.test' === $create_body['source']['metadata']['siteUrl'],
	'attributes the published version to its public WordPress site'
);
check(
	str_ends_with( $static_requests[1][3], '/sf.jsonc' ),
	'uploads only the file named by the opaque server target'
);
check(
	'public' === ( json_decode( file_get_contents( $static_requests[1][3] ), true )['access'] ?? '' ),
	'uploads the generated public-access declaration with the export'
);
check(
	str_ends_with( $static_requests[3][3], '/assets/app.js' ),
	'uploads the next opaque target after resuming'
);

Spacefast_Sync_State::save(
	array_merge( Spacefast_Sync_State::defaults(), array( 'desired' => 2, 'active_generation' => 1 ) )
);
$static_ack = Spacefast_Sync_State::acknowledge_static(
	Spacefast_Sync_State::get(),
	'ver_static',
	'live'
);
check( 'pending' === $static_ack['last_status'], 'a change during export remains queued for one successor publish' );
check( 1 === $static_ack['delivered'], 'static publishing acknowledges only the generation that was exported' );
check( 'ver_static' === $static_ack['last_version_id'], 'keeps the last static version id' );

$fallback_static_ack = Spacefast_Sync_State::acknowledge_static(
	array_merge(
		Spacefast_Sync_State::defaults(),
		array( 'desired' => 4, 'delivered' => 3, 'active_generation' => 0 )
	),
	'ver_without_start_hook',
	'live'
);
check( 4 === $fallback_static_ack['delivered'], 'static completion falls back to the desired generation when the exporter start hook is absent' );
check( 'live' === $fallback_static_ack['last_status'], 'a missing exporter start hook cannot create a publish loop' );

eval(
	'namespace Simply_Static {
		class Task { public function save_status_message($message) {} }
		class Options {
			private static $instance;
			public static function instance() { return self::$instance ??= new self(); }
			public function set($key, $value) { return $this; }
			public function save() { return true; }
			public function get($key) { return $GLOBALS["spacefast_simply_options"][$key] ?? null; }
			public function get_archive_dir() { return $GLOBALS["spacefast_simply_archive"]; }
		}
		class Plugin {
			public static $runs = 0;
			private static $instance;
			public static function instance() { return self::$instance ??= new self(); }
			public function run_static_export() { self::$runs++; return true; }
		}
	}'
);
$static_connection = array_merge(
	$connection,
	array(
		'mode' => Spacefast_Settings::MODE_STATIC,
		'scope' => 'teams:read spaces:read spaces:write spaces:publish offline_access',
	)
);
Spacefast_Settings::merge( $static_connection );
$deliver_static = new ReflectionMethod( Spacefast_Plugin::class, 'deliver_static' );
$stale_export = array_merge(
	Spacefast_Sync_State::defaults(),
	array(
		'desired' => 1,
		'last_status' => 'exporting',
		'last_attempt_at' => time() - Spacefast_Plugin::STATIC_DELIVERY_STALE_SECONDS - 1,
	)
);
Spacefast_Sync_State::save( $stale_export );
$deliver_static->invoke( null, $stale_export );
check( 1 === \Simply_Static\Plugin::$runs, 'an abandoned static export is restarted after the bounded stale window' );
check( 'exporting' === Spacefast_Sync_State::get()['last_status'], 'a recovered static export returns to an active state' );
check( time() - 2 <= Spacefast_Sync_State::get()['last_attempt_at'], 'a recovered static export records a fresh heartbeat' );

\Simply_Static\Plugin::$runs = 0;
$GLOBALS['spacefast_scheduled'] = array();
$recent_export = array_merge(
	$stale_export,
	array( 'last_attempt_at' => time() )
);
Spacefast_Sync_State::save( $recent_export );
$deliver_static->invoke( null, $recent_export );
check( 0 === \Simply_Static\Plugin::$runs, 'a recent active export is not started twice' );
check( false !== wp_next_scheduled( Spacefast_Sync_State::HOOK ), 'an active export retains a stale-recovery watchdog' );
Spacefast_Plugin::static_publish_progress( 'uploading' );
check( 'uploading' === Spacefast_Sync_State::get()['last_status'], 'static upload progress refreshes the delivery heartbeat' );

Spacefast_Static_Publisher::reset();
$retry_requests = array();
$retry_uploads = 0;
$retry_target = array(
	'path' => 'index.html',
	'method' => 'PUT',
	'url' => 'https://uploads.example.test/retry',
	'headers' => array(),
);
$retry_client = new Spacefast_Client(
	static function ( string $url, array $args ) use ( &$retry_requests, $retry_target ): array {
		$retry_requests[] = array( $url, $args['method'] );
		if ( 'GET' === $args['method'] ) {
			return response( 200, array( 'id' => 'ver_retry', 'status' => 'ready', 'isCurrentProduction' => true ) );
		}
		if ( str_contains( $url, '/uploads/resume' ) ) {
			return response( 200, array( 'upload' => null ) );
		}
		return response(
			201,
			array(
				'versionId' => 'ver_retry',
				'upload' => array(
					'summary' => array( 'upload' => 1, 'reused' => 0, 'ignored' => 0 ),
					'targets' => array( $retry_target ),
				),
			)
		);
	},
	static function () use ( &$retry_uploads ): array {
		$retry_uploads++;
		return response( 1 === $retry_uploads ? 503 : 204 );
	}
);
$retry_step = Spacefast_Static_Publisher::step( $archive, $retry_client );
check( false === $retry_step['done'] && 0 === $retry_step['uploaded'], 'a transient upload failure keeps the publish running' );
check( 'ver_retry' === $retry_step['version_id'], 'a transient upload failure keeps the same Spacefast version' );
$retry_state = get_option( Spacefast_Static_Publisher::OPTION, array() );
check( $retry_state['retry_at'] > time(), 'a transient failure schedules its retry instead of firing it at once' );
$retry_step = Spacefast_Static_Publisher::step( $archive, $retry_client );
check( 1 === $retry_uploads && 0 === $retry_step['uploaded'], 'a step inside the retry wait sends nothing' );
skip_publish_retry_wait();
$retry_step = Spacefast_Static_Publisher::step( $archive, $retry_client );
check( 1 === $retry_step['uploaded'] && 2 === $retry_uploads, 'the next step retries the same upload target' );
$retry_step = Spacefast_Static_Publisher::step( $archive, $retry_client );
check( 'finalizing' === $retry_step['status'], 'a finished upload waits for activation' );
$retry_step = Spacefast_Static_Publisher::step( $archive, $retry_client );
check( true === $retry_step['done'] && 'live' === $retry_step['status'], 'the retried publish goes live' );
$retry_creates = array_filter(
	$retry_requests,
	static fn ( array $request ): bool => 'POST' === $request[1] && str_ends_with( $request[0], '/versions' )
);
$retry_confirms = array_filter(
	$retry_requests,
	static fn ( array $request ): bool => str_contains( $request[0], '/versions/ver_retry/uploads/resume' )
);
$retry_finalizes = array_filter(
	$retry_requests,
	static fn ( array $request ): bool => str_contains( $request[0], '/finalize' )
);
check( 1 === count( $retry_creates ), 'a retried publish never declares a second version' );
check( 1 === count( $retry_confirms ), 'a finished upload confirms the missing set once before waiting' );
check( 0 === count( $retry_finalizes ), 'the plugin never calls finalize, which its scopes do not grant' );

Spacefast_Static_Publisher::reset();
$expired_uploads = 0;
$expired_resumes = 0;
$expired_client = new Spacefast_Client(
	static function ( string $url, array $args ) use ( &$expired_resumes, $retry_target ): array {
		if ( 'GET' === $args['method'] ) {
			return response( 200, array( 'id' => 'ver_expired', 'status' => 'ready', 'isCurrentProduction' => true ) );
		}
		$upload = array(
			'summary' => array( 'upload' => 1, 'reused' => 0, 'ignored' => 0 ),
			'targets' => array( $retry_target ),
		);
		if ( str_contains( $url, '/uploads/resume' ) ) {
			$expired_resumes++;
			if ( 1 === $expired_resumes ) return response( 503 );
			return response( 200, array( 'upload' => 2 === $expired_resumes ? $upload : null ) );
		}
		return response( 201, array( 'versionId' => 'ver_expired', 'upload' => $upload ) );
	},
	static function () use ( &$expired_uploads ): array {
		$expired_uploads++;
		return response( 1 === $expired_uploads ? 401 : 204 );
	}
);
$expired_step = Spacefast_Static_Publisher::step( $archive, $expired_client );
check( false === $expired_step['done'] && 1 === $expired_resumes, 'an expired upload session asks for fresh targets' );
skip_publish_retry_wait();
$expired_step = Spacefast_Static_Publisher::step( $archive, $expired_client );
check( 2 === $expired_resumes && 1 === $expired_uploads, 'a failed refresh is retried before any stale target is sent again' );
$expired_step = Spacefast_Static_Publisher::step( $archive, $expired_client );
check( 2 === $expired_uploads && 1 === $expired_step['uploaded'], 'the refreshed target uploads' );

Spacefast_Static_Publisher::reset();
$missing_uploads = 0;
$missing_resumes = 0;
$missing_client = new Spacefast_Client(
	static function ( string $url, array $args ) use ( &$missing_resumes, $retry_target ): array {
		if ( 'GET' === $args['method'] ) {
			return response( 200, array( 'id' => 'ver_missing', 'status' => 'ready', 'isCurrentProduction' => true ) );
		}
		$upload = array(
			'summary' => array( 'upload' => 1, 'reused' => 0, 'ignored' => 0 ),
			'targets' => array( $retry_target ),
		);
		if ( str_contains( $url, '/uploads/resume' ) ) {
			$missing_resumes++;
			return response( 200, array( 'upload' => 1 === $missing_resumes ? $upload : null ) );
		}
		return response( 201, array( 'versionId' => 'ver_missing', 'upload' => $upload ) );
	},
	static function () use ( &$missing_uploads ): array {
		$missing_uploads++;
		return response( 204 );
	}
);
for ( $i = 0; $i < 6; $i++ ) {
	$missing_step = Spacefast_Static_Publisher::step( $archive, $missing_client );
	if ( $missing_step['done'] ) break;
}
check( true === $missing_step['done'] && 2 === $missing_uploads, 'a file the server still lacks is uploaded again before waiting' );
check( 2 === $missing_resumes, 'the completion check runs again after the missing file lands' );

Spacefast_Static_Publisher::reset();
$stuck_client = new Spacefast_Client(
	static function ( string $url, array $args ) use ( $retry_target ): array {
		$upload = array(
			'summary' => array( 'upload' => 1, 'reused' => 0, 'ignored' => 0 ),
			'targets' => array( $retry_target ),
		);
		if ( str_contains( $url, '/uploads/resume' ) ) {
			return response( 200, array( 'upload' => $upload ) );
		}
		return response( 201, array( 'versionId' => 'ver_stuck', 'upload' => $upload ) );
	},
	static fn (): array => response( 204 )
);
$stuck = false;
try {
	for ( $i = 0; $i < 20; $i++ ) {
		Spacefast_Static_Publisher::step( $archive, $stuck_client );
	}
} catch ( RuntimeException $error ) {
	$stuck = 'Spacefast kept reporting uploaded files as missing.' === $error->getMessage();
}
check( $stuck, 'a file the runtime never records ends the publish after bounded upload rounds' );

Spacefast_Static_Publisher::reset();
$rejecting_client = new Spacefast_Client(
	static function ( string $url, array $args ) use ( $retry_target ): array {
		return response(
			201,
			array(
				'versionId' => 'ver_rejected',
				'upload' => array(
					'summary' => array( 'upload' => 1, 'reused' => 0, 'ignored' => 0 ),
					'targets' => array( $retry_target ),
				),
			)
		);
	},
	static fn (): array => response( 400 )
);
$rejected = false;
try {
	Spacefast_Static_Publisher::step( $archive, $rejecting_client );
} catch ( RuntimeException $error ) {
	$rejected = 'Spacefast rejected a generated file upload.' === $error->getMessage();
}
check( $rejected, 'a rejected upload still ends the publish' );
Spacefast_Static_Publisher::reset();

// Staging keeps the legacy live payload identical and pins the channel while resuming.
Spacefast_Settings::merge( array_merge( $static_connection, array( 'scope' => 'teams:read spaces:read spaces:write publish:write offline_access', 'review_before_live' => true ) ) );
$version_requests = array();
$version_failure = false;
$version_live = false;
$version_client = new Spacefast_Client(
	static function ( string $url, array $args ) use ( &$version_requests, &$version_failure, &$version_live ): array {
		$version_requests[] = array( $url, $args );
		if ( $version_failure ) return response( 403, array() );
		if ( 'DELETE' === $args['method'] ) return array( 'response' => array( 'code' => 204 ), 'body' => '' );
		if ( str_ends_with( $url, '/preview-session' ) ) return response( 200, array( 'url' => 'https://preview.example.test/?proof=private', 'expiresAt' => '2026-11-16T20:10:00Z' ) );
		if ( str_ends_with( $url, '/promote' ) ) return response( 200, array( 'id' => 'ver_promoted', 'status' => 'ready', 'isCurrentProduction' => true ) );
		if ( str_ends_with( $url, '/versions' ) ) return response( 201, array( 'versionId' => 'ver_staged', 'upload' => null ) );
		if ( str_contains( $url, '/versions?' ) ) return response( 200, array() );
		return response( 200, array( 'id' => 'ver_staged', 'status' => 'ready', 'isCurrentProduction' => $version_live ) );
	}
);
$version_client->create_static_version( $manifest, 'event-legacy', 'snapshot' );
check( $version_requests[0][1]['body'] === $static_requests[0][1]['body'], 'toggle off preserves the legacy create request byte for byte' );
$version_requests = array();
Spacefast_Static_Publisher::step( $archive, $version_client, 'additive', null );
$staged_body = json_decode( $version_requests[0][1]['body'], true );
check( array_key_exists( 'channel', $staged_body['finalize'] ) && null === $staged_body['finalize']['channel'], 'staging explicitly serializes channel as null' );
check( 'additive' === $staged_body['publishMode'], 'partial staging preserves omitted files with additive publishing' );
Spacefast_Settings::merge( array( 'review_before_live' => false ) );
$staged_done = Spacefast_Static_Publisher::step( $archive, $version_client, 'additive', 'live' );
check( $staged_done['done'] && 'staged' === $staged_done['status'], 'changing the toggle during upload cannot promote the staged version' );
check( 1 === count( array_filter( $version_requests, static fn( array $r ): bool => str_ends_with( $r[0], '/versions' ) ) ), 'resuming staging does not create another version' );
Spacefast_Sync_State::save( array_merge( Spacefast_Sync_State::defaults(), array( 'desired' => 2, 'active_generation' => 1, 'active_source' => 'auto' ) ) );
Spacefast_Plugin::static_publish_completed( 'ver_staged', 'staged', 'additive' );
$staged_state = Spacefast_Sync_State::get();
check( 'ver_staged' === $staged_state['staged_version_id'] && 'additive' === $staged_state['staged_publish_mode'], 'completion retains staged receipt and publish mode' );
check( 'auto' === $staged_state['version_sources']['ver_staged'], 'history remembers automatic exports without changing the legacy payload' );
check( 1 === $staged_state['delivered'] && 'pending' === $staged_state['last_status'], 'staging acknowledges only the exported generation' );
$action = new ReflectionMethod( Spacefast_Plugin::class, 'version_action' );
$GLOBALS['spacefast_test_caps'] = array( 'edit_others_posts' );
check( ! Spacefast_Plugin::can_stage(), 'editors cannot directly publish when review is off' );
Spacefast_Settings::merge( array( 'review_before_live' => true ) );
check( Spacefast_Plugin::can_stage() && ! Spacefast_Plugin::can_promote(), 'editors can stage while promotion defaults to administrators' );
foreach ( array( 'go_live', 'rollback', 'discard' ) as $verb ) {
	$_POST = array( 'version_id' => 'ver_staged', '_wpnonce' => 'spacefast_wordpress_' . $verb );
	$before = count( $version_requests );
	$denied = false;
	try { $action->invoke( null, $verb, $version_client ); } catch ( RuntimeException $error ) { $denied = true; }
	check( $denied && $before === count( $version_requests ), 'editor cannot ' . $verb . ' through a forged form' );
}
$GLOBALS['spacefast_test_caps'] = array( 'manage_options' );
$GLOBALS['spacefast_test_filters']['spacefast_wordpress_can_promote'] = static fn( bool $allowed ): bool => false;
check( ! Spacefast_Plugin::can_promote(), 'the promotion capability filter can restrict administrators' );
$denied = false;
try { $action->invoke( null, 'discard', $version_client ); } catch ( RuntimeException $error ) { $denied = true; }
check( $denied, 'handlers enforce the promotion capability filter' );
unset( $GLOBALS['spacefast_test_filters']['spacefast_wordpress_can_promote'] );
foreach ( array( 'go_live', 'rollback', 'discard', 'preview' ) as $verb ) {
	$_POST = array( 'version_id' => 'ver_staged', '_wpnonce' => 'wrong-action' );
	$before = count( $version_requests );
	$denied = false;
	try { $action->invoke( null, $verb, $version_client ); } catch ( RuntimeException $error ) { $denied = 'Invalid nonce' === $error->getMessage(); }
	check( $denied && $before === count( $version_requests ), $verb . ' requires its own valid nonce before HTTP' );
}
$_POST = array( 'version_id' => 'ver_staged', '_wpnonce' => 'spacefast_wordpress_go_live' );
Spacefast_Settings::merge( array( 'scope' => 'spaces:publish' ) );
$before = count( $version_requests );
check( ! $action->invoke( null, 'go_live', $version_client )['ok'] && $before === count( $version_requests ), 'old grants require reconnection before promotion' );
Spacefast_Settings::merge( array( 'scope' => 'publish:write spaces:write' ) );
$_POST['version_id'] = 'ver_other';
check( ! $action->invoke( null, 'go_live', $version_client )['ok'], 'stale go-live forms cannot promote a different staged version' );
$_POST['version_id'] = '../live';
check( ! $action->invoke( null, 'go_live', $version_client )['ok'], 'invalid version ids are rejected before transport' );
$_POST = array( 'version_id' => 'ver_staged', '_wpnonce' => 'spacefast_wordpress_preview' );
$GLOBALS['spacefast_test_caps'] = array( 'edit_others_posts' );
$preview = $action->invoke( null, 'preview', $version_client );
check( $preview['ok'] && str_ends_with( end( $version_requests )[0], '/ver_staged/preview-session' ), 'editors can request a version-specific preview session' );
check( '{"landingPath":"/"}' === end( $version_requests )[1]['body'], 'preview opens the exported root' );
$GLOBALS['spacefast_test_caps'] = array( 'manage_options' );
$_POST['_wpnonce'] = 'spacefast_wordpress_go_live';
$version_failure = true;
check( ! $action->invoke( null, 'go_live', $version_client )['ok'] && $staged_state === Spacefast_Sync_State::get(), 'failed actions leave the staged and pending state intact' );
$version_failure = false;
$before = Spacefast_Sync_State::get();
foreach ( array( 403, 500, 200 ) as $status_code ) {
	$failed_promote = new Spacefast_Client( static function ( string $url ) use ( $status_code ): array {
		return str_ends_with( $url, '/promote' )
			? response( $status_code, array( 'id' => 'ver_waiting', 'status' => 'activating', 'isCurrentProduction' => false ) )
			: response( 200, array( 'id' => 'ver_staged', 'status' => 'ready', 'isCurrentProduction' => false ) );
	} );
	check( ! $action->invoke( null, 'go_live', $failed_promote )['ok'] && $before === Spacefast_Sync_State::get(), 'unconfirmed or failed promotions cannot claim success or clear staging' );
}
$promoted = $action->invoke( null, 'go_live', $version_client );
check( $promoted['ok'] && str_ends_with( end( $version_requests )[0], '/ver_staged/promote' ), 'go live promotes the staged version' );
check( '{"channel":"live"}' === end( $version_requests )[1]['body'], 'promotion retains the server default refreshRewrites behavior' );
check( 'ver_promoted' === Spacefast_Sync_State::get()['last_version_id'], 'promotion records the returned id, which may be a new rollback version' );
check( '' === Spacefast_Sync_State::get()['staged_version_id'] && 1 === Spacefast_Sync_State::get()['delivered'], 'promotion clears only its staged card and preserves queued edits' );
Spacefast_Sync_State::save( $staged_state );
$_POST = array( 'version_id' => 'ver_older', '_wpnonce' => 'spacefast_wordpress_rollback' );
$rolled_back = $action->invoke( null, 'rollback', $version_client );
check( $rolled_back['ok'] && str_ends_with( end( $version_requests )[0], '/ver_older/promote' ), 'rollback promotes the selected old id' );
check( 'ver_staged' === Spacefast_Sync_State::get()['staged_version_id'], 'rollback does not discard a separate staged review' );

// Spacefast can require a person to approve a promotion before it runs.
$approval_requests = array();
$approval_state = 'required';
$approval_client = new Spacefast_Client(
	static function ( string $url, array $args ) use ( &$approval_requests, &$approval_state ): array {
		if ( ! str_ends_with( $url, '/promote' ) ) return response( 200, array( 'id' => 'ver_older', 'status' => 'ready', 'isCurrentProduction' => false ) );
		$approval_requests[] = $args['headers'];
		if ( ! isset( $args['headers']['X-Spacefast-Continuation-Token'] ) ) {
			return array( 'response' => array( 'code' => 409 ), 'body' => json_encode( array(
				'code' => 'confirmation_required',
				'detail' => 'A person must approve this exact action at url before the agent can continue.',
				'details' => array( 'url' => 'https://my.spacefast.test/approvals/gdc_one', 'continuationToken' => 'secret-continuation', 'expiresAt' => gmdate( 'c', time() + 300 ) ),
			) ) );
		}
		if ( 'required' === $approval_state ) return array( 'response' => array( 'code' => 409 ), 'body' => json_encode( array( 'code' => 'confirmation_pending', 'detail' => 'Still waiting.' ) ) );
		return response( 200, array( 'id' => 'ver_rollback', 'status' => 'ready', 'isCurrentProduction' => true ) );
	}
);
$finish = new ReflectionMethod( Spacefast_Plugin::class, 'finish_approval_action' );
$_POST = array( 'version_id' => 'ver_older', '_wpnonce' => 'spacefast_wordpress_rollback' );
$asked = $action->invoke( null, 'rollback', $approval_client );
$pending = Spacefast_Plugin::pending_approval();
check( ! $asked['ok'] && 'approval_required' === $asked['code'] && str_contains( $asked['message'], 'Approve it in Spacefast' ), 'an approval-gated rollback asks for approval in plain words' );
check( ! str_contains( $asked['message'], 'agent' ) && ! str_contains( $asked['message'], 'secret-continuation' ), 'the approval notice hides the API agent text and the token' );
check( is_array( $pending ) && 'rollback' === $pending['action'] && 'ver_older' === $pending['version_id'] && 'https://my.spacefast.test/approvals/gdc_one' === $pending['url'], 'the pending approval is remembered with its link' );
$_POST = array( '_wpnonce' => 'spacefast_wordpress_finish_approval' );
$early = $finish->invoke( null, $approval_client );
check( ! $early['ok'] && 'approval_pending' === $early['code'] && null !== Spacefast_Plugin::pending_approval(), 'finishing before approval keeps the request open' );
$approval_state = 'approved';
$finished = $finish->invoke( null, $approval_client );
$last = end( $approval_requests );
check( $finished['ok'] && 'ver_rollback' === Spacefast_Sync_State::get()['last_version_id'], 'finishing an approved rollback makes it live' );
check( 'secret-continuation' === $last['X-Spacefast-Continuation-Token'] && $approval_requests[0]['Idempotency-Key'] === $last['Idempotency-Key'], 'the approved request is resent with its token and original idempotency key' );
check( null === Spacefast_Plugin::pending_approval(), 'a finished approval is cleared' );
update_option( Spacefast_Plugin::APPROVAL_OPTION, array_merge( $pending, array( 'expires_at' => time() - 1 ) ) );
check( null === Spacefast_Plugin::pending_approval() && false === get_option( Spacefast_Plugin::APPROVAL_OPTION, false ), 'an expired approval is dropped' );
$open_client = new Spacefast_Client(
	static fn( string $url ): array => str_ends_with( $url, '/promote' )
		? array( 'response' => array( 'code' => 409 ), 'body' => json_encode( array( 'code' => 'confirmation_token_required', 'detail' => 'Send this request again with the continuation token in the X-Spacefast-Continuation-Token header.', 'details' => array( 'approvalId' => 'gdc_one' ) ) ) )
		: response( 200, array( 'id' => 'ver_older', 'status' => 'ready', 'isCurrentProduction' => false ) )
);
$_POST = array( 'version_id' => 'ver_older', '_wpnonce' => 'spacefast_wordpress_rollback' );
$reopened = $action->invoke( null, 'rollback', $open_client );
check( ! $reopened['ok'] && 'approval_open' === $reopened['code'] && ! str_contains( $reopened['message'], 'X-Spacefast' ), 'a repeat of an action with an open approval explains itself without API header jargon' );
Spacefast_Sync_State::save( $staged_state );
$_POST = array( 'version_id' => 'ver_staged', '_wpnonce' => 'spacefast_wordpress_discard' );
$version_live = true;
$before = count( $version_requests );
check( ! $action->invoke( null, 'discard', $version_client )['ok'] && $before + 1 === count( $version_requests ), 'discard refuses a version that another publisher made live' );
$version_live = false;
check( $action->invoke( null, 'discard', $version_client )['ok'], 'discard accepts the API empty 204 response' );
check( 'DELETE' === end( $version_requests )[1]['method'] && '' === Spacefast_Sync_State::get()['staged_version_id'], 'discard deletes only the staged version and clears its card' );
$version_client->list_versions( 100 );
check( str_ends_with( end( $version_requests )[0], '/versions?limit=20' ), 'history is bounded to the most recent 20 versions' );
$_POST = array();
// Exercise the actual Simply Static task for both automatic and partial staging.
require_once dirname( __DIR__ ) . '/includes/class-spacefast-simply-static-task.php';
$GLOBALS['spacefast_simply_archive'] = $archive;
$task_requests = array();
$task_live = false;
$GLOBALS['spacefast_remote_handler'] = static function ( string $url, array $args ) use ( &$task_requests, &$task_live ): array {
	$task_requests[] = array( $url, $args );
	if ( str_ends_with( $url, '/versions' ) ) return response( 201, array( 'versionId' => 'ver_task', 'upload' => null ) );
	return response( 200, array( 'id' => 'ver_task', 'status' => 'ready', 'isCurrentProduction' => $task_live ) );
};
foreach ( array( 'export' => 'snapshot', 'update' => 'additive' ) as $generate_type => $expected_mode ) {
	$GLOBALS['spacefast_simply_options'] = array( 'generate_type' => $generate_type );
	Spacefast_Static_Publisher::reset();
	Spacefast_Sync_State::save( array_merge( Spacefast_Sync_State::defaults(), array( 'active_review' => true, 'active_source' => 'auto' ) ) );
	Spacefast_Settings::merge( array( 'review_before_live' => false ) );
	if ( 'export' === $generate_type ) update_option( 'spacefast_wordpress_snapshot_required', true );
	else delete_option( 'spacefast_wordpress_snapshot_required' );
	$task_requests = array();
	$task = new Spacefast_Simply_Static_Publish_Task();
	check( false === $task->perform() && true === $task->perform(), 'the delivery task completes staged ' . $generate_type . ' exports' );
	$body = json_decode( $task_requests[0][1]['body'], true );
	check( null === $body['finalize']['channel'] && $expected_mode === $body['publishMode'], 'delivery carries the pinned review flag and mode for ' . $generate_type );
	check( 'ver_task' === Spacefast_Sync_State::get()['staged_version_id'], 'the delivery task records the staged version' );
	if ( 'export' === $generate_type ) {
		check( true === get_option( 'spacefast_wordpress_snapshot_required' ), 'a staged snapshot preserves the deletion guard until it goes live' );
		$_POST = array( 'version_id' => 'ver_task', '_wpnonce' => 'spacefast_wordpress_go_live' );
		check( $action->invoke( null, 'go_live', $version_client )['ok'], 'a complete staged snapshot can be promoted' );
		check( false === get_option( 'spacefast_wordpress_snapshot_required', false ), 'promoting the full snapshot releases the deletion guard' );
		$_POST = array();
	}
}
$task_live = true;
$task_requests = array();
$GLOBALS['spacefast_simply_options'] = array( 'generate_type' => 'export' );
Spacefast_Sync_State::save( Spacefast_Sync_State::defaults() );
check( false === $task->perform() && true === $task->perform(), 'the delivery task still publishes live with the toggle off' );
check( $task_requests[0][1]['body'] === $static_requests[0][1]['body'], 'toggle-off delivery keeps the legacy request body byte for byte' );

// Render the same version cards exercised by WordPress acceptance, with an injected transport.
Spacefast_Sync_State::save( $staged_state );
Spacefast_Settings::merge( array( 'review_before_live' => true ) );
$GLOBALS['spacefast_remote_handler'] = static fn(): array => response( 200, array(
	array( 'id' => 'ver_staged', 'status' => 'ready', 'fileCount' => 5, 'filesAddedCount' => 1, 'filesChangedCount' => 2, 'filesRemovedCount' => 3 ),
	array( 'id' => 'ver_live', 'status' => 'ready', 'fileCount' => 4, 'isCurrentProduction' => true ),
	array( 'id' => 'ver_old', 'status' => 'ready', 'fileCount' => 3 ),
) );
$render_versions = new ReflectionMethod( Spacefast_Plugin::class, 'render_versions' );
ob_start();
$render_versions->invoke( null );
$markup = (string) ob_get_clean();
foreach ( array( 'Staged version', '1 files added, 2 changed, 3 removed.', 'Open preview', 'Copy preview link', 'Go live', 'Discard', 'Versions', 'Auto', 'Live', 'Make live' ) as $label ) {
	check( str_contains( $markup, $label ), 'the version cards render ' . $label );
}
check( str_contains( $markup, 'value="ver_old"' ) && str_contains( $markup, 'spacefast_wordpress_rollback' ), 'history forms name the selected version and action nonce' );
$GLOBALS['spacefast_test_caps'] = array( 'edit_others_posts' );
ob_start();
$render_versions->invoke( null );
$editor_markup = (string) ob_get_clean();
check( str_contains( $editor_markup, 'Open preview' ) && ! str_contains( $editor_markup, '>Go live<' ) && ! str_contains( $editor_markup, '>Make live<' ), 'editor review cards omit promotion controls' );
$GLOBALS['spacefast_test_caps'] = array( 'manage_options' );
$GLOBALS['spacefast_remote_handler'] = null;
Spacefast_Settings::merge( $connection );

unlink( $archive . '/assets/app.js' );
rmdir( $archive . '/assets' );
unlink( $archive . '/index.html' );
unlink( $archive . '/sf.jsonc' );
rmdir( $archive );
unlink( $custom_archive . '/index.html' );
unlink( $custom_archive . '/sf.jsonc' );
rmdir( $custom_archive );
Spacefast_Static_Publisher::reset();

fwrite( STDOUT, "Spacefast WordPress behavior tests: PASS\n" );
