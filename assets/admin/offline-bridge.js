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

  var inputEl           = document.getElementById('brz-ob-input');
  var applyBtn          = document.getElementById('brz-ob-apply');
  var errorEl           = document.getElementById('brz-ob-error');
  var progressContainer = document.getElementById('brz-ob-progress-container');
  var progressText      = document.getElementById('brz-ob-progress-text');
  var progressPercent   = document.getElementById('brz-ob-progress-percent');
  var progressBar       = document.getElementById('brz-ob-progress-bar');
  var statsEl           = document.getElementById('brz-ob-stats');
  var resultsEl         = document.getElementById('brz-ob-results');
  var snackbar          = document.getElementById('brz-snackbar');

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
        ? item.fields_applied.join('، ')
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

      var skuCell = '';
      if (item.success && item.url && item.sku && item.sku !== '-') {
        skuCell = '<a class="brz-ob-clean-link" href="' + item.url + '" target="_blank">' + item.sku + '</a>';
      } else {
        skuCell = (item.sku || '-');
      }

      var productName = item.product_name || '-';
      var nameCell = '';
      if (item.success && item.url && productName !== '-') {
        nameCell = '<a class="brz-ob-clean-link" href="' + item.url + '" target="_blank">' + productName + '</a>';
      } else {
        nameCell = productName;
      }

      rows +=
        '<tr>' +
          '<td>' + skuCell + '</td>' +
          '<td>' + nameCell + '</td>' +
          '<td>' + fieldsText + '</td>' +
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
                '<th>اس‌کا‌یو</th>' +
                '<th>نام محصول</th>' +
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
    if (progressContainer) progressContainer.style.display = 'none';
    if (progressBar) progressBar.style.width = '0%';
    if (progressPercent) progressPercent.textContent = '0%';
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
      showError((i18n.maxExceeded || 'حداکثر %d آیتم مجاز است.').replace('%d', String(maxItems)));
      return;
    }

    // 3. Start processing
    applyBtn.classList.add('brz-ob-button--loading');

    // Split items into batches of 50
    var BATCH_SIZE = 50;
    var chunks = [];
    for (var i = 0; i < items.length; i += BATCH_SIZE) {
      chunks.push(items.slice(i, i + BATCH_SIZE));
    }

    var allResults = [];
    var totalSuccessCount = 0;
    var totalFailedCount = 0;
    var processedCount = 0;

    function processBatch(batchIndex) {
      if (batchIndex >= chunks.length) {
        // Complete!
        applyBtn.classList.remove('brz-ob-button--loading');
        if (progressContainer) progressContainer.style.display = 'none';

        var summaryData = {
          total: items.length,
          success_count: totalSuccessCount,
          failed_count: totalFailedCount
        };

        // Render Stats Panel
        renderStats(summaryData);

        // Render Results Table
        if (allResults.length) {
          renderResults(allResults);
        }

        // Show snackbar
        if (totalFailedCount > 0) {
          // Partial failure — warning snackbar (8s)
          var partialMsg = (i18n.partial || '%d موفق، %d ناموفق.')
            .replace('%d', String(totalSuccessCount))
            .replace('%d', String(totalFailedCount));
          showSnackbar(partialMsg, 8000);
        } else {
          // All success — success snackbar (5s)
          var successMsg = (i18n.success || '%d مورد با موفقیت اعمال شد.')
            .replace('%d', String(totalSuccessCount));
          showSnackbar(successMsg, 5000);
        }
        return;
      }

      var currentChunk = chunks[batchIndex];

      // Update progress bar
      if (progressContainer) {
        progressContainer.style.display = 'block';
        var pct = Math.round((processedCount / items.length) * 100);
        if (progressBar) progressBar.style.width = pct + '%';
        if (progressPercent) progressPercent.textContent = pct + '%';
        if (progressText) {
          progressText.textContent = (i18n.processing || 'در حال پردازش %d از %d...')
            .replace('%d', String(processedCount))
            .replace('%d', String(items.length)) + ' (بخش ' + (batchIndex + 1) + ' از ' + chunks.length + ')';
        }
      }

      // AJAX Request for this chunk
      var formData = new FormData();
      formData.append('action', 'brz_offline_bridge_apply');
      formData.append('_nonce', nonce);
      formData.append('items', JSON.stringify(currentChunk));

      fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (res) {
          if (res.success === true && res.data) {
            var data = res.data;
            if (data.results) {
              allResults = allResults.concat(data.results);
            }
            totalSuccessCount += (data.success_count || 0);
            totalFailedCount += (data.failed_count || 0);
            processedCount += currentChunk.length;

            // Next batch
            processBatch(batchIndex + 1);
          } else {
            // Server error response for this batch
            var errorMsg = (res.data && res.data.message) || (i18n.networkError || 'خطای شبکه');
            markChunkAsFailed(currentChunk, errorMsg);
            processBatch(batchIndex + 1);
          }
        })
        .catch(function (err) {
          // Network error for this batch
          var errorMsg = (i18n.networkError || 'خطای شبکه') + ': ' + err.message;
          markChunkAsFailed(currentChunk, errorMsg);
          processBatch(batchIndex + 1);
        });
    }

    function markChunkAsFailed(chunk, errorMsg) {
      for (var k = 0; k < chunk.length; k++) {
        var item = chunk[k];
        allResults.push({
          id: item.id || null,
          product_name: '-',
          fields_applied: [],
          success: false,
          warnings: [],
          error: errorMsg
        });
        totalFailedCount++;
      }
      processedCount += chunk.length;
    }

    // Start with the first batch
    processBatch(0);

  });

})();
