<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_Sync_State {
	const OPTION = 'spacefast_wordpress_sync_state';
	const HOOK = 'spacefast_wordpress_deliver';

	/**
	 * @return array{desired:int,delivered:int,event_id:string,reasons:array<int,string>,attempts:int,next_at:int,last_status:string,last_message:string,last_build_id:string,last_version_id:string,last_change_at:int,last_attempt_at:int,last_success_at:int,last_settings_sync_at:int,active_generation:int,settings_pending:bool}
	 */
	public static function defaults(): array {
		return array(
			'desired' => 0,
			'delivered' => 0,
			'event_id' => '',
			'reasons' => array(),
			'attempts' => 0,
			'next_at' => 0,
			'last_status' => 'idle',
			'last_message' => '',
			'last_build_id' => '',
			'last_version_id' => '',
			'last_change_at' => 0,
			'last_attempt_at' => 0,
			'last_success_at' => 0,
			'last_settings_sync_at' => 0,
			'active_generation' => 0,
			'settings_pending' => false,
		);
	}

	/**
	 * @return array{desired:int,delivered:int,event_id:string,reasons:array<int,string>,attempts:int,next_at:int,last_status:string,last_message:string,last_build_id:string,last_version_id:string,last_change_at:int,last_attempt_at:int,last_success_at:int,last_settings_sync_at:int,active_generation:int,settings_pending:bool}
	 */
	public static function get(): array {
		$value = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $value ) ? $value : array() );
	}

	/**
	 * Pure state transition used by WordPress hooks and tests.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function record_change( array $state, string $reason, string $event_id ): array {
		$state = array_merge( self::defaults(), $state );
		$state['desired'] = (int) $state['desired'] + 1;
		$state['event_id'] = $event_id;
		$state['reasons'] = array_values(
			array_unique(
				array_slice(
					array_merge( (array) $state['reasons'], array( sanitize_key( $reason ) ) ),
					-10
				)
			)
		);
		$state['attempts'] = 0;
		$state['next_at'] = 0;
		$state['last_change_at'] = time();
		$active_status = (string) $state['last_status'];
		$state['last_status'] = in_array( $active_status, array( 'building', 'exporting', 'uploading', 'finalizing' ), true )
			? $active_status
			: 'pending';
		$state['last_message'] = '';
		return $state;
	}

	/**
	 * Only acknowledges the generation that was actually delivered.
	 *
	 * @param array<string,mixed> $state State after HTTP returns.
	 * @return array<string,mixed>
	 */
	public static function acknowledge( array $state, int $generation, string $build_id ): array {
		$state = array_merge( self::defaults(), $state );
		$state['delivered'] = max( (int) $state['delivered'], $generation );
		$state['attempts'] = 0;
		$state['next_at'] = 0;
		$state['last_status'] = 'building';
		$state['last_message'] = '';
		$state['last_build_id'] = $build_id;
		return $state;
	}

	/** @param array<string,mixed> $state State. @return array<string,mixed> */
	public static function acknowledge_build_terminal( array $state, string $status, string $message = '' ): array {
		$state = array_merge( self::defaults(), $state );
		if ( 'succeeded' === $status ) {
			$state['last_status'] = (int) $state['desired'] > (int) $state['delivered'] ? 'pending' : 'live';
			$state['last_message'] = '';
			$state['last_success_at'] = time();
			$state['last_build_id'] = '';
			return $state;
		}
		$state['last_status'] = 'blocked';
		$state['last_build_id'] = '';
		$state['last_message'] = '' !== $message
			? $message
			: 'The build did not finish successfully. The previous live version is safe.';
		return $state;
	}

	/** @param array<string,mixed> $state State. @return array<string,mixed> */
	public static function acknowledge_settings_sync( array $state ): array {
		$state = array_merge( self::defaults(), $state );
		$state['last_settings_sync_at'] = time();
		$state['settings_pending'] = false;
		return $state;
	}

	/**
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public static function acknowledge_static( array $state, string $version_id, string $status ): array {
		$state = array_merge( self::defaults(), $state );
		$generation = 0 < (int) $state['active_generation']
			? (int) $state['active_generation']
			: (int) $state['desired'];
		$state['delivered'] = max( (int) $state['delivered'], $generation );
		$state['attempts'] = 0;
		$state['next_at'] = 0;
		$state['last_status'] = (int) $state['desired'] > (int) $state['delivered'] ? 'pending' : $status;
		$state['last_message'] = '';
		$state['last_version_id'] = $version_id;
		$state['last_success_at'] = time();
		$state['active_generation'] = 0;
		return $state;
	}

	public static function retry_delay( int $attempt ): int {
		$delays = array( 60, 300, 900, 3600, 21600, DAY_IN_SECONDS );
		return $delays[ min( max( 1, $attempt ), count( $delays ) ) - 1 ];
	}

	public static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Apply a compare-and-swap transition so concurrent content and cron
	 * requests cannot overwrite one another.
	 *
	 * @param callable(array<string,mixed>):array<string,mixed> $transition Transition.
	 * @return array<string,mixed>
	 */
	public static function mutate( callable $transition ): array {
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$current = self::get();
			$next = $transition( $current );
			if ( self::compare_and_swap_option( self::OPTION, $current, $next ) ) {
				return $next;
			}
		}
		throw new RuntimeException( 'Spacefast state changed too many times; retrying later.' );
	}

	public static function claim_lock( string $option, string $owner, int $now ): bool {
		$next = array(
			'owner' => $owner,
			'expires' => $now + 120,
		);
		$current = get_option( $option, false );
		if ( false === $current ) {
			return add_option( $option, $next, '', false );
		}
		if ( is_array( $current ) && (int) ( $current['expires'] ?? 0 ) > $now ) {
			return false;
		}
		return self::compare_and_swap_option( $option, $current, $next );
	}

	public static function release_lock( string $option, string $owner ): void {
		global $wpdb;
		$current = get_option( $option, false );
		if ( ! is_array( $current ) || $owner !== ( $current['owner'] ?? '' ) ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( $option, 'options' );
	}

	/**
	 * @param mixed $expected Expected value.
	 * @param mixed $next Next value.
	 */
	private static function compare_and_swap_option( string $option, $expected, $next ): bool {
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $next ),
				$option,
				maybe_serialize( $expected )
			)
		);
		// Raw SQL bypasses WordPress's option-cache maintenance. A failed CAS
		// means another request changed the row, so the next retry must reload
		// that newer value instead of comparing the same stale cached value.
		wp_cache_delete( $option, 'options' );
		if ( 1 === $updated ) {
			return true;
		}
		return false;
	}

	public static function reset(): void {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::OPTION );
		delete_option( 'spacefast_wordpress_worker_lock' );
	}
}
