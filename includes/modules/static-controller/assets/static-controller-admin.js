// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/* ==========================================================================
   Buyruz Static Controller - Admin JavaScript
   ========================================================================== */

jQuery(document).ready(function ($) {
  'use strict';

  var config = window.brz_static || {};
  if (!config.ajax_url || !config.nonce) {
    return;
  }

  /* ==========================================================================
     1. STATE & DOM REFERENCES
     ========================================================================== */

  // Selected pages array: [{id, type, taxonomy?}]
  var selectedPages = [];

  // Current search/pagination state
  var currentSearch = '';
  var currentPage   = 1;
  var debounceTimer = null;

  // DOM elements
  var $searchInput    = $('#brz-static-search-input');
  var $pageList       = $('#brz-static-page-list');
  var $pagination     = $('#brz-static-pagination');
  var $outputPath     = $('#brz-static-output-path');
  var $modalCode      = $('#brz-static-modal-code');
  var $regenerateBtn  = $('#brz-static-regenerate-btn');
  var $savePagesBtn   = $('#brz-static-save-pages-btn');
  var $saveSettingsBtn = $('#brz-static-save-settings-btn');
  var $saveModalBtn   = $('#brz-static-save-modal-btn');

  /* ==========================================================================
     2. HELPERS
     ========================================================================== */

  /**
   * Escape HTML entities for safe DOM insertion.
   */
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
  }

  /**
   * Show a snackbar notification message.
   * @param {string} message - Persian message text
   * @param {string} type    - 'success' or 'error'
   */
  function showSnackbar(message, type) {
    // Remove existing snackbar
    $('.brz-static-snackbar').remove();

    var cssClass = type === 'success'
      ? 'brz-static-snackbar brz-static-snackbar--success'
      : 'brz-static-snackbar brz-static-snackbar--error';

    var $snackbar = $('<div class="' + cssClass + '">' + escapeHtml(message) + '</div>');
    $('body').append($snackbar);

    // Animate in
    setTimeout(function () {
      $snackbar.addClass('is-visible');
    }, 10);

    // Auto-dismiss after 4 seconds
    setTimeout(function () {
      $snackbar.removeClass('is-visible');
      setTimeout(function () {
        $snackbar.remove();
      }, 300);
    }, 4000);
  }

  /**
   * Check if a page is currently selected.
   * @param {number} id   - Page/term ID
   * @param {string} type - 'post' or 'term'
   * @return {boolean}
   */
  function isSelected(id, type) {
    for (var i = 0; i < selectedPages.length; i++) {
      if (selectedPages[i].id === id && selectedPages[i].type === type) {
        return true;
      }
    }
    return false;
  }

  /**
   * Add a page to the selected array.
   * @param {object} page - {id, type, taxonomy?}
   */
  function addSelection(page) {
    if (!isSelected(page.id, page.type)) {
      selectedPages.push(page);
    }
  }

  /**
   * Remove a page from the selected array.
   * @param {number} id   - Page/term ID
   * @param {string} type - 'post' or 'term'
   */
  function removeSelection(id, type) {
    selectedPages = selectedPages.filter(function (p) {
      return !(p.id === id && p.type === type);
    });
  }

  /* ==========================================================================
     3. INITIAL LOAD - Populate selected pages from server
     ========================================================================== */

  /**
   * Load initial selected pages and trigger first search.
   */
  function initSelectedPages() {
    // Load selected pages from the page list data attribute if available
    var $dataEl = $('#brz-static-selected-data');
    if ($dataEl.length && $dataEl.val()) {
      try {
        selectedPages = JSON.parse($dataEl.val());
      } catch (e) {
        selectedPages = [];
      }
    }

    // Trigger initial search to populate the page list
    doSearch('', 1);
  }

  /* ==========================================================================
     4. SEARCH WITH DEBOUNCE (300ms)
     ========================================================================== */

  /**
   * Perform AJAX search for pages.
   * @param {string} search - Search term
   * @param {number} page   - Page number
   */
  function doSearch(search, page) {
    currentSearch = search;
    currentPage   = page;

    // Show loading state
    $pageList.html('<p class="brz-static-loading">' + escapeHtml(config.strings.loading) + '</p>');

    $.ajax({
      url: config.ajax_url,
      method: 'POST',
      data: {
        action: 'brz_static_search_pages',
        _ajax_nonce: config.nonce,
        search: search,
        page: page
      },
      success: function (response) {
        if (response.success && response.data) {
          renderPageList(response.data.items);
          renderPagination(response.data.pages, page);
        } else {
          $pageList.html('<p class="brz-static-empty">' + escapeHtml(config.strings.search_empty) + '</p>');
          $pagination.empty();
        }
      },
      error: function () {
        showSnackbar(config.strings.network_error, 'error');
      }
    });
  }

  // Debounced search input handler
  $searchInput.on('input', function () {
    var term = $.trim($(this).val());

    if (debounceTimer) {
      clearTimeout(debounceTimer);
    }

    debounceTimer = setTimeout(function () {
      doSearch(term, 1);
    }, 300);
  });

  /* ==========================================================================
     5. RENDER PAGE LIST
     ========================================================================== */

  /**
   * Render the list of pages with checkboxes.
   * @param {Array} items - Array of {id, title, url, type, page_type, taxonomy?}
   */
  function renderPageList(items) {
    $pageList.empty();

    if (!items || items.length === 0) {
      $pageList.html('<p class="brz-static-empty">' + escapeHtml(config.strings.search_empty) + '</p>');
      return;
    }

    $.each(items, function (i, item) {
      var checked = isSelected(item.id, item.type) ? ' checked' : '';
      var taxonomy = item.taxonomy ? ' data-taxonomy="' + escapeHtml(item.taxonomy) + '"' : '';

      var html =
        '<div class="brz-static-page-item" data-id="' + item.id + '" data-type="' + escapeHtml(item.type) + '"' + taxonomy + '>' +
          '<label class="brz-static-page-item__checkbox">' +
            '<input type="checkbox"' + checked + '>' +
          '</label>' +
          '<span class="brz-static-page-item__title">' + escapeHtml(item.title) + '</span>' +
          '<span class="brz-static-page-item__type">' + escapeHtml(item.page_type) + '</span>' +
          '<span class="brz-static-page-item__url" dir="ltr">' + escapeHtml(item.url) + '</span>' +
        '</div>';

      $pageList.append(html);
    });
  }

  /* ==========================================================================
     6. PAGE SELECTION / DESELECTION
     ========================================================================== */

  // Event delegation for checkbox changes in the page list
  $pageList.on('change', 'input[type="checkbox"]', function () {
    var $item    = $(this).closest('.brz-static-page-item');
    var id       = parseInt($item.data('id'), 10);
    var type     = $item.data('type');
    var taxonomy = $item.data('taxonomy') || null;

    if ($(this).is(':checked')) {
      var page = { id: id, type: type };
      if (taxonomy) {
        page.taxonomy = taxonomy;
      }
      addSelection(page);
    } else {
      removeSelection(id, type);
    }
  });

  /* ==========================================================================
     7. SAVE SELECTED PAGES
     ========================================================================== */

  /**
   * Save selected pages via AJAX.
   */
  function saveSelectedPages() {
    var $btn = $savePagesBtn.length ? $savePagesBtn : $saveModalBtn;
    $btn.prop('disabled', true);

    $.ajax({
      url: config.ajax_url,
      method: 'POST',
      data: {
        action: 'brz_static_save_pages',
        _ajax_nonce: config.nonce,
        selected: JSON.stringify(selectedPages)
      },
      success: function (response) {
        if (response.success) {
          showSnackbar(config.strings.save_success, 'success');
        } else {
          var msg = (response.data && response.data.message)
            ? response.data.message
            : config.strings.save_error;
          showSnackbar(msg, 'error');
        }
      },
      error: function () {
        showSnackbar(config.strings.network_error, 'error');
        // Preserve unsaved selections on error — no state reset
      },
      complete: function () {
        $btn.prop('disabled', false);
      }
    });
  }

  // Bind save pages button
  if ($savePagesBtn.length) {
    $savePagesBtn.on('click', saveSelectedPages);
  }

  /* ==========================================================================
     8. PAGINATION
     ========================================================================== */

  /**
   * Render pagination controls.
   * @param {number} totalPages  - Total number of pages
   * @param {number} activePage  - Currently active page
   */
  function renderPagination(totalPages, activePage) {
    $pagination.empty();

    if (!totalPages || totalPages <= 1) {
      return;
    }

    for (var i = 1; i <= totalPages; i++) {
      var activeClass = (i === activePage) ? ' is-active' : '';
      $pagination.append(
        '<button type="button" class="brz-static-pagination__btn' + activeClass + '" data-page="' + i + '">' + i + '</button>'
      );
    }
  }

  // Event delegation for pagination clicks
  $pagination.on('click', '.brz-static-pagination__btn', function () {
    var page = parseInt($(this).data('page'), 10);
    if (page && page !== currentPage) {
      doSearch(currentSearch, page);
    }
  });

  /* ==========================================================================
     9. SAVE SETTINGS (Output Path + Modal Code)
     ========================================================================== */

  /**
   * Save settings (output path and modal code) via AJAX.
   */
  function saveSettings() {
    var $btn = $saveSettingsBtn.length ? $saveSettingsBtn : $saveModalBtn;
    $btn.prop('disabled', true);

    $.ajax({
      url: config.ajax_url,
      method: 'POST',
      data: {
        action: 'brz_static_save_settings',
        _ajax_nonce: config.nonce,
        output_path: $.trim($outputPath.val()),
        modal_global: $modalCode.val()
      },
      success: function (response) {
        if (response.success) {
          showSnackbar(config.strings.save_success, 'success');
        } else {
          var msg = (response.data && response.data.message)
            ? response.data.message
            : config.strings.save_error;
          showSnackbar(msg, 'error');
        }
      },
      error: function () {
        showSnackbar(config.strings.network_error, 'error');
        // Preserve unsaved data on error
      },
      complete: function () {
        $btn.prop('disabled', false);
      }
    });
  }

  // Bind save settings button
  if ($saveSettingsBtn.length) {
    $saveSettingsBtn.on('click', saveSettings);
  }

  // Bind save modal button (combines settings save with page save)
  if ($saveModalBtn.length) {
    $saveModalBtn.on('click', function () {
      saveSettings();
      saveSelectedPages();
    });
  }

  /* ==========================================================================
     10. MANUAL REGENERATION
     ========================================================================== */

  $regenerateBtn.on('click', function () {
    var $btn = $(this);
    $btn.prop('disabled', true);

    $.ajax({
      url: config.ajax_url,
      method: 'POST',
      data: {
        action: 'brz_static_regenerate',
        _ajax_nonce: config.nonce
      },
      success: function (response) {
        if (response.success) {
          showSnackbar(config.strings.regenerate_ok, 'success');

          // Update status display if timestamp returned
          if (response.data && response.data.timestamp) {
            $('#brz-static-last-generated').text(response.data.timestamp);
          }
        } else {
          var msg = (response.data && response.data.message)
            ? response.data.message
            : config.strings.regenerate_err;
          showSnackbar(msg, 'error');
        }
      },
      error: function () {
        showSnackbar(config.strings.network_error, 'error');
      },
      complete: function () {
        $btn.prop('disabled', false);
      }
    });
  });

  /* ==========================================================================
     11. INITIALIZATION & DATA LOADING
     ========================================================================== */

  /**
   * Load settings via AJAX to prevent WAF blocks on HTML responses.
   */
  function loadSettings() {
    $.ajax({
      url: config.ajax_url,
      method: 'POST',
      data: {
        action: 'brz_static_get_settings',
        _ajax_nonce: config.nonce
      },
      success: function (response) {
        if (response.success && response.data) {
          var data = response.data;
          
          // Populate fields
          $outputPath.val(data.output_path || '');
          $modalCode.val(data.modal_global || '');
          
          // Populate status
          var lastGen = data.last_generated ? data.last_generated : 'هنوز تولید نشده';
          $('#brz-static-last-generated').text(lastGen);
          
          var statusLabels = {
            'idle': 'بدون فعالیت',
            'success': 'موفق',
            'error': 'خطا',
            'running': 'در حال اجرا'
          };
          var statusLabel = statusLabels[data.generation_status] || data.generation_status;
          var $statusSpan = $('#brz-static-generation-status');
          
          $statusSpan.text(statusLabel)
                     .removeClass()
                     .addClass('brz-static-status brz-static-status--' + escapeHtml(data.generation_status));
        } else {
          showSnackbar('خطا در بارگذاری تنظیمات', 'error');
        }
      },
      error: function () {
        showSnackbar(config.strings.network_error, 'error');
      },
      complete: function () {
        // Fade in the UI container
        $('.brz-static-app-container').css('opacity', '1');
      }
    });
  }

  initSelectedPages();
  loadSettings();

});
