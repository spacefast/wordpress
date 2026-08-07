<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'spacefast_wordpress_deliver' );
delete_option( 'spacefast_wordpress_settings' );
delete_option( 'spacefast_wordpress_sync_state' );
delete_option( 'spacefast_wordpress_worker_lock' );
delete_option( 'spacefast_wordpress_publish_state' );
delete_option( 'spacefast_wordpress_oauth_pending' );
delete_option( 'spacefast_wordpress_oauth_choices' );
delete_option( 'spacefast_wordpress_space_creation' );
delete_option( 'spacefast_wordpress_snapshot_required' );
delete_transient( 'spacefast_wordpress_admin_notice' );
