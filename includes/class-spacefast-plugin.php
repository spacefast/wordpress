<?php

defined( 'ABSPATH' ) || exit;

final class Spacefast_Plugin {
	const AUTOMATIC_DEBOUNCE_SECONDS = MINUTE_IN_SECONDS;
	const LOCK_OPTION = 'spacefast_wordpress_worker_lock';
	const NOTICE_TRANSIENT = 'spacefast_wordpress_admin_notice';

	/** @var bool */
	private static $change_recorded = false;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'oauth_callback' ) );
		add_action( 'admin_post_spacefast_wordpress_oauth_start', array( __CLASS__, 'oauth_start' ) );
		add_action( 'admin_post_spacefast_wordpress_select_space', array( __CLASS__, 'select_space' ) );
		add_action( 'admin_post_spacefast_wordpress_create_space', array( __CLASS__, 'create_space' ) );
		add_action( 'admin_post_spacefast_wordpress_prepare_static', array( __CLASS__, 'prepare_static' ) );
		add_action( 'admin_post_spacefast_wordpress_change_space', array( __CLASS__, 'change_space' ) );
		add_action( 'admin_post_spacefast_wordpress_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_spacefast_wordpress_test', array( __CLASS__, 'test_connection' ) );
		add_action( 'admin_post_spacefast_wordpress_build', array( __CLASS__, 'manual_build' ) );
		add_action( 'admin_post_spacefast_wordpress_publish', array( __CLASS__, 'manual_static_publish' ) );
		add_action( 'admin_post_spacefast_wordpress_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_spacefast_wordpress_sync_settings', array( __CLASS__, 'manual_settings_sync' ) );
		add_action( Spacefast_Sync_State::HOOK, array( __CLASS__, 'deliver' ) );
		add_action( 'init', array( __CLASS__, 'register_simply_static' ), 20 );
		add_action( 'transition_post_status', array( __CLASS__, 'post_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'post_deleted' ), 10, 2 );
		add_action( 'save_post_attachment', array( __CLASS__, 'attachment_changed' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'attachment_changed' ) );
		add_action( 'add_attachment', array( __CLASS__, 'attachment_changed' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'deletion_changed' ) );
		add_action( 'created_term', array( __CLASS__, 'term_changed' ) );
		add_action( 'edited_term', array( __CLASS__, 'term_changed' ) );
		add_action( 'delete_term', array( __CLASS__, 'deletion_changed' ) );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'menu_changed' ) );
		add_action( 'profile_update', array( __CLASS__, 'user_changed' ) );
		add_action( 'user_register', array( __CLASS__, 'user_changed' ) );
		add_action( 'deleted_user', array( __CLASS__, 'user_changed' ) );
		add_action( 'update_option_blogname', array( __CLASS__, 'wordpress_setting_changed' ), 10, 3 );
		add_action( 'update_option_blogdescription', array( __CLASS__, 'wordpress_setting_changed' ), 10, 3 );
		add_action( 'update_option_blog_public', array( __CLASS__, 'wordpress_setting_changed' ), 10, 3 );
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
			self::schedule_after_change( (int) $state['last_change_at'] );
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

	public static function oauth_start(): void {
		self::assert_admin( 'spacefast_wordpress_oauth_start' );
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		try {
			$result = ( new Spacefast_OAuth() )->begin( $mode );
			if ( ! $result['ok'] ) throw new RuntimeException( $result['message'] );
			wp_redirect( esc_url_raw( (string) $result['url'] ) );
			exit;
		} catch ( Throwable $error ) {
			self::notice( 'error', $error->getMessage() );
			self::redirect();
		}
	}

	public static function prepare_static(): void {
		self::assert_admin( 'spacefast_wordpress_prepare_static' );
		$result = self::install_simply_static();
		if ( ! $result['ok'] ) {
			self::notice( 'error', $result['message'] );
			self::redirect();
		}
		try {
			$oauth = ( new Spacefast_OAuth() )->begin( Spacefast_Settings::MODE_STATIC );
			if ( ! $oauth['ok'] ) throw new RuntimeException( $oauth['message'] );
			wp_redirect( esc_url_raw( (string) $oauth['url'] ) );
			exit;
		} catch ( Throwable $error ) {
			self::notice( 'error', $error->getMessage() );
			self::redirect();
		}
	}

	public static function oauth_callback(): void {
		if ( 'callback' !== ( $_GET['spacefast_oauth'] ?? '' ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Spacefast.', 'spacefast-wordpress' ), 403 );
		}
		if ( isset( $_GET['error'] ) ) {
			self::notice( 'error', sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ) ) );
			self::redirect();
		}
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$result = ( new Spacefast_OAuth() )->finish( $code, $state );
		if ( $result['ok'] && ! empty( $result['resumed'] ) ) {
			self::mark_settings_pending();
			$sync = self::sync_space_settings();
			self::notice(
				$sync['ok'] ? 'success' : 'error',
				$sync['ok']
					? __( 'Spacefast reconnected and WordPress settings are in sync.', 'spacefast-wordpress' )
					: $sync['message']
			);
			self::redirect();
		}
		self::notice(
			$result['ok'] ? 'success' : 'error',
			$result['ok']
				? __( 'Team authorized. Choose the Space WordPress should use.', 'spacefast-wordpress' )
				: $result['message']
		);
		self::redirect();
	}

	public static function select_space(): void {
		self::assert_admin( 'spacefast_wordpress_select_space' );
		$space_id = sanitize_text_field( wp_unslash( $_POST['space_id'] ?? '' ) );
		$choices = get_option( Spacefast_OAuth::CHOICES_OPTION, array() );
		if ( ! preg_match( '/^spc_[A-Za-z0-9_-]+$/', $space_id ) || time() > (int) ( $choices['expires_at'] ?? 0 ) ) {
			self::notice( 'error', __( 'Space choices expired. Authorize again.', 'spacefast-wordpress' ) );
			self::redirect();
		}
		$spaces = is_array( $choices['spaces'] ?? null ) ? $choices['spaces'] : array();
		$teams = is_array( $choices['teams'] ?? null ) ? $choices['teams'] : array();
		$space = null;
		foreach ( $spaces as $candidate ) {
			if ( is_array( $candidate ) && hash_equals( (string) ( $candidate['id'] ?? '' ), $space_id ) ) $space = $candidate;
		}
		if ( ! is_array( $space ) ) {
			self::notice( 'error', __( 'That Space is no longer available. Authorize again.', 'spacefast-wordpress' ) );
			self::redirect();
		}
		$team_id = (string) ( $space['teamId'] ?? '' );
		$team = null;
		foreach ( $teams as $candidate ) {
			if ( is_array( $candidate ) && hash_equals( (string) ( $candidate['id'] ?? '' ), $team_id ) ) $team = $candidate;
		}
		$result = self::connect_space( $space, is_array( $team ) ? $team : array() );
		self::notice(
			$result['ok'] ? 'success' : 'error',
			$result['ok'] ? self::setup_started_message() : $result['message']
		);
		self::redirect();
	}

	public static function create_space(): void {
		self::assert_admin( 'spacefast_wordpress_create_space' );
		if ( Spacefast_Settings::MODE_STATIC !== Spacefast_Settings::mode() ) {
			self::notice( 'error', __( 'New headless Spaces need a connected repository. Create one in Spacefast, then choose it here.', 'spacefast-wordpress' ) );
			self::redirect();
		}
		if ( ! Spacefast_OAuth::has_scope( 'spaces:write' ) ) {
			self::notice( 'error', __( 'Reauthorize Spacefast before creating a Space.', 'spacefast-wordpress' ) );
			self::redirect();
		}
		$choices = get_option( Spacefast_OAuth::CHOICES_OPTION, array() );
		$teams = is_array( $choices['teams'] ?? null ) ? array_values( $choices['teams'] ) : array();
		if ( time() > (int) ( $choices['expires_at'] ?? 0 ) || 1 !== count( $teams ) || ! is_array( $teams[0] ) ) {
			self::notice( 'error', __( 'Team authorization expired. Start again.', 'spacefast-wordpress' ) );
			self::redirect();
		}
		$team = $teams[0];
		$team_id = (string) ( $team['id'] ?? '' );
		$creation = get_option( Spacefast_OAuth::CREATION_OPTION, array() );
		$creation = is_array( $creation ) ? $creation : array();
		$reuse_creation = $team_id === (string) ( $creation['team_id'] ?? '' )
			&& 1 === preg_match( '/^wordpress-space-[0-9a-f-]{36}$/', (string) ( $creation['idempotency_key'] ?? '' ) );
		if ( $reuse_creation ) {
			$title = (string) ( $creation['title'] ?? '' );
			$idempotency_key = (string) $creation['idempotency_key'];
		} else {
			$title = sanitize_text_field( wp_unslash( $_POST['space_title'] ?? '' ) );
			if ( '' === $title ) $title = sanitize_text_field( (string) get_bloginfo( 'name' ) );
			$idempotency_key = 'wordpress-space-' . wp_generate_uuid4();
			$creation = array(
				'team_id' => $team_id,
				'title' => $title,
				'idempotency_key' => $idempotency_key,
			);
		}
		if ( ! preg_match( '/^team_[A-Za-z0-9_-]+$/', $team_id ) || '' === $title ) {
			self::notice( 'error', __( 'Spacefast could not determine the Team or Space name.', 'spacefast-wordpress' ) );
			self::redirect();
		}
		if ( ! $reuse_creation ) update_option( Spacefast_OAuth::CREATION_OPTION, $creation, false );
		$space = $creation['space'] ?? null;
		if ( ! is_array( $space ) || ! preg_match( '/^spc_[A-Za-z0-9_-]+$/', (string) ( $space['id'] ?? '' ) ) ) {
			$created = ( new Spacefast_Client() )->create_space( $team_id, $title, $idempotency_key );
			$space = $created['data']['space'] ?? null;
			if ( ! $created['ok'] || ! is_array( $space ) || ! preg_match( '/^spc_[A-Za-z0-9_-]+$/', (string) ( $space['id'] ?? '' ) ) ) {
				self::notice( 'error', $created['ok'] ? __( 'Spacefast returned an invalid Space.', 'spacefast-wordpress' ) : $created['message'] );
				self::redirect();
			}
			$creation['space'] = $space;
			update_option( Spacefast_OAuth::CREATION_OPTION, $creation, false );
		}
		Spacefast_OAuth::remember_space_choice( $space );
		$result = self::connect_space( $space, $team );
		self::notice(
			$result['ok'] ? 'success' : 'error',
			$result['ok'] ? self::setup_started_message() : $result['message']
		);
		self::redirect();
	}

	/**
	 * @param array<string,mixed> $space Spacefast Space.
	 * @param array<string,mixed> $team Authorized Team.
	 * @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>}
	 */
	private static function connect_space( array $space, array $team ): array {
		Spacefast_Settings::merge(
			array(
				'team_id' => (string) ( $space['teamId'] ?? $team['id'] ?? '' ),
				'team_name' => (string) ( $team['name'] ?? $space['teamSlug'] ?? '' ),
				'team_slug' => (string) ( $team['slug'] ?? $space['teamSlug'] ?? '' ),
				'space_id' => (string) $space['id'],
				'space_name' => (string) ( $space['title'] ?? $space['name'] ?? $space['slug'] ?? '' ),
				'space_slug' => (string) ( $space['slug'] ?? '' ),
				'live_url' => (string) ( $space['liveUrl'] ?? '' ),
				'verified_at' => 0,
			)
		);
		self::mark_settings_pending();
		$settings_sync = self::sync_space_settings();
		if ( ! $settings_sync['ok'] ) {
			self::static_publish_failed( $settings_sync['message'] );
			return $settings_sync;
		}
		$result = ( new Spacefast_Client() )->health();
		if ( ! $result['ok'] ) {
			self::static_publish_failed( $result['message'] );
			return $result;
		}
		delete_option( Spacefast_OAuth::CHOICES_OPTION );
		delete_option( Spacefast_OAuth::CREATION_OPTION );
		self::configure_simply_static();
		self::record_change( 'first_publish', true );
		wp_clear_scheduled_hook( Spacefast_Sync_State::HOOK );
		self::deliver();
		return array(
			'ok' => true,
			'retryable' => false,
			'code' => 'setup_started',
			'message' => '',
			'data' => array(),
		);
	}

	private static function setup_started_message(): string {
		return Spacefast_Settings::MODE_STATIC === Spacefast_Settings::mode()
			? __( 'Generating and publishing the first version now.', 'spacefast-wordpress' )
			: __( 'Space selected. Building the first version now.', 'spacefast-wordpress' );
	}

	public static function change_space(): void {
		self::assert_admin( 'spacefast_wordpress_change_space' );
		$result = ( new Spacefast_OAuth() )->load_choices();
		if ( $result['ok'] ) {
			Spacefast_Settings::merge(
				array(
					'space_id' => '',
					'space_name' => '',
					'space_slug' => '',
					'live_url' => '',
					'verified_at' => 0,
				)
			);
		}
		self::notice(
			$result['ok'] ? 'success' : 'error',
			$result['ok']
				? __( 'Choose the new Space for this WordPress site.', 'spacefast-wordpress' )
				: $result['message']
		);
		self::redirect();
	}

	public static function disconnect(): void {
		self::assert_admin( 'spacefast_wordpress_disconnect' );
		( new Spacefast_OAuth() )->revoke();
		Spacefast_Settings::disconnect();
		self::notice( 'success', __( 'Spacefast disconnected and its refresh access was revoked.', 'spacefast-wordpress' ) );
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
		self::record_change( 'manual', true );
		wp_clear_scheduled_hook( Spacefast_Sync_State::HOOK );
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
		self::record_change( 'manual', true );
		wp_clear_scheduled_hook( Spacefast_Sync_State::HOOK );
		self::deliver();
		$state = Spacefast_Sync_State::get();
		$active = in_array( (string) $state['last_status'], array( 'exporting', 'uploading', 'finalizing' ), true );
		self::notice(
			$active ? 'success' : 'error',
			$active
				? __( 'Publishing started. You can leave this page.', 'spacefast-wordpress' )
				: ( (string) $state['last_message'] ?: __( 'The publish could not start. The current live version is safe.', 'spacefast-wordpress' ) )
		);
		self::redirect();
	}

	public static function save_settings(): void {
		self::assert_admin( 'spacefast_wordpress_save_settings' );
		Spacefast_Settings::merge(
			array(
				'automatic_sync' => isset( $_POST['automatic_sync'] ),
				'sync_title' => isset( $_POST['sync_title'] ),
				'sync_visibility' => isset( $_POST['sync_visibility'] ),
				'sync_source' => isset( $_POST['sync_source'] ),
			)
		);
		self::mark_settings_pending();
		$result = self::sync_space_settings();
		self::notice(
			$result['ok'] ? 'success' : 'error',
			$result['ok']
				? __( 'WordPress settings synced to the Space.', 'spacefast-wordpress' )
				: $result['message']
		);
		self::redirect();
	}

	public static function manual_settings_sync(): void {
		self::assert_admin( 'spacefast_wordpress_sync_settings' );
		self::mark_settings_pending();
		$result = self::sync_space_settings();
		self::notice(
			$result['ok'] ? 'success' : 'error',
			$result['ok']
				? __( 'WordPress settings synced to the Space.', 'spacefast-wordpress' )
				: $result['message']
		);
		self::redirect();
	}

	public static function wordpress_setting_changed(): void {
		$settings = Spacefast_Settings::get();
		if ( empty( $settings['automatic_sync'] ) || ! Spacefast_Settings::selected() ) return;
		if ( ! Spacefast_OAuth::has_scope( 'spaces:write' ) ) {
			self::record_change( 'settings' );
			return;
		}
		self::mark_settings_pending();
		self::record_change( 'settings' );
	}

	private static function mark_settings_pending(): void {
		Spacefast_Sync_State::mutate(
			static function ( array $state ): array {
				$state['settings_pending'] = true;
				return $state;
			}
		);
	}

	/** @return array{ok:bool,retryable:bool,code:string,message:string,data:array<string,mixed>} */
	private static function sync_space_settings(): array {
		if ( ! Spacefast_Settings::selected() ) {
			return array(
				'ok' => false,
				'retryable' => false,
				'code' => 'space_required',
				'message' => __( 'Choose a Space before syncing settings.', 'spacefast-wordpress' ),
				'data' => array(),
			);
		}
		if ( ! Spacefast_OAuth::has_scope( 'spaces:write' ) ) {
			return array(
				'ok' => false,
				'retryable' => false,
				'code' => 'reauthorization_required',
				'message' => __( 'Reconnect Spacefast to sync WordPress settings.', 'spacefast-wordpress' ),
				'data' => array(),
			);
		}
		$client = new Spacefast_Client();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$current = $client->get_space();
			if ( ! $current['ok'] ) return $current;
			$space = $current['data'];
			$body = Spacefast_Site_Settings::patch(
				$space,
				Spacefast_Settings::get(),
				Spacefast_Site_Settings::snapshot(),
				Spacefast_Settings::mode()
			);
			$result = $client->update_space( $body );
			if ( ! $result['ok'] && 'name_managed_by_config' === $result['code'] && isset( $body['title'] ) ) {
				unset( $body['title'] );
				$result = $client->update_space( $body );
			}
			if ( $result['ok'] ) {
				$updated = $result['data'];
				Spacefast_Settings::merge(
					array(
						'space_name' => (string) ( $updated['title'] ?? Spacefast_Settings::get()['space_name'] ),
						'live_url' => (string) ( $updated['liveUrl'] ?? Spacefast_Settings::get()['live_url'] ),
						'last_settings_sync_at' => time(),
					)
				);
				Spacefast_Sync_State::mutate(
					static fn( array $state ): array => Spacefast_Sync_State::acknowledge_settings_sync( $state )
				);
				return $result;
			}
			if ( 'publish_base_changed' !== $result['code'] ) return $result;
		}
		return array(
			'ok' => false,
			'retryable' => true,
			'code' => 'publish_base_changed',
			'message' => __( 'Space settings changed at the same time. Nothing was overwritten; try again.', 'spacefast-wordpress' ),
			'data' => array(),
		);
	}

	/** @return array{ok:bool,message:string} */
	private static function install_simply_static(): array {
		if ( self::simply_static_available() ) return array( 'ok' => true, 'message' => '' );
		$plugin = 'simply-static/simply-static.php';
		if ( ! function_exists( 'is_plugin_active' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			if ( ! current_user_can( 'install_plugins' ) ) {
				return array( 'ok' => false, 'message' => __( 'Ask an administrator to install Simply Static.', 'spacefast-wordpress' ) );
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			$info = plugins_api( 'plugin_information', array( 'slug' => 'simply-static', 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $info ) || ! is_object( $info ) || empty( $info->download_link ) ) {
				return array( 'ok' => false, 'message' => __( 'Simply Static could not be downloaded. Try again from Plugins.', 'spacefast-wordpress' ) );
			}
			$installed = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->install( $info->download_link );
			if ( true !== $installed ) {
				return array( 'ok' => false, 'message' => __( 'Simply Static could not be installed. Try again from Plugins.', 'spacefast-wordpress' ) );
			}
		}
		if ( ! is_plugin_active( $plugin ) ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return array( 'ok' => false, 'message' => __( 'Ask an administrator to activate Simply Static.', 'spacefast-wordpress' ) );
			}
			$error = activate_plugin( $plugin );
			if ( is_wp_error( $error ) ) {
				return array( 'ok' => false, 'message' => __( 'Simply Static could not be activated. Try again from Plugins.', 'spacefast-wordpress' ) );
			}
		}
		return array( 'ok' => true, 'message' => '' );
	}

	public static function post_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$post_type = get_post_type_object( $post->post_type );
		if (
			! $post_type
			|| ! $post_type->public
			|| ( Spacefast_Settings::MODE_HEADLESS === Spacefast_Settings::mode() && ! $post_type->show_in_rest )
		) {
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
		if (
			! $post_type
			|| ! $post_type->public
			|| ( Spacefast_Settings::MODE_HEADLESS === Spacefast_Settings::mode() && ! $post_type->show_in_rest )
		) {
			return;
		}
		if ( 'publish' === $post->post_status ) {
			self::deletion_changed();
		}
	}

	public static function deletion_changed(): void {
		if ( Spacefast_Settings::MODE_STATIC === Spacefast_Settings::mode() ) {
			update_option( 'spacefast_wordpress_snapshot_required', 1, false );
		}
		self::record_change( 'deleted' );
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

	private static function record_change( string $reason, bool $force = false ): void {
		if (
			self::$change_recorded
			|| ! Spacefast_Settings::selected()
			|| ( ! $force && empty( Spacefast_Settings::get()['automatic_sync'] ) )
		) {
			return;
		}
		self::$change_recorded = true;
		$event_id = (string) wp_generate_uuid4();
		$state = Spacefast_Sync_State::mutate(
			static fn( array $state ): array => Spacefast_Sync_State::record_change(
				$state,
				$reason,
				$event_id
			)
		);
		self::schedule_after_change( (int) $state['last_change_at'] );
	}

	private static function schedule_after_change( int $changed_at ): void {
		$timestamp = max( time() + 1, $changed_at + self::AUTOMATIC_DEBOUNCE_SECONDS );
		$scheduled = wp_next_scheduled( Spacefast_Sync_State::HOOK );
		if ( $scheduled === $timestamp ) {
			return;
		}
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, Spacefast_Sync_State::HOOK );
		}
		wp_schedule_single_event( $timestamp, Spacefast_Sync_State::HOOK );
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
		if ( ! Spacefast_Settings::selected() ) return;
		$now = time();
		$owner = (string) wp_generate_uuid4();
		if ( ! Spacefast_Sync_State::claim_lock( self::LOCK_OPTION, $owner, $now ) ) {
			self::schedule( $now + 60 );
			return;
		}

		try {
			$snapshot = Spacefast_Sync_State::get();
			if ( ! empty( $snapshot['settings_pending'] ) ) {
				$settings_sync = self::sync_space_settings();
				if ( ! $settings_sync['ok'] ) {
					Spacefast_Sync_State::mutate(
						static function ( array $state ) use ( $settings_sync ): array {
							$state['last_status'] = 'blocked';
							$state['last_message'] = $settings_sync['message'];
							return $state;
						}
					);
					if ( $settings_sync['retryable'] ) self::schedule( time() + 60 );
					return;
				}
				$snapshot = Spacefast_Sync_State::get();
			}
			if ( Spacefast_Settings::MODE_STATIC === Spacefast_Settings::mode() ) {
				self::deliver_static( $snapshot );
				return;
			}
			if ( 'building' === (string) $snapshot['last_status'] && '' !== (string) $snapshot['last_build_id'] ) {
				self::poll_headless_build( $snapshot );
				return;
			}
			$generation = (int) $snapshot['desired'];
			if ( $generation <= (int) $snapshot['delivered'] || '' === $snapshot['event_id'] ) {
				return;
			}
			Spacefast_Sync_State::mutate(
				static function ( array $state ): array {
					$state['last_attempt_at'] = time();
					return $state;
				}
			);
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
				self::schedule( time() + 15 );
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

	/** @param array<string,mixed> $snapshot Current delivery state. */
	private static function poll_headless_build( array $snapshot ): void {
		$result = ( new Spacefast_Client() )->get_build( (string) $snapshot['last_build_id'] );
		if ( ! $result['ok'] ) {
			if ( $result['retryable'] ) self::schedule( time() + 60 );
			else self::static_publish_failed( $result['message'] );
			return;
		}
		$build = $result['data'];
		$status = (string) ( $build['status'] ?? '' );
		if ( in_array( $status, array( 'queued', 'running', 'waiting_for_source', 'uploading_source' ), true ) ) {
			self::schedule( time() + 15 );
			return;
		}
		$diagnostics = isset( $build['diagnostics'] ) && is_array( $build['diagnostics'] ) ? $build['diagnostics'] : array();
		$message = '';
		foreach ( $diagnostics as $diagnostic ) {
			if ( is_array( $diagnostic ) && 'error' === ( $diagnostic['severity'] ?? '' ) ) {
				$message = sanitize_text_field( (string) ( $diagnostic['message'] ?? '' ) );
				break;
			}
		}
		$current = Spacefast_Sync_State::mutate(
			static fn( array $state ): array => Spacefast_Sync_State::acknowledge_build_terminal( $state, $status, $message )
		);
		if ( 'succeeded' === $status ) {
			Spacefast_Settings::merge( array( 'verified_at' => time() ) );
			if ( (int) $current['desired'] > (int) $current['delivered'] ) {
				self::schedule_after_change( (int) $current['last_change_at'] );
			}
		}
	}

	/** @param array<string,mixed> $snapshot Current delivery state. */
	private static function deliver_static( array $snapshot ): void {
		if ( in_array( (string) $snapshot['last_status'], array( 'exporting', 'uploading', 'finalizing' ), true ) ) return;
		if ( (int) $snapshot['desired'] <= (int) $snapshot['delivered'] ) return;
		if ( ! self::simply_static_available() ) {
			self::static_publish_failed( __( 'Simply Static is required. Install it, then retry; the current live version is safe.', 'spacefast-wordpress' ) );
			return;
		}
		self::configure_simply_static();
		try {
			$started = \Simply_Static\Plugin::instance()->run_static_export();
			if ( ! $started ) {
				self::schedule( time() + 60 );
				return;
			}
			Spacefast_Sync_State::mutate(
				static function ( array $state ): array {
					$state['last_status'] = 'exporting';
					$state['last_message'] = '';
					return $state;
				}
			);
		} catch ( Throwable $error ) {
			self::static_publish_failed( $error->getMessage() );
		}
	}

	public static function register_simply_static(): void {
		if ( ! self::simply_static_available() ) {
			return;
		}
		self::configure_simply_static();
		require_once dirname( __DIR__ ) . '/includes/class-spacefast-simply-static-task.php';
		add_filter( 'simplystatic.archive_creation_job.task_list', array( __CLASS__, 'simply_static_tasks' ), 20, 2 );
		add_filter( 'simply_static_class_name', array( __CLASS__, 'simply_static_task_class' ), 20, 2 );
		add_action( 'ss_archive_creation_job_after_start_queue', array( __CLASS__, 'static_export_started' ), 20 );
	}

	private static function configure_simply_static(): void {
		if ( Spacefast_Settings::MODE_STATIC !== Spacefast_Settings::mode() || ! self::simply_static_available() ) return;
		$live_url = (string) Spacefast_Settings::get()['live_url'];
		$parts = wp_parse_url( $live_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return;
		$host = (string) $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		\Simply_Static\Options::instance()
			->set( 'destination_url_type', 'absolute' )
			->set( 'destination_scheme', (string) $parts['scheme'] . '://' )
			->set( 'destination_host', $host )
			->save();
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
			|| ! Spacefast_Settings::selected()
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
			|| ! Spacefast_Settings::selected()
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
				$next['active_generation'] = (int) $next['desired'];
				return $next;
			}
		);
	}

	public static function static_publish_completed( string $version_id, string $status ): void {
		$current = Spacefast_Sync_State::mutate(
			static fn( array $state ): array => Spacefast_Sync_State::acknowledge_static(
				$state,
				$version_id,
				$status
			)
		);
		if ( 'live' === $status ) Spacefast_Settings::merge( array( 'verified_at' => time() ) );
		if ( (int) $current['desired'] > (int) $current['delivered'] ) {
			self::schedule_after_change( (int) $current['last_change_at'] );
		}
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
		if ( Spacefast_Settings::MODE_HEADLESS === Spacefast_Settings::mode() ) {
			$tests['direct']['spacefast_cron'] = array(
				'label' => __( 'Spacefast background delivery', 'spacefast-wordpress' ),
				'test' => array( __CLASS__, 'site_health_cron_result' ),
			);
		}
		return $tests;
	}

	/** @return array<string,mixed> */
	public static function site_health_cron_result(): array {
		$state = Spacefast_Sync_State::get();
		$pending = (int) $state['desired'] > (int) $state['delivered'];
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$missing = $pending && false === wp_next_scheduled( Spacefast_Sync_State::HOOK );
		$delayed = $pending && ( $missing || ( 0 < (int) $state['next_at'] && (int) $state['next_at'] + 300 < time() ) );
		$healthy = ! $disabled && ! $delayed;
		return array(
			'label' => $healthy
				? __( 'Spacefast background delivery is ready', 'spacefast-wordpress' )
				: __( 'Spacefast background delivery needs attention', 'spacefast-wordpress' ),
			'status' => $healthy ? 'good' : 'recommended',
			'badge' => array( 'label' => 'Spacefast', 'color' => 'blue' ),
			'description' => '<p>' . esc_html(
				$disabled
					? __( 'WP-Cron is disabled. Configure the server to request wp-cron.php regularly so content changes trigger builds.', 'spacefast-wordpress' )
					: ( $delayed
						? __( 'A build trigger is delayed. The current live site is safe; open Settings → Spacefast and retry.', 'spacefast-wordpress' )
						: __( 'WordPress can deliver content changes in the background.', 'spacefast-wordpress' ) )
			) . '</p>',
			'test' => 'spacefast_cron',
		);
	}

	public static function status_label( string $status, bool $static_mode ): string {
		$labels = array(
			'idle' => __( 'Ready', 'spacefast-wordpress' ),
			'pending' => $static_mode ? __( 'Waiting for export', 'spacefast-wordpress' ) : __( 'Changes waiting to build', 'spacefast-wordpress' ),
			'building' => __( 'Building the new version', 'spacefast-wordpress' ),
			'exporting' => __( 'Generating static site', 'spacefast-wordpress' ),
			'uploading' => __( 'Publishing files', 'spacefast-wordpress' ),
			'finalizing' => __( 'Activating new version', 'spacefast-wordpress' ),
			'live' => __( 'Published live', 'spacefast-wordpress' ),
			'ready' => __( 'Version ready', 'spacefast-wordpress' ),
			'unchanged' => __( 'Already up to date', 'spacefast-wordpress' ),
			'retrying' => __( 'Retrying automatically', 'spacefast-wordpress' ),
			'blocked' => __( 'Needs attention', 'spacefast-wordpress' ),
		);
		return (string) ( $labels[ $status ] ?? __( 'Unknown', 'spacefast-wordpress' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function site_health_result(): array {
		$connected = Spacefast_Settings::configured();
		$selected = Spacefast_Settings::selected();
		$result = $selected
			? ( new Spacefast_Client() )->health()
			: array( 'ok' => false );
		$healthy = $connected && true === ( $result['ok'] ?? false );
		return array(
			'label' => $healthy
				? __( 'Spacefast is connected', 'spacefast-wordpress' )
				: ( $selected ? __( 'Spacefast is finishing setup', 'spacefast-wordpress' ) : __( 'Spacefast needs attention', 'spacefast-wordpress' ) ),
			'status' => $healthy ? 'good' : ( $selected ? 'recommended' : 'critical' ),
			'badge' => array(
				'label' => 'Spacefast',
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html(
				$healthy
					? ( Spacefast_Settings::MODE_STATIC === Spacefast_Settings::mode()
						? __( 'Simply Static exports can publish to this Space.', 'spacefast-wordpress' )
						: __( 'Public content changes can trigger repository builds.', 'spacefast-wordpress' ) )
					: ( $selected
						? __( 'The first publish or build has not completed yet. The previous live version, if any, is safe.', 'spacefast-wordpress' )
						: __( 'Open Settings → Spacefast to repair the connection.', 'spacefast-wordpress' ) )
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
		$settings = Spacefast_Settings::get();
		$authorized = Spacefast_Settings::authorized();
		$selected = Spacefast_Settings::selected();
		$configured = Spacefast_Settings::configured();
		$mode = Spacefast_Settings::mode();
		$static_mode = Spacefast_Settings::MODE_STATIC === $mode;
		$can_create_space = Spacefast_OAuth::has_scope( 'spaces:write' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spacefast', 'spacefast-wordpress' ); ?></h1>
			<p><?php esc_html_e( 'Publish this WordPress site to a fast, static Space.', 'spacefast-wordpress' ); ?></p>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $authorized ) : ?>
				<div class="card" style="max-width:720px;padding:24px">
					<h2 style="margin-top:0"><?php esc_html_e( 'Publish with Spacefast', 'spacefast-wordpress' ); ?></h2>
					<p><?php esc_html_e( 'We will prepare a static copy, create a Space, and publish the first version.', 'spacefast-wordpress' ); ?></p>
					<p><?php esc_html_e( 'You will sign in to Spacefast and authorize exactly one Team.', 'spacefast-wordpress' ); ?></p>
					<?php if ( self::simply_static_available() ) : ?>
						<?php self::oauth_action_form( Spacefast_Settings::MODE_STATIC, __( 'Publish with Spacefast', 'spacefast-wordpress' ), 'primary' ); ?>
					<?php elseif ( current_user_can( 'install_plugins' ) || ( file_exists( WP_PLUGIN_DIR . '/simply-static/simply-static.php' ) && current_user_can( 'activate_plugins' ) ) ) : ?>
						<?php self::action_form( 'spacefast_wordpress_prepare_static', __( 'Install Simply Static and continue', 'spacefast-wordpress' ), 'primary' ); ?>
					<?php else : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'Ask an administrator to install and activate Simply Static, then return here.', 'spacefast-wordpress' ); ?></p></div>
					<?php endif; ?>
				</div>
				<?php $issues = self::preflight_issues(); ?>
				<?php if ( $issues ) : ?><div class="notice notice-warning inline" style="max-width:720px"><p><strong><?php esc_html_e( 'Before the first publish', 'spacefast-wordpress' ); ?></strong></p><ul style="list-style:disc;padding-left:20px"><?php foreach ( $issues as $issue ) : ?><li><?php echo esc_html( $issue ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
				<details style="max-width:720px;margin-top:20px"><summary><strong><?php esc_html_e( 'Use WordPress as a CMS for a repository site', 'spacefast-wordpress' ); ?></strong></summary>
					<p><?php esc_html_e( 'Choose this when an Astro or other repository project reads the WordPress REST API during builds.', 'spacefast-wordpress' ); ?></p>
					<?php self::oauth_action_form( Spacefast_Settings::MODE_HEADLESS, __( 'Connect a repository Space', 'spacefast-wordpress' ) ); ?>
				</details>
			<?php elseif ( ! $selected ) : ?>
				<h2><?php esc_html_e( 'Choose a Space', 'spacefast-wordpress' ); ?></h2>
				<p><?php esc_html_e( 'Only Spaces from the Team you authorized are shown.', 'spacefast-wordpress' ); ?></p>
				<?php $choices = get_option( Spacefast_OAuth::CHOICES_OPTION, array() ); $spaces = is_array( $choices['spaces'] ?? null ) ? $choices['spaces'] : array(); ?>
				<?php $creation = get_option( Spacefast_OAuth::CREATION_OPTION, array() ); $creation = is_array( $creation ) ? $creation : array(); $created_space = is_array( $creation['space'] ?? null ) ? $creation['space'] : null; ?>
				<?php if ( $static_mode && $can_create_space && ! $created_space ) : ?>
					<div class="card" style="max-width:720px;padding:24px">
						<h3 style="margin-top:0"><?php esc_html_e( 'Create a new Space', 'spacefast-wordpress' ); ?></h3>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="spacefast_wordpress_create_space">
							<?php wp_nonce_field( 'spacefast_wordpress_create_space' ); ?>
							<label for="spacefast-space-title"><strong><?php esc_html_e( 'Space name', 'spacefast-wordpress' ); ?></strong></label><br>
							<input id="spacefast-space-title" name="space_title" type="text" class="regular-text" maxlength="255" required value="<?php echo esc_attr( (string) ( $creation['title'] ?? get_bloginfo( 'name' ) ) ); ?>">
							<?php submit_button( __( 'Create Space and publish', 'spacefast-wordpress' ), 'primary', 'submit', false ); ?>
						</form>
					</div>
				<?php elseif ( $static_mode && $created_space ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'The Space was created, but the first publish did not start. Choose it below to retry safely.', 'spacefast-wordpress' ); ?></p></div>
				<?php elseif ( $static_mode ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Reconnect Spacefast to create a Space from WordPress.', 'spacefast-wordpress' ); ?></p></div>
					<?php self::oauth_action_form( Spacefast_Settings::MODE_STATIC, __( 'Reconnect Spacefast', 'spacefast-wordpress' ) ); ?>
				<?php endif; ?>
				<?php if ( $spaces ) : ?>
					<?php if ( $static_mode ) : ?><details style="max-width:720px;margin-top:20px"><summary><?php esc_html_e( 'Use an existing Space', 'spacefast-wordpress' ); ?></summary><?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px;margin-top:12px">
						<input type="hidden" name="action" value="spacefast_wordpress_select_space">
						<?php wp_nonce_field( 'spacefast_wordpress_select_space' ); ?>
						<label for="spacefast-space"><strong><?php esc_html_e( 'Space', 'spacefast-wordpress' ); ?></strong></label><br>
						<select id="spacefast-space" name="space_id" class="regular-text" required>
							<option value=""><?php esc_html_e( 'Choose a Space…', 'spacefast-wordpress' ); ?></option>
							<?php foreach ( $spaces as $space ) : if ( ! is_array( $space ) ) continue; ?>
								<option value="<?php echo esc_attr( (string) ( $space['id'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $space['title'] ?? $space['name'] ?? $space['slug'] ?? '' ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( $static_mode ? __( 'Use this Space and publish', 'spacefast-wordpress' ) : __( 'Connect and build', 'spacefast-wordpress' ) ); ?>
					</form>
					<?php if ( $static_mode ) : ?></details><?php endif; ?>
				<?php else : ?>
					<?php if ( ! $static_mode ) : ?><div class="notice notice-error inline"><p><?php esc_html_e( 'No repository-backed Spaces are available. Create one in Spacefast, connect its repository, then reconnect.', 'spacefast-wordpress' ); ?></p></div><?php endif; ?>
				<?php endif; ?>
				<?php self::action_form( 'spacefast_wordpress_disconnect', __( 'Start over', 'spacefast-wordpress' ), 'secondary' ); ?>
			<?php else : ?>
				<div class="card" style="max-width:720px;padding:24px">
					<h2 style="margin-top:0"><?php echo esc_html( $configured ? self::status_label( (string) $state['last_status'], $static_mode ) : __( 'Finishing setup', 'spacefast-wordpress' ) ); ?></h2>
					<p><strong><?php echo esc_html( (string) $settings['space_name'] ); ?></strong> · <?php echo esc_html( (string) $settings['team_name'] ); ?></p>
					<?php if ( $state['last_message'] ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( (string) $state['last_message'] ); ?></p></div><?php endif; ?>
					<?php if ( $settings['live_url'] ) : ?><p><a class="button button-secondary" href="<?php echo esc_url( (string) $settings['live_url'] ); ?>" target="_blank" rel="external noreferrer noopener"><?php esc_html_e( 'Open live site', 'spacefast-wordpress' ); ?></a></p><?php endif; ?>
					<?php if ( $state['last_success_at'] ) : ?><p class="description"><?php echo esc_html( sprintf( __( 'Last published %s', 'spacefast-wordpress' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['last_success_at'] ) ) ); ?></p><?php endif; ?>
					<?php if ( max( 0, (int) $state['desired'] - (int) $state['delivered'] ) ) : ?><p><?php esc_html_e( 'Changes are waiting. Spacefast will combine them into the next publish.', 'spacefast-wordpress' ); ?></p><?php endif; ?>
					<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
						<?php if ( $static_mode ) : ?><?php self::action_form( 'spacefast_wordpress_publish', $configured ? __( 'Publish now', 'spacefast-wordpress' ) : __( 'Retry publish', 'spacefast-wordpress' ), 'primary' ); ?>
						<?php else : ?><?php self::action_form( 'spacefast_wordpress_build', $configured ? __( 'Build now', 'spacefast-wordpress' ) : __( 'Retry build', 'spacefast-wordpress' ), 'primary' ); ?><?php endif; ?>
					</div>
				</div>
				<h2><?php esc_html_e( 'WordPress sync', 'spacefast-wordpress' ); ?></h2>
				<?php if ( ! Spacefast_OAuth::has_scope( 'spaces:write' ) ) : ?>
					<div class="notice notice-warning inline" style="max-width:720px"><p><?php esc_html_e( 'Reconnect once to let WordPress keep this Space’s settings in sync.', 'spacefast-wordpress' ); ?></p><?php self::oauth_action_form( $mode, __( 'Reconnect Spacefast', 'spacefast-wordpress' ) ); ?></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px">
					<input type="hidden" name="action" value="spacefast_wordpress_save_settings">
					<?php wp_nonce_field( 'spacefast_wordpress_save_settings' ); ?>
					<label style="display:block;margin:10px 0"><input type="checkbox" name="automatic_sync" value="1" <?php checked( ! empty( $settings['automatic_sync'] ) ); ?>> <strong><?php esc_html_e( 'Publish automatically when WordPress changes', 'spacefast-wordpress' ); ?></strong><br><span class="description" style="margin-left:24px"><?php esc_html_e( 'A 60-second quiet window combines a batch of edits into one publish.', 'spacefast-wordpress' ); ?></span></label>
					<label style="display:block;margin:10px 0"><input type="checkbox" name="sync_title" value="1" <?php checked( ! empty( $settings['sync_title'] ) ); ?>> <?php esc_html_e( 'Sync the site title and description', 'spacefast-wordpress' ); ?></label>
					<label style="display:block;margin:10px 0"><input type="checkbox" name="sync_visibility" value="1" <?php checked( ! empty( $settings['sync_visibility'] ) ); ?>> <?php esc_html_e( 'Sync search engine visibility', 'spacefast-wordpress' ); ?></label>
					<?php if ( ! $static_mode ) : ?><label style="display:block;margin:10px 0"><input type="checkbox" name="sync_source" value="1" <?php checked( ! empty( $settings['sync_source'] ) ); ?>> <?php esc_html_e( 'Use this site as the repository build’s WordPress source', 'spacefast-wordpress' ); ?></label><?php else : ?><input type="hidden" name="sync_source" value="1"><?php endif; ?>
					<?php submit_button( __( 'Save and sync', 'spacefast-wordpress' ), 'secondary', 'submit', false ); ?>
				</form>
				<?php if ( $settings['last_settings_sync_at'] ) : ?><p class="description"><?php echo esc_html( sprintf( __( 'Settings last synced %s', 'spacefast-wordpress' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $settings['last_settings_sync_at'] ) ) ); ?></p><?php endif; ?>
				<details style="max-width:720px;margin-top:12px"><summary><?php esc_html_e( 'Technical details', 'spacefast-wordpress' ); ?></summary><p><?php echo esc_html( $static_mode ? __( 'Version ID:', 'spacefast-wordpress' ) : __( 'Build ID:', 'spacefast-wordpress' ) ); ?> <code><?php echo esc_html( (string) ( $static_mode ? ( $state['last_version_id'] ?: '—' ) : ( $state['last_build_id'] ?: '—' ) ) ); ?></code></p></details>
				<?php if ( $static_mode && ! self::simply_static_available() ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Static WordPress needs the free Simply Static plugin.', 'spacefast-wordpress' ); ?> <?php echo wp_kses_post( self::simply_static_action_link() ); ?></p></div>
				<?php endif; ?>
				<details style="max-width:720px;margin-top:16px"><summary><?php esc_html_e( 'Change connection', 'spacefast-wordpress' ); ?></summary>
					<div style="display:flex;gap:8px;margin-top:12px">
						<?php self::action_form( 'spacefast_wordpress_test', __( 'Run connection check', 'spacefast-wordpress' ), 'secondary' ); ?>
						<?php self::action_form( 'spacefast_wordpress_sync_settings', __( 'Sync settings now', 'spacefast-wordpress' ), 'secondary' ); ?>
						<?php self::action_form( 'spacefast_wordpress_change_space', __( 'Change Space', 'spacefast-wordpress' ), 'secondary' ); ?>
						<?php self::oauth_action_form( $mode, __( 'Reauthorize Team', 'spacefast-wordpress' ) ); ?>
						<?php self::oauth_action_form( $static_mode ? Spacefast_Settings::MODE_HEADLESS : Spacefast_Settings::MODE_STATIC, $static_mode ? __( 'Switch to Headless CMS', 'spacefast-wordpress' ) : __( 'Switch to Static WordPress', 'spacefast-wordpress' ) ); ?>
					</div>
					<div style="margin-top:12px"><?php self::action_form( 'spacefast_wordpress_disconnect', __( 'Disconnect', 'spacefast-wordpress' ), 'delete' ); ?></div>
				</details>
				<p class="description"><?php esc_html_e( 'OAuth tokens are stored as non-autoloaded WordPress options and are never displayed.', 'spacefast-wordpress' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function simply_static_action_link(): string {
		$plugin = 'simply-static/simply-static.php';
		if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			if ( ! current_user_can( 'activate_plugins' ) ) return esc_html__( 'Ask an administrator to activate it.', 'spacefast-wordpress' );
			$url = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin ) ), 'activate-plugin_' . $plugin );
			return '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Activate Simply Static', 'spacefast-wordpress' ) . '</a>';
		}
		if ( ! current_user_can( 'install_plugins' ) ) return esc_html__( 'Ask an administrator to install it.', 'spacefast-wordpress' );
		$url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=simply-static' ), 'install-plugin_simply-static' );
		return '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Install Simply Static', 'spacefast-wordpress' ) . '</a>';
	}

	/** @return array<int,string> */
	private static function preflight_issues(): array {
		$issues = array();
		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			$issues[] = __( 'Use a public HTTPS WordPress address before connecting.', 'spacefast-wordpress' );
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || ! is_writable( (string) $uploads['basedir'] ) ) {
			$issues[] = __( 'Make the WordPress uploads directory writable so the static export can be generated.', 'spacefast-wordpress' );
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$issues[] = __( 'WP-Cron is disabled. Configure a server cron for wp-cron.php so automatic publishing keeps running.', 'spacefast-wordpress' );
		}
		return $issues;
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

	private static function oauth_action_form( string $mode, string $label, string $class = 'secondary' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="spacefast_wordpress_oauth_start">
			<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>">
			<?php wp_nonce_field( 'spacefast_wordpress_oauth_start' ); ?>
			<button type="submit" class="button button-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
