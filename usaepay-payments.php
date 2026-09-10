<?php
/**
 * Plugin Name: USAePay Payments
 * Plugin URI: https://github.com/netzih/usaepay-wordpress
 * Description: Take card payments through USAePay in Gravity Forms, GiveWP and WooCommerce. Card details are entered in USAePay's hosted Pay.js fields and never touch this site.
 * Version: 0.1.1
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Chabad of Richmond
 * License: GPL-2.0-or-later
 * Text Domain: usaepay-payments
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
  add_action('admin_notices', static function (): void {
    echo '<div class="notice notice-error"><p>USAePay Payments: run <code>composer install</code> in the plugin directory (vendor/autoload.php is missing).</p></div>';
  });
  return;
}
require_once __DIR__ . '/vendor/autoload.php';

\Usaepay\WordPress\Plugin::boot(__FILE__);

register_deactivation_hook(__FILE__, static function (): void {
  // Renewal workers must not fire while the plugin is inactive.
  wp_clear_scheduled_hook(\Usaepay\WordPress\Plugin::CRON_GIVEWP);
  wp_clear_scheduled_hook('gravityformsusaepay_cron');
});
