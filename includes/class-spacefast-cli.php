<?php

defined( 'ABSPATH' ) || exit;

/** Publish and operate the Space connected to this WordPress site. */
final class Spacefast_CLI {
	/**
	 * Show the locally recorded connection and delivery state.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function status( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			WP_CLI::error( 'Format must be table or json.' );
		}
		$settings = Spacefast_Settings::get();
		$state = Spacefast_Sync_State::get();
		// Explicit allowlist: never serialize the settings option (it holds tokens).
		$status = array(
			'connection' => Spacefast_Settings::configured() ? 'connected' : ( Spacefast_Settings::selected() ? 'unverified' : 'disconnected' ),
			'mode' => Spacefast_Settings::mode(),
			'team_id' => $settings['team_id'],
			'team' => $settings['team_name'],
			'space_id' => $settings['space_id'],
			'space' => $settings['space_name'],
			'last_version_id' => $state['last_version_id'],
			'last_build_id' => $state['last_build_id'],
			'live_url' => $settings['live_url'],
			'state' => $state['last_status'],
			'pending' => (int) $state['desired'] > (int) $state['delivered'],
		);
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $status ) );
			return;
		}
		$rows = array();
		foreach ( $status as $field => $value ) {
			$rows[] = array( 'field' => $field, 'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Start a full Simply Static export and deliver it to Spacefast.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Run export tasks inline and wait for the version to become live.
	 *
	 * [--timeout=<s>]
	 * : Maximum wait in seconds, checked between tasks and HTTP requests.
	 * ---
	 * default: 600
	 * ---
	 */
	public function publish( array $args, array $assoc_args ): void {
		$timeout = $this->timeout( $assoc_args );
		$wait = isset( $assoc_args['wait'] );
		$job = null;
		if ( $wait && Spacefast_Plugin::simply_static_available() ) {
			$job = \Simply_Static\Plugin::instance()->get_archive_creation_job();
			if ( ! $job instanceof Spacefast_CLI_Export_Job ) {
				WP_CLI::error( 'This Simply Static worker does not support inline export. Update Simply Static or publish without --wait.' );
			}
			Spacefast_CLI_Export_Job::$inline = true;
		}
		$error = null;
		// Simply Static fires this only after accepting a new export. Clear
		// leftover Pro/404 scope flags without altering an already active export.
		$full_export = static function (): void {
			delete_option( 'simply-static-use-single' );
			delete_option( 'simply-static-use-build' );
			delete_option( 'simply-static-404-only' );
		};
		add_action( 'ss_before_static_export', $full_export, PHP_INT_MAX );
		try {
			$deadline = microtime( true ) + $timeout;
			$result = Spacefast_Plugin::do_manual_static_publish();
			if ( ! $result['ok'] ) throw new RuntimeException( $result['message'] );
			if ( $wait ) $this->wait( (int) $result['generation'], $deadline, $job );
		} catch ( Throwable $failure ) {
			$error = $failure->getMessage();
		} finally {
			remove_action( 'ss_before_static_export', $full_export, PHP_INT_MAX );
			if ( $job instanceof Spacefast_CLI_Export_Job ) {
				Spacefast_CLI_Export_Job::$inline = false;
				// On timeout, leave a durable background job that can resume normally.
				if ( $job->is_queued() ) $job->dispatch();
			}
		}
		if ( null !== $error ) WP_CLI::error( $error );
		WP_CLI::success( $wait ? 'Publish is live (or already up to date).' : $result['message'] );
	}

	/**
	 * Trigger the headless production build now.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Wait up to 600 seconds for the requested build to succeed.
	 */
	public function build( array $args, array $assoc_args ): void {
		try {
			$deadline = microtime( true ) + 600;
			$result = Spacefast_Plugin::do_manual_build();
			if ( ! $result['ok'] ) throw new RuntimeException( $result['message'] );
			if ( isset( $assoc_args['wait'] ) ) $this->wait( (int) $result['generation'], $deadline );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::success( isset( $assoc_args['wait'] ) ? 'Build succeeded.' : $result['message'] );
	}

	/**
	 * Push the WordPress settings enabled under WordPress sync.
	 *
	 * @subcommand sync-settings
	 */
	public function sync_settings( array $args, array $assoc_args ): void {
		try {
			$result = Spacefast_Plugin::do_manual_settings_sync();
			if ( ! $result['ok'] ) throw new RuntimeException( $result['message'] );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::success( 'WordPress settings synced to the Space.' );
	}

	/**
	 * Revoke refresh access and remove the local connection and pending work.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 */
	public function disconnect( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Disconnect this WordPress site from Spacefast?', $assoc_args );
		try {
			Spacefast_Plugin::do_disconnect();
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::success( 'Spacefast disconnected.' );
	}

	private function timeout( array $assoc_args ): int {
		$value = $assoc_args['timeout'] ?? '600';
		$timeout = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $timeout ) WP_CLI::error( 'Timeout must be a positive integer in seconds.' );
		return $timeout;
	}

	private function wait( int $generation, float $deadline, ?Spacefast_CLI_Export_Job $job = null ): void {
		$delay = 1;
		while ( true ) {
			// A long-running CLI request must see updates from other workers.
			wp_cache_delete( Spacefast_Sync_State::OPTION, 'options' );
			wp_cache_delete( Spacefast_Settings::OPTION, 'options' );
			$state = Spacefast_Sync_State::get();
			if ( 'blocked' === $state['last_status'] ) {
				throw new RuntimeException( $state['last_message'] ?: 'Delivery failed.' );
			}
			$completed = (int) $state['delivered'] >= $generation
				&& ( in_array( $state['last_status'], array( 'live', 'unchanged' ), true )
					|| ( null === $job && 'pending' === $state['last_status'] ) );
			if ( $completed && ( null === $job || ! $job->is_queued() ) ) return;
			if ( microtime( true ) >= $deadline ) {
				throw new RuntimeException( 'Timed out waiting for Spacefast. Delivery may continue in the background; check wp spacefast status.' );
			}
			if ( ! Spacefast_Settings::selected() ) throw new RuntimeException( 'Spacefast was disconnected while waiting.' );
			if (
				'' !== $state['last_version_id']
				&& ( 'ready' === $state['last_status']
					|| ( null !== $job && 'pending' === $state['last_status'] && (int) $state['delivered'] >= $generation ) )
			) {
				$version = ( new Spacefast_Client() )->get_static_version( $state['last_version_id'] );
				if ( ! $version['ok'] && ! $version['retryable'] ) throw new RuntimeException( $version['message'] );
				if ( $version['ok'] ) {
					$status = $version['data']['status'] ?? '';
					if ( in_array( $status, array( 'failed', 'canceled', 'expired' ), true ) ) {
						throw new RuntimeException( 'Spacefast could not activate the version.' );
					}
					if ( 'ready' === $status && true === ( $version['data']['isCurrentProduction'] ?? false ) ) {
						Spacefast_Sync_State::mutate(
							static function ( array $current ) use ( $state ): array {
								if ( $current['last_version_id'] === $state['last_version_id'] && 'ready' === $current['last_status'] ) $current['last_status'] = 'live';
								return $current;
							}
						);
						Spacefast_Settings::merge( array( 'verified_at' => time() ) );
						$completed = (int) $state['delivered'] >= $generation;
						if ( $completed && ( null === $job || ! $job->is_queued() ) ) return;
					}
				}
			}
			$advanced = false;
			if ( null !== $job ) {
				$advanced = $job->run_step();
				if ( ! $job->is_queued() && ! $job->is_processing() && ! $completed ) {
					$after = Spacefast_Sync_State::get();
					if ( in_array( $after['last_status'], array( 'exporting', 'uploading', 'finalizing' ), true ) ) {
						throw new RuntimeException( 'Simply Static stopped before delivery completed. Check its export log.' );
					}
				}
			}
			if ( ! $advanced && ! $completed && (int) $state['next_at'] <= time() ) Spacefast_Plugin::deliver();
			$publish = get_option( Spacefast_Static_Publisher::OPTION, array() );
			if ( $advanced && 'finalizing' !== ( $publish['phase'] ?? '' ) && (int) ( $publish['retry_at'] ?? 0 ) <= time() ) {
				$delay = 1;
				continue;
			}
			usleep( (int) ( min( $delay, max( 0, $deadline - microtime( true ) ) ) * 1000000 ) );
			$delay = min( 15, $delay * 2 );
		}
	}
}

// Installed before init constructs Simply Static's worker. Ordinary CLI exports
// keep background dispatch; only `spacefast publish --wait` enables inline work.
add_filter(
	'ss_archive_creation_job_class',
	static function ( string $class ): string {
		if ( 'Simply_Static\\Archive_Creation_Job' !== ltrim( $class, '\\' ) ) return $class;
		require_once __DIR__ . '/class-spacefast-cli-export-job.php';
		return Spacefast_CLI_Export_Job::class;
	}
);
WP_CLI::add_command( 'spacefast', Spacefast_CLI::class );
