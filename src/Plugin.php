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
    self::$instance->hooks();
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

  }

  private function hooks(): void {
    DonorMessage::setTranslator(static fn(string $text): string => __($text, 'usaepay-payments'));

    add_action('init', [$this, 'loadTextdomain']);
    if (is_admin()) {
      (new Admin\SettingsPage($this->settings, $this->gateway))->register();
    }

    // Gravity Forms add-ons must be registered on gform_loaded.
    add_action('gform_loaded', [$this, 'bootGravityForms'], 5);

    // GiveWP: register the gateway when GiveWP collects gateways, add a
    // pointer section under Donations > Settings > Payment Gateways and
    // run the renewal worker hourly.
    add_action('givewp_register_payment_gateway', [$this, 'bootGiveWP']);
    add_filter('give_get_sections_gateways', [$this, 'giveSettingsSection']);
    add_filter('give_get_settings_gateways', [$this, 'giveSettingsFields']);
    add_action(self::CRON_GIVEWP, [$this, 'runGiveRenewals']);
    add_action('init', [$this, 'scheduleGiveRenewals']);

    // WooCommerce: gateway class, block checkout support, feature compatibility.
    add_filter('woocommerce_payment_gateways', [$this, 'wooGateways']);
    add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'wooBlocks']);
    add_action('before_woocommerce_init', [$this, 'wooCompatibility']);
  }

  public function wooGateways(array $gateways): array {
    if (class_exists('WC_Payment_Gateway')) {
      $gateways[] = Modules\WooCommerce\Gateway::class;
    }
    return $gateways;
  }

  public function wooBlocks($registry): void {
    if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
      $registry->register(new Modules\WooCommerce\BlocksSupport());
    }
  }

  public function wooCompatibility(): void {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
      \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $this->file, TRUE);
      \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $this->file, TRUE);
    }
  }

  public const CRON_GIVEWP = 'usaepay_givewp_renewals';

  public function bootGiveWP($registrar): void {
    if (!class_exists('\Give\Framework\PaymentGateways\PaymentGateway')) {
      return;
    }
    try {
      $registrar->registerGateway(Modules\GiveWP\Gateway::class);
    }
    catch (\OverflowException $e) {
      // Already registered (GiveWP fires the collection more than once).
    }
  }

  public function giveSettingsSection(array $sections): array {
    $sections[Modules\GiveWP\Gateway::ID] = __('USAePay', 'usaepay-payments');
    return $sections;
  }

  public function giveSettingsFields(array $settings): array {
    if (!function_exists('give_get_current_setting_section') || give_get_current_setting_section() !== Modules\GiveWP\Gateway::ID) {
      return $settings;
    }
    $url = admin_url('options-general.php?page=' . Admin\SettingsPage::PAGE);
    return [
      ['type' => 'title', 'id' => 'give_title_usaepay'],
      [
        'name' => __('USAePay account', 'usaepay-payments'),
        'id' => 'usaepay_pointer',
        'type' => 'give_docs_link',
        'url' => $url,
        'title' => __('Open Settings > USAePay', 'usaepay-payments'),
        'desc' => __('Credentials are shared with the other USAePay integrations on this site and are managed under Settings > USAePay. GiveWP Test Mode uses the sandbox credentials; live mode uses the live ones. Recurring donations are charged by this site every renewal date (hourly WordPress cron), nothing is scheduled in the USAePay console.', 'usaepay-payments'),
      ],
      ['type' => 'sectionend', 'id' => 'give_title_usaepay'],
    ];
  }

  public function scheduleGiveRenewals(): void {
    if (!function_exists('give')) {
      return;
    }
    if (!wp_next_scheduled(self::CRON_GIVEWP)) {
      wp_schedule_event(time() + 300, 'hourly', self::CRON_GIVEWP);
    }
  }

  public function runGiveRenewals(): void {
    if (!function_exists('give') || !class_exists('\Give\Subscriptions\Models\Subscription')) {
      return;
    }
    $summary = (new Modules\GiveWP\Renewals())->run();
    Log::debug('GiveWP renewals', $summary);
  }

  public function loadTextdomain(): void {
    load_plugin_textdomain('usaepay-payments', FALSE, dirname(plugin_basename($this->file)) . '/languages');
  }

  public function bootGravityForms(): void {
    if (!method_exists('GFForms', 'include_payment_addon_framework')) {
      return;
    }
    \GFForms::include_payment_addon_framework();
    if (!\GF_Fields::exists(Modules\GravityForms\CardField::TYPE)) {
      \GF_Fields::register(new Modules\GravityForms\CardField());
    }
    \GFAddOn::register(Modules\GravityForms\AddOn::class);
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
