<?php

namespace Usaepay\WordPress;

use Usaepay\DonorMessage;

/**
 * Plugin bootstrap: shared settings and gateway factory, plus one module per
 * host plugin (Gravity Forms, GiveWP, WooCommerce) that registers only when
 * that plugin is active.
 */
final class Plugin {

  public const VERSION = '0.1.0';

  public const SLUG = 'usaepay-payments';

  private static ?Plugin $instance = NULL;

  private string $file;

  private Settings $settings;

  private Gateway $gateway;

  public static function boot(string $file): void {
    if (self::$instance) {
      return;
    }
    self::$instance = new self($file);
  }

  public static function instance(): Plugin {
    if (!self::$instance) {
      throw new \LogicException('USAePay Payments has not been booted.');
    }
    return self::$instance;
  }

  private function __construct(string $file) {
    $this->file = $file;
    $this->settings = new Settings();
    $this->gateway = new Gateway($this->settings);

    DonorMessage::setTranslator(static fn(string $text): string => __($text, 'usaepay-payments'));

    add_action('init', [$this, 'loadTextdomain']);
    if (is_admin()) {
      (new Admin\SettingsPage($this->settings, $this->gateway))->register();
    }

    // Gravity Forms add-ons must be registered on gform_loaded.
    add_action('gform_loaded', [$this, 'bootGravityForms'], 5);
  }

  public function loadTextdomain(): void {
    load_plugin_textdomain('usaepay-payments', FALSE, dirname(plugin_basename($this->file)) . '/languages');
  }

  public function bootGravityForms(): void {
    if (!method_exists('GFForms', 'include_payment_addon_framework')) {
      return;
    }
    \GFForms::include_payment_addon_framework();
    if (class_exists(Modules\GravityForms\AddOn::class)) {
      \GFAddOn::register(Modules\GravityForms\AddOn::class);
    }
  }

  public function settings(): Settings {
    return $this->settings;
  }

  public function gateway(): Gateway {
    return $this->gateway;
  }

  public function file(): string {
    return $this->file;
  }

  public function url(string $path = ''): string {
    return plugins_url(ltrim($path, '/'), $this->file);
  }

  public function path(string $path = ''): string {
    return plugin_dir_path($this->file) . ltrim($path, '/');
  }

  /**
   * Version string for enqueued assets: file mtime in development so edits
   * bypass browser caches, the plugin version otherwise.
   */
  public function assetVersion(string $relativePath): string {
    $file = $this->path($relativePath);
    if ((defined('WP_DEBUG') && WP_DEBUG) && is_file($file)) {
      return (string) filemtime($file);
    }
    return self::VERSION;
  }

}
