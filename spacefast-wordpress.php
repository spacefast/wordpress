<?php
/**
 * Plugin Name: Spacefast
 * Plugin URI: https://spacefast.com/
 * Description: Publishes static WordPress exports or rebuilds a headless Spacefast site.
 * Version: 0.3.1
 * Update URI: https://github.com/spacefast/wordpress
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Spacefast
 * License: GPL-2.0-or-later
 * Text Domain: spacefast-wordpress
 */

defined( 'ABSPATH' ) || exit;

define( 'SPACEFAST_WORDPRESS_VERSION', '0.3.1' );
define( 'SPACEFAST_WORDPRESS_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-spacefast-settings.php';
require_once __DIR__ . '/includes/class-spacefast-sync-state.php';
require_once __DIR__ . '/includes/class-spacefast-oauth.php';
require_once __DIR__ . '/includes/class-spacefast-client.php';
require_once __DIR__ . '/includes/class-spacefast-static-publisher.php';
require_once __DIR__ . '/includes/class-spacefast-plugin.php';

register_activation_hook( __FILE__, array( 'Spacefast_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Spacefast_Plugin', 'deactivate' ) );

Spacefast_Plugin::boot();
