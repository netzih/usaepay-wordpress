/* global usaepay */
/**
 * Framework-free helper around USAePay Pay.js v2. Each module (Gravity Forms,
 * GiveWP, WooCommerce) uses it to load the script once, mount the hosted card
 * fields, turn them into a single-use payment key on submit, and offer Apple
 * Pay for one-time payments. Nothing here knows about the host plugin.
 */
(function (window, document) {
  'use strict';

  var loading = null;

  function t(key, fallback) {
    var strings = (window.UsaepayPayJsConfig && window.UsaepayPayJsConfig.i18n) || {};
    return strings[key] || fallback;
  }

  /**
   * Pay.js reports validation problems in terse merchant language
   * ("Invalid card informtion", "card number is required"); reword them.
   */
  function payerWording(message) {
    var m = String(message || '').toLowerCase();
    if (!m) {
      return t('unableToValidate', 'Unable to validate the card.');
    }
    if (/expir/.test(m)) {
      return t('checkExpiry', 'Please check the expiration date (MM/YY).');
    }
    if (/cvv|cvc|security|card code/.test(m)) {
      return t('checkCvv', 'Please check the security code (the 3 or 4 digit CVV).');
    }
    if (/required|length|invalid card|card number|luhn|informtion|information/.test(m)) {
      return t('checkCard', 'Please check the card number, expiration date and security code.');
    }
    if (/public key|authenticat|unauthori|not allowed|invalid key/.test(m)) {
      return t('misconfigured', 'The payment form is not configured correctly, so no charge was made. Please contact us.');
    }
    if (/network|timeout|failed to fetch|unavailable/.test(m)) {
      return t('noResponse', 'The card processor did not respond. Please wait a moment and try again.');
    }
    return String(message);
  }

  function errorText(error) {
    if (!error) {
      return '';
    }
    var message = error.message || error;
    try {
      var decoded = typeof error === 'string' ? JSON.parse(error) : error;
      message = decoded.message || message;
    } catch (ignored) {
      // plain string
    }
    return String(message);
  }

  /**
   * Load pay.js once; resolves when window.usaepay exists.
   */
  function load(url) {
    if (typeof usaepay !== 'undefined') {
      return Promise.resolve();
    }
    if (loading) {
      return loading;
    }
    loading = new Promise(function (resolve, reject) {
      var script = document.querySelector('script[data-usaepay-payjs]') || document.querySelector('script[src="' + url + '"]');
      if (!script) {
        script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.dataset.usaepayPayjs = '1';
        document.head.appendChild(script);
      }
      var attempts = 0;
      var poll = window.setInterval(function () {
        if (typeof usaepay !== 'undefined') {
          window.clearInterval(poll);
          resolve();
        } else if (++attempts > 150) {
          window.clearInterval(poll);
          reject(new Error(t('loadFailed', 'The secure USAePay card form could not be loaded.')));
        }
      }, 100);
      script.addEventListener('load', function () {
        if (typeof usaepay !== 'undefined') {
          window.clearInterval(poll);
          resolve();
        }
      });
      script.addEventListener('error', function () {
        window.clearInterval(poll);
        reject(new Error(t('loadFailed', 'The secure USAePay card form could not be loaded.')));
      });
    });
    loading.catch(function () { loading = null; });
    return loading;
  }

  /**
   * Mount the hosted card fields.
   *
   * @param {Object} options
   *   publicKey, payJsUrl, container (element or id), onFieldError(text|''),
   *   styles (optional Pay.js styles override).
   * @return {Promise<{client, cardEntry}>}
   */
  function mount(options) {
    var container = typeof options.container === 'string' ? document.getElementById(options.container) : options.container;
    if (!container) {
      return Promise.reject(new Error('USAePay: card container not found.'));
    }
    if (!container.id) {
      container.id = 'usaepay-card-' + Math.random().toString(36).slice(2);
    }
    return load(options.payJsUrl).then(function () {
      var client = new usaepay.Client(options.publicKey);
      var cardEntry = client.createPaymentCardEntry();
      cardEntry.generateHTML({
        styles: options.styles || {
          base: { 'font-size': '16px', 'height': '42px', 'line-height': '42px', 'color': '#2c3338', 'background': 'transparent' },
          valid: { 'color': '#2c3338' },
          invalid: { 'color': '#b32d2e' }
        },
        display_errors: false
      });
      // Re-mounting (form re-rendered by AJAX) starts from an empty box.
      container.innerHTML = '';
      cardEntry.addHTML(container.id);
      cardEntry.addEventListener('error', function (error) {
        if (options.onFieldError) {
          var text = errorText(error);
          options.onFieldError(text ? payerWording(text) : '');
        }
      });
      return { client: client, cardEntry: cardEntry };
    });
  }

  /**
   * Turn the entered card into a single-use payment key.
   */
  function tokenize(handles) {
    return handles.client.getPaymentKey(handles.cardEntry).then(function (result) {
      var key = typeof result === 'string' ? result : (result && result.key);
      if (!key) {
        throw new Error(t('noKey', 'USAePay did not return a payment key.'));
      }
      return key;
    }, function (error) {
      throw new Error(payerWording(errorText(error)));
    });
  }

  /**
   * Apple Pay button for one-time payments. Resolves with the entry when the
   * button was added, or null when Apple Pay is unavailable here.
   *
   * @param {Object} options
   *   client, targetDiv (id), displayName, countryCode, currencyCode,
   *   getAmount() -> "12.34" or "0.00", onAuthorized(payment), onKey(key),
   *   onError(text), onCancel(), buttonType ('donate'|'buy'|'plain').
   */
  function applePay(options) {
    var client = options.client;
    if (!window.ApplePaySession || !client || !client.createApplePayEntry) {
      return Promise.resolve(null);
    }
    var entry = client.createApplePayEntry({
      targetDiv: options.targetDiv,
      displayName: options.displayName,
      paymentRequest: {
        total: { label: options.displayName, amount: options.getAmount(), type: 'final' },
        countryCode: options.countryCode || 'US',
        currencyCode: options.currencyCode || 'USD',
        requiredBillingContactFields: ['postalAddress', 'name'],
        requiredShippingContactFields: ['email']
      },
      applePayBtn: { type: options.buttonType || 'plain', color: 'black' }
    });
    entry.on('applePayPaymentAuthorized', function (event) {
      if (options.onAuthorized) {
        try { options.onAuthorized(event && event.payment ? event.payment : {}); } catch (ignored) { /* prefill is a convenience */ }
      }
    });
    entry.on('applePaySuccess', function () {
      client.getPaymentKey(entry).then(function (result) {
        var key = typeof result === 'string' ? result : (result && result.key);
        if (!key) {
          throw new Error(t('noKey', 'USAePay did not return a payment key.'));
        }
        options.onKey(key);
      }).catch(function (error) {
        options.onError(errorText(error));
      });
    });
    entry.on('applePayError', function () {
      options.onError(t('applePayFailed', 'Apple Pay could not complete the payment. Please try again or enter your card details.'));
    });
    entry.on('applePayCancelled', function () {
      if (options.onCancel) { options.onCancel(); }
    });
    return entry.checkCompatibility().then(function () {
      entry.addButton();
      var button = document.getElementById('payjs-applePayBtn');
      if (button) {
        // Runs before Pay.js opens the sheet: refresh the amount from the form.
        button.addEventListener('click', function (event) {
          var amount = options.getAmount();
          if (amount === '0.00') {
            event.preventDefault();
            event.stopImmediatePropagation();
            options.onError(t('chooseAmount', 'Please choose an amount before paying with Apple Pay.'));
            return;
          }
          entry.applePayPaymentRequest.total.amount = amount;
        }, true);
      }
      return entry;
    }, function () {
      return null;
    });
  }

  window.UsaepayPayJs = {
    load: load,
    mount: mount,
    tokenize: tokenize,
    applePay: applePay,
    payerWording: payerWording,
    errorText: errorText
  };
}(window, document));
