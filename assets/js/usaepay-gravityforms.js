/* global UsaepayPayJs, usaepay_gravityforms_strings, gform, jQuery, gformCalculateTotalPrice */
/**
 * Gravity Forms front end: mounts the hosted card fields into the USAePay
 * Card field, and turns them into a payment key inside GF 3's
 * gform/submission/pre_submission filter (aborting the submit on failure).
 */
(function (window, document) {
  'use strict';

  var cfg = window.usaepay_gravityforms_strings || {};
  var handles = {};

  function errorElement(h) {
    return document.getElementById(h.container.id.replace(/-card$/, '-errors'));
  }

  function setError(h, text, raw) {
    var el = errorElement(h);
    if (el) {
      el.textContent = text || '';
      // Raw gateway or Pay.js text for support; the visible text is payer wording.
      el.dataset.rawError = raw ? String(raw) : '';
    }
  }

  function formTotal(formId) {
    try {
      var total = typeof gformCalculateTotalPrice === 'function' ? Number(gformCalculateTotalPrice(formId)) : 0;
      return total > 0 ? total.toFixed(2) : '0.00';
    } catch (e) {
      return '0.00';
    }
  }

  function submitForm(formId) {
    var form = document.getElementById('gform_' + formId);
    var button = form && form.querySelector('#gform_submit_button_' + formId);
    if (button) {
      button.click();
    } else if (form && form.requestSubmit) {
      form.requestSubmit();
    }
  }

  function mountApplePay(formId, h) {
    var wrapper = document.getElementById(h.container.id.replace(/-card$/, '-apple-pay'));
    if (!wrapper || h.container.dataset.applePay !== '1' || !cfg.applePay || cfg.applePay.enabled !== '1') {
      return;
    }
    UsaepayPayJs.applePay({
      client: h.client,
      targetDiv: wrapper.querySelector('.usaepay-apple-pay-button').id,
      displayName: cfg.applePay.displayName,
      countryCode: cfg.applePay.countryCode,
      currencyCode: cfg.applePay.currencyCode,
      buttonType: 'plain',
      getAmount: function () { return formTotal(formId); },
      onKey: function (key) {
        setError(h, '');
        h.input.value = key;
        submitForm(formId);
      },
      onError: function (text) { setError(h, text); },
      onCancel: function () { setError(h, ''); }
    }).then(function (entry) {
      if (entry) {
        wrapper.hidden = false;
      }
    });
  }

  function mountForm(formId) {
    var form = document.getElementById('gform_' + formId);
    var container = form && form.querySelector('.usaepay-card-element');
    if (!container || container.dataset.usaepayMounted === '1') {
      return;
    }
    container.dataset.usaepayMounted = '1';
    var input = form.querySelector('input.usaepay-payment-key');
    if (input) {
      // A key echoed back after a server-side validation error is single-use
      // and possibly expired; always tokenize afresh.
      input.value = '';
    }
    var h = { container: container, input: input, form: form, client: null, cardEntry: null };
    handles[formId] = h;
    if (cfg.configured !== '1' || !cfg.publicKey) {
      setError(h, (cfg.i18n && cfg.i18n.notConfigured) || 'The payment form is not configured.');
      return;
    }
    UsaepayPayJs.mount({
      publicKey: cfg.publicKey,
      payJsUrl: cfg.payJsUrl,
      container: container,
      onFieldError: function (text) { setError(h, text); }
    }).then(function (mounted) {
      h.client = mounted.client;
      h.cardEntry = mounted.cardEntry;
      mountApplePay(formId, h);
    }).catch(function (error) {
      setError(h, UsaepayPayJs.errorText(error));
    });
  }

  function visible(el) {
    return !!(el && el.offsetParent !== null);
  }

  function installSubmissionFilter() {
    if (!window.gform || !window.gform.utils || !window.gform.utils.addAsyncFilter) {
      return;
    }
    window.gform.utils.addAsyncFilter('gform/submission/pre_submission', async function (data) {
      if (data.abort) {
        return data;
      }
      var types = window.gform.submission || {};
      // Only the final submit: a key minted on "Next" would be cleared when the
      // following page renders, so the card field must be on the last page.
      if (data.submissionType !== types.SUBMISSION_TYPE_SUBMIT) {
        return data;
      }
      var formId = parseInt(data.form.dataset.formid, 10);
      var h = handles[formId];
      if (!h || !document.body.contains(h.container) || !h.input) {
        return data;
      }
      // Hidden by conditional logic: nothing to tokenize now.
      if (!visible(h.container)) {
        return data;
      }
      if (h.input.value) {
        return data;
      }
      if (!h.client) {
        setError(h, (cfg.i18n && cfg.i18n.notConfigured) || 'The payment form is not configured.');
        data.abort = true;
        return data;
      }
      try {
        h.input.value = await UsaepayPayJs.tokenize(h);
        setError(h, '');
      } catch (error) {
        setError(h, UsaepayPayJs.errorText(error), error && error.raw ? error.raw : UsaepayPayJs.errorText(error));
        data.abort = true;
      }
      return data;
    });
  }

  document.addEventListener('gform/post_render', function (event) {
    mountForm(parseInt(event.detail.formId, 10));
  });
  if (window.jQuery) {
    jQuery(document).on('gform_post_render', function (event, formId) {
      mountForm(parseInt(formId, 10));
    });
  }
  installSubmissionFilter();
  // Forms already rendered before this script ran.
  document.querySelectorAll('form[id^="gform_"] .usaepay-card-element').forEach(function (el) {
    mountForm(parseInt(el.dataset.formId, 10));
  });
}(window, document));
