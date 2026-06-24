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
  var pasteBtn          = document.getElementById('brz-ob-paste');
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
    if (!raw || !raw.trim()) {
      showError(i18n.emptyInput || 'کادر خالی است.');
      return;
    }

    var items;
    try {
      items = JSON.parse(raw);
    } catch (e) {
      showError((i18n.invalidJson || 'JSON نامعتبر') + ': ' + e.message);
      return;
    }

    // Normalize "queue payload" format from Google Sheet (e.g. {price_updates:[...], brand_sync:{create:[...]}})
    var isQueuePayload = !Array.isArray(items) && (items.price_updates || items.brand_sync);
    if (isQueuePayload) {
      var queueSteps = [];
      // Convert brand_sync.create to create_dependencies payload
      if (items.brand_sync && items.brand_sync.create && items.brand_sync.create.length) {
        queueSteps.push({ create_dependencies: true, brands: items.brand_sync.create });
      }
      // Convert price_updates to product array payload
      if (items.price_updates && items.price_updates.length) {
        queueSteps.push(items.price_updates);
      }
      if (!queueSteps.length) {
        showError(i18n.invalidArray || 'آرایه خالی یا نامعتبر.');
        return;
      }
      // Execute steps sequentially (dependencies first, then products)
      applyBtn.classList.add('brz-ob-button--loading');
      var queueIndex = 0;
      var queueAllResults = [];
      var queueTotalSuccess = 0;
      var queueTotalFailed = 0;
      var queueDependencyIds = null;

      function processQueueStep() {
        if (queueIndex >= queueSteps.length) {
          applyBtn.classList.remove('brz-ob-button--loading');
          if (progressContainer) progressContainer.style.display = 'none';

          // Build combined response for Sheet to parse (brands + price results)
          var combinedResponse = {};
          if (queueDependencyIds && Object.keys(queueDependencyIds).length > 0) {
            for (var k in queueDependencyIds) {
              combinedResponse[k] = queueDependencyIds[k];
            }
          }
          if (queueAllResults.length) {
            var priceResults = [];
            for (var ri = 0; ri < queueAllResults.length; ri++) {
              var r = queueAllResults[ri];
              if (r && r.success && r.id) {
                priceResults.push({ id: r.id, success: true });
              }
            }
            if (priceResults.length) {
              combinedResponse.price_updates = priceResults;
            }
          }

          if (Object.keys(combinedResponse).length > 0) {
            showDependencyModal(combinedResponse);
          }
          if (queueAllResults.length) {
            renderStats({ total: queueAllResults.length, success_count: queueTotalSuccess, failed_count: queueTotalFailed });
            renderResults(queueAllResults);
          }
          if (queueTotalFailed > 0) {
            showSnackbar((i18n.partial || '%d موفق، %d ناموفق.').replace('%d', String(queueTotalSuccess)).replace('%d', String(queueTotalFailed)), 8000);
          } else {
            var msg = queueTotalSuccess > 0
              ? (i18n.success || '%d مورد با موفقیت اعمال شد.').replace('%d', String(queueTotalSuccess))
              : 'وابستگی‌ها پردازش شدند.';
            showSnackbar(msg, 5000);
          }
          return;
        }
        var stepData = queueSteps[queueIndex];
        var formData = new FormData();
        formData.append('action', 'brz_offline_bridge_apply');
        formData.append('_nonce', nonce);
        formData.append('items', JSON.stringify(stepData));

        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
          .then(function (response) { return response.json(); })
          .then(function (res) {
            if (res.success === true && res.data) {
              var data = res.data;
              if (data.results) queueAllResults = queueAllResults.concat(data.results);
              queueTotalSuccess += (data.success_count || 0);
              queueTotalFailed += (data.failed_count || 0);
              if (data.dependency_ids) {
                if (!queueDependencyIds) queueDependencyIds = {};
                var deps = data.dependency_ids;
                if (deps.new_brands) queueDependencyIds.new_brands = (queueDependencyIds.new_brands || []).concat(deps.new_brands);
                if (deps.new_attributes) queueDependencyIds.new_attributes = (queueDependencyIds.new_attributes || []).concat(deps.new_attributes);
                if (deps.new_terms) queueDependencyIds.new_terms = (queueDependencyIds.new_terms || []).concat(deps.new_terms);
              }
            } else {
              showError((res.data && res.data.message) || (i18n.networkError || 'خطای شبکه'));
            }
            queueIndex++;
            processQueueStep();
          })
          .catch(function (err) {
            showError((i18n.networkError || 'خطای شبکه') + ': ' + err.message);
            applyBtn.classList.remove('brz-ob-button--loading');
          });
      }
      processQueueStep();
      return;
    }

    // Determine if it's a Dependency Object Payload or a Product Array Payload
    var isDependencyPayload = !Array.isArray(items) && items.create_dependencies === true;
    if (!isDependencyPayload && (!Array.isArray(items) || items.length === 0)) {
      showError(i18n.invalidArray || 'آرایه خالی یا نامعتبر.');
      return;
    }

    if (Array.isArray(items) && items.length > maxItems) {
      showError((i18n.maxExceeded || 'حداکثر %d آیتم مجاز است.').replace('%d', String(maxItems)));
      return;
    }

    // 3. Start processing
    applyBtn.classList.add('brz-ob-button--loading');

    var BATCH_SIZE = 50;
    var chunks = [];
    if (isDependencyPayload) {
      chunks.push(items);
    } else {
      for (var i = 0; i < items.length; i += BATCH_SIZE) {
        chunks.push(items.slice(i, i + BATCH_SIZE));
      }
    }

    var allResults = [];
    var totalSuccessCount = 0;
    var totalFailedCount = 0;
    var processedCount = 0;
    var allDependencyIds = null;

    function showDependencyModal(dataObj) {
      var overlay = document.createElement('div');
      overlay.className = 'brz-ob-modal-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:99999;display:flex;align-items:center;justify-content:center;transition:opacity 0.3s ease;opacity:0;';
      
      var modal = document.createElement('div');
      modal.style.cssText = 'background:#fff;border-radius:12px;width:90%;max-width:500px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;font-family:Tahoma,sans-serif;direction:rtl;transition:transform 0.3s ease;transform:scale(0.95);';
      
      var header = document.createElement('div');
      header.style.cssText = 'padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;';
      header.innerHTML = '<h3 style="margin:0;font-size:16px;color:#0f172a;">کدهای بازگشتی برای گوگل شیت</h3>';
      
      var body = document.createElement('div');
      body.style.cssText = 'padding:20px;';
      
      var jsonStr = JSON.stringify(dataObj, null, 2);
      var codeBox = document.createElement('pre');
      codeBox.style.cssText = 'background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;font-family:monospace;font-size:13px;direction:ltr;text-align:left;overflow-x:auto;max-height:250px;margin:0 0 16px 0;';
      codeBox.textContent = jsonStr;
      
      var copyBtn = document.createElement('button');
      copyBtn.type = 'button';
      copyBtn.className = 'brz-ob-copy-btn';
      copyBtn.style.cssText = 'width:100%;padding:12px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background 0.2s;';
      copyBtn.innerHTML = '<span style="margin-left:8px;">📋</span> کپی کدها';
      
      copyBtn.addEventListener('click', function() {
        navigator.clipboard.writeText(jsonStr).then(function() {
          copyBtn.style.background = '#10b981';
          copyBtn.innerHTML = '✔️ کپی شد! در حال بستن...';
          setTimeout(function() {
            closeModal();
          }, 1500);
        });
      });
      
      function closeModal() {
        overlay.style.opacity = '0';
        modal.style.transform = 'scale(0.95)';
        setTimeout(function() {
          if (document.body.contains(overlay)) {
            document.body.removeChild(overlay);
          }
          document.removeEventListener('keydown', handleModalKeydown);
        }, 300);
        
        if (inputEl) {
          inputEl.value = '';
          inputEl.focus();
        }
      }
      
      function handleModalKeydown(e) {
        if (e.key === 'Escape') {
          closeModal();
        } else if (e.key === 'Enter' || e.key === 'c' || e.key === 'C' || ((e.ctrlKey || e.metaKey) && e.key === 'c')) {
          e.preventDefault();
          copyBtn.click();
        }
      }
      
      // Close/Copy on keys
      document.addEventListener('keydown', handleModalKeydown);
      
      // Close on click outside (overlay click)
      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal();
      });

      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.innerHTML = '✕';
      closeBtn.style.cssText = 'background:transparent;border:none;font-size:18px;cursor:pointer;color:#64748b;';
      closeBtn.addEventListener('click', closeModal);
      
      header.appendChild(closeBtn);
      body.appendChild(codeBox);
      body.appendChild(copyBtn);
      modal.appendChild(header);
      modal.appendChild(body);
      overlay.appendChild(modal);
      document.body.appendChild(overlay);
      
      // Trigger entrance animation
      requestAnimationFrame(function() {
        requestAnimationFrame(function() {
          overlay.style.opacity = '1';
          modal.style.transform = 'scale(1)';
          setTimeout(function() {
            copyBtn.focus();
          }, 100);
        });
      });
    }

    function processBatch(batchIndex) {
      if (batchIndex >= chunks.length) {
        applyBtn.classList.remove('brz-ob-button--loading');
        if (progressContainer) progressContainer.style.display = 'none';

        if (allDependencyIds && Object.keys(allDependencyIds).length > 0) {
            showDependencyModal(allDependencyIds);
            var depCount = 0;
            Object.keys(allDependencyIds).forEach(function(k) { depCount += allDependencyIds[k].length; });
            showSnackbar(depCount + ' مورد وابستگی پردازش شد.', 5000);
        } else if (isDependencyPayload) {
            showSnackbar('هیچ وابستگی جدید یا موجودی یافت نشد. لطفاً لاگ سرور را بررسی کنید.', 8000);
        } else {
            // Show snackbar
            if (totalFailedCount > 0) {
              showSnackbar((i18n.partial || '%d موفق، %d ناموفق.').replace('%d', String(totalSuccessCount)).replace('%d', String(totalFailedCount)), 8000);
            } else {
              showSnackbar((i18n.success || '%d مورد با موفقیت اعمال شد.').replace('%d', String(totalSuccessCount)), 5000);
            }
        }

        if (!isDependencyPayload) {
            renderStats({ total: items.length, success_count: totalSuccessCount, failed_count: totalFailedCount });
            if (allResults.length) renderResults(allResults);
        }
        return;
      }

      var currentChunk = chunks[batchIndex];

      if (progressContainer && !isDependencyPayload) {
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

      var formData = new FormData();
      formData.append('action', 'brz_offline_bridge_apply');
      formData.append('_nonce', nonce);
      formData.append('items', JSON.stringify(currentChunk));

      fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
        .then(function (response) { return response.json(); })
        .then(function (res) {
          if (res.success === true && res.data) {
            var data = res.data;
            if (data.results) allResults = allResults.concat(data.results);
            totalSuccessCount += (data.success_count || 0);
            totalFailedCount += (data.failed_count || 0);
            processedCount += isDependencyPayload ? 1 : currentChunk.length;

            if (data.dependency_ids) {
                if (!allDependencyIds) allDependencyIds = {};
                var deps = data.dependency_ids;
                if (deps.new_brands) allDependencyIds.new_brands = (allDependencyIds.new_brands || []).concat(deps.new_brands);
                if (deps.new_attributes) allDependencyIds.new_attributes = (allDependencyIds.new_attributes || []).concat(deps.new_attributes);
                if (deps.new_terms) allDependencyIds.new_terms = (allDependencyIds.new_terms || []).concat(deps.new_terms);
                if (deps.new_products) allDependencyIds.new_products = (allDependencyIds.new_products || []).concat(deps.new_products);
            }
            processBatch(batchIndex + 1);
          } else {
            markChunkAsFailed(currentChunk, (res.data && res.data.message) || (i18n.networkError || 'خطای شبکه'));
            processBatch(batchIndex + 1);
          }
        })
        .catch(function (err) {
          markChunkAsFailed(currentChunk, (i18n.networkError || 'خطای شبکه') + ': ' + err.message);
          processBatch(batchIndex + 1);
        });
    }

    function markChunkAsFailed(chunk, errorMsg) {
      if (!Array.isArray(chunk)) return; // skip if dependency object
      for (var k = 0; k < chunk.length; k++) {
        var item = chunk[k];
        allResults.push({ id: item.id || null, product_name: '-', fields_applied: [], success: false, warnings: [], error: errorMsg });
        totalFailedCount++;
      }
      processedCount += chunk.length;
    }

    processBatch(0);

  });

  // 1. Submit on Ctrl+Enter / Cmd+Enter inside the main input textarea
  inputEl.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      applyBtn.click();
    }
  });

  // 1.2 Paste from clipboard button action
  if (pasteBtn && inputEl) {
    pasteBtn.addEventListener('click', function () {
      navigator.clipboard.readText().then(function (text) {
        if (text) {
          inputEl.value = text;
          inputEl.focus();
          showSnackbar('داده‌ها از کلیپ‌بورد جایگذاری شدند.', 2000);
        } else {
          showSnackbar('کلیپ‌بورد خالی است یا دسترسی مجاز نیست.', 3000);
        }
      }).catch(function (err) {
        showSnackbar('خطا در خواندن کلیپ‌بورد: ' + err.message, 4000);
      });
    });
  }

  // 2. Auto-focus function
  function autoFocusActiveElement() {
    if (document.hidden) return;
    
    // Check if our modal is open
    var overlay = document.querySelector('.brz-ob-modal-overlay');
    if (overlay) {
      var copyBtn = overlay.querySelector('.brz-ob-copy-btn');
      if (copyBtn) {
        copyBtn.focus();
      }
    } else {
      if (inputEl) {
        inputEl.focus();
        inputEl.select();
      }
    }
  }

  // 3. Register focus and visibilitychange listeners
  window.addEventListener('focus', autoFocusActiveElement);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      autoFocusActiveElement();
    }
  });

  // 4. Initial auto-focus on page load
  setTimeout(autoFocusActiveElement, 100);

})();
