<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Minimal WordPress shims for the pure classes under test.
if (!function_exists('__')) {
  function __(string $text, string $domain = 'default'): string {
    return $text;
  }
}

if (!defined('HOUR_IN_SECONDS')) {
  define('HOUR_IN_SECONDS', 3600);
}
