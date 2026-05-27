/* ==========================================================================
   Buyruz HTTP Firewall - Admin JavaScript
   هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید
   ========================================================================== */

jQuery(document).ready(function ($) {
  'use strict';

  var config = window.brz_firewall || {};
  if (!config.ajax_url || !config.nonce) {
    return;
  }

  /* ==========================================================================
     1. DOM REFERENCES
     ========================================================================== */

  var $modeRadios    = $('input[name="brz_firewall_mode"]');
  var $modeOptions   = $('.brz-firewall-mode-option');
  var $domainInput   = $('#brz-firewall-new-domain');
  var $addBtn        = $('#brz-firewall-add-btn');
  var $domainList    = $('#brz-firewall-domain-list');
  var $errorMsg      = $('#brz-firewall-error');
  var $listTitle     = $('#brz-firewall-list-title');

  /* ==========================================================================
     2. HELPERS
     ========================================================================== */

  /**
   * Show inline validation error message.
   */
  function showError(message) {
    $errorMsg.text(message).show();
  }

  /**
   * Hide inline validation error message.
   */
  function hideError() {
    $errorMsg.text('').hide();
  }

  /**
   * Escape HTML entities for safe insertion.
   */
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  /**
   * Escape string for use in HTML attributes.
   */
  function escapeAttr(str) {
    return str.replace(/&/g, '&amp;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#39;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;');
  }

  /**
   * Build and render the full domain list from an array of domain strings.
   */
  function renderDomains(domains) {
    $domainList.empty();

    if (!domains || domains.length === 0) {
      $domainList.html(
        '<p class="brz-firewall-empty">' +
          (config.strings && config.strings.empty || 'هنوز دامنه‌ای اضافه نشده است.') +
        '</p>'
      );
      return;
    }

    $.each(domains, function (i, domain) {
      $domainList.append(
        '<div class="brz-firewall-domain-item" data-domain="' + escapeAttr(domain) + '">' +
          '<span class="brz-firewall-domain-item__name" dir="ltr">' + escapeHtml(domain) + '</span>' +
          '<button type="button" class="brz-firewall-domain-item__delete" title="' +
            (config.strings && config.strings.remove || 'حذف') +
          '">&times;</button>' +
        '</div>'
      );
    });
  }

  /* ==========================================================================
     3. MODE SWITCH HANDLER
     ========================================================================== */

  $modeRadios.on('change', function () {
    var $radio  = $(this);
    var mode    = $radio.val();
    var $option = $radio.closest('.brz-firewall-mode-option');

    // Disable interaction during request.
    $modeOptions.css('pointer-events', 'none');

    $.ajax({
      url: config.ajax_url,
      method: 'POST',
      data: {
        action: 'brz_firewall_switch_mode',
        _ajax_nonce: config.nonce,
        mode: mode
      },
      success: function (response) {
        if (response.success && response.data) {
          // Update active state on mode options.
          $modeOptions.removeClass('is-active');
          $option.addClass('is-active');

          // Update domain list with new mode's domains.
          renderDomains(response.data.domains);

          // Update list title.
          $listTitle.text(mode === 'blacklist' ? 'دامنه‌های مسدود' : 'دامنه‌های مجاز');

          hideError();
        } else {
          // Show error and revert radio selection.
          var message = (response.data && response.data.message)
            ? response.data.message
            : 'خطا در تغییر حالت';
          showError(message);
        }
      },
      error: function () {
        showError('خطا در ارتباط با سرور');
      },
      complete: function () {
        $modeOptions.css('pointer-events', '');
      }
    });
  });

  /* ==========================================================================
     4. ADD DOMAIN HANDLER
     ========================================================================== */

  function addDomain() {
    var domain = $.trim($domainInput.val());

    if (!domain) {
      showError(config.strings && config.strings.empty_domain || 'دامنه نمی‌تواند خالی باشد');
      return;
    }

    hideError();
    $addBtn.prop('disabled', true);

    $.ajax({
      url: config.ajax_url,
      method: 'POST',
      data: {
        action: 'brz_firewall_add_domain',
        _ajax_nonce: config.nonce,
        domain: domain
      },
      success: function (response) {
        if (response.success && response.data) {
          // Re-render the full domain list from server response.
          renderDomains(response.data.domains);

          // Clear input.
          $domainInput.val('');
          hideError();
        } else {
          // Show server-side validation error inline.
          var message = (response.data && response.data.message)
            ? response.data.message
            : (config.strings && config.strings.add_error || 'خطا در افزودن دامنه');
          showError(message);
        }
      },
      error: function () {
        showError(config.strings && config.strings.add_error || 'خطا در افزودن دامنه');
      },
      complete: function () {
        $addBtn.prop('disabled', false);
      }
    });
  }

  $addBtn.on('click', addDomain);

  // Allow pressing Enter in the input field to add domain.
  $domainInput.on('keypress', function (e) {
    if (e.which === 13) {
      e.preventDefault();
      addDomain();
    }
  });

  /* ==========================================================================
     5. REMOVE DOMAIN HANDLER (Event Delegation)
     ========================================================================== */

  $domainList.on('click', '.brz-firewall-domain-item__delete', function () {
    var $item  = $(this).closest('.brz-firewall-domain-item');
    var domain = $item.data('domain');

    // Optimistic UI: immediately slide up the item.
    $item.slideUp(200);

    $.ajax({
      url: config.ajax_url,
      method: 'POST',
      data: {
        action: 'brz_firewall_remove_domain',
        _ajax_nonce: config.nonce,
        domain: domain
      },
      success: function (response) {
        if (response.success && response.data) {
          // Re-render domain list from server response.
          renderDomains(response.data.domains);
          hideError();
        } else {
          // Revert: show the item again on failure.
          $item.slideDown(200);
          var message = (response.data && response.data.message)
            ? response.data.message
            : 'خطا در حذف دامنه';
          showError(message);
        }
      },
      error: function () {
        // Revert: show the item again on network error.
        $item.slideDown(200);
        showError('خطا در ارتباط با سرور');
      }
    });
  });

  /* ==========================================================================
     6. BATCH ADD HANDLER
     ========================================================================== */

  var $batchTextarea = $('#brz-firewall-batch');
  var $batchBtn      = $('#brz-firewall-batch-btn');
  var $batchStatus   = $('#brz-firewall-batch-status');

  if ($batchBtn.length) {
    $batchBtn.on('click', function () {
      var domains = $.trim($batchTextarea.val());
      if (!domains) {
        $batchStatus.text('لیست خالی است').css('color', '#dc2626');
        return;
      }

      $batchBtn.prop('disabled', true);
      $batchStatus.text('در حال پردازش...').css('color', '#6b7280');

      $.ajax({
        url: config.ajax_url,
        method: 'POST',
        data: {
          action: 'brz_firewall_add_batch',
          _ajax_nonce: config.nonce,
          domains: domains
        },
        success: function (response) {
          if (response.success && response.data) {
            renderDomains(response.data.domains);
            $batchTextarea.val('');
            $batchStatus.text(response.data.message).css('color', '#16a34a');
            hideError();
          } else {
            var msg = (response.data && response.data.message) || 'خطا';
            $batchStatus.text(msg).css('color', '#dc2626');
          }
        },
        error: function () {
          $batchStatus.text('خطا در ارتباط با سرور').css('color', '#dc2626');
        },
        complete: function () {
          $batchBtn.prop('disabled', false);
        }
      });
    });
  }

});
