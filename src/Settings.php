<?php

namespace Usaepay\WordPress;

/**
 * Account settings shared by every module. One option row; live and sandbox
 * credentials are both kept so switching modes does not lose either.
 */
final class Settings {

  public const OPTION = 'usaepay_payments';

  public const MODE_LIVE = 'live';

  public const MODE_SANDBOX = 'sandbox';

  private ?array $values = NULL;

  public static function defaults(): array {
    return [
      'mode' => self::MODE_SANDBOX,
      'live_api_key' => '',
      'live_api_pin' => '',
      'live_public_key' => '',
      'sandbox_api_key' => '',
      'sandbox_api_pin' => '',
      'sandbox_public_key' => '',
      'apple_pay' => FALSE,
      'apple_pay_display_name' => '',
      'debug_log' => FALSE,
    ];
  }

  public function all(): array {
    if ($this->values === NULL) {
      $stored = get_option(self::OPTION, []);
      $this->values = array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }
    return $this->values;
  }

  public function get(string $key): mixed {
    return $this->all()[$key] ?? NULL;
  }

  public function forget(): void {
    $this->values = NULL;
  }

  public function mode(): string {
    return $this->get('mode') === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_SANDBOX;
  }

  public function isSandbox(): bool {
    return $this->mode() === self::MODE_SANDBOX;
  }

  public function apiKey(?string $mode = NULL): string {
    return trim((string) $this->get(($mode ?? $this->mode()) . '_api_key'));
  }

  public function apiPin(?string $mode = NULL): string {
    return (string) $this->get(($mode ?? $this->mode()) . '_api_pin');
  }

  public function publicKey(?string $mode = NULL): string {
    return trim((string) $this->get(($mode ?? $this->mode()) . '_public_key'));
  }

  public function isConfigured(?string $mode = NULL): bool {
    return $this->apiKey($mode) !== '' && $this->apiPin($mode) !== '' && $this->publicKey($mode) !== '';
  }

  public function apiUrl(?string $mode = NULL): string {
    return ($mode ?? $this->mode()) === self::MODE_SANDBOX
      ? 'https://sandbox.usaepay.com/api/v2'
      : 'https://secure.usaepay.com/api/v2';
  }

  public function payJsUrl(?string $mode = NULL): string {
    return ($mode ?? $this->mode()) === self::MODE_SANDBOX
      ? 'https://sandbox.usaepay.com/js/v2/pay.js'
      : 'https://www.usaepay.com/js/v2/pay.js';
  }

  public function applePayEnabled(): bool {
    return !empty($this->get('apple_pay'));
  }

  public function applePayDisplayName(): string {
    $name = trim((string) $this->get('apple_pay_display_name'));
    return $name !== '' ? $name : wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
  }

  public function debugLog(): bool {
    return !empty($this->get('debug_log'));
  }

  /**
   * Sanitize callback for register_setting().
   */
  public function sanitize(mixed $input): array {
    $input = is_array($input) ? $input : [];
    $current = $this->all();
    $clean = $current;

    $clean['mode'] = ($input['mode'] ?? '') === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_SANDBOX;
    foreach (['live', 'sandbox'] as $mode) {
      foreach (['api_key', 'public_key'] as $field) {
        $clean[$mode . '_' . $field] = sanitize_text_field((string) ($input[$mode . '_' . $field] ?? ''));
      }
      // A blank PIN field keeps the stored PIN, so re-saving other settings
      // never wipes it; the form shows a placeholder when one is stored.
      $pin = (string) ($input[$mode . '_api_pin'] ?? '');
      if ($pin !== '') {
        $clean[$mode . '_api_pin'] = trim($pin);
      }
      if (!empty($input[$mode . '_clear_pin'])) {
        $clean[$mode . '_api_pin'] = '';
      }
    }
    $clean['apple_pay'] = !empty($input['apple_pay']);
    $clean['apple_pay_display_name'] = sanitize_text_field((string) ($input['apple_pay_display_name'] ?? ''));
    $clean['debug_log'] = !empty($input['debug_log']);

    $this->values = $clean;
    return $clean;
  }

}
