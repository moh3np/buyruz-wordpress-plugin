/* ==========================================================================
   Buyruz Offline Bridge - Client-Side Logic
   اعمال آفلاین تغییرات محصولات از Google Sheet
   ========================================================================== */

(function () {
  'use strict';

  /* ==========================================================================
     1. LOCALIZED DATA & DOM REFERENCES
     ========================================================================== */

  var config = window.brzOfflineBridge || {};
  if (!config.ajaxUrl) {
    return;
  }

  var ajaxUrl  = config.ajaxUrl;
  var nonce    = config.nonce;
  var maxItems = config.maxItems || 500;
  var i18n     = config.i18n || {};

  var inputEl    = document.getElementById('brz-ob-input');
  var applyBtn   = document.getElementById('brz-ob-apply');
  var errorEl    = document.getElementById('brz-ob-error');
  var progressEl = document.getElementById('brz-ob-progress');
  var statsEl    = document.getElementById('brz-ob-stats');
  var resultsEl  = document.getElementById('brz-ob-results');
  var snackbar   = document.getElementById('brz-snackbar');

  if (!inputEl || !applyBtn) {
    return;
  }

  /* ==========================================================================
     2. HELPER FUNCTIONS
     ========================================================================== */

  var snackbarTimer;

  function showError(msg) {
    if (!errorEl) return;
    errorEl.textContent = msg;
    errorEl.style.display = 'block';
  }

  function hideError() {
    if (!errorEl) return;
    errorEl.textContent = '';
    errorEl.style.display = 'none';
  }

  function showSnackbar(msg, duration) {
    if (!snackbar) return;
    snackbar.textContent = msg;
    snackbar.classList.add('is-visible');
    clearTimeout(snackbarTimer);
    snackbarTimer = setTimeout(function () {
      snackbar.classList.remove('is-visible');
    }, duration);
  }

  function renderStats(data) {
    if (!statsEl) return;

    statsEl.innerHTML =
      '<div class="brz-ob-stat">' +
        '<div class="brz-ob-stat__value brz-ob-stat__value--total">' + data.total + '</div>' +
        '<div class="brz-ob-stat__label">' + (i18n.totalLabel || 'مجموع') + '</div>' +
      '</div>' +
      '<div class="brz-ob-stat">' +
        '<div class="brz-ob-stat__value brz-ob-stat__value--success">' + data.success_count + '</div>' +
        '<div class="brz-ob-stat__label">' + (i18n.statusSuccess || 'موفق') + '</div>' +
      '</div>' +
      '<div class="brz-ob-stat">' +
        '<div class="brz-ob-stat__value brz-ob-stat__value--error">' + data.failed_count + '</div>' +
        '<div class="brz-ob-stat__label">' + (i18n.statusFailed || 'ناموفق') + '</div>' +
      '</div>';

    statsEl.style.display = '';
  }

  function renderResults(results) {
    if (!resultsEl || !results || !results.length) return;

    var rows = '';
    for (var i = 0; i < results.length; i++) {
      var item = results[i];
      var fieldsText = item.fields_applied && item.fields_applied.length
        ? item.fields_applied.join(', ')
        : '-';
      var statusClass = item.success ? 'is-on' : 'is-off';
      var statusLabel = item.success
        ? (i18n.statusSuccess || 'موفق')
        : (i18n.statusFailed || 'ناموفق');
      var errorText = '';

      if (item.error) {
        errorText = item.error;
      } else if (item.warnings && item.warnings.length) {
        errorText = item.warnings.join('، ');
      } else {
        errorText = '-';
      }

      rows +=
        '<tr>' +
          '<td>' + (item.id || '-') + '</td>' +
          '<td dir="ltr">' + fieldsText + '</td>' +
          '<td><span class="brz-status ' + statusClass + '">' + statusLabel + '</span></td>' +
          '<td>' + errorText + '</td>' +
        '</tr>';
    }

    resultsEl.innerHTML =
      '<div class="brz-card">' +
        '<div class="brz-card__header"><h3>' + (i18n.resultsTitle || 'نتایج') + '</h3></div>' +
        '<div class="brz-card__body">' +
          '<div class="brz-table-responsive">' +
            '<table class="widefat">' +
              '<thead><tr>' +
                '<th>آیدی محصول</th>' +
                '<th>فیلدهای اعمال‌شده</th>' +
                '<th>وضعیت</th>' +
                '<th>جزئیات</th>' +
              '</tr></thead>' +
              '<tbody>' + rows + '</tbody>' +
            '</table>' +
          '</div>' +
        '</div>' +
      '</div>';

    resultsEl.style.display = '';
  }

  /* ==========================================================================
     3. BUTTON CLICK HANDLER
     ========================================================================== */

  applyBtn.addEventListener('click', function () {

    // 1. Clear previous state
    hideError();
    if (progressEl) progressEl.style.display = 'none';
    if (statsEl) statsEl.style.display = 'none';
    if (resultsEl) {
      resultsEl.style.display = 'none';
      resultsEl.innerHTML = '';
    }
    if (snackbar) snackbar.classList.remove('is-visible');

    // 2. Client-side validation
    var raw = inputEl.value;

    // 2a. Empty/whitespace check
    if (!raw || !raw.trim()) {
      showError(i18n.emptyInput || 'کادر خالی است.');
      return;
    }

    // 2b. JSON parse
    var items;
    try {
      items = JSON.parse(raw);
    } catch (e) {
      showError((i18n.invalidJson || 'JSON نامعتبر') + ': ' + e.message);
      return;
    }

    // 2c. Not array or empty array
    if (!Array.isArray(items) || items.length === 0) {
      showError(i18n.invalidArray || 'آرایه خالی یا نامعتبر.');
      return;
    }

    // 2d. Max items check
    if (items.length > maxItems) {
      showError(i18n.maxExceeded || 'حداکثر ۵۰۰ آیتم مجاز است.');
      return;
    }

    // 3. Start processing
    applyBtn.classList.add('brz-ob-button--loading');
    if (progressEl) {
      progressEl.textContent = (i18n.processing || 'در حال پردازش %d از %d...')
        .replace('%d', '0')
        .replace('%d', String(items.length));
      progressEl.style.display = 'block';
    }

    // 4. AJAX Request
    var formData = new FormData();
    formData.append('action', 'brz_offline_bridge_apply');
    formData.append('_nonce', nonce);
    formData.append('items', JSON.stringify(items));

    fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (res) {
        // Remove loading state
        applyBtn.classList.remove('brz-ob-button--loading');
        if (progressEl) progressEl.style.display = 'none';

        if (res.success === true && res.data) {
          // 5. On success response
          var data = res.data;

          // Render Stats Panel
          renderStats(data);

          // Render Results Table
          if (data.results) {
            renderResults(data.results);
          }

          // Show snackbar
          if (data.failed_count > 0) {
            // Partial failure — warning snackbar (8s)
            var partialMsg = (i18n.partial || '%d موفق، %d ناموفق.')
              .replace('%d', String(data.success_count))
              .replace('%d', String(data.failed_count));
            showSnackbar(partialMsg, 8000);
          } else {
            // All success — success snackbar (5s)
            var successMsg = (i18n.success || '%d مورد با موفقیت اعمال شد.')
              .replace('%d', String(data.success_count));
            showSnackbar(successMsg, 5000);
          }
        } else {
          // 6. On error response (res.success === false)
          var errorMsg = (res.data && res.data.message) || (i18n.networkError || 'خطای شبکه');
          showSnackbar(errorMsg, 8000);
        }
      })
      .catch(function (err) {
        // 7. On network error
        applyBtn.classList.remove('brz-ob-button--loading');
        if (progressEl) progressEl.style.display = 'none';
        showSnackbar((i18n.networkError || 'خطای شبکه') + ': ' + err.message, 8000);
      });
  });

})();
