<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_Client {
	/** @var callable */
	private $transport;
	/** @var callable|null */
	private $upload_transport;

	public function __construct( ?callable $transport = null, ?callable $upload_transport = null ) {
		$this->transport = $transport ?? 'wp_safe_remote_request';
		$this->upload_transport = $upload_transport;
	}

	/**
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	private function request( string $method, string $path, ?array $body = null, array $headers = array() ): array {
		$settings = Spacefast_Settings::get();
		$access_token = ( new Spacefast_OAuth( $this->transport ) )->access_token();
		if ( '' === $access_token ) {
			return array(
				'ok' => false,
				'retryable' => false,
				'code' => 'reauthorization_required',
				'message' => 'Reconnect Spacefast to continue.',
				'data' => array(),
			);
		}
		$args = array(
			'method' => $method,
			'timeout' => 10,
			'redirection' => 0,
			'reject_unsafe_urls' => true,
			'sslverify' => true,
			'limit_response_size' => 65536,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept' => 'application/json',
					'User-Agent' => 'Spacefast-WordPress/' . SPACEFAST_WORDPRESS_VERSION,
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}

		$response = call_user_func( $this->transport, $settings['api_url'] . $path, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok' => false,
				'retryable' => true,
				'code' => 'network_error',
				'message' => 'Spacefast could not be reached.',
				'data' => array(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		if ( $status >= 200 && $status < 300 ) {
			$data = isset( $decoded['data'] ) && is_array( $decoded['data'] )
				? $decoded['data']
				: $decoded;
			return array(
				'ok' => true,
				'retryable' => false,
				'code' => 'ok',
				'message' => '',
				'data' => $data,
				'pagination' => isset( $decoded['pagination'] ) && is_array( $decoded['pagination'] )
					? $decoded['pagination']
					: array(),
			);
		}

		return array(
			'ok' => false,
			'retryable' => in_array( $status, array( 408, 425, 429 ), true )
				|| $status >= 500
				|| in_array(
					(string) ( $decoded['code'] ?? '' ),
					array( 'repository_not_ready', 'idempotency_conflict_in_progress' ),
					true
				),
			'code' => (string) ( $decoded['code'] ?? 'http_' . $status ),
			'message' => in_array( $status, array( 401, 403 ), true )
				? 'The Spacefast connection is no longer authorized.'
				: (string) ( $decoded['detail'] ?? 'Spacefast rejected the request.' ),
			'data' => array(),
		);
	}

	/** @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>} */
	public function list_teams(): array {
		return $this->list_all( '/v1/teams' );
	}

	/** @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>} */
	public function list_spaces(): array {
		return $this->list_all( '/v1/spaces' );
	}

	/** @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>} */
	public function create_space( string $team_id, string $title, string $idempotency_key ): array {
		return $this->request(
			'POST',
			'/v1/spaces',
			array(
				'teamId' => $team_id,
				'title' => $title,
			),
			array( 'Idempotency-Key' => $idempotency_key )
		);
	}

	/** @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>} */
	public function get_space(): array {
		$settings = Spacefast_Settings::get();
		return $this->request( 'GET', '/v1/spaces/' . rawurlencode( (string) $settings['space_id'] ) );
	}

	/**
	 * @param array<string,mixed> $body Space settings patch.
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	public function update_space( array $body ): array {
		$settings = Spacefast_Settings::get();
		return $this->request(
			'PATCH',
			'/v1/spaces/' . rawurlencode( (string) $settings['space_id'] ),
			$body
		);
	}

	/** @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>} */
	private function list_all( string $path ): array {
		$items = array();
		$cursor = '';
		for ( $page = 0; $page < 100; $page++ ) {
			$query = $path . '?limit=100';
			if ( '' !== $cursor ) {
				$query .= '&cursor=' . rawurlencode( $cursor );
			}
			$result = $this->request( 'GET', $query );
			if ( ! $result['ok'] ) {
				return $result;
			}
			$items = array_merge( $items, array_values( $result['data'] ) );
			$pagination = is_array( $result['pagination'] ?? null ) ? $result['pagination'] : array();
			$next_cursor = (string) ( $pagination['nextCursor'] ?? '' );
			if ( true !== ( $pagination['hasMore'] ?? false ) || '' === $next_cursor ) {
				$result['data'] = $items;
				return $result;
			}
			$cursor = $next_cursor;
		}
		return array(
			'ok' => false,
			'retryable' => false,
			'code' => 'pagination_limit',
			'message' => 'Spacefast returned too many pages while loading choices.',
			'data' => array(),
		);
	}

	/**
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	public function health(): array {
		return Spacefast_Settings::MODE_STATIC === Spacefast_Settings::mode()
			? $this->static_health()
			: $this->repository_health();
	}

	/**
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	public function repository_health(): array {
		$settings = Spacefast_Settings::get();
		$space_id = rawurlencode( $settings['space_id'] );
		$source_url = rawurlencode( untrailingslashit( home_url() ) );
		return $this->request(
			'GET',
			"/v1/spaces/{$space_id}/builds/wordpress?sourceUrl={$source_url}"
		);
	}

	/**
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	public function static_health(): array {
		$settings = Spacefast_Settings::get();
		return $this->request(
			'GET',
			'/v1/spaces/' . rawurlencode( $settings['space_id'] ) . '/versions?limit=1'
		);
	}

	/**
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	public function trigger_build( string $event_id ): array {
		$health = $this->repository_health();
		if ( ! $health['ok'] ) {
			return $health;
		}
		$settings = Spacefast_Settings::get();
		$result = $this->request(
			'POST',
			'/v1/spaces/' . rawurlencode( $settings['space_id'] ) . '/builds/wordpress',
			array(
				'sourceUrl' => untrailingslashit( home_url() ),
			),
			array( 'Idempotency-Key' => $event_id )
		);
		if (
			$result['ok']
			&& (
				! isset( $result['data']['build'] )
				|| ! is_array( $result['data']['build'] )
				|| ! preg_match( '/^bld_[A-Za-z0-9_-]+$/', (string) ( $result['data']['build']['id'] ?? '' ) )
			)
		) {
			return array(
				'ok' => false,
				'retryable' => true,
				'code' => 'invalid_build_receipt',
				'message' => 'Spacefast returned an invalid build receipt.',
				'data' => array(),
			);
		}
		return $result;
	}

	/** @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>} */
	public function get_build( string $build_id ): array {
		if ( ! preg_match( '/^bld_[A-Za-z0-9_-]+$/', $build_id ) ) {
			return array(
				'ok' => false,
				'retryable' => false,
				'code' => 'invalid_build_id',
				'message' => 'Spacefast returned an invalid build receipt.',
				'data' => array(),
			);
		}
		return $this->request( 'GET', '/v1/builds/' . rawurlencode( $build_id ) );
	}

	/**
	 * @param array<int,array{path:string,size:int,sha256:string}> $files Files.
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	public function create_static_version( array $files, string $event_id, string $publish_mode ): array {
		$settings = Spacefast_Settings::get();
		return $this->request(
			'POST',
			'/v1/spaces/' . rawurlencode( $settings['space_id'] ) . '/versions',
			array(
				'publishMode' => $publish_mode,
				'files' => $files,
				'finalize' => array( 'channel' => 'live' ),
				'source' => array(
					'kind' => 'api',
					'client' => 'spacefast-wordpress/' . SPACEFAST_WORDPRESS_VERSION,
					'message' => 'Published from WordPress with Simply Static.',
					'metadata' => array(
						'integration' => 'wordpress',
						'exporter' => 'simply-static',
						'siteUrl' => untrailingslashit( home_url() ),
					),
				),
			),
			array( 'Idempotency-Key' => $event_id )
		);
	}

	/**
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	public function resume_static_upload( string $version_id ): array {
		$settings = Spacefast_Settings::get();
		return $this->request(
			'POST',
			'/v1/spaces/' . rawurlencode( $settings['space_id'] ) . '/versions/'
				. rawurlencode( $version_id ) . '/uploads/resume'
		);
	}

	/**
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	public function get_static_version( string $version_id ): array {
		$settings = Spacefast_Settings::get();
		return $this->request(
			'GET',
			'/v1/spaces/' . rawurlencode( $settings['space_id'] ) . '/versions/'
				. rawurlencode( $version_id )
		);
	}

	/**
	 * @param array<string,mixed> $target Opaque server-issued upload target.
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	public function upload_static_file( array $target, string $file_path ): array {
		$settings = Spacefast_Settings::get();
		$url = (string) ( $target['url'] ?? '' );
		if ( str_starts_with( $url, '/' ) ) {
			$url = $settings['api_url'] . $url;
		}
		$method = (string) ( $target['method'] ?? '' );
		$headers = isset( $target['headers'] ) && is_array( $target['headers'] )
			? $target['headers']
			: array();
		if ( ! in_array( $method, array( 'PUT', 'POST' ), true ) || '' === $url ) {
			return self::upload_error( false, 'invalid_upload_target', 'Spacefast returned an invalid upload target.' );
		}
		if ( ! is_readable( $file_path ) || ! is_file( $file_path ) ) {
			return self::upload_error( false, 'export_file_missing', 'A generated export file is no longer readable.' );
		}

		if ( is_callable( $this->upload_transport ) ) {
			$response = call_user_func( $this->upload_transport, $url, $method, $headers, $file_path );
			return self::upload_response( $response );
		}

		return $this->curl_upload( $url, $method, $headers, $file_path );
	}

	/**
	 * @param mixed $response Upload transport response.
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	private static function upload_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			return self::upload_error( true, 'network_error', 'The generated file could not be uploaded.' );
		}
		$status = is_array( $response )
			? (int) wp_remote_retrieve_response_code( $response )
			: (int) $response;
		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok' => true,
				'retryable' => false,
				'code' => 'ok',
				'message' => '',
				'data' => array(),
			);
		}
		return self::upload_error(
			in_array( $status, array( 408, 425, 429 ), true ) || $status >= 500,
			'upload_http_' . $status,
			in_array( $status, array( 401, 403 ), true )
				? 'The Spacefast upload session expired.'
				: 'Spacefast rejected a generated file upload.'
		);
	}

	/**
	 * @param array<string,mixed> $headers Headers.
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	private function curl_upload( string $url, string $method, array $headers, string $file_path ): array {
		if ( ! function_exists( 'curl_init' ) ) {
			return self::upload_error( false, 'curl_required', 'Spacefast static publishing requires the PHP cURL extension.' );
		}
		$parts = wp_parse_url( $url );
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		$host = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		$validated = wp_http_validate_url( $url );
		$development = str_ends_with( $host, '.sf.localhost' ) && 'http' === $scheme;
		if ( ( 'https' !== $scheme && ! $development ) || ( ! $validated && ! $development ) ) {
			return self::upload_error( false, 'unsafe_upload_url', 'Spacefast returned an unsafe upload URL.' );
		}
		$size = filesize( $file_path );
		$stream = fopen( $file_path, 'rb' );
		if ( false === $size || false === $stream ) {
			return self::upload_error( false, 'export_file_missing', 'A generated export file is no longer readable.' );
		}
		$curl_headers = array();
		foreach ( $headers as $name => $value ) {
			if ( preg_match( '/[\r\n]/', (string) $name . (string) $value ) ) {
				fclose( $stream );
				return self::upload_error( false, 'invalid_upload_target', 'Spacefast returned invalid upload headers.' );
			}
			$curl_headers[] = (string) $name . ': ' . (string) $value;
		}
		$curl_headers[] = 'Content-Length: ' . $size;
		$handle = curl_init( $url );
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_UPLOAD => true,
				CURLOPT_INFILE => $stream,
				CURLOPT_INFILESIZE => $size,
				CURLOPT_HTTPHEADER => $curl_headers,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_PROTOCOLS => $development
					? CURLPROTO_HTTP | CURLPROTO_HTTPS
					: CURLPROTO_HTTPS,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT => 120,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
			),
		);
		$result = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error = curl_error( $handle );
		curl_close( $handle );
		fclose( $stream );
		if ( false === $result ) {
			unset( $error );
			return self::upload_error( true, 'network_error', 'The generated file could not be uploaded.' );
		}
		return self::upload_response( $status );
	}

	/**
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	private static function upload_error( bool $retryable, string $code, string $message ): array {
		return array(
			'ok' => false,
			'retryable' => $retryable,
			'code' => $code,
			'message' => $message,
			'data' => array(),
		);
	}
}
