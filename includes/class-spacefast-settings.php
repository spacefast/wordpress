<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_Settings {
	const OPTION = 'spacefast_wordpress_settings';
	const MODE_HEADLESS = 'headless';
	const MODE_STATIC = 'static';

	/** @return array<string,mixed> */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$mode = (string) ( $stored['mode'] ?? self::MODE_HEADLESS );
		if ( ! in_array( $mode, self::modes(), true ) ) {
			$mode = self::MODE_HEADLESS;
		}
		return array_merge(
			array(
				'api_url' => self::api_url(),
				'mode' => $mode,
				'client_id' => '',
				'access_token' => '',
				'refresh_token' => '',
				'expires_at' => 0,
				'scope' => '',
				'team_id' => '',
				'team_name' => '',
				'team_slug' => '',
				'space_id' => '',
				'space_name' => '',
				'space_slug' => '',
				'live_url' => '',
				'verified_at' => 0,
			),
			$stored,
			array( 'api_url' => self::api_url(), 'mode' => $mode )
		);
	}

	public static function api_url(): string {
		return defined( 'SPACEFAST_WORDPRESS_API_URL' )
			? untrailingslashit( (string) SPACEFAST_WORDPRESS_API_URL )
			: 'https://api.spacefast.com';
	}

	/** @param array<string,mixed> $values Values to merge. */
	public static function merge( array $values ): void {
		update_option( self::OPTION, array_merge( self::get(), $values ), false );
		if ( class_exists( 'Spacefast_Sync_State' ) && false === get_option( Spacefast_Sync_State::OPTION, false ) ) {
			add_option( Spacefast_Sync_State::OPTION, Spacefast_Sync_State::defaults(), '', false );
		}
	}

	public static function save_mode( string $mode ): void {
		if ( ! in_array( $mode, self::modes(), true ) ) {
			throw new InvalidArgumentException( 'Publishing mode is invalid.' );
		}
		self::merge(
			array(
				'mode' => $mode,
				'verified_at' => 0,
			)
		);
	}

	/** @return array<int,string> */
	public static function modes(): array {
		return array( self::MODE_HEADLESS, self::MODE_STATIC );
	}

	public static function mode(): string {
		return (string) self::get()['mode'];
	}

	public static function authorized(): bool {
		$value = self::get();
		return '' !== $value['client_id']
			&& '' !== $value['access_token']
			&& '' !== $value['refresh_token'];
	}

	public static function configured(): bool {
		$value = self::get();
		return self::authorized()
			&& '' !== $value['team_id']
			&& '' !== $value['space_id']
			&& 0 < (int) $value['verified_at'];
	}

	public static function disconnect(): void {
		delete_option( self::OPTION );
		delete_option( Spacefast_OAuth::PENDING_OPTION );
		delete_option( Spacefast_OAuth::CHOICES_OPTION );
		delete_option( 'spacefast_wordpress_snapshot_required' );
		Spacefast_Sync_State::reset();
		Spacefast_Static_Publisher::reset();
	}
}
