<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_Settings {
	const OPTION = 'spacefast_wordpress_settings';
	const MODE_HEADLESS = 'headless';
	const MODE_STATIC = 'static';

	/**
	 * Parse the one-time Dashboard connection.
	 *
	 * @return array{api_url:string,space_id:string,token:string}
	 */
	public static function parse_connection( string $json ): array {
		$value = json_decode( $json, true );
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Connection must be valid JSON.' );
		}

		$api_url = isset( $value['apiUrl'] ) && is_string( $value['apiUrl'] )
			? untrailingslashit( trim( $value['apiUrl'] ) )
			: '';
		$space_id = isset( $value['spaceId'] ) && is_string( $value['spaceId'] )
			? trim( $value['spaceId'] )
			: '';
		$token = isset( $value['token'] ) && is_string( $value['token'] )
			? trim( $value['token'] )
			: '';

		if ( ! self::allowed_api_url( $api_url ) ) {
			throw new InvalidArgumentException( 'API URL is not an official Spacefast API origin.' );
		}
		if ( ! preg_match( '/^spc_[A-Za-z0-9_-]+$/', $space_id ) ) {
			throw new InvalidArgumentException( 'Space ID is invalid.' );
		}
		if ( ! preg_match( '/^sfa_[A-Za-z0-9_-]+$/', $token ) ) {
			throw new InvalidArgumentException( 'Connection token is invalid.' );
		}

		return array(
			'api_url' => $api_url,
			'space_id' => $space_id,
			'token' => $token,
		);
	}

	private static function allowed_api_url( string $api_url ): bool {
		if (
			defined( 'SPACEFAST_WORDPRESS_API_URL' )
			&& untrailingslashit( (string) SPACEFAST_WORDPRESS_API_URL ) === $api_url
		) {
			return true;
		}
		$parts = wp_parse_url( $api_url );
		if (
			! is_array( $parts )
			|| 'https' !== ( $parts['scheme'] ?? '' )
			|| isset( $parts['path'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		return 1 === preg_match( '/^api(?:-[a-z0-9-]+)?\\.spacefast\\.com$/', $host )
			|| 'api.sf.localhost' === $host;
	}

	/**
	 * @return array{api_url:string,space_id:string,token:string,mode:string}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$mode = (string) ( $stored['mode'] ?? self::MODE_HEADLESS );
		if ( ! in_array( $mode, self::modes(), true ) ) {
			$mode = self::MODE_HEADLESS;
		}
		return array(
			'api_url' => defined( 'SPACEFAST_WORDPRESS_API_URL' )
				? untrailingslashit( (string) SPACEFAST_WORDPRESS_API_URL )
				: (string) ( $stored['api_url'] ?? '' ),
			'space_id' => defined( 'SPACEFAST_WORDPRESS_SPACE_ID' )
				? (string) SPACEFAST_WORDPRESS_SPACE_ID
				: (string) ( $stored['space_id'] ?? '' ),
			'token' => defined( 'SPACEFAST_WORDPRESS_TOKEN' )
				? (string) SPACEFAST_WORDPRESS_TOKEN
				: (string) ( $stored['token'] ?? '' ),
			'mode' => $mode,
		);
	}

	/**
	 * @param array{api_url:string,space_id:string,token:string} $connection Connection.
	 */
	public static function save( array $connection ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$mode = in_array( (string) ( $stored['mode'] ?? '' ), self::modes(), true )
			? (string) $stored['mode']
			: self::MODE_HEADLESS;
		update_option(
			self::OPTION,
			array_merge( $connection, array( 'mode' => $mode ) ),
			false
		);
		if ( false === get_option( Spacefast_Sync_State::OPTION, false ) ) {
			add_option( Spacefast_Sync_State::OPTION, Spacefast_Sync_State::defaults(), '', false );
		}
	}

	public static function save_mode( string $mode ): void {
		if ( ! in_array( $mode, self::modes(), true ) ) {
			throw new InvalidArgumentException( 'Publishing mode is invalid.' );
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored['mode'] = $mode;
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * @return array<int,string>
	 */
	public static function modes(): array {
		return array( self::MODE_HEADLESS, self::MODE_STATIC );
	}

	public static function mode(): string {
		return self::get()['mode'];
	}

	public static function disconnect(): void {
		delete_option( self::OPTION );
		Spacefast_Sync_State::reset();
		Spacefast_Static_Publisher::reset();
	}

	public static function configured(): bool {
		$value = self::get();
		return '' !== $value['api_url'] && '' !== $value['space_id'] && '' !== $value['token'];
	}
}
