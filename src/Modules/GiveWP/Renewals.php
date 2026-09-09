<?php

namespace Usaepay\WordPress\Modules\GiveWP;

use Give\Donations\Models\DonationNote;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\Models\SubscriptionNote;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;
use Usaepay\AmbiguousGatewayException;
use Usaepay\GatewayException;
use Usaepay\WordPress\Gateway as Shared;
use Usaepay\WordPress\Log;
use Usaepay\WordPress\Plugin;

/**
 * Charges due GiveWP recurring donations against the saved-card reference
 * stored as the subscription's gateway subscription id. Runs hourly from
 * WP-Cron (Plugin::CRON_GIVEWP). GiveWP core has no renewal cron of its own.
 *
 * Success: Subscription::createRenewal() records a completed renewal donation
 * and bumps renewsAt. Decline: status Failing, renewsAt pushed RETRY_DAYS
 * ahead, up to MAX_ATTEMPTS, then Cancelled. Attempt counts live in one
 * option keyed by subscription id (GiveWP has no subscription meta table).
 */
final class Renewals {

  public const RETRY_DAYS = 3;

  public const MAX_ATTEMPTS = 3;

  private const LOCK = 'usaepay_give_renewals_lock';

  private const STATE = 'usaepay_give_renewal_state';

  /**
   * @return array<string, int>
   */
  public function run(?\DateTimeImmutable $now = NULL): array {
    $now = $now ?? new \DateTimeImmutable('now', wp_timezone());
    $summary = ['due' => 0, 'charged' => 0, 'declined' => 0, 'cancelled' => 0, 'completed' => 0, 'skipped' => 0, 'ambiguous' => 0];
    if (get_transient(self::LOCK)) {
      $summary['skipped']++;
      return $summary;
    }
    set_transient(self::LOCK, time(), 15 * MINUTE_IN_SECONDS);
    try {
      foreach ($this->dueSubscriptions($now) as $subscription) {
        $summary['due']++;
        $outcome = $this->process($subscription, $now);
        $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
      }
    }
    finally {
      delete_transient(self::LOCK);
    }
    return $summary;
  }

  /**
   * @return Subscription[]
   */
  public function dueSubscriptions(\DateTimeImmutable $now): array {
    $rows = Subscription::query()
      ->whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::FAILING])
      ->where('expiration', $now->format('Y-m-d H:i:s'), '<=')
      ->getAll();
    $due = [];
    foreach ((array) $rows as $subscription) {
      if ($subscription->gatewayId !== Gateway::ID || trim((string) $subscription->gatewaySubscriptionId) === '') {
        continue;
      }
      $due[] = $subscription;
    }
    return $due;
  }

  /**
   * @return string charged | declined | cancelled | completed | skipped | ambiguous
   */
  public function process(Subscription $subscription, \DateTimeImmutable $now): string {
    $id = (int) $subscription->id;
    $isTest = method_exists($subscription->mode, 'isTest') ? $subscription->mode->isTest() : !$subscription->mode->isLive();
    if ($isTest !== (Gateway::mode() === 'sandbox')) {
      Log::debug('GiveWP renewal skipped: subscription mode differs from current test-mode setting', ['subscription' => $id]);
      return 'skipped';
    }
    if ($this->installmentsDone($subscription)) {
      $subscription->status = SubscriptionStatus::COMPLETED();
      $subscription->save();
      SubscriptionNote::create(['subscriptionId' => $id, 'content' => __('All scheduled donations have been made.', 'usaepay-payments')]);
      return 'completed';
    }
    $lockKey = 'usaepay_give_renewal_' . $id;
    if (get_transient($lockKey)) {
      return 'skipped';
    }
    set_transient($lockKey, time(), 10 * MINUTE_IN_SECONDS);

    try {
      $state = $this->state($id);
      $attempt = (int) ($state['attempts'] ?? 0);
      $scheduled = $subscription->renewsAt instanceof \DateTimeInterface ? $subscription->renewsAt->format('Y-m-d') : $now->format('Y-m-d');
      $installment = (string) ($state['installment'] ?? $scheduled);
      $orderId = sprintf('give-sub-%d-%s-%d', $id, $installment, $attempt);
      $initial = $subscription->initialDonation();
      $invoice = 'GIVE-S' . $id;
      $payer = $initial ? [
        'email' => (string) $initial->email,
        'first_name' => (string) $initial->firstName,
        'last_name' => (string) $initial->lastName,
        'phone' => (string) $initial->phone,
        'address' => (string) ($initial->billingAddress->address1 ?? ''),
        'address2' => (string) ($initial->billingAddress->address2 ?? ''),
        'city' => (string) ($initial->billingAddress->city ?? ''),
        'state' => (string) ($initial->billingAddress->state ?? ''),
        'postcode' => (string) ($initial->billingAddress->zip ?? ''),
        'country' => (string) ($initial->billingAddress->country ?? ''),
      ] : [];
      $currency = 'USD';
      try {
        $currency = (string) $subscription->amount->getCurrency()->getCode();
      }
      catch (\Throwable $e) {
        // keep USD
      }
      $metadata = Plugin::instance()->gateway()->metadata($invoice, (string) ($initial ? $initial->formTitle : __('Recurring donation', 'usaepay-payments')), $payer, ['currency' => $currency, 'orderid' => $orderId]);
      unset($metadata['clientip']);
      $amount = $subscription->amount->formatToDecimal();
      $pendingOrderId = (string) ($state['reconcile'] ?? '');

      try {
        $client = Plugin::instance()->gateway()->client(Gateway::INTEGRATION, Gateway::mode());
        if ($pendingOrderId !== '') {
          // A previous run sent a charge and never read the answer: find out
          // what happened before sending another one.
          $found = $this->findApproved($client, $pendingOrderId);
          unset($state['reconcile']);
          $this->saveState($id, $state ?: NULL);
          if ($found) {
            SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('USAePay confirms the earlier charge %s went through; recorded without charging again.', 'usaepay-payments'), $pendingOrderId)]);
            return $this->recordSuccess($subscription, $found, $installment);
          }
        }
        $response = $client->saleWithCardReference((string) $subscription->gatewaySubscriptionId, $amount, $metadata);
      }
      catch (AmbiguousGatewayException $e) {
        Log::error('GiveWP renewal ambiguous', ['subscription' => $id, 'error' => $e->getMessage()]);
        $found = $this->findApproved($client, $orderId);
        if (!$found) {
          $state['reconcile'] = $orderId;
          $this->saveState($id, $state);
          SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('USAePay did not answer when charging the renewal due %1$s (attempt %2$d). It will be checked again next hour before any retry.', 'usaepay-payments'), $installment, $attempt + 1)]);
          return 'ambiguous';
        }
        $response = $found;
      }
      catch (GatewayException $e) {
        $response = ['result_code' => 'E', 'error' => $e->getMessage()] + $e->getResponseData();
      }
      catch (\Throwable $e) {
        // Misconfiguration or a coding error must not kill the whole cron run.
        Log::error('GiveWP renewal skipped', ['subscription' => $id, 'error' => $e->getMessage()]);
        SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('USAePay renewal skipped: %s', 'usaepay-payments'), $e->getMessage())]);
        return 'skipped';
      }

      if (Shared::approved($response)) {
        return $this->recordSuccess($subscription, $response, $installment);
      }
      return $this->recordFailure($subscription, $response, $installment, $attempt, $now);
    }
    finally {
      delete_transient($lockKey);
    }
  }

  private function recordSuccess(Subscription $subscription, array $response, string $installment): string {
    $id = (int) $subscription->id;
    $transaction = Shared::transactionReference($response);
    if ($transaction !== '' && give()->donations->getByGatewayTransactionId($transaction)) {
      Log::debug('GiveWP renewal already recorded', ['subscription' => $id, 'transaction' => $transaction]);
      return 'skipped';
    }
    // A retry moved renewsAt to the retry date; put the real due date back so
    // createRenewal() advances from it and the billing anniversary stays put.
    $state = $this->state($id);
    if (!empty($state['renews_at'])) {
      $subscription->renewsAt = new \DateTime((string) $state['renews_at'], wp_timezone());
    }
    if ($subscription->status->isFailing()) {
      $subscription->status = SubscriptionStatus::ACTIVE();
    }
    $subscription->save();
    $donation = $subscription->createRenewal(['gatewayTransactionId' => $transaction]);
    DonationNote::create([
      'donationId' => $donation->id,
      'content' => sprintf(__('Renewal due %1$s charged via USAePay. %2$s', 'usaepay-payments'), $installment, (new Gateway())->gatewayNote($response)),
    ]);
    $this->saveState($id, NULL);

    if ($this->installmentsDone($subscription)) {
      $subscription->status = SubscriptionStatus::COMPLETED();
      $subscription->save();
      SubscriptionNote::create(['subscriptionId' => $id, 'content' => __('All scheduled donations have been made.', 'usaepay-payments')]);
      return 'completed';
    }
    return 'charged';
  }

  private function recordFailure(Subscription $subscription, array $response, string $installment, int $attempt, \DateTimeImmutable $now): string {
    $id = (int) $subscription->id;
    $failure = Shared::failure($response);
    $attempt++;
    Log::error('GiveWP renewal declined', ['subscription' => $id, 'attempt' => $attempt, 'gateway' => $failure['gateway']]);

    if ($attempt >= self::MAX_ATTEMPTS) {
      $subscription->status = SubscriptionStatus::CANCELLED();
      $subscription->save();
      SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('Renewal due %1$s declined (attempt %2$d of %3$d): %4$s. Subscription cancelled; the donor may start a new one.', 'usaepay-payments'), $installment, $attempt, self::MAX_ATTEMPTS, $failure['gateway'])]);
      $this->saveState($id, NULL);
      return 'cancelled';
    }

    $retry = $now->modify('+' . self::RETRY_DAYS . ' days');
    $state = $this->state($id);
    if (empty($state['renews_at']) && $subscription->renewsAt instanceof \DateTimeInterface) {
      // Remember the real due date; renewsAt is about to hold the retry date.
      $state['renews_at'] = $subscription->renewsAt->format('Y-m-d H:i:s');
    }
    $subscription->status = SubscriptionStatus::FAILING();
    $subscription->renewsAt = \DateTime::createFromImmutable($retry);
    $subscription->save();
    $this->saveState($id, ['attempts' => $attempt, 'installment' => $installment, 'renews_at' => $state['renews_at'] ?? NULL]);
    SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('Renewal due %1$s declined (attempt %2$d of %3$d): %4$s. Next attempt %5$s.', 'usaepay-payments'), $installment, $attempt, self::MAX_ATTEMPTS, $failure['gateway'], $retry->format('Y-m-d H:i'))]);
    return 'declined';
  }

  /**
   * GiveWP's own end condition (Subscription::shouldEndSubscription): the
   * initial donation plus renewals has reached the installment count.
   * hasExceededTheMaxInstallments() is strictly "greater than" and would let
   * one installment too many through.
   */
  private function installmentsDone(Subscription $subscription): bool {
    return !$subscription->isIndefinite() && $subscription->totalDonations() >= (int) $subscription->installments;
  }

  /**
   * The approved transaction carrying this orderid, or NULL when none is
   * listed or the lookup itself fails.
   */
  private function findApproved(\Usaepay\GatewayClient $client, string $orderId): ?array {
    try {
      $found = $client->findTransactionByOrderId($orderId);
    }
    catch (\Throwable $lookup) {
      Log::error('GiveWP renewal reconciliation failed', ['error' => $lookup->getMessage()]);
      return NULL;
    }
    return $found && Shared::approved($found) ? $found : NULL;
  }

  private function state(int $subscriptionId): array {
    $all = get_option(self::STATE, []);
    return is_array($all) && isset($all[$subscriptionId]) && is_array($all[$subscriptionId]) ? $all[$subscriptionId] : [];
  }

  private function saveState(int $subscriptionId, ?array $state): void {
    $all = get_option(self::STATE, []);
    $all = is_array($all) ? $all : [];
    if ($state === NULL) {
      unset($all[$subscriptionId]);
    }
    else {
      $all[$subscriptionId] = $state;
    }
    update_option(self::STATE, $all, FALSE);
  }

}
