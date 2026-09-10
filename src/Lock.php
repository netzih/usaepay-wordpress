<?php

namespace Usaepay\WordPress;

/**
 * A mutual-exclusion lock backed by a row in wp_options.
 *
 * Transients are read-then-write and two cron workers can both pass the read;
 * here the INSERT itself is the test, because option_name is unique. The row
 * is written with plain SQL so the option caches never see it. A holder that
 * died keeps the lock only until its TTL passes.
 */
final class Lock {

  private const PREFIX = 'usaepay_lock_';

  /**
   * @return bool
   *   TRUE when this caller now holds the lock.
   */
  public static function acquire(string $name, int $ttlSeconds): bool {
    global $wpdb;
    $option = self::option($name);
    $now = time();
    $suppress = $wpdb->suppress_errors(TRUE);
    try {
      $inserted = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
        $option,
        (string) $now
      ));
      if ($inserted === 1) {
        return TRUE;
      }
      $held = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
      if ($held <= 0) {
        return FALSE;
      }
      if ($held + $ttlSeconds >= $now) {
        return FALSE;
      }
      // Stale: take it over, but only from the exact value we saw.
      $taken = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
        (string) $now,
        $option,
        (string) $held
      ));
      return $taken === 1;
    }
    finally {
      $wpdb->suppress_errors($suppress);
    }
  }

  public static function release(string $name): void {
    global $wpdb;
    $wpdb->delete($wpdb->options, ['option_name' => self::option($name)]);
  }

  private static function option(string $name): string {
    return self::PREFIX . preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);
  }

}
