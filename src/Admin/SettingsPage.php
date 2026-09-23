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
    add_action('admin_post_usaepay_check_marker', [$this, 'checkMarker']);
    add_action('admin_post_usaepay_run_renewals', [$this, 'runRenewals']);
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
      <p><?php esc_html_e('The default USAePay account serves every form and checkout on this site; plugins that support it (Embed Forms) can charge chosen forms to one of the additional accounts below. Card details are entered in fields hosted by USAePay (Pay.js); this site only ever handles single-use payment keys and saved-card references.', 'usaepay-payments'); ?></p>

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

        <?php $this->renderAccounts(); ?>

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
      <?php $this->renderUnresolved(); ?>
    </div>
    <?php
  }

  /**
   * More merchant accounts, each with its own live and sandbox credentials,
   * plus one blank row to add another. The mode above applies to all.
   */
  private function renderAccounts(): void {
    $name = Settings::OPTION . '[accounts]';
    $rows = array_values($this->settings->extraAccounts());
    $rows[] = NULL;
    ?>
    <h2><?php esc_html_e('Additional accounts', 'usaepay-payments'); ?></h2>
    <p class="description"><?php esc_html_e('Other USAePay merchant accounts a form can be charged to instead of the default one (Embed Forms: choose the account under the form\'s Settings > Payments). Charges, renewals and refunds always go to the account a payment was made with, so change a form\'s account freely, but do not remove an account that still has active recurring payments.', 'usaepay-payments'); ?></p>
    <?php foreach ($rows as $i => $account) :
      $field = static fn(string $key) => esc_attr($name . '[' . $i . '][' . $key . ']');
      $id = 'usaepay-account-' . $i;
      ?>
      <table class="form-table usaepay-account" role="presentation" style="border-top:1px solid #c3c4c7">
        <tr>
          <th scope="row"><label for="<?php echo esc_attr($id); ?>-label"><?php echo $account ? esc_html__('Account name', 'usaepay-payments') : esc_html__('Add an account', 'usaepay-payments'); ?></label></th>
          <td>
            <input id="<?php echo esc_attr($id); ?>-label" class="regular-text" type="text" name="<?php echo $field('label'); ?>" value="<?php echo esc_attr($account['label'] ?? ''); ?>" placeholder="<?php echo $account ? '' : esc_attr__('e.g. Camp account', 'usaepay-payments'); ?>">
            <?php if ($account) : ?>
              <input type="hidden" name="<?php echo $field('id'); ?>" value="<?php echo esc_attr($account['id']); ?>">
              <code style="margin-left:8px"><?php echo esc_html($account['id']); ?></code>
              <label style="margin-left:12px"><input type="checkbox" name="<?php echo $field('remove'); ?>" value="1"> <?php esc_html_e('Remove this account', 'usaepay-payments'); ?></label>
            <?php else : ?>
              <p class="description"><?php esc_html_e('Fill in a name and the keys, then save. Leave blank to add nothing.', 'usaepay-payments'); ?></p>
            <?php endif; ?>
          </td>
        </tr>
        <?php foreach (['live' => __('Live', 'usaepay-payments'), 'sandbox' => __('Sandbox', 'usaepay-payments')] as $mode => $modeLabel) :
          $pin = (string) ($account[$mode . '_api_pin'] ?? '');
          ?>
          <tr>
            <th scope="row"><?php echo esc_html($modeLabel); ?></th>
            <td>
              <p><label><?php esc_html_e('API key (source key)', 'usaepay-payments'); ?><br><input class="regular-text code" type="text" autocomplete="off" name="<?php echo $field($mode . '_api_key'); ?>" value="<?php echo esc_attr($account[$mode . '_api_key'] ?? ''); ?>"></label></p>
              <p><label><?php esc_html_e('API PIN', 'usaepay-payments'); ?><br><input class="regular-text code" type="password" autocomplete="new-password" name="<?php echo $field($mode . '_api_pin'); ?>" value="" placeholder="<?php echo $pin !== '' ? esc_attr__('(saved — leave blank to keep)', 'usaepay-payments') : ''; ?>"></label>
                <?php if ($pin !== '') : ?>
                  <label style="margin-left:8px"><input type="checkbox" name="<?php echo $field($mode . '_clear_pin'); ?>" value="1"> <?php esc_html_e('Clear saved PIN', 'usaepay-payments'); ?></label>
                <?php endif; ?></p>
              <p><label><?php esc_html_e('Pay.js public key', 'usaepay-payments'); ?><br><input class="regular-text code" type="text" autocomplete="off" name="<?php echo $field($mode . '_public_key'); ?>" value="<?php echo esc_attr($account[$mode . '_public_key'] ?? ''); ?>"></label></p>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach;
  }

  /**
   * Charges and refunds whose answer was never recorded, with a check action.
   */
  private function renderUnresolved(): void {
    $items = (new Unresolved($this->gateway, $this->settings))->items();
    $runUrl = wp_nonce_url(admin_url('admin-post.php?action=usaepay_run_renewals'), 'usaepay_run_renewals');
    ?>
    <h2><?php esc_html_e('Unresolved requests', 'usaepay-payments'); ?></h2>
    <p><?php esc_html_e('A charge or refund is listed here when it was sent to USAePay and its answer was never recorded: the connection dropped, the page was closed, or the site crashed in between. Nothing here is charged or refunded again until USAePay has confirmed what happened to the earlier request; "Check" asks now.', 'usaepay-payments'); ?></p>
    <?php if (!$items) : ?>
      <p><em><?php esc_html_e('None. Every request has a recorded answer.', 'usaepay-payments'); ?></em></p>
    <?php else : ?>
      <table class="widefat striped" style="max-width: 1100px">
        <thead>
          <tr>
            <th><?php esc_html_e('Record', 'usaepay-payments'); ?></th>
            <th><?php esc_html_e('Request', 'usaepay-payments'); ?></th>
            <th><?php esc_html_e('orderid at USAePay', 'usaepay-payments'); ?></th>
            <th><?php esc_html_e('Sent', 'usaepay-payments'); ?></th>
            <th><?php esc_html_e('Amount', 'usaepay-payments'); ?></th>
            <th><?php esc_html_e('Mode', 'usaepay-payments'); ?></th>
            <th><?php esc_html_e('Account', 'usaepay-payments'); ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item) : ?>
            <?php $checkUrl = wp_nonce_url(admin_url('admin-post.php?action=usaepay_check_marker&item=' . rawurlencode($item['key'])), 'usaepay_check_marker_' . $item['key']); ?>
            <tr>
              <td><a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['module'] . ': ' . $item['record']); ?></a></td>
              <td><?php echo esc_html($item['kind'] === 'refund' ? __('Refund', 'usaepay-payments') : __('Charge', 'usaepay-payments')); ?></td>
              <td><code><?php echo esc_html($item['orderid']); ?></code></td>
              <td><?php echo $item['sent_at'] > 0 ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $item['sent_at'])) : '&mdash;'; ?></td>
              <td><?php echo $item['amount'] !== NULL ? esc_html($item['amount']) : '&mdash;'; ?></td>
              <td><?php echo esc_html($item['mode']); ?></td>
              <td><?php echo esc_html($this->settings->accountLabel($item['account'] ?? '')); ?></td>
              <td><a class="button button-small" href="<?php echo esc_url($checkUrl); ?>"><?php esc_html_e('Check at USAePay', 'usaepay-payments'); ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p>
      <a class="button" href="<?php echo esc_url($runUrl); ?>"><?php esc_html_e('Run renewal workers now', 'usaepay-payments'); ?></a>
      <span class="description"><?php esc_html_e('Charges every due subscription of Gravity Forms, GiveWP and any other plugin that charges through this one, and records any renewal listed above whose charge went through. WooCommerce Subscriptions renewals run on their own scheduler.', 'usaepay-payments'); ?></span>
    </p>
    <?php
  }

  /**
   * Ask USAePay about one unresolved request and report in a notice.
   */
  public function checkMarker(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to do that.', 'usaepay-payments'));
    }
    $key = isset($_GET['item']) ? sanitize_text_field(wp_unslash((string) $_GET['item'])) : '';
    check_admin_referer('usaepay_check_marker_' . $key);
    $unresolved = new Unresolved($this->gateway, $this->settings);
    $item = $unresolved->find($key);
    if ($item === NULL) {
      $result = ['ok' => TRUE, 'message' => __('That request has been resolved in the meantime and is no longer listed.', 'usaepay-payments')];
    }
    else {
      $result = $unresolved->check($item);
    }
    set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), ['ok' => $result['ok'], 'messages' => [$result['message']]], 120);
    wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
    exit;
  }

  /**
   * Run the Gravity Forms and GiveWP renewal workers now.
   */
  public function runRenewals(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to do that.', 'usaepay-payments'));
    }
    check_admin_referer('usaepay_run_renewals');
    $messages = [];
    $plugin = \Usaepay\WordPress\Plugin::instance();
    if ($plugin->moduleEnabled('gravityforms') && class_exists('GFAPI') && class_exists(\Usaepay\WordPress\Modules\GravityForms\AddOn::class)) {
      $summary = (new \Usaepay\WordPress\Modules\GravityForms\Renewals(\Usaepay\WordPress\Modules\GravityForms\AddOn::get_instance(), $this->gateway, $this->settings))->run();
      $messages[] = __('Gravity Forms:', 'usaepay-payments') . ' ' . self::summaryText($summary);
    }
    if ($plugin->moduleEnabled('givewp') && function_exists('give')) {
      $summary = (new \Usaepay\WordPress\Modules\GiveWP\Renewals())->run();
      $messages[] = __('GiveWP:', 'usaepay-payments') . ' ' . self::summaryText($summary);
    }
    foreach (self::renewalWorkers() as $label => $worker) {
      $summary = $worker();
      $messages[] = $label . ': ' . self::summaryText(is_array($summary) ? $summary : []);
    }
    if (!$messages) {
      $messages[] = __('No plugin with a renewal worker is active.', 'usaepay-payments');
    }
    set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), ['ok' => TRUE, 'messages' => $messages], 120);
    wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
    exit;
  }

  /**
   * Renewal workers of other plugins that charge through this one, keyed by
   * the label shown in the notice. Each returns a summary of counts.
   *
   *   add_filter('usaepay_payments_renewal_workers', fn(array $w) => $w + ['My plugin' => fn() => $worker->run()]);
   *
   * @return array<string, callable(): array<string, int>>
   */
  private static function renewalWorkers(): array {
    $workers = apply_filters('usaepay_payments_renewal_workers', []);
    return is_array($workers) ? array_filter($workers, 'is_callable') : [];
  }

  /**
   * @param array<string, int> $summary
   */
  private static function summaryText(array $summary): string {
    $parts = [];
    foreach ($summary as $name => $count) {
      if ($count > 0 || $name === 'due') {
        $parts[] = $name . ' ' . (int) $count;
      }
    }
    return $parts ? implode(', ', $parts) : __('nothing due', 'usaepay-payments');
  }

  /**
   * Prove the saved credentials of every account for the current mode
   * work, without charging.
   */
  public function checkCredentials(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to do that.', 'usaepay-payments'));
    }
    check_admin_referer('usaepay_check_credentials');

    $mode = $this->settings->mode();
    $messages = [];
    $ok = TRUE;
    $accounts = $this->settings->accounts();
    foreach ($accounts as $account => $accountLabel) {
      $modeLabel = $mode === Settings::MODE_LIVE ? __('Live', 'usaepay-payments') : __('Sandbox', 'usaepay-payments');
      $label = count($accounts) > 1 ? $accountLabel . ' — ' . $modeLabel : $modeLabel;
      $result = $this->checkAccount($mode, $account, $label);
      $ok = $ok && $result['ok'];
      $messages = array_merge($messages, $result['messages']);
    }

    set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), ['ok' => $ok, 'messages' => $messages], 120);
    wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
    exit;
  }

  /**
   * @return array{ok: bool, messages: string[]}
   */
  private function checkAccount(string $mode, string $account, string $label): array {
    $messages = [];
    $ok = TRUE;
    if (!$this->settings->hasApiCredentials($mode, $account)) {
      return ['ok' => FALSE, 'messages' => [sprintf(__('%s credentials are incomplete: the API key and PIN are both required.', 'usaepay-payments'), $label)]];
    }
    $client = $this->gateway->client('settings check', $mode, $account);
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
      if ($this->settings->publicKey($mode, $account) === '') {
        throw new GatewayException(__('none entered; the checkout card fields need it.', 'usaepay-payments'));
      }
      $client->verifyPublicKey($this->settings->publicKey($mode, $account));
      $messages[] = sprintf(__('%s Pay.js public key accepted.', 'usaepay-payments'), $label);
    }
    catch (GatewayException $e) {
      $ok = FALSE;
      $messages[] = sprintf(__('%1$s Pay.js public key rejected: %2$s', 'usaepay-payments'), $label, $e->getMessage());
    }
    return ['ok' => $ok, 'messages' => $messages];
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
