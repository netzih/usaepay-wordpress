<?php

namespace Usaepay\Tests;

use PHPUnit\Framework\TestCase;
use Usaepay\AmbiguousGatewayException;
use Usaepay\CardDetails;
use Usaepay\Country;
use Usaepay\DonorMessage;
use Usaepay\GatewayClient;
use Usaepay\GatewayException;

final class CardDetailsTest extends TestCase {

  public function testPrefersSavedCardFields(): void {
    $details = CardDetails::fromResponse([
      'creditcard' => ['number' => '5500xxxxxxxx0004'],
      'savedcard' => ['key' => 'ref', 'type' => 'Visa', 'cardnumber' => '4000xxxxxxxx2224'],
    ]);
    self::assertSame(['brand' => 'Visa', 'last4' => '2224'], $details);
  }

  public function testFallsBackToMaskedNumberPrefix(): void {
    self::assertSame(['brand' => 'MasterCard', 'last4' => '0004'], CardDetails::fromResponse(['creditcard' => ['number' => '5500xxxxxxxx0004']]));
    self::assertSame(['brand' => 'Amex', 'last4' => '0005'], CardDetails::fromResponse(['creditcard' => ['number' => '37xxxxxxxxx0005']]));
    self::assertSame(['brand' => 'Discover', 'last4' => '1117'], CardDetails::fromResponse(['creditcard' => ['number' => '6011xxxxxxxx1117']]));
    self::assertSame(['brand' => 'MasterCard', 'last4' => '9999'], CardDetails::fromResponse(['creditcard' => ['number' => '2221 xxxx xxxx 9999']]));
  }

  public function testNormalizesBrandLabels(): void {
    self::assertSame('MasterCard', CardDetails::normalizeBrand('MASTERCARD'));
    self::assertSame('Amex', CardDetails::normalizeBrand('American Express'));
    self::assertNull(CardDetails::normalizeBrand('JCB'));
  }

  public function testEmptyResponseGivesNulls(): void {
    self::assertSame(['brand' => NULL, 'last4' => NULL], CardDetails::fromResponse([]));
  }

}
