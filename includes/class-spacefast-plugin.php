<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_Plugin {
	const LOCK_OPTION = 'spacefast_wordpress_worker_lock';
	const NOTICE_TRANSIENT = 'spacefast_wordpress_admin_notice';

	/** @var bool */
	private static $change_recorded = false;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_spacefast_wordpress_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_spacefast_wordpress_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_spacefast_wordpress_mode', array( __CLASS__, 'save_mode' ) );
		add_action( 'admin_post_spacefast_wordpress_test', array( __CLASS__, 'test_connection' ) );
		add_action( 'admin_post_spacefast_wordpress_build', array( __CLASS__, 'manual_build' ) );
		add_action( 'admin_post_spacefast_wordpress_publish', array( __CLASS__, 'manual_static_publish' ) );
		add_action( Spacefast_Sync_State::HOOK, array( __CLASS__, 'deliver' ) );
		add_action( 'init', array( __CLASS__, 'register_simply_static' ), 20 );
		add_action( 'transition_post_status', array( __CLASS__, 'post_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'post_deleted' ), 10, 2 );
		add_action( 'save_post_attachment', array( __CLASS__, 'attachment_changed' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'attachment_changed' ) );
		add_action( 'add_attachment', array( __CLASS__, 'attachment_changed' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'attachment_changed' ) );
		add_action( 'created_term', array( __CLASS__, 'term_changed' ) );
		add_action( 'edited_term', array( __CLASS__, 'term_changed' ) );
		add_action( 'delete_term', array( __CLASS__, 'term_changed' ) );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'menu_changed' ) );
		add_action( 'profile_update', array( __CLASS__, 'user_changed' ) );
		add_action( 'user_register', array( __CLASS__, 'user_changed' ) );
		add_action( 'deleted_user', array( __CLASS__, 'user_changed' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health_tests' ) );
	}

	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die(
				esc_html__( 'Activate Spacefast separately on each WordPress site.', 'spacefast-wordpress' )
			);
		}
		if ( false === get_option( Spacefast_Sync_State::OPTION, false ) ) {
			add_option( Spacefast_Sync_State::OPTION, Spacefast_Sync_State::defaults(), '', false );
		}
		$state = Spacefast_Sync_State::get();
		if ( (int) $state['desired'] > (int) $state['delivered'] ) {
			self::schedule( time() + 15 );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Spacefast_Sync_State::HOOK );
		delete_option( self::LOCK_OPTION );
	}

	public static function admin_menu(): void {
		add_options_page(
			__( 'Spacefast', 'spacefast-wordpress' ),
			__( 'Spacefast', 'spacefast-wordpress' ),
			'manage_options',
			'spacefast-wordpress',
			array( __CLASS__, 'render_admin' )
		);
	}

	private static function assert_admin( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Spacefast.', 'spacefast-wordpress' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function redirect(): void {
		wp_safe_redirect( admin_url( 'options-general.php?page=spacefast-wordpress' ) );
		exit;
	}

	private static function notice( string $type, string $message ): void {
		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'type' => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	public static function connect(): void {
		self::assert_admin( 'spacefast_wordpress_connect' );
		$raw = isset( $_POST['connection'] )
			? sanitize_textarea_field( wp_unslash( $_POST['connection'] ) )
			: '';
		try {
			Spacefast_Settings::save( Spacefast_Settings::parse_connection( $raw ) );
			self::notice( 'success', __( 'Spacefast connection saved.', 'spacefast-wordpress' ) );
		} catch ( InvalidArgumentException $error ) {
			self::notice( 'error', $error->getMessage() );
		}
		self::redirect();
	}

	public static function disconnect(): void {
		self::assert_admin( 'spacefast_wordpress_disconnect' );
		Spacefast_Settings::disconnect();
		self::notice( 'success', __( 'Spacefast disconnected. Revoke its key in Spacefast Developer settings.', 'spacefast-wordpress' ) );
		self::redirect();
	}

	public static function save_mode(): void {
		self::assert_admin( 'spacefast_wordpress_mode' );
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		try {
			Spacefast_Settings::save_mode( $mode );
			Spacefast_Static_Publisher::reset();
			self::notice( 'success', __( 'Publishing mode saved.', 'spacefast-wordpress' ) );
		} catch ( InvalidArgumentException $error ) {
			self::notice( 'error', $error->getMessage() );
		}
		self::redirect();
	}

	public static function test_connection(): void {
		self::assert_admin( 'spacefast_wordpress_test' );
		$result = ( new Spacefast_Client() )->health();
		self::notice(
			$result['ok'] ? 'success' : 'error',
			$result['ok']
				? ( Spacefast_Settings::MODE_STATIC === Spacefast_Settings::mode()
					? __( 'Connection healthy. Static exports can publish to this Space.', 'spacefast-wordpress' )
					: __( 'Connection healthy. WordPress and the production repository are ready.', 'spacefast-wordpress' ) )
				: $result['message']
		);
		self::redirect();
	}

	public static function manual_build(): void {
		self::assert_admin( 'spacefast_wordpress_build' );
		if ( Spacefast_Settings::MODE_HEADLESS !== Spacefast_Settings::mode() ) {
			self::notice( 'error', __( 'Switch to Headless CMS to trigger repository builds.', 'spacefast-wordpress' ) );
			self::redirect();
		}
		self::record_change( 'manual' );
		self::deliver();
		self::redirect();
	}

	public static function manual_static_publish(): void {
		self::assert_admin( 'spacefast_wordpress_publish' );
		if ( Spacefast_Settings::MODE_STATIC !== Spacefast_Settings::mode() ) {
			self::notice( 'error', __( 'Switch to Static WordPress to publish an export.', 'spacefast-wordpress' ) );
			self::redirect();
		}
		if ( ! self::simply_static_available() ) {
			self::notice( 'error', __( 'Install and activate Simply Static before publishing.', 'spacefast-wordpress' ) );
			self::redirect();
		}
		try {
			$started = \Simply_Static\Plugin::instance()->run_static_export();
			self::notice(
				$started ? 'success' : 'error',
				$started
					? __( 'Static export started. Simply Static will publish it to Spacefast.', 'spacefast-wordpress' )
					: __( 'Simply Static is already running or could not start the export.', 'spacefast-wordpress' )
			);
		} catch ( Throwable $error ) {
			self::notice( 'error', $error->getMessage() );
		}
		self::redirect();
	}

	public static function post_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! $post_type->public || ! $post_type->show_in_rest ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		self::record_change( 'post_' . $new_status );
	}

	public static function post_deleted( int $post_id, WP_Post $post ): void {
		unset( $post_id );
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! $post_type->public || ! $post_type->show_in_rest ) {
			return;
		}
		if ( 'publish' === $post->post_status ) {
			self::record_change( 'post_deleted' );
		}
	}

	public static function term_changed(): void {
		self::record_change( 'taxonomy' );
	}

	public static function menu_changed(): void {
		self::record_change( 'navigation' );
	}

	public static function attachment_changed(): void {
		self::record_change( 'media' );
	}

	public static function user_changed(): void {
		self::record_change( 'user' );
	}

	private static function record_change( string $reason ): void {
		if (
			self::$change_recorded
			|| ! Spacefast_Settings::configured()
			|| Spacefast_Settings::MODE_HEADLESS !== Spacefast_Settings::mode()
		) {
			return;
		}
		self::$change_recorded = true;
		$event_id = (string) wp_generate_uuid4();
		Spacefast_Sync_State::mutate(
			static fn( array $state ): array => Spacefast_Sync_State::record_change(
				$state,
				$reason,
				$event_id
			)
		);
		self::schedule( time() + 15 );
	}

	private static function schedule( int $timestamp ): void {
		$scheduled = wp_next_scheduled( Spacefast_Sync_State::HOOK );
		if ( $scheduled && $scheduled <= $timestamp ) {
			return;
		}
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, Spacefast_Sync_State::HOOK );
		}
		wp_schedule_single_event( $timestamp, Spacefast_Sync_State::HOOK );
	}

	public static function deliver(): void {
		if (
			! Spacefast_Settings::configured()
			|| Spacefast_Settings::MODE_HEADLESS !== Spacefast_Settings::mode()
		) {
			return;
		}
		$now = time();
		$owner = (string) wp_generate_uuid4();
		if ( ! Spacefast_Sync_State::claim_lock( self::LOCK_OPTION, $owner, $now ) ) {
			self::schedule( $now + 60 );
			return;
		}

		try {
			$snapshot = Spacefast_Sync_State::get();
			$generation = (int) $snapshot['desired'];
			if ( $generation <= (int) $snapshot['delivered'] || '' === $snapshot['event_id'] ) {
				return;
			}
			$result = ( new Spacefast_Client() )->trigger_build( (string) $snapshot['event_id'] );
			$current = Spacefast_Sync_State::get();
			if ( $result['ok'] ) {
				$build = isset( $result['data']['build'] ) && is_array( $result['data']['build'] )
					? $result['data']['build']
					: array();
				$current = Spacefast_Sync_State::mutate(
					static fn( array $state ): array => Spacefast_Sync_State::acknowledge(
						$state,
						$generation,
						(string) $build['id']
					)
				);
				if ( (int) $current['desired'] > (int) $current['delivered'] ) {
					self::schedule( time() + 15 );
				}
				return;
			}

			$current = Spacefast_Sync_State::mutate(
				static function ( array $state ) use ( $result ): array {
					$state['last_status'] = $result['retryable'] ? 'retrying' : 'blocked';
					$state['last_message'] = $result['message'];
					if ( $result['retryable'] ) {
						$state['attempts'] = (int) $state['attempts'] + 1;
						$state['next_at'] = time() + Spacefast_Sync_State::retry_delay( $state['attempts'] );
					}
					return $state;
				}
			);
			if ( $result['retryable'] ) {
				self::schedule( (int) $current['next_at'] );
			}
		} catch ( Throwable ) {
			self::schedule( time() + 60 );
		} finally {
			Spacefast_Sync_State::release_lock( self::LOCK_OPTION, $owner );
		}
	}

	public static function register_simply_static(): void {
		if ( ! self::simply_static_available() ) {
			return;
		}
		require_once dirname( __DIR__ ) . '/includes/class-spacefast-simply-static-task.php';
		add_filter( 'simplystatic.archive_creation_job.task_list', array( __CLASS__, 'simply_static_tasks' ), 20, 2 );
		add_filter( 'simply_static_class_name', array( __CLASS__, 'simply_static_task_class' ), 20, 2 );
		add_action( 'ss_archive_creation_job_after_start_queue', array( __CLASS__, 'static_export_started' ), 20 );
	}

	public static function simply_static_available(): bool {
		return class_exists( '\\Simply_Static\\Plugin' )
			&& class_exists( '\\Simply_Static\\Task' )
			&& class_exists( '\\Simply_Static\\Options' );
	}

	/**
	 * @param array<int,string> $tasks Tasks.
	 * @return array<int,string>
	 */
	public static function simply_static_tasks( array $tasks, string $delivery_method ): array {
		unset( $delivery_method );
		if (
			Spacefast_Settings::MODE_STATIC !== Spacefast_Settings::mode()
			|| ! Spacefast_Settings::configured()
			|| in_array( 'spacefast_publish', $tasks, true )
		) {
			return $tasks;
		}
		$wrapup = array_search( 'wrapup', $tasks, true );
		$position = false === $wrapup ? count( $tasks ) : $wrapup;
		array_splice( $tasks, $position, 0, array( 'spacefast_publish' ) );
		return $tasks;
	}

	public static function simply_static_task_class( string $class_name, string $task_name ): string {
		return 'spacefast_publish' === $task_name
			? Spacefast_Simply_Static_Publish_Task::class
			: $class_name;
	}

	public static function static_export_started( int $blog_id = 0 ): void {
		unset( $blog_id );
		if (
			Spacefast_Settings::MODE_STATIC !== Spacefast_Settings::mode()
			|| ! Spacefast_Settings::configured()
		) {
			return;
		}
		Spacefast_Static_Publisher::reset();
		Spacefast_Sync_State::mutate(
			static function ( array $state ): array {
				$next = Spacefast_Sync_State::record_change(
					$state,
					'static_export',
					(string) wp_generate_uuid4()
				);
				$next['last_status'] = 'exporting';
				return $next;
			}
		);
	}

	public static function static_publish_completed( string $version_id, string $status ): void {
		Spacefast_Sync_State::mutate(
			static fn( array $state ): array => Spacefast_Sync_State::acknowledge_static(
				$state,
				$version_id,
				$status
			)
		);
	}

	public static function static_publish_failed( string $message ): void {
		Spacefast_Sync_State::mutate(
			static function ( array $state ) use ( $message ): array {
				$state['last_status'] = 'blocked';
				$state['last_message'] = $message;
				return $state;
			}
		);
	}

	/**
	 * @param array<string,mixed> $tests Tests.
	 * @return array<string,mixed>
	 */
	public static function site_health_tests( array $tests ): array {
		$tests['direct']['spacefast_connection'] = array(
			'label' => __( 'Spacefast connection', 'spacefast-wordpress' ),
			'test' => array( __CLASS__, 'site_health_result' ),
		);
		return $tests;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function site_health_result(): array {
		$result = Spacefast_Settings::configured()
			? ( new Spacefast_Client() )->health()
			: array( 'ok' => false );
		$healthy = true === ( $result['ok'] ?? false );
		return array(
			'label' => $healthy
				? __( 'Spacefast is connected', 'spacefast-wordpress' )
				: __( 'Spacefast needs attention', 'spacefast-wordpress' ),
			'status' => $healthy ? 'good' : 'critical',
			'badge' => array(
				'label' => 'Spacefast',
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html(
				$healthy
					? ( Spacefast_Settings::MODE_STATIC === Spacefast_Settings::mode()
						? __( 'Simply Static exports can publish to this Space.', 'spacefast-wordpress' )
						: __( 'Public content changes can trigger repository builds.', 'spacefast-wordpress' ) )
					: __( 'Open Settings → Spacefast to repair the connection.', 'spacefast-wordpress' )
			) . '</p>',
			'test' => 'spacefast_connection',
		);
	}

	public static function render_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( self::NOTICE_TRANSIENT );
		delete_transient( self::NOTICE_TRANSIENT );
		$state = Spacefast_Sync_State::get();
		$configured = Spacefast_Settings::configured();
		$mode = Spacefast_Settings::mode();
		$static_mode = Spacefast_Settings::MODE_STATIC === $mode;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spacefast', 'spacefast-wordpress' ); ?></h1>
			<p><?php esc_html_e( 'Publish WordPress to Spacefast directly, or use WordPress as the CMS for a repository-built site.', 'spacefast-wordpress' ); ?></p>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $configured ) : ?>
				<h2><?php esc_html_e( 'Connect', 'spacefast-wordpress' ); ?></h2>
				<p><?php esc_html_e( 'In Spacefast, open Settings → Data sources → Connect WordPress. Paste the one-time connection below.', 'spacefast-wordpress' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="spacefast_wordpress_connect">
					<?php wp_nonce_field( 'spacefast_wordpress_connect' ); ?>
					<label class="screen-reader-text" for="spacefast-connection"><?php esc_html_e( 'Spacefast connection', 'spacefast-wordpress' ); ?></label>
					<textarea id="spacefast-connection" name="connection" rows="6" class="large-text code" required autocomplete="off"></textarea>
					<?php submit_button( __( 'Save connection', 'spacefast-wordpress' ) ); ?>
				</form>
			<?php else : ?>
				<h2><?php esc_html_e( 'Publishing mode', 'spacefast-wordpress' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px">
					<input type="hidden" name="action" value="spacefast_wordpress_mode">
					<?php wp_nonce_field( 'spacefast_wordpress_mode' ); ?>
					<fieldset>
						<label style="display:block;margin-bottom:10px">
							<input type="radio" name="mode" value="static" <?php checked( $static_mode ); ?>>
							<strong><?php esc_html_e( 'Static WordPress', 'spacefast-wordpress' ); ?></strong><br>
							<span class="description" style="margin-left:24px"><?php esc_html_e( 'Simply Static generates the site; Spacefast publishes the generated files.', 'spacefast-wordpress' ); ?></span>
						</label>
						<label style="display:block">
							<input type="radio" name="mode" value="headless" <?php checked( ! $static_mode ); ?>>
							<strong><?php esc_html_e( 'Headless CMS', 'spacefast-wordpress' ); ?></strong><br>
							<span class="description" style="margin-left:24px"><?php esc_html_e( 'WordPress content changes rebuild the connected Astro or other repository project.', 'spacefast-wordpress' ); ?></span>
						</label>
					</fieldset>
					<?php submit_button( __( 'Save mode', 'spacefast-wordpress' ), 'secondary' ); ?>
				</form>
				<h2><?php esc_html_e( 'Status', 'spacefast-wordpress' ); ?></h2>
				<table class="widefat striped" style="max-width:720px"><tbody>
					<tr><th><?php esc_html_e( 'Delivery', 'spacefast-wordpress' ); ?></th><td><?php echo esc_html( (string) $state['last_status'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Pending changes', 'spacefast-wordpress' ); ?></th><td><?php echo esc_html( (string) max( 0, (int) $state['desired'] - (int) $state['delivered'] ) ); ?></td></tr>
					<tr><th><?php echo esc_html( $static_mode ? __( 'Last version', 'spacefast-wordpress' ) : __( 'Last build', 'spacefast-wordpress' ) ); ?></th><td><code><?php echo esc_html( (string) ( $static_mode ? ( $state['last_version_id'] ?: '—' ) : ( $state['last_build_id'] ?: '—' ) ) ); ?></code></td></tr>
					<?php if ( $state['last_message'] ) : ?><tr><th><?php esc_html_e( 'Message', 'spacefast-wordpress' ); ?></th><td><?php echo esc_html( (string) $state['last_message'] ); ?></td></tr><?php endif; ?>
				</tbody></table>
				<?php if ( $static_mode && ! self::simply_static_available() ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Static WordPress needs the free Simply Static plugin. Install and activate it, then return here.', 'spacefast-wordpress' ); ?></p></div>
				<?php endif; ?>
				<div style="display:flex;gap:8px;margin-top:16px">
					<?php self::action_form( 'spacefast_wordpress_test', __( 'Test connection', 'spacefast-wordpress' ), 'secondary' ); ?>
					<?php if ( $static_mode ) : ?>
						<?php self::action_form( 'spacefast_wordpress_publish', __( 'Export and publish', 'spacefast-wordpress' ), 'primary' ); ?>
					<?php else : ?>
						<?php self::action_form( 'spacefast_wordpress_build', __( 'Build now', 'spacefast-wordpress' ), 'primary' ); ?>
					<?php endif; ?>
					<?php self::action_form( 'spacefast_wordpress_disconnect', __( 'Disconnect', 'spacefast-wordpress' ), 'delete' ); ?>
				</div>
				<p class="description"><?php esc_html_e( 'The connection secret is stored as a non-autoloaded WordPress option and is never displayed.', 'spacefast-wordpress' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function action_form( string $action, string $label, string $class ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="button button-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
