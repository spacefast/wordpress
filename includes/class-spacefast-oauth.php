<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_OAuth {
	const PENDING_OPTION = 'spacefast_wordpress_oauth_pending';
	const CHOICES_OPTION = 'spacefast_wordpress_oauth_choices';
	const CREATION_OPTION = 'spacefast_wordpress_space_creation';
	const RESOURCE_PATH = '/v1';

	/** @var callable */
	private $transport;

	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport ?? 'wp_safe_remote_request';
	}

	/** @return array<int,string> */
	public static function scopes( string $mode ): array {
		return Spacefast_Settings::MODE_STATIC === $mode
			? array( 'teams:read', 'spaces:read', 'spaces:write', 'spaces:publish', 'offline_access' )
			: array( 'teams:read', 'spaces:read', 'spaces:write', 'builds:trigger', 'offline_access' );
	}

	public static function has_scope( string $scope ): bool {
		$granted = preg_split( '/\s+/', trim( (string) Spacefast_Settings::get()['scope'] ) );
		return is_array( $granted ) && in_array( $scope, $granted, true );
	}

	/** @param array<string,mixed> $space Space returned by Spacefast. */
	public static function remember_space_choice( array $space ): void {
		$choices = get_option( self::CHOICES_OPTION, array() );
		$choices = is_array( $choices ) ? $choices : array();
		$spaces = is_array( $choices['spaces'] ?? null ) ? array_values( $choices['spaces'] ) : array();
		$space_id = (string) ( $space['id'] ?? '' );
		if ( ! preg_match( '/^spc_[A-Za-z0-9_-]+$/', $space_id ) ) return;
		$spaces = array_values(
			array_filter(
				$spaces,
				static fn( $candidate ): bool => ! is_array( $candidate ) || ! hash_equals( (string) ( $candidate['id'] ?? '' ), $space_id )
			)
		);
		$spaces[] = $space;
		$choices['spaces'] = $spaces;
		update_option( self::CHOICES_OPTION, $choices, false );
	}

	public static function callback_url(): string {
		return add_query_arg(
			array( 'page' => 'spacefast-wordpress', 'spacefast_oauth' => 'callback' ),
			admin_url( 'options-general.php' )
		);
	}

	public static function pkce_challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	public static function client_name(): string {
		$parts = wp_parse_url( home_url() );
		$host = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
		return '' === $host
			? 'Spacefast for WordPress'
			: 'Spacefast for WordPress (' . substr( $host, 0, 120 ) . ')';
	}

	/** @return array{ok:bool,message:string,url?:string} */
	public function begin( string $mode ): array {
		if ( ! in_array( $mode, Spacefast_Settings::modes(), true ) ) {
			return array( 'ok' => false, 'message' => 'Choose a publishing mode.' );
		}
		$api_url = Spacefast_Settings::api_url();
		$callback = self::callback_url();
		$scopes = implode( ' ', self::scopes( $mode ) );
		$resource = $api_url . self::RESOURCE_PATH;
		$current = Spacefast_Settings::get();
		$registration = $this->json_request(
			'POST',
			$api_url . '/v1/auth/oauth2/register',
			array(
				'client_name' => self::client_name(),
				'client_uri' => 'https://github.com/spacefast/wordpress',
				'redirect_uris' => array( $callback ),
				'grant_types' => array( 'authorization_code', 'refresh_token' ),
				'response_types' => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
				'scope' => $scopes,
				'resources' => array( $resource ),
			)
		);
		if ( ! $registration['ok'] || empty( $registration['data']['client_id'] ) ) {
			return array( 'ok' => false, 'message' => $registration['message'] ?: 'Spacefast could not start authorization.' );
		}
		$client_id = (string) $registration['data']['client_id'];
		$state = wp_generate_password( 48, false, false );
		$verifier = wp_generate_password( 96, false, false );
		update_option(
			self::PENDING_OPTION,
			array(
				'state' => $state,
				'verifier' => $verifier,
				'client_id' => $client_id,
				'mode' => $mode,
				'callback' => $callback,
				'resume_connection' => Spacefast_Settings::configured() && $mode === $current['mode']
					? array(
						'team_id' => $current['team_id'],
						'team_name' => $current['team_name'],
						'team_slug' => $current['team_slug'],
						'space_id' => $current['space_id'],
						'space_name' => $current['space_name'],
						'space_slug' => $current['space_slug'],
						'live_url' => $current['live_url'],
						'verified_at' => $current['verified_at'],
					)
					: null,
				'expires_at' => time() + 10 * MINUTE_IN_SECONDS,
			),
			false
		);
		$query = array(
			'response_type' => 'code',
			'client_id' => $client_id,
			'redirect_uri' => $callback,
			'scope' => $scopes,
			'state' => $state,
			'code_challenge' => self::pkce_challenge( $verifier ),
			'code_challenge_method' => 'S256',
			'resource' => $resource,
		);
		// add_query_arg() deliberately leaves values unencoded. The callback URL
		// has its own query string, so using it here would turn the callback's
		// second parameter into a top-level authorization parameter.
		$url = $api_url . '/v1/auth/oauth2/authorize?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		return array( 'ok' => true, 'message' => '', 'url' => $url );
	}

	/** @return array{ok:bool,message:string,resumed?:bool} */
	public function finish( string $code, string $state ): array {
		$pending = get_option( self::PENDING_OPTION, array() );
		delete_option( self::PENDING_OPTION );
		if ( ! is_array( $pending ) || time() > (int) ( $pending['expires_at'] ?? 0 ) ) {
			return array( 'ok' => false, 'message' => 'Authorization expired. Start again.' );
		}
		if ( ! hash_equals( (string) ( $pending['state'] ?? '' ), $state ) || '' === $code ) {
			return array( 'ok' => false, 'message' => 'Authorization could not be verified. Start again.' );
		}
		$previous = Spacefast_Settings::get();
		$tokens = $this->form_request(
			Spacefast_Settings::api_url() . '/v1/auth/oauth2/token',
			array(
				'grant_type' => 'authorization_code',
				'client_id' => (string) $pending['client_id'],
				'redirect_uri' => (string) $pending['callback'],
				'code' => $code,
				'code_verifier' => (string) $pending['verifier'],
				'resource' => Spacefast_Settings::api_url() . self::RESOURCE_PATH,
			)
		);
		if ( ! $tokens['ok'] ) return array( 'ok' => false, 'message' => $tokens['message'] );
		$data = $tokens['data'];
		if ( empty( $data['access_token'] ) || empty( $data['refresh_token'] ) ) {
			return array( 'ok' => false, 'message' => 'Spacefast returned an incomplete authorization.' );
		}
		$this->revoke( $previous );
		Spacefast_Settings::merge(
			array(
				'mode' => (string) $pending['mode'],
				'client_id' => (string) $pending['client_id'],
				'access_token' => (string) $data['access_token'],
				'refresh_token' => (string) $data['refresh_token'],
				'expires_at' => time() + max( 60, (int) ( $data['expires_in'] ?? 900 ) ),
				'scope' => (string) ( $data['scope'] ?? '' ),
				'team_id' => '', 'space_id' => '', 'verified_at' => 0,
			)
		);
		$choices_result = $this->load_choices();
		if ( ! $choices_result['ok'] ) return $choices_result;
		$resume = isset( $pending['resume_connection'] ) && is_array( $pending['resume_connection'] )
			? $pending['resume_connection']
			: null;
		$choices = get_option( self::CHOICES_OPTION, array() );
		$spaces = is_array( $choices['spaces'] ?? null ) ? $choices['spaces'] : array();
		$resume_space_id = is_array( $resume ) ? (string) ( $resume['space_id'] ?? '' ) : '';
		$available = false;
		foreach ( $spaces as $space ) {
			if ( is_array( $space ) && '' !== $resume_space_id && hash_equals( (string) ( $space['id'] ?? '' ), $resume_space_id ) ) {
				$available = true;
				break;
			}
		}
		if ( $available && is_array( $resume ) ) {
			Spacefast_Settings::merge( $resume );
			delete_option( self::CHOICES_OPTION );
			return array( 'ok' => true, 'message' => '', 'resumed' => true );
		}
		return array( 'ok' => true, 'message' => '', 'resumed' => false );
	}

	/** @return array{ok:bool,message:string} */
	public function load_choices(): array {
		$client = new Spacefast_Client( $this->transport );
		$teams = $client->list_teams();
		$spaces = $client->list_spaces();
		if ( ! $teams['ok'] ) return array( 'ok' => false, 'message' => $teams['message'] );
		if ( ! $spaces['ok'] ) return array( 'ok' => false, 'message' => $spaces['message'] );
		update_option(
			self::CHOICES_OPTION,
			array( 'teams' => $teams['data'], 'spaces' => $spaces['data'], 'expires_at' => time() + 10 * MINUTE_IN_SECONDS ),
			false
		);
		return array( 'ok' => true, 'message' => '' );
	}

	/** @return array{ok:bool,message:string} */
	public function refresh(): array {
		$settings = Spacefast_Settings::get();
		$result = $this->form_request(
			$settings['api_url'] . '/v1/auth/oauth2/token',
			array(
				'grant_type' => 'refresh_token',
				'client_id' => (string) $settings['client_id'],
				'refresh_token' => (string) $settings['refresh_token'],
				'resource' => $settings['api_url'] . self::RESOURCE_PATH,
			)
		);
		if ( ! $result['ok'] ) return array( 'ok' => false, 'message' => $result['message'] );
		$data = $result['data'];
		if ( empty( $data['access_token'] ) || empty( $data['refresh_token'] ) ) {
			return array( 'ok' => false, 'message' => 'Spacefast returned an incomplete token refresh.' );
		}
		Spacefast_Settings::merge(
			array(
				'access_token' => (string) $data['access_token'],
				'refresh_token' => (string) $data['refresh_token'],
				'expires_at' => time() + max( 60, (int) ( $data['expires_in'] ?? 900 ) ),
				'scope' => (string) ( $data['scope'] ?? $settings['scope'] ),
			)
		);
		return array( 'ok' => true, 'message' => '' );
	}

	public function access_token(): string {
		$settings = Spacefast_Settings::get();
		if ( (int) $settings['expires_at'] <= time() + 60 ) {
			$result = $this->refresh();
			if ( ! $result['ok'] ) return '';
			$settings = Spacefast_Settings::get();
		}
		return (string) $settings['access_token'];
	}

	/** @param array<string,mixed>|null $settings Settings whose tokens should be revoked. */
	public function revoke( ?array $settings = null ): void {
		$settings = $settings ?? Spacefast_Settings::get();
		foreach ( array( $settings['refresh_token'], $settings['access_token'] ) as $token ) {
			if ( ! is_string( $token ) || '' === $token ) continue;
			$this->form_request(
				$settings['api_url'] . '/v1/auth/oauth2/revoke',
				array( 'token' => $token, 'client_id' => (string) $settings['client_id'] )
			);
		}
	}

	/** @return array{ok:bool,message:string,data:array<string,mixed>} */
	private function json_request( string $method, string $url, array $body ): array {
		return $this->response( call_user_func( $this->transport, $url, array(
			'method' => $method, 'timeout' => 15, 'redirection' => 0, 'reject_unsafe_urls' => true,
			'sslverify' => true, 'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( $body ),
		) ) );
	}

	/** @return array{ok:bool,message:string,data:array<string,mixed>} */
	private function form_request( string $url, array $body ): array {
		return $this->response( call_user_func( $this->transport, $url, array(
			'method' => 'POST', 'timeout' => 15, 'redirection' => 0, 'reject_unsafe_urls' => true,
			'sslverify' => true, 'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body' => http_build_query( $body, '', '&', PHP_QUERY_RFC3986 ),
		) ) );
	}

	/** @param mixed $response Response. @return array{ok:bool,message:string,data:array<string,mixed>} */
	private function response( $response ): array {
		if ( is_wp_error( $response ) ) return array( 'ok' => false, 'message' => 'Spacefast could not be reached.', 'data' => array() );
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		if ( 200 <= $status && 300 > $status ) return array( 'ok' => true, 'message' => '', 'data' => $data );
		return array( 'ok' => false, 'message' => (string) ( $data['error_description'] ?? $data['detail'] ?? 'Spacefast rejected the request.' ), 'data' => array() );
	}
}
