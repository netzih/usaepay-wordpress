<?php

namespace Usaepay\Tests;

use PHPUnit\Framework\TestCase;
use Usaepay\AmbiguousGatewayException;
use Usaepay\CardDetails;
use Usaepay\Country;
use Usaepay\DonorMessage;
use Usaepay\GatewayClient;
use Usaepay\GatewayException;

final class CountryTest extends TestCase {

  public function testAlpha2ToAlpha3(): void {
    self::assertSame('USA', Country::alpha3('US'));
    self::assertSame('CAN', Country::alpha3('ca'));
    self::assertSame('ISR', Country::alpha3(' IL '));
    self::assertSame('GBR', Country::alpha3('GB'));
    self::assertSame('CHE', Country::alpha3('CH'));
    self::assertSame('ZAF', Country::alpha3('ZA'));
  }

  public function testAlpha3PassesThroughOnlyKnownCodes(): void {
    self::assertSame('USA', Country::alpha3('usa'));
    self::assertSame('', Country::alpha3('XXX'));
  }

  public function testAlpha2FromAlpha3(): void {
    self::assertSame('US', Country::alpha2('USA'));
    self::assertSame('IL', Country::alpha2('isr'));
    self::assertSame('CA', Country::alpha2('CA'));
    self::assertSame('', Country::alpha2('XXX'));
  }

  public function testUnknownInputIsOmitted(): void {
    self::assertSame('', Country::alpha3(''));
    self::assertSame('', Country::alpha3('ZZ'));
    self::assertSame('', Country::alpha3('United States'));
  }

}
