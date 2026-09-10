<?php

namespace Usaepay\WordPress;

use Usaepay\AmbiguousGatewayException;
use Usaepay\GatewayClient;
use Usaepay\GatewayException;
use Usaepay\ReconciliationInconclusiveException;

/**
 * Runs a gateway call at most once per stored marker.
 *
 * The marker ({orderid, sent_at}) is written by the caller's store BEFORE the
 * request goes out and stays there until the answer was conclusive and, for
 * an approval, until the caller has recorded it. A later call with the same
 * store finds the marker, looks the orderid up (bounded by when it was sent,
 * so a miss is conclusive) and returns the earlier transaction instead of
 * sending a second one. When USAePay cannot say, nothing is sent.
 */
final class Reconcile {

  /**
   * @param callable(): mixed $read
   *   Returns the stored marker array, or anything else when none.
   * @param callable(?array): void $write
   *   Stores a marker, or removes it when given NULL.
   * @param callable(GatewayClient): array $call
   *   The gateway request.
   * @param string[] $types
   *   Transaction types that count as "this request went through":
   *   GatewayClient::TYPES_CHARGE or TYPES_REFUND. Refunds inherit the sale's
   *   orderid, so for them $orderId is the sale's.
   *
   * @return array{response: array, reconciled: bool, amount: ?string}
   *   'reconciled' is TRUE when the response is an earlier transaction found
   *   at USAePay rather than the answer to a request made now; 'amount' is
   *   then the amount that earlier request was made for.
   *
   * @throws ReconciliationInconclusiveException
   *   USAePay could not be asked whether an earlier request went through;
   *   nothing was sent. The caller must not retry blindly.
   * @throws AmbiguousGatewayException
   *   The request was sent and no answer came; the marker stays for next time.
   * @throws GatewayException
   */
  public static function once(GatewayClient $client, callable $read, callable $write, string $orderId, ?string $amount, callable $call, array $types = GatewayClient::TYPES_CHARGE): array {
    $marker = $read();
    if (is_array($marker) && !empty($marker['orderid'])) {
      // Look for what was actually sent then, not what is asked for now.
      $earlierAmount = isset($marker['amount']) && $marker['amount'] !== '' ? (string) $marker['amount'] : $amount;
      $found = self::lookup($client, (string) $marker['orderid'], ((int) ($marker['sent_at'] ?? 0)) ?: NULL, $earlierAmount, $types);
      if ($found && Gateway::approved($found)) {
        return ['response' => $found, 'reconciled' => TRUE, 'amount' => $earlierAmount];
      }
      // Declined, or provably never received: a fresh request is safe.
    }
    $write(['orderid' => $orderId, 'sent_at' => time(), 'amount' => $amount]);
    try {
      $response = $call($client);
    }
    catch (AmbiguousGatewayException $e) {
      try {
        $found = self::lookup($client, $orderId, time(), $amount, $types);
      }
      catch (ReconciliationInconclusiveException $lookup) {
        $found = NULL;
      }
      if ($found && Gateway::approved($found)) {
        return ['response' => $found, 'reconciled' => TRUE, 'amount' => $amount];
      }
      throw $e;
    }
    catch (GatewayException | \InvalidArgumentException $e) {
      // The gateway answered (or the request never left): nothing happened.
      $write(NULL);
      throw $e;
    }
    if (!Gateway::approved($response)) {
      $write(NULL);
    }
    return ['response' => $response, 'reconciled' => FALSE, 'amount' => $amount];
  }

  /**
   * Marker store in a record's meta through plain get/update/delete callables.
   *
   * @return array{0: callable, 1: callable}
   */
  public static function metaStore(callable $get, callable $update, callable $delete): array {
    return [
      $get,
      static function (?array $marker) use ($update, $delete): void {
        if ($marker === NULL) {
          $delete();
        }
        else {
          $update($marker);
        }
      },
    ];
  }

  /**
   * Text for an administrator when a refund cannot safely be sent.
   */
  public static function refundBlockedMessage(\Throwable $e, string $reference): string {
    if ($e instanceof ReconciliationInconclusiveException) {
      return sprintf(__('An earlier refund of this transaction may have gone through, and USAePay could not confirm it. Nothing was refunded now. Check transaction %1$s in the USAePay console, then try again. (%2$s)', 'usaepay-payments'), $reference, $e->getMessage());
    }
    return sprintf(__('USAePay did not answer, so the refund may or may not have gone through. Try again in a moment: the earlier attempt is checked before anything is refunded again. (%s)', 'usaepay-payments'), $e->getMessage());
  }

  /**
   * Text when an earlier, unrecorded refund of a different amount turned up.
   */
  public static function refundMismatchMessage(string $earlierAmount, string $reference): string {
    return sprintf(__('An earlier refund of %1$s went through at USAePay (transaction %2$s) but was never recorded here. Record that refund first, using the same amount, before refunding a different amount.', 'usaepay-payments'), $earlierAmount, $reference);
  }

  /**
   * The listed transaction for an orderid, or NULL when USAePay provably has
   * none of that amount.
   *
   * @throws ReconciliationInconclusiveException
   *   When the answer is unknown, including when the lookup itself failed.
   */
  public static function lookup(GatewayClient $client, string $orderId, ?int $sentAt, ?string $amount, array $types = GatewayClient::TYPES_CHARGE): ?array {
    try {
      return $client->findTransactionByOrderId($orderId, $sentAt, 5, $amount, $types);
    }
    catch (ReconciliationInconclusiveException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new ReconciliationInconclusiveException('The lookup for ' . $orderId . ' failed: ' . $e->getMessage(), 0, [], $e);
    }
  }

  /**
   * Marker store in a transient, for callers with no record of their own yet
   * (a Gravity Forms submission has no entry until it is authorized).
   *
   * @return array{0: callable, 1: callable}
   */
  public static function transientStore(string $key, int $ttl = 2 * HOUR_IN_SECONDS): array {
    $name = 'usaepay_marker_' . md5($key);
    return [
      static fn() => get_transient($name),
      static function (?array $marker) use ($name, $ttl): void {
        if ($marker === NULL) {
          delete_transient($name);
        }
        else {
          set_transient($name, $marker, $ttl);
        }
      },
    ];
  }

}
