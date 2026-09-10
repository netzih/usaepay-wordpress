<?php

namespace Usaepay\WordPress\Modules\GravityForms;

use Usaepay\AmbiguousGatewayException;
use Usaepay\GatewayException;
use Usaepay\ReconciliationInconclusiveException;
use Usaepay\WordPress\Gateway;
use Usaepay\WordPress\Lock;
use Usaepay\WordPress\Settings;

/**
 * Charges due subscription installments against the saved card reference.
 *
 * Runs from GF's hourly {slug}_cron. Each installment has a scheduled date;
 * a decline is retried every Schedule::RETRY_DAYS up to Schedule::MAX_ATTEMPTS
 * times, then the subscription is cancelled. The orderid sent to USAePay is
 * unique per entry/installment/attempt and is written to the entry (with the
 * time) BEFORE the charge is sent; it is cleared only once the outcome has
 * been recorded. A run that finds the marker looks the orderid up first, so a
 * crash or lost response anywhere in between never leads to a second charge.
 */
final class Renewals {

  private const LOCK = 'gf_renewals';

  private AddOn $addon;

  private Gateway $gateway;

  private Settings $settings;

  public function __construct(AddOn $addon, Gateway $gateway, Settings $settings) {
    $this->addon = $addon;
    $this->gateway = $gateway;
    $this->settings = $settings;
  }

  /**
   * @return array<string, int>
   */
  public function run(?\DateTimeImmutable $now = NULL): array {
    $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $summary = ['due' => 0, 'charged' => 0, 'declined' => 0, 'cancelled' => 0, 'expired' => 0, 'skipped' => 0, 'ambiguous' => 0];
    $lock = Lock::acquire(self::LOCK, 15 * MINUTE_IN_SECONDS);
    if ($lock === NULL) {
      $summary['skipped']++;
      return $summary;
    }
    try {
      foreach ($this->dueEntries($now) as $entry) {
        $summary['due']++;
        $outcome = $this->process($entry, $now);
        $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
      }
    }
    finally {
      Lock::release(self::LOCK, $lock);
    }
    return $summary;
  }

  /**
   * Active or Failed (retrying) USAePay subscriptions whose next charge is due.
   *
   * @return array[]
   */
  public function dueEntries(\DateTimeImmutable $now): array {
    $criteria = [
      'status' => 'active',
      'field_filters' => [
        ['key' => 'payment_gateway', 'value' => $this->addon->get_slug()],
        ['key' => 'transaction_type', 'value' => '2'],
        ['key' => 'payment_status', 'operator' => 'in', 'value' => ['Active', 'Failed']],
      ],
    ];
    // Page through every matching entry: a single fixed page would silently
    // leave newer subscriptions uncharged once a site outgrows it.
    $entries = [];
    $pageSize = 200;
    for ($offset = 0; $offset < 100000; $offset += $pageSize) {
      $page = \GFAPI::get_entries(0, $criteria, ['key' => 'id', 'direction' => 'ASC'], ['offset' => $offset, 'page_size' => $pageSize]);
      if (is_wp_error($page)) {
        $this->addon->log_error(__METHOD__ . '(): ' . $page->get_error_message());
        break;
      }
      $entries = array_merge($entries, $page);
      if (count($page) < $pageSize) {
        break;
      }
    }
    $due = [];
    foreach ($entries as $entry) {
      $next = (string) gform_get_meta($entry['id'], 'usaepay_next_charge');
      if ($next === '' || (string) gform_get_meta($entry['id'], 'usaepay_card_reference') === '') {
        continue;
      }
      if (new \DateTimeImmutable($next, new \DateTimeZone('UTC')) <= $now) {
        $due[] = $entry;
      }
    }
    return $due;
  }

  /**
   * @return string
   *   charged | declined | cancelled | expired | skipped | ambiguous
   */
  public function process(array $entry, \DateTimeImmutable $now): string {
    $id = (int) $entry['id'];
    $meta = static fn(string $key, $default = '') => gform_get_meta($id, $key) ?? $default;

    $mode = (string) $meta('usaepay_mode');
    if ($mode !== '' && $mode !== $this->settings->mode()) {
      $this->addon->log_debug(__METHOD__ . "(): entry #$id was created in $mode mode; current mode is " . $this->settings->mode() . '. Skipped.');
      return 'skipped';
    }
    $lockKey = 'gf_renewal_' . $id;
    $lock = Lock::acquire($lockKey, 10 * MINUTE_IN_SECONDS);
    if ($lock === NULL) {
      return 'skipped';
    }

    try {
      $times = (int) $meta('usaepay_recurring_times', 0);
      $made = (int) $meta('usaepay_payments_made', 0);
      $length = max(1, (int) $meta('usaepay_interval_length', 1));
      $unit = (string) $meta('usaepay_interval_unit', 'month');
      $attempt = (int) $meta('usaepay_failed_attempts', 0);
      $index = max(1, (int) $meta('usaepay_installment_index', 1));
      $startMeta = (string) $meta('usaepay_schedule_start');
      $start = $startMeta !== '' ? new \DateTimeImmutable($startMeta, new \DateTimeZone('UTC')) : NULL;
      $scheduled = $start
        ? Schedule::installmentDate($start, $length, $unit, $index)
        : new \DateTimeImmutable((string) $meta('usaepay_scheduled_date') ?: (string) $meta('usaepay_next_charge'), new \DateTimeZone('UTC'));

      if ($times > 0 && $made >= $times) {
        $this->addon->expire_subscription($entry, ['note' => sprintf(__('All %d scheduled payments have been made.', 'usaepay-payments'), $times)]);
        gform_update_meta($id, 'usaepay_next_charge', '');
        return 'expired';
      }

      $amount = (float) rgar($entry, 'payment_amount');
      if ($amount <= 0) {
        $this->addon->log_error(__METHOD__ . "(): entry #$id has no recurring amount; skipped.");
        return 'skipped';
      }
      $payer = is_array($meta('usaepay_payer')) ? $meta('usaepay_payer') : [];
      $orderId = Gateway::orderId(Schedule::orderId($id, $scheduled, $attempt));
      $metadata = $this->gateway->metadata(
        Schedule::invoice($id),
        (string) $meta('usaepay_form_title') ?: sprintf(__('Gravity Forms entry %d', 'usaepay-payments'), $id),
        $payer,
        ['currency' => (string) rgar($entry, 'currency') ?: 'USD', 'orderid' => $orderId]
      );
      // Renewals run without a browser; the stored IP would be misleading.
      unset($metadata['clientip']);
      $reference = (string) $meta('usaepay_card_reference');
      $pendingOrderId = (string) $meta('usaepay_reconcile_order_id');
      $pendingSentAt = (int) $meta('usaepay_reconcile_sent_at', 0);
      $response = NULL;

      try {
        $client = $this->gateway->client(AddOn::INTEGRATION);
        if ($pendingOrderId !== '') {
          // A previous run sent this charge and never recorded the answer.
          // Find out what happened before sending another one; an unanswered
          // lookup keeps the marker and ends this run without a charge.
          $response = $this->findTransaction($client, $pendingOrderId, $pendingSentAt ?: NULL, AddOn::money($amount));
          if ($response && Gateway::transactionReference($response) === (string) $meta('usaepay_last_transaction_key')) {
            // Recorded in full by a run that died just before clearing the
            // marker; this run is for the next installment.
            $this->clearMarker($id);
            $response = NULL;
          }
          elseif ($response) {
            $this->addon->add_note($id, sprintf(__('USAePay confirms the earlier charge %s was processed; recorded without charging again.', 'usaepay-payments'), $pendingOrderId));
          }
          else {
            $this->addon->add_note($id, sprintf(__('USAePay has no record of the earlier charge %s; charging now.', 'usaepay-payments'), $pendingOrderId));
          }
        }
        if ($response === NULL) {
          $this->setMarker($id, $orderId);
          $response = $client->saleWithCardReference($reference, AddOn::money($amount), $metadata);
        }
      }
      catch (ReconciliationInconclusiveException $e) {
        $this->addon->log_error(__METHOD__ . "(): entry #$id: " . $e->getMessage());
        $this->addon->add_note($id, sprintf(__('USAePay could not confirm whether the charge %s was processed. No new charge was sent; it will be checked again next hour.', 'usaepay-payments'), $pendingOrderId), 'error');
        return 'ambiguous';
      }
      catch (AmbiguousGatewayException $e) {
        $this->addon->log_error(__METHOD__ . "(): entry #$id ambiguous: " . $e->getMessage());
        try {
          $found = $this->findTransaction($client, $orderId, time(), AddOn::money($amount));
        }
        catch (ReconciliationInconclusiveException $lookup) {
          $found = NULL;
        }
        if (!$found) {
          // The marker stays: the next run reconciles before any retry.
          $this->addon->add_note($id, sprintf(__('USAePay did not answer when charging installment %1$s (attempt %2$d). It will be checked again next hour before any retry.', 'usaepay-payments'), $scheduled->format('Y-m-d'), $attempt + 1), 'error');
          return 'ambiguous';
        }
        $response = $found;
      }
      catch (GatewayException $e) {
        // The gateway answered: nothing was charged.
        $this->clearMarker($id);
        $response = ['result_code' => 'E', 'error' => $e->getMessage()] + $e->getResponseData();
      }
      catch (\Throwable $e) {
        // Misconfiguration or a coding error must not kill the whole cron run.
        // A marker already written stays, so the next run looks it up first.
        $this->addon->log_error(__METHOD__ . "(): entry #$id: " . $e->getMessage());
        $this->addon->add_note($id, sprintf(__('USAePay renewal skipped: %s', 'usaepay-payments'), $e->getMessage()), 'error');
        return 'skipped';
      }

      if (Gateway::approved($response)) {
        return $this->recordSuccess($entry, $response, $amount, $scheduled, $start ?? $scheduled, $index, $now, $length, $unit, $made, $times);
      }
      return $this->recordFailure($entry, $response, $amount, $scheduled, $now, $attempt);
    }
    finally {
      Lock::release($lockKey, $lock);
    }
  }

  /**
   * The listed transaction carrying this orderid (approved or declined), or
   * NULL when USAePay provably has none.
   *
   * @throws \Usaepay\ReconciliationInconclusiveException
   *   When the answer is unknown: the listing window ran out or the lookup
   *   itself failed. Callers must not charge.
   */
  private function findTransaction(\Usaepay\GatewayClient $client, string $orderId, ?int $sentAt, string $amount): ?array {
    try {
      return $client->findTransactionByOrderId($orderId, $sentAt, 5, $amount);
    }
    catch (ReconciliationInconclusiveException $e) {
      throw $e;
    }
    catch (\Throwable $lookup) {
      $this->addon->log_error(__METHOD__ . '(): reconciliation failed: ' . $lookup->getMessage());
      throw new ReconciliationInconclusiveException('The lookup for ' . $orderId . ' failed: ' . $lookup->getMessage(), 0, [], $lookup);
    }
  }

  /**
   * Record that a charge with this orderid is about to be sent.
   */
  private function setMarker(int $entryId, string $orderId): void {
    gform_update_meta($entryId, 'usaepay_reconcile_order_id', $orderId);
    gform_update_meta($entryId, 'usaepay_reconcile_sent_at', time());
  }

  private function clearMarker(int $entryId): void {
    gform_update_meta($entryId, 'usaepay_reconcile_order_id', '');
    gform_update_meta($entryId, 'usaepay_reconcile_sent_at', 0);
  }

  private function recordSuccess(array $entry, array $response, float $amount, \DateTimeImmutable $scheduled, \DateTimeImmutable $start, int $index, \DateTimeImmutable $now, int $length, string $unit, int $made, int $times): string {
    $id = (int) $entry['id'];
    $transaction = Gateway::transactionReference($response);
    // Write order matters. The schedule (which makes the entry not due) is
    // advanced before the transaction key and the marker are touched, so a
    // crash anywhere in here can at worst duplicate a local note, never a
    // charge: while the marker stands, the next run finds this transaction
    // and lands here again; once the key matches, it only clears the marker.
    $this->addon->add_subscription_payment($entry, [
      'amount' => $amount,
      'transaction_id' => $transaction,
      'subscription_id' => (string) rgar($entry, 'transaction_id'),
      'payment_method' => Gateway::card($response)['brand'] ?: '',
      'note' => sprintf(__('Installment for %1$s charged via USAePay. %2$s', 'usaepay-payments'), $scheduled->format('Y-m-d'), $this->addon->gatewayNote($response)),
    ]);
    $made++;
    gform_update_meta($id, 'usaepay_payments_made', $made);
    gform_update_meta($id, 'usaepay_failed_attempts', 0);

    if ($times > 0 && $made >= $times) {
      $entry['payment_status'] = 'Active';
      $this->addon->expire_subscription($entry, ['note' => sprintf(__('All %d scheduled payments have been made.', 'usaepay-payments'), $times)]);
      gform_update_meta($id, 'usaepay_next_charge', '');
      gform_update_meta($id, 'usaepay_last_transaction_key', $transaction);
      $this->clearMarker($id);
      return 'expired';
    }
    [$nextIndex, $next] = Schedule::nextInstallmentAfter($start, $now, $length, $unit, $index + 1);
    gform_update_meta($id, 'usaepay_schedule_start', $start->format('Y-m-d H:i:s'));
    gform_update_meta($id, 'usaepay_installment_index', $nextIndex);
    gform_update_meta($id, 'usaepay_scheduled_date', $next->format('Y-m-d H:i:s'));
    gform_update_meta($id, 'usaepay_next_charge', $next->format('Y-m-d H:i:s'));
    gform_update_meta($id, 'usaepay_last_transaction_key', $transaction);
    $this->clearMarker($id);
    return 'charged';
  }

  private function recordFailure(array $entry, array $response, float $amount, \DateTimeImmutable $scheduled, \DateTimeImmutable $now, int $attempt): string {
    $id = (int) $entry['id'];
    $failure = Gateway::failure($response);
    $attempt++;
    gform_update_meta($id, 'usaepay_failed_attempts', $attempt);
    $this->clearMarker($id);
    $this->addon->log_error(__METHOD__ . "(): entry #$id declined (attempt $attempt): " . $failure['gateway']);

    if ($attempt >= Schedule::MAX_ATTEMPTS) {
      $this->addon->fail_subscription_payment($entry, [
        'amount' => $amount,
        'note' => sprintf(__('Installment for %1$s declined (attempt %2$d of %3$d): %4$s', 'usaepay-payments'), $scheduled->format('Y-m-d'), $attempt, Schedule::MAX_ATTEMPTS, $failure['gateway']),
      ]);
      $form = \GFAPI::get_form($entry['form_id']);
      $feed = $this->addon->get_payment_feed($entry, $form);
      $entry['payment_status'] = 'Failed';
      $this->addon->cancel_subscription($entry, $feed ?: ['id' => 0, 'meta' => []], sprintf(__('Subscription cancelled after %d declined attempts. The payer may start a new one.', 'usaepay-payments'), Schedule::MAX_ATTEMPTS));
      gform_update_meta($id, 'usaepay_next_charge', '');
      return 'cancelled';
    }

    $retry = $now->modify('+' . Schedule::RETRY_DAYS . ' days');
    $this->addon->fail_subscription_payment($entry, [
      'amount' => $amount,
      'note' => sprintf(__('Installment for %1$s declined (attempt %2$d of %3$d): %4$s. Next attempt %5$s (UTC).', 'usaepay-payments'), $scheduled->format('Y-m-d'), $attempt, Schedule::MAX_ATTEMPTS, $failure['gateway'], $retry->format('Y-m-d H:i')),
    ]);
    gform_update_meta($id, 'usaepay_next_charge', $retry->format('Y-m-d H:i:s'));
    return 'declined';
  }

}
