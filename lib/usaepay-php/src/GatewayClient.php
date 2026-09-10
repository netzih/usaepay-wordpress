<?php

namespace Usaepay;

/**
 * Minimal USAePay REST API v2 client.
 */
class GatewayClient {

  public const DEFAULT_SOFTWARE = 'usaepay-php/0.1';

  /**
   * Amount authorized (then voided) to vault a replacement card.
   */
  public const CARD_VERIFICATION_AMOUNT = '1.00';

  private string $apiKey;

  private string $apiPin;

  private string $baseUrl;

  /**
   * Sent as USAePay's "software" field and in the User-Agent so the console
   * shows which integration made each transaction.
   */
  private string $software;

  /**
   * Optional test transport: fn(string $method, string $url, array $headers, ?string $body): array.
   */
  private $transport;

  public function __construct(string $apiKey, string $apiPin, string $baseUrl, ?callable $transport = NULL, string $software = self::DEFAULT_SOFTWARE) {
    $this->apiKey = trim($apiKey);
    $this->software = trim($software) === '' ? self::DEFAULT_SOFTWARE : trim($software);
    $this->apiPin = $apiPin;
    $this->baseUrl = rtrim($baseUrl, '/');
    $this->transport = $transport;

    if ($this->apiKey === '' || $this->apiPin === '') {
      throw new \InvalidArgumentException('USAePay API key and PIN are required.');
    }
    if (!str_starts_with($this->baseUrl, 'https://')) {
      throw new \InvalidArgumentException('USAePay API URL must use HTTPS.');
    }
  }

  public function saleWithPaymentKey(string $paymentKey, string $amount, array $metadata = [], bool $saveCard = FALSE): array {
    $payload = $this->salePayload($amount, $metadata);
    $payload['payment_key'] = $this->validateToken($paymentKey);
    if ($saveCard) {
      $payload['save_card'] = TRUE;
    }
    return $this->request('POST', '/transactions', $payload);
  }

  public function saleWithCardReference(string $cardReference, string $amount, array $metadata = []): array {
    $payload = $this->salePayload($amount, $metadata);
    $payload['creditcard'] = [
      'number' => $this->validateToken($cardReference),
      'expiration' => '0000',
    ];
    return $this->request('POST', '/transactions', $payload);
  }

  /**
   * Vault a card from a Pay.js payment key without keeping a charge.
   *
   * USAePay's cc:save command rejects payment keys ("Invalid Card Number"),
   * and the sandbox rejects $0.00 authorizations, so the card is verified with
   * a small authorization that requests save_card and is voided immediately.
   * The returned array is the authorization response (with savedcard); if the
   * void failed, 'void_error' carries the reason and the hold expires on its
   * own per the merchant's authorization-expiry setting.
   */
  public function verifyAndSaveCardWithPaymentKey(string $paymentKey, array $metadata = []): array {
    $payload = [
      'command' => 'cc:authonly',
      'amount' => self::CARD_VERIFICATION_AMOUNT,
      'payment_key' => $this->validateToken($paymentKey),
      'save_card' => TRUE,
      'description' => 'Card verification',
      'software' => $this->software,
    ];
    // orderid/invoice let a lost response be reconciled like any other charge.
    foreach (['clientip', 'custid', 'orderid', 'invoice'] as $key) {
      if (isset($metadata[$key]) && $metadata[$key] !== '') {
        $payload[$key] = $metadata[$key];
      }
    }
    $payload += $this->billingAddressPayload($metadata);
    $response = $this->request('POST', '/transactions', $payload);

    $refnum = trim((string) ($response['refnum'] ?? ''));
    if (($response['result_code'] ?? '') === 'A' && $refnum !== '') {
      try {
        $void = $this->void($refnum);
        if (($void['result_code'] ?? '') !== 'A') {
          $response['void_error'] = $this->errorMessage($void, 'USAePay did not void the verification authorization.');
        }
      }
      catch (GatewayException $e) {
        $response['void_error'] = $e->getMessage();
      }
    }
    return $response;
  }

  /**
   * Void an unsettled transaction.
   *
   * @param string $reference
   *   USAePay refnum (digits) or transaction key.
   */
  public function void(string $reference): array {
    $payload = ['command' => 'void'] + $this->transactionReference($reference);
    return $this->request('POST', '/transactions', $payload);
  }

  /**
   * Refund a settled transaction in full or in part.
   *
   * USAePay refuses to void a settled transaction ("Issue refund instead") and
   * refuses quickrefund on an unsettled one, so callers should void unsettled
   * transactions and refund settled ones; so callers should check status_code via getTransaction() first.
   *
   * @param string $reference
   *   USAePay refnum (digits) or transaction key.
   */
  public function refund(string $reference, ?string $amount = NULL, array $metadata = []): array {
    $payload = ['command' => 'refund'] + $this->transactionReference($reference);
    if ($amount !== NULL) {
      $payload['amount'] = $this->normalizeAmount($amount);
    }
    // An orderid on the refund lets a lost response be reconciled before the
    // refund is sent again.
    foreach (['orderid', 'invoice', 'description'] as $key) {
      if (isset($metadata[$key]) && $metadata[$key] !== '') {
        $payload[$key] = $metadata[$key];
      }
    }
    return $this->request('POST', '/transactions', $payload);
  }

  /**
   * Prove the API key and PIN work without moving money.
   *
   * Lists the most recent transaction, which USAePay answers with HTTP 401
   * ("Specified source key not found." or "API authentication failed") when
   * the key or PIN is wrong. Returns the decoded list response.
   */
  public function verifyCredentials(): array {
    return $this->request('GET', '/transactions?limit=1');
  }

  /**
   * Prove a Pay.js public key belongs to this account without moving money.
   *
   * Mints a single-use payment key for a Luhn-valid placeholder number, the
   * same call the Pay.js iframe makes. Nothing is charged and the key expires
   * unused. A wrong public key raises "Specified source key not found.".
   */
  public function verifyPublicKey(string $publicKey): array {
    $publicKey = trim($publicKey);
    if ($publicKey === '') {
      throw new \InvalidArgumentException('A Pay.js public key is required.');
    }
    $url = $this->baseUrl . '/pub/payment_keys';
    $headers = [
      'Accept: application/json',
      'Content-Type: application/json',
      'Authorization: Basic ' . base64_encode($publicKey . ':s2//'),
      'User-Agent: ' . $this->userAgent(),
    ];
    $body = json_encode(['creditcard' => ['number' => '4111111111111111', 'expiration' => '1229', 'cvc' => '123']], JSON_THROW_ON_ERROR);
    $result = $this->transport ? ($this->transport)('POST', $url, $headers, $body) : $this->curlRequest('POST', $url, $headers, $body);
    $status = (int) ($result['status'] ?? 0);
    $data = json_decode((string) ($result['body'] ?? ''), TRUE);
    $data = is_array($data) ? $data : [];
    if ($status < 200 || $status >= 300) {
      throw new GatewayException($this->errorMessage($data, 'USAePay returned HTTP ' . $status . '.'), $status, $data);
    }
    return $data;
  }

  /**
   * USAePay stamps 'created' in the merchant account's time zone without an
   * offset. Reading it as UTC can be off by up to 14 hours either way, so a
   * time cutoff is widened by this much before it is trusted.
   */
  public const CREATED_TIME_SLACK = 26 * 3600;

  /**
   * trantype_code values of a charge: sale and authorization.
   */
  public const TYPES_CHARGE = ['S', 'A'];

  /**
   * trantype_code values of money going back: credit (refund of a sale) and
   * a standalone refund. A refund inherits the orderid and invoice of the sale
   * it reverses (verified against the sandbox; an orderid sent with the refund
   * command is ignored), so refunds are found by the sale's orderid plus type.
   */
  public const TYPES_REFUND = ['C', 'R'];

  /**
   * Find a recent transaction by the orderid we sent with it.
   *
   * The transactions list endpoint ignores filter parameters (verified against
   * the sandbox), so this pages through the newest transactions and matches
   * orderid locally. Used to reconcile a charge whose response never arrived.
   *
   * @param int|null $sentAt
   *   Unix time the charge was sent. Paging stops at the first row created
   *   before it (minus CREATED_TIME_SLACK): a miss is then conclusive.
   * @param int $maxPages
   *   Pages of 100 rows to read before giving up.
   * @param string|null $amount
   *   When given, only a row for this amount counts; a row with the orderid
   *   but another amount is ignored. Guards against orderid reuse.
   * @param string[] $types
   *   trantype_code values that count (TYPES_CHARGE or TYPES_REFUND). Rows
   *   without a type code always count.
   *
   * @return array|null
   *   The transaction row (with 'key', 'result_code', 'trantype_code',
   *   'status_code', 'amount', 'creditcard'), or NULL when the orderid is
   *   provably absent: the listing ran out, or every row newer than the cutoff
   *   was checked.
   *
   * @throws ReconciliationInconclusiveException
   *   When $maxPages were read without finding the orderid or reaching the
   *   cutoff (or, with no cutoff, the end of the listing).
   */
  public function findTransactionByOrderId(string $orderId, ?int $sentAt = NULL, int $maxPages = 5, ?string $amount = NULL, array $types = self::TYPES_CHARGE): ?array {
    $orderId = trim($orderId);
    if ($orderId === '') {
      throw new \InvalidArgumentException('An orderid is required.');
    }
    $cutoff = $sentAt !== NULL ? $sentAt - self::CREATED_TIME_SLACK : NULL;
    $amount = $amount === NULL ? NULL : $this->normalizeAmount($amount);
    $pageSize = 100;
    for ($page = 0; $page < max(1, $maxPages); $page++) {
      $rows = $this->listTransactions($pageSize, $page * $pageSize);
      foreach ($rows as $row) {
        if (is_array($row) && (string) ($row['orderid'] ?? '') === $orderId && self::matches($row, $amount, $types)) {
          return $row;
        }
        if ($cutoff !== NULL) {
          $created = self::createdTime($row);
          if ($created !== NULL && $created < $cutoff) {
            return NULL;
          }
        }
      }
      if (count($rows) < $pageSize) {
        return NULL;
      }
    }
    throw new ReconciliationInconclusiveException(sprintf(
      'The newest %d USAePay transactions do not carry orderid %s, and older ones were not checked.',
      max(1, $maxPages) * $pageSize,
      $orderId
    ));
  }

  /**
   * The row has one of the wanted types and the expected amount. Rows without
   * a type code are accepted.
   */
  private static function matches(array $row, ?string $amount, array $types): bool {
    $type = strtoupper(trim((string) ($row['trantype_code'] ?? '')));
    if ($type !== '' && !in_array($type, $types, TRUE)) {
      return FALSE;
    }
    if ($amount !== NULL && isset($row['amount']) && is_numeric($row['amount'])
      && abs((float) $row['amount'] - (float) $amount) >= 0.005) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * A listed row's 'created' stamp read as UTC (see CREATED_TIME_SLACK).
   */
  private static function createdTime(array $row): ?int {
    $created = trim((string) ($row['created'] ?? ''));
    if ($created === '') {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable($created, new \DateTimeZone('UTC')))->getTimestamp();
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * One page of the account's transactions, newest first. The endpoint
   * accepts only limit/offset (no filters or date ranges).
   *
   * @return array[]
   */
  public function listTransactions(int $limit = 100, int $offset = 0): array {
    $limit = max(1, min(100, $limit));
    $list = $this->request('GET', '/transactions?limit=' . $limit . '&offset=' . max(0, $offset));
    $rows = is_array($list['data'] ?? NULL) ? $list['data'] : [];
    return array_values(array_filter($rows, 'is_array'));
  }

  /**
   * A USAePay customer record (created by other systems, e.g. a store); used
   * by the importer to find an email address when the sale carries none.
   */
  public function getCustomer(string $customerKey): array {
    $customerKey = trim($customerKey);
    if ($customerKey === '') {
      throw new \InvalidArgumentException('A USAePay customer key is required.');
    }
    return $this->request('GET', '/customers/' . rawurlencode($customerKey));
  }

  public function getTransaction(string $transactionKey): array {
    $transactionKey = trim($transactionKey);
    if ($transactionKey === '') {
      throw new \InvalidArgumentException('A USAePay transaction key is required.');
    }
    return $this->request('GET', '/transactions/' . rawurlencode($transactionKey));
  }

  private function salePayload(string $amount, array $metadata): array {
    $payload = [
      'command' => 'cc:sale',
      'amount' => $this->normalizeAmount($amount),
      'software' => $this->software,
    ];

    // A top-level 'email' makes USAePay send its own customer receipt, and no
    // request-level flag suppresses it. The calling application sends its own
    // receipts, so the payer's address travels only inside billing_address,
    // which does not trigger one.
    // 'custid' identifies the payer to the USAePay console (contact id or
    // email); no customer record is created there.
    foreach (['invoice', 'orderid', 'description', 'clientip', 'currency', 'custid'] as $key) {
      if (isset($metadata[$key]) && $metadata[$key] !== '') {
        $payload[$key] = $metadata[$key];
      }
    }
    $payload += $this->billingAddressPayload($metadata);

    return $payload;
  }

  private function billingAddressPayload(array $metadata): array {
    if (empty($metadata['billing_address']) || !is_array($metadata['billing_address'])) {
      return [];
    }
    $address = array_filter(
      $metadata['billing_address'],
      static fn($value) => $value !== NULL && $value !== ''
    );
    return $address ? ['billing_address' => $address] : [];
  }

  /**
   * @return array{refnum: string}|array{trankey: string}
   */
  private function transactionReference(string $reference): array {
    $reference = trim($reference);
    if ($reference === '') {
      throw new \InvalidArgumentException('A USAePay transaction reference is required.');
    }
    return ctype_digit($reference)
      ? ['refnum' => $reference]
      : ['trankey' => $this->validateToken($reference)];
  }

  private function normalizeAmount(string $amount): string {
    if (!is_numeric($amount) || (float) $amount < 0) {
      throw new \InvalidArgumentException('Payment amount must be a non-negative number.');
    }
    return number_format((float) $amount, 2, '.', '');
  }

  private function validateToken(string $token): string {
    $token = trim($token);
    if ($token === '' || strlen($token) > 255 || preg_match('/[\x00-\x20]/', $token)) {
      throw new \InvalidArgumentException('Invalid USAePay payment token.');
    }
    return $token;
  }

  private function request(string $method, string $path, ?array $payload = NULL): array {
    $url = $this->baseUrl . '/' . ltrim($path, '/');
    $body = $payload === NULL ? NULL : json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = [
      'Accept: application/json',
      'Content-Type: application/json',
      'Authorization: ' . $this->authorizationHeader(),
      'User-Agent: ' . $this->userAgent(),
    ];

    if ($this->transport) {
      $result = ($this->transport)($method, $url, $headers, $body);
    }
    else {
      $result = $this->curlRequest($method, $url, $headers, $body);
    }

    $status = (int) ($result['status'] ?? 0);
    $responseBody = (string) ($result['body'] ?? '');
    $decoded = json_decode($responseBody, TRUE);
    $data = is_array($decoded) ? $decoded : [];

    // 5xx, no status (transport failure), 408 (the gateway may have processed
    // the request but timed out sending the answer) and 429 (documented as
    // "not processed", but a lookup is cheaper than trusting that) are
    // ambiguous.
    if ($status >= 500 || $status === 0 || $status === 408 || $status === 429) {
      throw new AmbiguousGatewayException(
        'USAePay did not return a conclusive response. Reconcile the transaction before retrying.',
        $status,
        $data
      );
    }
    if ($status < 200 || $status >= 300) {
      $message = $this->errorMessage($data, 'USAePay returned HTTP ' . $status . '.');
      throw new GatewayException($message, $status, $data);
    }
    if (!is_array($decoded)) {
      throw new AmbiguousGatewayException('USAePay returned an unreadable response.', $status);
    }

    return $data;
  }

  private function errorMessage(array $data, string $fallback): string {
    foreach (['error', 'message', 'result'] as $field) {
      if (is_string($data[$field] ?? NULL) && trim($data[$field]) !== '') {
        return trim($data[$field]);
      }
      if (is_array($data[$field] ?? NULL)) {
        foreach (['message', 'description', 'error'] as $nestedField) {
          if (is_string($data[$field][$nestedField] ?? NULL) && trim($data[$field][$nestedField]) !== '') {
            return trim($data[$field][$nestedField]);
          }
        }
      }
    }
    return $fallback;
  }

  private function userAgent(): string {
    return preg_replace('/[^A-Za-z0-9.\/_-]+/', '-', $this->software);
  }

  private function authorizationHeader(): string {
    $seed = bin2hex(random_bytes(16));
    $hash = hash('sha256', $this->apiKey . $seed . $this->apiPin);
    $apiHash = 's2/' . $seed . '/' . $hash;
    return 'Basic ' . base64_encode($this->apiKey . ':' . $apiHash);
  }

  private function curlRequest(string $method, string $url, array $headers, ?string $body): array {
    if (!function_exists('curl_init')) {
      throw new GatewayException('PHP cURL is required for USAePay payments.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 35,
      CURLOPT_SSL_VERIFYPEER => TRUE,
      CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== NULL) {
      curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($curl);
    if ($responseBody === FALSE) {
      $message = curl_error($curl);
      throw new AmbiguousGatewayException(
        'The connection to USAePay failed: ' . $message
      );
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    return ['status' => $status, 'body' => $responseBody];
  }

}
