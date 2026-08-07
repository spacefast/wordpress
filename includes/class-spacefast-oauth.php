<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_OAuth {
	const PENDING_OPTION = 'spacefast_wordpress_oauth_pending';
	const CHOICES_OPTION = 'spacefast_wordpress_oauth_choices';
	const RESOURCE_PATH = '/v1';

	/** @var callable */
	private $transport;

	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport ?? 'wp_safe_remote_request';
	}

	/** @return array<int,string> */
	public static function scopes( string $mode ): array {
		return array(
			'teams:read',
			'spaces:read',
			Spacefast_Settings::MODE_STATIC === $mode ? 'spaces:publish' : 'builds:trigger',
			'offline_access',
		);
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

	/** @return array{ok:bool,message:string,url?:string} */
	public function begin( string $mode ): array {
		if ( ! in_array( $mode, Spacefast_Settings::modes(), true ) ) {
			return array( 'ok' => false, 'message' => 'Choose a publishing mode.' );
		}
		$api_url = Spacefast_Settings::api_url();
		$callback = self::callback_url();
		$scopes = implode( ' ', self::scopes( $mode ) );
		$resource = $api_url . self::RESOURCE_PATH;
		$registration = $this->json_request(
			'POST',
			$api_url . '/v1/auth/oauth2/register',
			array(
				'client_name' => 'Spacefast for WordPress',
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
		return array( 'ok' => true, 'message' => '', 'url' => add_query_arg( $query, $api_url . '/v1/auth/oauth2/authorize' ) );
	}

	/** @return array{ok:bool,message:string} */
	public function finish( string $code, string $state ): array {
		$pending = get_option( self::PENDING_OPTION, array() );
		delete_option( self::PENDING_OPTION );
		if ( ! is_array( $pending ) || time() > (int) ( $pending['expires_at'] ?? 0 ) ) {
			return array( 'ok' => false, 'message' => 'Authorization expired. Start again.' );
		}
		if ( ! hash_equals( (string) ( $pending['state'] ?? '' ), $state ) || '' === $code ) {
			return array( 'ok' => false, 'message' => 'Authorization could not be verified. Start again.' );
		}
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
		return $this->load_choices();
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

	public function revoke(): void {
		$settings = Spacefast_Settings::get();
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
