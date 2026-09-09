<?php

namespace Usaepay\WordPress\Admin;

use Usaepay\GatewayException;
use Usaepay\WordPress\Gateway;
use Usaepay\WordPress\Settings;

/**
 * Settings > USAePay: credentials for live and sandbox, mode switch, Apple Pay
 * and a "Check credentials" action that proves the keys work without charging.
 */
final class SettingsPage {

  public const PAGE = 'usaepay-payments';

  private const NOTICE_TRANSIENT = 'usaepay_payments_notice';

  private Settings $settings;

  private Gateway $gateway;

  public function __construct(Settings $settings, Gateway $gateway) {
    $this->settings = $settings;
    $this->gateway = $gateway;
  }

  public function register(): void {
    add_action('admin_menu', [$this, 'addMenu']);
    add_action('admin_init', [$this, 'registerSetting']);
    add_action('admin_post_usaepay_check_credentials', [$this, 'checkCredentials']);
    add_action('admin_notices', [$this, 'showNotice']);
    add_filter('plugin_action_links_' . plugin_basename(\Usaepay\WordPress\Plugin::instance()->file()), [$this, 'actionLinks']);
  }

  public function addMenu(): void {
    add_options_page(
      __('USAePay Payments', 'usaepay-payments'),
      __('USAePay', 'usaepay-payments'),
      'manage_options',
      self::PAGE,
      [$this, 'render']
    );
  }

  public function registerSetting(): void {
    register_setting('usaepay_payments', Settings::OPTION, [
      'type' => 'array',
      'sanitize_callback' => [$this->settings, 'sanitize'],
      'default' => Settings::defaults(),
    ]);
  }

  public function actionLinks(array $links): array {
    $url = admin_url('options-general.php?page=' . self::PAGE);
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'usaepay-payments') . '</a>');
    return $links;
  }

  public function render(): void {
    if (!current_user_can('manage_options')) {
      return;
    }
    $v = $this->settings->all();
    $checkUrl = wp_nonce_url(admin_url('admin-post.php?action=usaepay_check_credentials'), 'usaepay_check_credentials');
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('USAePay Payments', 'usaepay-payments'); ?></h1>
      <p><?php esc_html_e('One USAePay account for every form and checkout on this site. Card details are entered in fields hosted by USAePay (Pay.js); this site only ever handles single-use payment keys and saved-card references.', 'usaepay-payments'); ?></p>

      <form method="post" action="options.php">
        <?php settings_fields('usaepay_payments'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('Mode', 'usaepay-payments'); ?></th>
            <td>
              <fieldset>
                <label><input type="radio" name="<?php echo esc_attr(Settings::OPTION); ?>[mode]" value="sandbox" <?php checked($v['mode'], 'sandbox'); ?>> <?php esc_html_e('Sandbox (test) — sandbox.usaepay.com, no real money', 'usaepay-payments'); ?></label><br>
                <label><input type="radio" name="<?php echo esc_attr(Settings::OPTION); ?>[mode]" value="live" <?php checked($v['mode'], 'live'); ?>> <?php esc_html_e('Live — secure.usaepay.com', 'usaepay-payments'); ?></label>
              </fieldset>
            </td>
          </tr>
        </table>

        <?php foreach (['live' => __('Live credentials', 'usaepay-payments'), 'sandbox' => __('Sandbox credentials', 'usaepay-payments')] as $mode => $title): ?>
          <h2><?php echo esc_html($title); ?></h2>
          <p class="description">
            <?php
            echo $mode === 'live'
              ? esc_html__('From the USAePay merchant console: Settings > Source Keys. The key needs Sale, Auth Only, Void and Credit (refund) allowed. The Pay.js public key comes from Settings > Payment Forms / Pay.js.', 'usaepay-payments')
              : esc_html__('From sandbox.usaepay.com. Test card 4000100011112224 approves and 4000300011112220 declines.', 'usaepay-payments');
            ?>
          </p>
          <table class="form-table" role="presentation">
            <tr>
              <th scope="row"><label for="usaepay-<?php echo esc_attr($mode); ?>-api-key"><?php esc_html_e('API key (source key)', 'usaepay-payments'); ?></label></th>
              <td><input id="usaepay-<?php echo esc_attr($mode); ?>-api-key" class="regular-text code" type="text" autocomplete="off" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($mode); ?>_api_key]" value="<?php echo esc_attr($v[$mode . '_api_key']); ?>"></td>
            </tr>
            <tr>
              <th scope="row"><label for="usaepay-<?php echo esc_attr($mode); ?>-api-pin"><?php esc_html_e('API PIN', 'usaepay-payments'); ?></label></th>
              <td>
                <input id="usaepay-<?php echo esc_attr($mode); ?>-api-pin" class="regular-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($mode); ?>_api_pin]" value="" placeholder="<?php echo $v[$mode . '_api_pin'] !== '' ? esc_attr__('(saved — leave blank to keep)', 'usaepay-payments') : ''; ?>">
                <?php if ($v[$mode . '_api_pin'] !== ''): ?>
                  <label style="margin-left:8px"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($mode); ?>_clear_pin]" value="1"> <?php esc_html_e('Clear saved PIN', 'usaepay-payments'); ?></label>
                <?php endif; ?>
                <p class="description"><?php esc_html_e('Set on the source key in the console. Requests are signed with it; it is never sent in clear.', 'usaepay-payments'); ?></p>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="usaepay-<?php echo esc_attr($mode); ?>-public-key"><?php esc_html_e('Pay.js public key', 'usaepay-payments'); ?></label></th>
              <td><input id="usaepay-<?php echo esc_attr($mode); ?>-public-key" class="regular-text code" type="text" autocomplete="off" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($mode); ?>_public_key]" value="<?php echo esc_attr($v[$mode . '_public_key']); ?>">
                <p class="description"><?php esc_html_e('Used in the browser to create the hosted card fields. Safe to expose; it can only mint single-use payment keys.', 'usaepay-payments'); ?></p></td>
            </tr>
          </table>
        <?php endforeach; ?>

        <h2><?php esc_html_e('Options', 'usaepay-payments'); ?></h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('Apple Pay', 'usaepay-payments'); ?></th>
            <td>
              <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[apple_pay]" value="1" <?php checked(!empty($v['apple_pay'])); ?>> <?php esc_html_e('Offer Apple Pay for one-time payments where the browser supports it', 'usaepay-payments'); ?></label>
              <p class="description"><?php esc_html_e('Requires Apple Pay enabled on the USAePay account, this domain registered under Settings > Apple Pay, and Apple\'s domain-association file served from /.well-known/. Not available for recurring payments (the key is single-use).', 'usaepay-payments'); ?></p>
              <p><label for="usaepay-apple-pay-name"><?php esc_html_e('Name shown on the Apple Pay sheet', 'usaepay-payments'); ?></label><br>
              <input id="usaepay-apple-pay-name" class="regular-text" type="text" name="<?php echo esc_attr(Settings::OPTION); ?>[apple_pay_display_name]" value="<?php echo esc_attr($v['apple_pay_display_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Debug log', 'usaepay-payments'); ?></th>
            <td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[debug_log]" value="1" <?php checked(!empty($v['debug_log'])); ?>> <?php esc_html_e('Write request summaries to the PHP error log (tokens and credentials are redacted)', 'usaepay-payments'); ?></label></td>
          </tr>
        </table>

        <p class="submit">
          <?php submit_button(NULL, 'primary', 'submit', FALSE); ?>
          <a class="button" href="<?php echo esc_url($checkUrl); ?>" style="margin-left:8px"><?php esc_html_e('Check credentials', 'usaepay-payments'); ?></a>
          <span class="description" style="margin-left:8px"><?php esc_html_e('Save first. The check lists one transaction and mints an unused payment key; nothing is charged.', 'usaepay-payments'); ?></span>
        </p>
      </form>
    </div>
    <?php
  }

  /**
   * Prove the saved credentials for the current mode work, without charging.
   */
  public function checkCredentials(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to do that.', 'usaepay-payments'));
    }
    check_admin_referer('usaepay_check_credentials');

    $mode = $this->settings->mode();
    $label = $mode === Settings::MODE_LIVE ? __('Live', 'usaepay-payments') : __('Sandbox', 'usaepay-payments');
    $messages = [];
    $ok = TRUE;

    if (!$this->settings->isConfigured($mode)) {
      $ok = FALSE;
      $messages[] = sprintf(__('%s credentials are incomplete: API key, PIN and Pay.js public key are all required.', 'usaepay-payments'), $label);
    }
    else {
      $client = $this->gateway->client('settings check', $mode);
      try {
        $list = $client->verifyCredentials();
        $rows = is_array($list['data'] ?? NULL) ? count($list['data']) : 0;
        $messages[] = $rows > 0
          ? sprintf(__('%s API key and PIN work (the account has transactions).', 'usaepay-payments'), $label)
          : sprintf(__('%s API key and PIN work (no transactions on the account yet).', 'usaepay-payments'), $label);
      }
      catch (GatewayException $e) {
        $ok = FALSE;
        $messages[] = sprintf(__('%1$s API key or PIN rejected: %2$s', 'usaepay-payments'), $label, $e->getMessage());
      }
      try {
        $client->verifyPublicKey($this->settings->publicKey($mode));
        $messages[] = sprintf(__('%s Pay.js public key accepted.', 'usaepay-payments'), $label);
      }
      catch (GatewayException $e) {
        $ok = FALSE;
        $messages[] = sprintf(__('%1$s Pay.js public key rejected: %2$s', 'usaepay-payments'), $label, $e->getMessage());
      }
    }

    set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), ['ok' => $ok, 'messages' => $messages], 120);
    wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
    exit;
  }

  public function showNotice(): void {
    $key = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
    $notice = get_transient($key);
    if (!is_array($notice)) {
      return;
    }
    delete_transient($key);
    $class = $notice['ok'] ? 'notice-success' : 'notice-error';
    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . implode('<br>', array_map('esc_html', $notice['messages'])) . '</p></div>';
  }

}
