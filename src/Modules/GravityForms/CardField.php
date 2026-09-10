<?php

namespace Usaepay\WordPress\Modules\GravityForms;

use Usaepay\WordPress\Plugin;

/**
 * "USAePay Card" form field: a container the Pay.js hosted card fields are
 * mounted into, plus a hidden input carrying the single-use payment key.
 * Card data never reaches this server; the entry stores only "Visa ending
 * in 2224" after the charge.
 */
final class CardField extends \GF_Field {

  public const TYPE = 'usaepay_card';

  public $type = self::TYPE;

  public $duplicatable = FALSE;

  public $repeatable = FALSE;

  /**
   * Excludes the field from Save & Continue drafts (GF 2.9.23+).
   */
  public $is_payment = TRUE;

  public function get_form_editor_field_title() {
    return esc_attr__('USAePay Card', 'usaepay-payments');
  }

  public function get_form_editor_field_description() {
    return esc_attr__('Secure card entry hosted by USAePay. Add a USAePay feed under Form Settings to charge the card.', 'usaepay-payments');
  }

  public function get_form_editor_field_icon() {
    return 'gform-icon--credit-card';
  }

  public function get_form_editor_button() {
    return [
      'group' => 'pricing_fields',
      'text' => $this->get_form_editor_field_title(),
      'description' => $this->get_form_editor_field_description(),
    ];
  }

  public function get_form_editor_field_settings() {
    return [
      'conditional_logic_field_setting',
      'error_message_setting',
      'label_setting',
      'label_placement_setting',
      'admin_label_setting',
      'rules_setting',
      'description_setting',
      'css_class_setting',
    ];
  }

  public function is_conditional_logic_supported() {
    return TRUE;
  }

  public function get_field_input($form, $value = '', $entry = NULL) {
    $form_id = (int) ($form['id'] ?? 0);
    $id = (int) $this->id;
    $is_entry_detail = $this->is_entry_detail();
    $is_form_editor = $this->is_form_editor();

    if ($is_entry_detail) {
      return '<div class="ginput_container">' . esc_html((string) $value) . '</div>';
    }

    $base = 'usaepay-' . $form_id . '-' . $id;
    $note = esc_html__('Card details are entered securely in a form hosted by USAePay.', 'usaepay-payments');

    if ($is_form_editor) {
      return '<div class="ginput_container ginput_container_usaepay_card">'
        . '<div class="usaepay-card-element usaepay-card-element--preview" aria-hidden="true"><span>' . esc_html__('Card number', 'usaepay-payments') . '</span><span>' . esc_html__('MM/YY', 'usaepay-payments') . '</span><span>' . esc_html__('CVV', 'usaepay-payments') . '</span></div>'
        . '<p class="usaepay-card-note">' . $note . '</p></div>';
    }

    $apple_pay = $this->applePayAllowed($form) ? '1' : '0';
    $input_id = 'input_' . $form_id . '_' . $id;
    // On a multi-page form the card is tokenized when its page is left; the
    // key has to ride along on the later pages, where this field is not
    // shown. On the card's own page it starts empty so a fresh key is minted.
    $carry = '';
    if (is_string($value) && $value !== '' && class_exists('GFFormDisplay') && (int) $this->pageNumber > 0
      && (int) \GFFormDisplay::get_current_page($form_id) !== (int) $this->pageNumber) {
      $carry = $value;
    }

    return '<div class="ginput_container ginput_container_usaepay_card" data-usaepay-form="' . $form_id . '" data-usaepay-field="' . $id . '">'
      . '<div class="usaepay-apple-pay" id="' . esc_attr($base) . '-apple-pay" hidden>'
      . '<div class="usaepay-apple-pay-button" id="' . esc_attr($base) . '-apple-pay-button"></div>'
      . '<div class="usaepay-apple-pay-divider"><span>' . esc_html__('or enter card details', 'usaepay-payments') . '</span></div>'
      . '</div>'
      . '<div class="usaepay-card-element" id="' . esc_attr($base) . '-card" data-form-id="' . $form_id . '" data-field-id="' . $id . '" data-apple-pay="' . $apple_pay . '" aria-label="' . esc_attr__('Secure card details', 'usaepay-payments') . '"></div>'
      . '<div class="usaepay-card-errors" id="' . esc_attr($base) . '-errors" role="alert" aria-live="polite"></div>'
      . '<input type="hidden" class="usaepay-payment-key" name="input_' . $id . '" id="' . esc_attr($input_id) . '" value="' . esc_attr($carry) . '" autocomplete="off">'
      . '<p class="usaepay-card-note">' . $note . '</p>'
      . '</div>';
  }

  /**
   * Apple Pay keys are single-use, so the button is offered only when every
   * active USAePay feed on the form is a one-time payment.
   */
  private function applePayAllowed(array $form): bool {
    if (!Plugin::instance()->settings()->applePayEnabled() || !class_exists(AddOn::class)) {
      return FALSE;
    }
    $feeds = AddOn::get_instance()->get_active_feeds((int) ($form['id'] ?? 0));
    if (!$feeds) {
      return FALSE;
    }
    foreach ($feeds as $feed) {
      if (rgars($feed, 'meta/transactionType') !== 'product') {
        return FALSE;
      }
    }
    return TRUE;
  }

  public function validate($value, $form) {
    if ($this->isRequired && trim((string) $value) === '') {
      $this->failed_validation = TRUE;
      $this->validation_message = $this->errorMessage ?: esc_html__('Please enter your card details.', 'usaepay-payments');
    }
  }

  /**
   * The posted value is a single-use payment key; it must never be stored.
   * After a successful charge the add-on supplies "Visa ending in 2224".
   */
  public function get_value_save_input($value, $form, $input_name, $entry_id, $entry, $repeater_index = '') {
    $summary = class_exists(AddOn::class) ? AddOn::get_instance()->cardSummaryForEntry() : '';
    return $this->sanitize_entry_value($summary, $form['id']);
  }

  public function get_value_entry_list($value, $entry, $field_id, $columns, $form) {
    return esc_html((string) $value);
  }

  public function get_value_export($entry, $input_id = '', $use_text = FALSE, $is_csv = FALSE) {
    return (string) rgar($entry, $input_id ?: (string) $this->id);
  }

  public function allow_html() {
    return FALSE;
  }

}
