<?php

namespace Usaepay\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use Usaepay\WordPress\Modules\GravityForms\Schedule as GravityFormsSchedule;
use Usaepay\WordPress\Schedule;

final class SharedScheduleTest extends TestCase {

  public function testGravityFormsScheduleForwardsToTheSharedOne(): void {
    $start = new \DateTimeImmutable('2026-01-31 10:00:00', new \DateTimeZone('UTC'));
    self::assertEquals(Schedule::installmentDate($start, 1, 'month', 3), GravityFormsSchedule::installmentDate($start, 1, 'month', 3));
    self::assertSame(Schedule::MAX_ATTEMPTS, GravityFormsSchedule::MAX_ATTEMPTS);
    self::assertSame(Schedule::RETRY_DAYS, GravityFormsSchedule::RETRY_DAYS);
  }

}
