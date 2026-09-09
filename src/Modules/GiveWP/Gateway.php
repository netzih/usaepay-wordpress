<?php

namespace Usaepay\WordPress\Modules\GiveWP;

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Framework\PaymentGateways\Commands\GatewayCommand;
use Give\Framework\PaymentGateways\Commands\PaymentComplete;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\SubscriptionComplete;
use Give\Framework\PaymentGateways\Contracts\PaymentGatewayRefundable;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\Models\SubscriptionNote;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;
use Usaepay\AmbiguousGatewayException;
use Usaepay\DonorMessage;
use Usaepay\GatewayException;
use Usaepay\WordPress\Gateway as Shared;
use Usaepay\WordPress\Log;
use Usaepay\WordPress\Plugin;

/**
 * GiveWP gateway (visual donation forms, v3). One-time gifts charge the Pay.js
 * key in createPayment(); recurring gifts charge the first installment with
 * save_card and store the saved-card reference as the gateway subscription
 * id, which the renewal worker charges on each renewsAt date.
 *
 * GiveWP's own Test Mode selects the sandbox credentials from
 * Settings > USAePay; live mode selects the live ones.
 */
final class Gateway extends PaymentGateway implements PaymentGatewayRefundable {

  public const ID = 'usaepay';

  public const INTEGRATION = 'GiveWP';

  public static function id(): string {
    return self::ID;
  }

  public function getId(): string {
    return self::id();
  }

  public function getName(): string {
    return __('USAePay', 'usaepay-payments');
  }

  public function getPaymentMethodLabel(): string {
    return __('Credit Card', 'usaepay-payments');
  }

  /**
   * GiveWP's Test Mode chooses sandbox credentials.
   */
  public static function mode(): string {
    return function_exists('give_is_test_mode') && give_is_test_mode() ? 'sandbox' : 'live';
  }

  private function shared(): Shared {
    return Plugin::instance()->gateway();
  }

  // ---------------------------------------------------------------------
  // Form (v3) integration
  // ---------------------------------------------------------------------

  public function enqueueScript(int $formId) {
    $plugin = Plugin::instance();
    wp_enqueue_script('usaepay-payjs', $plugin->url('assets/js/usaepay-payjs.js'), [], $plugin->assetVersion('assets/js/usaepay-payjs.js'), TRUE);
    wp_enqueue_script('usaepay_givewp', $plugin->url('assets/js/usaepay-givewp.js'), ['react', 'wp-i18n', 'usaepay-payjs'], $plugin->assetVersion('assets/js/usaepay-givewp.js'), TRUE);
    wp_enqueue_style('usaepay-payments', $plugin->url('assets/css/usaepay-payments.css'), [], $plugin->assetVersion('assets/css/usaepay-payments.css'));
  }

  public function formSettings(int $formId): array {
    $settings = Plugin::instance()->settings();
    $mode = self::mode();
    return [
      'label' => $this->getPaymentMethodLabel(),
      'publicKey' => $settings->publicKey($mode),
      'payJsUrl' => $settings->payJsUrl($mode),
      'configured' => $settings->isConfigured($mode),
      'sandbox' => $mode === 'sandbox',
      'applePay' => [
        'enabled' => $settings->applePayEnabled(),
        'displayName' => $settings->applePayDisplayName(),
        'countryCode' => 'US',
      ],
      'i18n' => [
        'notConfigured' => __('The payment form is not configured correctly, so no charge was made. Please contact us.', 'usaepay-payments'),
        'secureNote' => __('Card details are entered securely in a form hosted by USAePay.', 'usaepay-payments'),
        'orCard' => __('or enter card details', 'usaepay-payments'),
        'sandboxNote' => __('Sandbox mode: use test card 4000100011112224.', 'usaepay-payments'),
      ],
    ];
  }

  // ---------------------------------------------------------------------
  // Payments
  // ---------------------------------------------------------------------

  /**
   * @param array $gatewayData
   *   'usaepayPaymentKey' from beforeCreatePayment().
   */
  public function createPayment(Donation $donation, $gatewayData): GatewayCommand {
    $key = trim((string) ($gatewayData['usaepayPaymentKey'] ?? ''));
    if ($key === '') {
      throw new PaymentGatewayException(__('Please enter your card details.', 'usaepay-payments'));
    }
    $orderId = 'give-' . $donation->id;
    $metadata = $this->metadata($donation, 'GIVE-' . $donation->id, $orderId);
    $amount = $donation->amount->formatToDecimal();

    $response = $this->charge(static fn($client) => $client->saleWithPaymentKey($key, $amount, $metadata), $orderId, $donation);
    $reference = Shared::transactionReference($response);
    $command = new PaymentComplete($reference);
    $command->setPaymentNotes($this->gatewayNote($response));
    return $command;
  }

  public function refundDonation(Donation $donation): GatewayCommand {
    $reference = trim((string) $donation->gatewayTransactionId);
    if ($reference === '') {
      throw new PaymentGatewayException(__('This donation has no USAePay transaction reference.', 'usaepay-payments'));
    }
    try {
      $client = $this->shared()->client(self::INTEGRATION, self::mode());
      $transaction = $client->getTransaction($reference);
      $status = (string) ($transaction['status_code'] ?? '');
      if ($status === 'P' || $status === 'A') {
        $response = $client->void($reference);
        $verb = __('voided before settlement', 'usaepay-payments');
      }
      else {
        $response = $client->refund($reference);
        $verb = __('refunded', 'usaepay-payments');
      }
    }
    catch (GatewayException $e) {
      Log::error('GiveWP refund failed', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      DonationNote::create(['donationId' => $donation->id, 'content' => sprintf(__('USAePay refund failed: %s', 'usaepay-payments'), $e->getMessage())]);
      throw new PaymentGatewayException(sprintf(__('USAePay refund failed: %s', 'usaepay-payments'), $e->getMessage()));
    }
    if (!Shared::approved($response)) {
      $failure = Shared::failure($response);
      DonationNote::create(['donationId' => $donation->id, 'content' => sprintf(__('USAePay refund failed: %s', 'usaepay-payments'), $failure['gateway'])]);
      throw new PaymentGatewayException(sprintf(__('USAePay refund failed: %s', 'usaepay-payments'), $failure['gateway']));
    }
    $command = new PaymentRefunded();
    $command->setPaymentNotes(sprintf(__('Donation %1$s via USAePay. Reference: %2$s', 'usaepay-payments'), $verb, Shared::transactionReference($response) ?: $reference));
    return $command;
  }

  // ---------------------------------------------------------------------
  // Subscriptions (site-managed)
  // ---------------------------------------------------------------------

  public function createSubscription(Donation $donation, Subscription $subscription, $gatewayData): GatewayCommand {
    $key = trim((string) ($gatewayData['usaepayPaymentKey'] ?? ''));
    if ($key === '') {
      throw new PaymentGatewayException(__('Please enter your card details.', 'usaepay-payments'));
    }
    $orderId = 'give-' . $donation->id;
    $metadata = $this->metadata($donation, 'GIVE-' . $donation->id, $orderId);
    $amount = $donation->amount->formatToDecimal();

    $response = $this->charge(static fn($client) => $client->saleWithPaymentKey($key, $amount, $metadata, TRUE), $orderId, $donation);
    $cardReference = trim((string) ($response['savedcard']['key'] ?? ''));
    if ($cardReference === '') {
      Log::error('GiveWP subscription: approved without saved card; voiding', ['donation' => $donation->id]);
      try {
        $this->shared()->client(self::INTEGRATION, self::mode())->void(Shared::transactionReference($response));
      }
      catch (\Throwable $e) {
        Log::error('GiveWP subscription: void failed', ['error' => $e->getMessage()]);
      }
      throw new PaymentGatewayException(__('The card could not be saved for future donations. Please try a different card or contact us.', 'usaepay-payments'));
    }
    $card = Shared::card($response);
    $reference = Shared::transactionReference($response);

    // The saved-card reference is the "gateway subscription id": nothing is
    // scheduled at USAePay, this site charges it on each renewal date.
    $command = new SubscriptionComplete($reference, $cardReference);
    // SubscriptionComplete carries no payment notes; write the donation note directly.
    DonationNote::create(['donationId' => $donation->id, 'content' => $this->gatewayNote($response)]);
    SubscriptionNote::create([
      'subscriptionId' => $subscription->id,
      'content' => sprintf(
        __('Recurring donation charged by this site through USAePay using %1$s ending in %2$s. Nothing is scheduled in the USAePay console. Declined renewals are retried every %3$d days, %4$d attempts in all.', 'usaepay-payments'),
        $card['brand'] ?: __('card', 'usaepay-payments'),
        $card['last4'] ?: '????',
        Renewals::RETRY_DAYS,
        Renewals::MAX_ATTEMPTS
      ),
    ]);
    return $command;
  }

  public function cancelSubscription(Subscription $subscription) {
    $subscription->status = SubscriptionStatus::CANCELLED();
    $subscription->save();
    SubscriptionNote::create([
      'subscriptionId' => $subscription->id,
      'content' => __('Cancelled. No further charges will be made through USAePay.', 'usaepay-payments'),
    ]);
  }

  public function canPauseSubscription(): bool {
    return FALSE;
  }

  public function canSyncSubscriptionWithPaymentGateway(): bool {
    return FALSE;
  }

  public function canUpdateSubscriptionAmount(): bool {
    // Amount is read from the subscription at each renewal; editing it in
    // GiveWP is enough, nothing to tell the gateway.
    return TRUE;
  }

  public function updateSubscriptionAmount(Subscription $subscription, \Give\Framework\Support\ValueObjects\Money $newRenewalAmount) {
    $subscription->amount = $newRenewalAmount;
    $subscription->save();
  }

  public function canUpdateSubscriptionPaymentMethod(): bool {
    return FALSE;
  }

  // ---------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------

  /**
   * Run a charge and turn every failure into donor-safe wording.
   */
  public function charge(callable $call, string $orderId, Donation $donation): array {
    try {
      $client = $this->shared()->client(self::INTEGRATION, self::mode());
    }
    catch (GatewayException $e) {
      Log::error('GiveWP: not configured', ['error' => $e->getMessage()]);
      throw new PaymentGatewayException(__('The payment system is not configured correctly, so no charge was made. Please contact us so we can fix it.', 'usaepay-payments'));
    }
    try {
      $response = $call($client);
    }
    catch (AmbiguousGatewayException $e) {
      Log::error('GiveWP: ambiguous response', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      $found = NULL;
      try {
        $found = $client->findTransactionByOrderId($orderId);
      }
      catch (\Throwable $lookup) {
        Log::error('GiveWP: reconciliation failed', ['error' => $lookup->getMessage()]);
      }
      if (!$found || !Shared::approved($found)) {
        throw new PaymentGatewayException(__('The payment could not be completed because the card processor did not respond. Please wait a moment and try again. If the problem continues, contact us.', 'usaepay-payments'));
      }
      $response = $found;
    }
    catch (GatewayException $e) {
      Log::error('GiveWP: gateway error', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      throw new PaymentGatewayException(DonorMessage::donorText($e->getMessage()));
    }
    catch (\InvalidArgumentException $e) {
      Log::error('GiveWP: invalid request', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      throw new PaymentGatewayException(__('The payment could not be processed. Please check the card details and try again, or contact us for help.', 'usaepay-payments'));
    }
    if (!Shared::approved($response)) {
      $failure = Shared::failure($response);
      Log::error('GiveWP: declined', ['donation' => $donation->id, 'gateway' => $failure['gateway']]);
      DonationNote::create(['donationId' => $donation->id, 'content' => sprintf(__('USAePay declined the card: %s', 'usaepay-payments'), $failure['gateway'])]);
      throw new PaymentGatewayException($failure['donor']);
    }
    return $response;
  }

  public function metadata(Donation $donation, string $invoice, string $orderId): array {
    $address = $donation->billingAddress;
    $payer = [
      'email' => (string) $donation->email,
      'first_name' => (string) $donation->firstName,
      'last_name' => (string) $donation->lastName,
      'phone' => (string) $donation->phone,
      'address' => $address ? (string) ($address->address1 ?? '') : '',
      'address2' => $address ? (string) ($address->address2 ?? '') : '',
      'city' => $address ? (string) ($address->city ?? '') : '',
      'state' => $address ? (string) ($address->state ?? '') : '',
      'postcode' => $address ? (string) ($address->zip ?? '') : '',
      'country' => $address ? (string) ($address->country ?? '') : '',
    ];
    $currency = 'USD';
    try {
      $currency = (string) $donation->amount->getCurrency()->getCode();
    }
    catch (\Throwable $e) {
      // Money proxies the currency; fall back to USD if the API changes.
    }
    return $this->shared()->metadata($invoice, (string) ($donation->formTitle ?: __('Donation', 'usaepay-payments')), $payer, ['currency' => $currency, 'orderid' => $orderId]);
  }

  public function gatewayNote(array $response): string {
    $parts = [sprintf(__('USAePay reference %s', 'usaepay-payments'), Shared::transactionReference($response))];
    if (!empty($response['refnum'])) {
      $parts[] = sprintf(__('refnum %s', 'usaepay-payments'), $response['refnum']);
    }
    if (!empty($response['authcode'])) {
      $parts[] = sprintf(__('auth code %s', 'usaepay-payments'), $response['authcode']);
    }
    if (!empty($response['avs']['result'])) {
      $parts[] = sprintf(__('AVS: %s', 'usaepay-payments'), $response['avs']['result']);
    }
    if (!empty($response['cvc']['result'])) {
      $parts[] = sprintf(__('CVV: %s', 'usaepay-payments'), $response['cvc']['result']);
    }
    $card = Shared::card($response);
    if ($card['last4']) {
      $parts[] = sprintf(__('%1$s ending in %2$s', 'usaepay-payments'), $card['brand'] ?: __('Card', 'usaepay-payments'), $card['last4']);
    }
    if (self::mode() === 'sandbox') {
      $parts[] = __('SANDBOX transaction', 'usaepay-payments');
    }
    return implode(', ', $parts) . '.';
  }

}
