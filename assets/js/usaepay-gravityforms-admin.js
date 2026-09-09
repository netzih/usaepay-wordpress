/* global jQuery, usaepay_gravityforms_admin_strings */
(function ($) {
  'use strict';
  var strings = window.usaepay_gravityforms_admin_strings || {};
  $(document).on('click', '#usaepay-refund-button', function () {
    var button = $(this);
    var result = $('#usaepay-refund-result');
    if (!window.confirm(strings.confirm || 'Refund this payment?')) {
      return;
    }
    button.prop('disabled', true);
    result.text(strings.working || '...');
    $.post(strings.ajaxUrl, {
      action: 'usaepay_gf_refund',
      nonce: button.data('nonce'),
      entry_id: button.data('entry-id'),
      amount: $('#usaepay-refund-amount').val()
    }).done(function (response) {
      if (response && response.success) {
        result.text(response.data.message);
        window.location.reload();
      } else {
        result.text((response && response.data && response.data.message) || 'Refund failed.');
        button.prop('disabled', false);
      }
    }).fail(function () {
      result.text('Refund failed.');
      button.prop('disabled', false);
    });
  });
}(jQuery));
