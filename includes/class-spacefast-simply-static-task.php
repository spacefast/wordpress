<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_Simply_Static_Publish_Task extends \Simply_Static\Task {
	/** @var string */
	protected static $task_name = 'spacefast_publish';

	/**
	 * Publish one server-issued file target per Simply Static background step.
	 *
	 * @return bool|WP_Error
	 */
	public function perform() {
		try {
			$options = \Simply_Static\Options::instance();
			$partial_export = 'export' !== $options->get( 'generate_type' )
				|| ! empty( get_option( 'simply-static-use-single' ) )
				|| ! empty( get_option( 'simply-static-use-build' ) )
				|| ! empty( get_option( 'simply-static-404-only' ) );
			if ( $partial_export && get_option( 'spacefast_wordpress_snapshot_required', false ) ) {
				throw new RuntimeException(
					__( 'Content was deleted. Run a full Export and publish so the old files are removed from Spacefast.', 'spacefast-wordpress' )
				);
			}
			$result = Spacefast_Static_Publisher::step(
				$options->get_archive_dir(),
				null,
				$partial_export ? 'additive' : 'snapshot'
			);
			if ( ! $result['done'] ) {
				$this->save_status_message(
					'finalizing' === $result['status']
						? __( 'Spacefast is activating the uploaded version', 'spacefast-wordpress' )
						: sprintf(
							/* translators: 1: uploaded files, 2: files requested by Spacefast. */
							__( 'Publishing to Spacefast: %1$d of %2$d files', 'spacefast-wordpress' ),
							$result['uploaded'],
							$result['total']
						)
				);
				return false;
			}

			Spacefast_Plugin::static_publish_completed( $result['version_id'], $result['status'] );
			if ( ! $partial_export ) {
				delete_option( 'spacefast_wordpress_snapshot_required' );
			}
			$this->save_status_message(
				'unchanged' === $result['status']
					? __( 'Spacefast is already up to date', 'spacefast-wordpress' )
					: __( 'Published to Spacefast', 'spacefast-wordpress' )
			);
			return true;
		} catch ( Throwable $error ) {
			Spacefast_Static_Publisher::reset();
			Spacefast_Plugin::static_publish_failed( $error->getMessage() );
			return new WP_Error( 'spacefast_publish_failed', $error->getMessage() );
		}
	}

	public function cleanup(): void {
		Spacefast_Static_Publisher::reset();
	}
}
