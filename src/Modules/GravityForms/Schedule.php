<?php

namespace Usaepay\WordPress\Modules\GravityForms;

/**
 * Date arithmetic and reference strings for site-managed subscriptions.
 * Pure PHP so it can be unit tested.
 */
final class Schedule {

  /**
   * Days between attempts after a declined installment.
   */
  public const RETRY_DAYS = 3;

  /**
   * Attempts per installment before the subscription is cancelled.
   */
  public const MAX_ATTEMPTS = 3;

  public const UNITS = ['day', 'week', 'month', 'year'];

  /**
   * The next scheduled date. Month and year steps keep the original day of
   * the month, clamped to the last day when the target month is shorter
   * (31 Jan + 1 month = 28/29 Feb), so a "31st" schedule never drifts.
   */
  public static function advance(\DateTimeImmutable $from, int $length, string $unit): \DateTimeImmutable {
    $length = max(1, $length);
    $unit = in_array($unit, self::UNITS, TRUE) ? $unit : 'month';
    if ($unit === 'day' || $unit === 'week') {
      return $from->modify('+' . ($unit === 'week' ? $length * 7 : $length) . ' days');
    }
    $months = $unit === 'year' ? $length * 12 : $length;
    $day = (int) $from->format('j');
    $firstOfTarget = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), 1)->modify('+' . $months . ' months');
    $lastDay = (int) $firstOfTarget->format('t');
    return $firstOfTarget->setDate((int) $firstOfTarget->format('Y'), (int) $firstOfTarget->format('n'), min($day, $lastDay));
  }

  /**
   * Date of installment number $index (1-based) counted from the schedule
   * start, so a "31st" schedule returns to the 31st after a short month
   * instead of drifting to the 28th.
   */
  public static function installmentDate(\DateTimeImmutable $start, int $length, string $unit, int $index): \DateTimeImmutable {
    return self::advance($start, max(1, $length) * max(1, $index), $unit);
  }

  /**
   * The first installment index at or after $fromIndex whose date is after
   * $now, for schedules that fell behind (cron not running for a while): at
   * most one installment is charged per run.
   *
   * @return array{0: int, 1: \DateTimeImmutable}
   */
  public static function nextInstallmentAfter(\DateTimeImmutable $start, \DateTimeImmutable $now, int $length, string $unit, int $fromIndex): array {
    $index = max(1, $fromIndex);
    $date = self::installmentDate($start, $length, $unit, $index);
    $guard = 0;
    while ($date <= $now && $guard++ < 1000) {
      $index++;
      $date = self::installmentDate($start, $length, $unit, $index);
    }
    return [$index, $date];
  }

  /**
   * Idempotency reference sent as USAePay's orderid: one per entry,
   * installment date and attempt, so a double cron run can be reconciled by
   * looking the reference up instead of charging twice.
   */
  public static function orderId(int $entryId, \DateTimeImmutable $scheduled, int $attempt): string {
    return sprintf('gf-%d-%s-%d', $entryId, $scheduled->format('Y-m-d'), max(0, $attempt));
  }

  /**
   * Invoice reference shown in the USAePay console (and copied onto refunds).
   */
  public static function invoice(int $entryId): string {
    return 'GF-' . $entryId;
  }

  public static function describeInterval(int $length, string $unit): string {
    $length = max(1, $length);
    switch ($unit) {
      case 'day':
        return $length === 1 ? __('every day', 'usaepay-payments') : sprintf(__('every %d days', 'usaepay-payments'), $length);

      case 'week':
        return $length === 1 ? __('every week', 'usaepay-payments') : sprintf(__('every %d weeks', 'usaepay-payments'), $length);

      case 'year':
        return $length === 1 ? __('every year', 'usaepay-payments') : sprintf(__('every %d years', 'usaepay-payments'), $length);

      default:
        return $length === 1 ? __('every month', 'usaepay-payments') : sprintf(__('every %d months', 'usaepay-payments'), $length);
    }
  }

}
