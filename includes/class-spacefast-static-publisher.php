<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_Static_Publisher {
	const OPTION = 'spacefast_wordpress_publish_state';
	// Consecutive transient failures tolerated before a publish gives up. Each
	// retry is one background step, so a brief outage no longer restarts the
	// whole export as a new version.
	const MAX_TRANSIENT_RETRIES = 5;

	/**
	 * Advance one bounded step of a Simply Static publish.
	 *
	 * @return array{done:bool,version_id:string,uploaded:int,total:int,status:string}
	 */
	public static function step(
		string $archive_dir,
		?Spacefast_Client $client = null,
		string $publish_mode = 'snapshot'
	): array {
		if ( ! in_array( $publish_mode, array( 'additive', 'snapshot' ), true ) ) {
			throw new InvalidArgumentException( 'Static publish mode is invalid.' );
		}
		$root = self::archive_root( $archive_dir );
		$client = $client ?? new Spacefast_Client();
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		if (
			(string) ( $state['archive_dir'] ?? '' ) === $root
			&& (string) ( $state['publish_mode'] ?? '' ) === $publish_mode
			&& 'finalizing' === ( $state['phase'] ?? '' )
		) {
			return self::poll_version( $state, $client );
		}

		if (
			(string) ( $state['archive_dir'] ?? '' ) !== $root
			|| (string) ( $state['publish_mode'] ?? '' ) !== $publish_mode
		) {
			$manifest = self::manifest( $root );
			$event_id = (string) wp_generate_uuid4();
			$result = $client->create_static_version( $manifest, $event_id, $publish_mode );
			if ( ! $result['ok'] ) {
				throw new RuntimeException( $result['message'] );
			}
			$version_id = (string) ( $result['data']['versionId'] ?? '' );
			if ( '' === $version_id ) {
				delete_option( self::OPTION );
				return array(
					'done' => true,
					'version_id' => '',
					'uploaded' => 0,
					'total' => count( $manifest ),
					'status' => 'unchanged',
				);
			}
			if ( ! preg_match( '/^ver_[A-Za-z0-9_-]+$/', $version_id ) ) {
				throw new RuntimeException( 'Spacefast returned an invalid version receipt.' );
			}
			$upload = $result['data']['upload'] ?? null;
			if ( null !== $upload && ! is_array( $upload ) ) {
				throw new RuntimeException( 'Spacefast returned invalid upload instructions.' );
			}
			$state = array(
				'archive_dir' => $root,
				'publish_mode' => $publish_mode,
				'event_id' => $event_id,
				'version_id' => $version_id,
				'upload' => $upload,
				'phase' => 'uploading',
				'next_target' => 0,
				'uploaded' => 0,
				'total' => is_array( $upload )
					? (int) ( $upload['summary']['upload'] ?? count( $manifest ) )
					: 0,
				'pages' => 0,
				'polls' => 0,
			);
			update_option( self::OPTION, $state, false );
			if ( null === $upload ) {
				return self::begin_finalizing( $state );
			}
		}

		$upload = $state['upload'] ?? null;
		if ( ! is_array( $upload ) ) {
			return self::begin_finalizing( $state );
		}
		$targets = isset( $upload['targets'] ) && is_array( $upload['targets'] )
			? array_values( $upload['targets'] )
			: array();
		$next_target = max( 0, (int) ( $state['next_target'] ?? 0 ) );

		if ( isset( $targets[ $next_target ] ) && is_array( $targets[ $next_target ] ) ) {
			$target = $targets[ $next_target ];
			$path = self::target_file( $root, (string) ( $target['path'] ?? '' ) );
			$result = $client->upload_static_file( $target, $path );
			if ( ! $result['ok'] ) {
				if ( in_array( $result['code'], array( 'upload_http_401', 'upload_http_403' ), true ) ) {
					return self::resume( $state, $client );
				}
				return self::retry_or_throw( $state, $result, 'uploading' );
			}
			$state['retries'] = 0;
			$state['next_target'] = $next_target + 1;
			$state['uploaded'] = (int) ( $state['uploaded'] ?? 0 ) + 1;
			update_option( self::OPTION, $state, false );
			return array(
				'done' => false,
				'version_id' => (string) $state['version_id'],
				'uploaded' => (int) $state['uploaded'],
				'total' => (int) $state['total'],
				'status' => 'uploading',
			);
		}

		$remaining = (int) ( $upload['summary']['upload'] ?? count( $targets ) );
		if ( $remaining > count( $targets ) ) {
			return self::resume( $state, $client );
		}

		return self::begin_finalizing( $state );
	}

	/**
	 * @return array<int,array{path:string,size:int,sha256:string,contentType:string}>
	 */
	public static function manifest( string $archive_dir ): array {
		$root = self::archive_root( $archive_dir );
		self::ensure_public_config( $root );
		$files = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			if ( ! $file->isReadable() ) {
				throw new RuntimeException( 'A generated export file is not readable.' );
			}
			$absolute = $file->getPathname();
			$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $absolute, strlen( $root ) + 1 ) );
			$size = $file->getSize();
			$digest = hash_file( 'sha256', $absolute );
			if ( '' === $relative || false === $digest || $size < 0 ) {
				throw new RuntimeException( 'A generated export file could not be read.' );
			}
			$files[] = array(
				'path' => $relative,
				'size' => $size,
				'sha256' => $digest,
				'contentType' => Spacefast_Client::content_type_for_file( $absolute ),
			);
		}
		if ( array() === $files ) {
			throw new RuntimeException( 'Simply Static generated no files to publish.' );
		}
		usort(
			$files,
			static fn( array $left, array $right ): int => strcmp( $left['path'], $right['path'] )
		);
		return $files;
	}

	public static function reset(): void {
		delete_option( self::OPTION );
	}

	private static function ensure_public_config( string $root ): void {
		$config = $root . DIRECTORY_SEPARATOR . 'sf.jsonc';
		if ( file_exists( $config ) || is_link( $config ) ) {
			if ( ! is_file( $config ) || is_link( $config ) || ! is_readable( $config ) ) {
				throw new RuntimeException( 'The generated Spacefast configuration is not a readable file.' );
			}
			return;
		}
		$written = file_put_contents( $config, "{\n  \"access\": \"public\"\n}\n", LOCK_EX );
		if ( false === $written ) {
			throw new RuntimeException( 'The generated export could not be made public on Spacefast.' );
		}
	}

	private static function archive_root( string $archive_dir ): string {
		$root = realpath( $archive_dir );
		if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
			throw new RuntimeException( 'The Simply Static export directory is not readable.' );
		}
		return untrailingslashit( $root );
	}

	private static function target_file( string $root, string $path ): string {
		if ( '' === $path || str_contains( $path, "\0" ) || str_contains( $path, '\\' ) ) {
			throw new RuntimeException( 'Spacefast requested an invalid export path.' );
		}
		$absolute = realpath( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path ) );
		if (
			false === $absolute
			|| ! is_file( $absolute )
			|| ! is_readable( $absolute )
			|| ! str_starts_with( $absolute, $root . DIRECTORY_SEPARATOR )
		) {
			throw new RuntimeException( 'Spacefast requested a file outside the generated export.' );
		}
		return $absolute;
	}

	/**
	 * @param array<string,mixed> $state State.
	 * @return array{done:bool,version_id:string,uploaded:int,total:int,status:string}
	 */
	private static function resume( array $state, Spacefast_Client $client ): array {
		$pages = (int) ( $state['pages'] ?? 0 ) + 1;
		if ( $pages > 10000 ) {
			throw new RuntimeException( 'Spacefast returned too many upload instruction pages.' );
		}
		$result = $client->resume_static_upload( (string) $state['version_id'] );
		if ( ! $result['ok'] ) {
			return self::retry_or_throw( $state, $result, 'uploading' );
		}
		$state['retries'] = 0;
		$upload = $result['data']['upload'] ?? null;
		if ( null !== $upload && ! is_array( $upload ) ) {
			throw new RuntimeException( 'Spacefast returned invalid upload instructions.' );
		}
		if ( null === $upload || empty( $upload['targets'] ) ) {
			return self::begin_finalizing( $state );
		}
		$state['upload'] = $upload;
		$state['next_target'] = 0;
		$state['pages'] = $pages;
		update_option( self::OPTION, $state, false );
		return array(
			'done' => false,
			'version_id' => (string) $state['version_id'],
			'uploaded' => (int) $state['uploaded'],
			'total' => (int) $state['total'],
			'status' => 'uploading',
		);
	}

	/**
	 * @param array<string,mixed> $state State.
	 * @return array{done:bool,version_id:string,uploaded:int,total:int,status:string}
	 */
	private static function begin_finalizing( array $state ): array {
		$state['phase'] = 'finalizing';
		$state['upload'] = null;
		$state['polls'] = 0;
		update_option( self::OPTION, $state, false );
		return array(
			'done' => false,
			'version_id' => (string) ( $state['version_id'] ?? '' ),
			'uploaded' => (int) ( $state['uploaded'] ?? 0 ),
			'total' => (int) ( $state['total'] ?? 0 ),
			'status' => 'finalizing',
		);
	}

	/**
	 * @param array<string,mixed> $state State.
	 * @return array{done:bool,version_id:string,uploaded:int,total:int,status:string}
	 */
	private static function poll_version( array $state, Spacefast_Client $client ): array {
		$polls = (int) ( $state['polls'] ?? 0 ) + 1;
		if ( $polls > 300 ) {
			throw new RuntimeException( 'Spacefast did not finish publishing the version.' );
		}
		if ( empty( $state['finalize_requested'] ) ) {
			$finalize = $client->finalize_static_version( (string) $state['version_id'] );
			if ( ! $finalize['ok'] && $finalize['retryable'] ) {
				return self::retry_or_throw( $state, $finalize, 'finalizing' );
			}
			// A refusal here usually means auto-finalize already owns the version;
			// the status poll below reports whatever it settles to.
			$state['finalize_requested'] = true;
			$state['retries'] = 0;
			update_option( self::OPTION, $state, false );
		}
		$result = $client->get_static_version( (string) $state['version_id'] );
		if ( ! $result['ok'] ) {
			if ( $result['retryable'] ) {
				$state['polls'] = $polls;
				update_option( self::OPTION, $state, false );
				return array(
					'done' => false,
					'version_id' => (string) $state['version_id'],
					'uploaded' => (int) $state['uploaded'],
					'total' => (int) $state['total'],
					'status' => 'finalizing',
				);
			}
			throw new RuntimeException( $result['message'] );
		}
		$status = (string) ( $result['data']['status'] ?? '' );
		if ( 'ready' === $status ) {
			delete_option( self::OPTION );
			return array(
				'done' => true,
				'version_id' => (string) $state['version_id'],
				'uploaded' => (int) $state['uploaded'],
				'total' => (int) $state['total'],
				'status' => true === ( $result['data']['isCurrentProduction'] ?? false ) ? 'live' : 'ready',
			);
		}
		if ( in_array( $status, array( 'failed', 'canceled', 'expired' ), true ) ) {
			throw new RuntimeException( 'Spacefast could not publish the generated version.' );
		}
		$state['polls'] = $polls;
		update_option( self::OPTION, $state, false );
		return array(
			'done' => false,
			'version_id' => (string) $state['version_id'],
			'uploaded' => (int) $state['uploaded'],
			'total' => (int) $state['total'],
			'status' => 'finalizing',
		);
	}

	/**
	 * Keep the publish state and try the same step again on a transient failure,
	 * up to MAX_TRANSIENT_RETRIES in a row. Anything else ends the publish.
	 *
	 * @param array<string,mixed> $state State.
	 * @param array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>} $result Failed result.
	 * @return array{done:bool,version_id:string,uploaded:int,total:int,status:string}
	 */
	private static function retry_or_throw( array $state, array $result, string $status ): array {
		$retries = (int) ( $state['retries'] ?? 0 ) + 1;
		if ( ! $result['retryable'] || $retries > self::MAX_TRANSIENT_RETRIES ) {
			throw new RuntimeException( $result['message'] );
		}
		$state['retries'] = $retries;
		update_option( self::OPTION, $state, false );
		return array(
			'done' => false,
			'version_id' => (string) ( $state['version_id'] ?? '' ),
			'uploaded' => (int) ( $state['uploaded'] ?? 0 ),
			'total' => (int) ( $state['total'] ?? 0 ),
			'status' => $status,
		);
	}
}
