/* ==========================================================================
   Buyruz Static Controller - Admin JavaScript (Tabbed Interface)
   ========================================================================== */

jQuery(document).ready(function ($) {
  'use strict';

  var config = window.brz_static || {};
  if (!config.ajax_url || !config.nonce) {
    return;
  }

  /* ==========================================================================
     1. STATE MANAGEMENT
     ========================================================================== */

  var state = {
    activeTab: 'dashboard',
    tabsLoaded: { dashboard: false, sitemap: false, manual: false, settings: false },
    sitemapPages: {
      page: 1,
      perPage: 25,
      search: '',
      filterType: '',
      filterStatus: '',
      total: 0,
      totalPages: 0,
      selected: []
    },
    debounceTimer: null
  };

  /* ==========================================================================
     2. SHARED UTILITIES
     ========================================================================== */

  /**
   * Escape HTML entities for safe DOM insertion.
   * @param {string} str
   * @return {string}
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
    $('.brz-static-snackbar').remove();

    var cssClass = 'brz-static-snackbar brz-static-snackbar--' + (type || 'success');
    var $snackbar = $('<div class="' + cssClass + '">' + escapeHtml(message) + '</div>');
    $('body').append($snackbar);

    setTimeout(function () {
      $snackbar.addClass('is-visible');
    }, 10);

    // Auto-dismiss for success (3s), persistent for error
    if (type === 'success') {
      setTimeout(function () {
        $snackbar.removeClass('is-visible');
        setTimeout(function () { $snackbar.remove(); }, 300);
      }, 3000);
    } else {
      // Add dismiss button for errors
      var $dismiss = $('<button class="brz-static-snackbar__dismiss">&times;</button>');
      $snackbar.append($dismiss);
      $dismiss.on('click', function () {
        $snackbar.removeClass('is-visible');
        setTimeout(function () { $snackbar.remove(); }, 300);
      });
    }
  }

  /**
   * Show loading overlay on a container.
   * @param {string} selector - CSS selector for the container
   */
  function showLoading(selector) {
    var $el = $(selector);
    if (!$el.find('.brz-static-loading-overlay').length) {
      $el.css('position', 'relative');
      $el.append('<div class="brz-static-loading-overlay"><span class="brz-static-spinner"></span></div>');
    }
    $el.find('button, input, select').prop('disabled', true);
  }

  /**
   * Hide loading overlay from a container.
   * @param {string} selector - CSS selector for the container
   */
  function hideLoading(selector) {
    var $el = $(selector);
    $el.find('.brz-static-loading-overlay').remove();
    $el.find('button, input, select').prop('disabled', false);
  }

  /**
   * Show a confirmation dialog.
   * @param {string}   title     - Dialog title
   * @param {string}   message   - Dialog message (can contain HTML)
   * @param {function} onConfirm - Callback on confirm
   */
  function showConfirmDialog(title, message, onConfirm) {
    $('.brz-static-confirm-dialog').remove();

    var html =
      '<div class="brz-static-confirm-dialog">' +
        '<div class="brz-static-confirm-dialog__backdrop"></div>' +
        '<div class="brz-static-confirm-dialog__box">' +
          '<h3 class="brz-static-confirm-dialog__title">' + escapeHtml(title) + '</h3>' +
          '<div class="brz-static-confirm-dialog__message">' + message + '</div>' +
          '<div class="brz-static-confirm-dialog__actions">' +
            '<button type="button" class="brz-static-confirm-dialog__cancel">' +
              (config.strings.cancel || 'انصراف') +
            '</button>' +
            '<button type="button" class="brz-static-confirm-dialog__confirm">' +
              (config.strings.confirm || 'تأیید') +
            '</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    var $dialog = $(html);
    $('body').append($dialog);

    setTimeout(function () { $dialog.addClass('is-visible'); }, 10);

    $dialog.find('.brz-static-confirm-dialog__confirm').on('click', function () {
      $dialog.removeClass('is-visible');
      setTimeout(function () { $dialog.remove(); }, 300);
      if (onConfirm) { onConfirm(); }
    });

    $dialog.find('.brz-static-confirm-dialog__cancel, .brz-static-confirm-dialog__backdrop').on('click', function () {
      $dialog.removeClass('is-visible');
      setTimeout(function () { $dialog.remove(); }, 300);
    });
  }

  /**
   * AJAX wrapper with loading state management and error handling.
   * @param {object} options - {action, data, container, onSuccess, onError}
   */
  function ajaxRequest(options) {
    var container = options.container || '.brz-static-tabs__panel.is-visible';

    if (options.showLoading !== false) {
      showLoading(container);
    }

    var requestData = $.extend({
      _ajax_nonce: config.nonce
    }, options.data || {});

    $.ajax({
      url: config.ajax_url,
      method: 'POST',
      data: requestData,
      success: function (response) {
        if (options.showLoading !== false) {
          hideLoading(container);
        }
        if (response.success) {
          if (options.onSuccess) { options.onSuccess(response.data); }
        } else {
          var msg = (response.data && response.data.message)
            ? response.data.message
            : (config.strings.generic_error || 'خطایی رخ داد');
          showSnackbar(msg, 'error');
          if (options.onError) { options.onError(response.data); }
        }
      },
      error: function () {
        if (options.showLoading !== false) {
          hideLoading(container);
        }
        showSnackbar(config.strings.network_error || 'خطا در ارتباط با سرور', 'error');
        if (options.onError) { options.onError(null); }
      }
    });
  }

  /* ==========================================================================
     3. TAB NAVIGATION SYSTEM
     ========================================================================== */

  /**
   * Initialize tab navigation.
   */
  function initTabs() {
    var $tabs = $('.brz-static-tabs__btn');
    var $panels = $('.brz-static-tabs__panel');

    $tabs.on('click', function () {
      var tabId = $(this).data('tab');
      if (tabId === state.activeTab) { return; }

      // Update active tab button
      $tabs.removeClass('is-active');
      $(this).addClass('is-active');

      // Switch panels with smooth transition
      $panels.removeClass('is-visible');
      var $targetPanel = $('[data-panel="' + tabId + '"]');
      // Small delay for CSS transition
      setTimeout(function () {
        $targetPanel.addClass('is-visible');
      }, 50);

      state.activeTab = tabId;

      // Lazy load tab data on first activation
      if (!state.tabsLoaded[tabId]) {
        loadTabData(tabId);
        state.tabsLoaded[tabId] = true;
      }
    });

    // Load initial tab (dashboard)
    loadTabData('dashboard');
    state.tabsLoaded.dashboard = true;
  }

  /**
   * Load data for a specific tab.
   * @param {string} tabId - Tab identifier
   */
  function loadTabData(tabId) {
    switch (tabId) {
      case 'dashboard':
        loadDashboard();
        break;
      case 'sitemap':
        loadSitemapPages(1, {});
        break;
      case 'manual':
        loadManualPages();
        break;
      case 'settings':
        loadSettings();
        break;
    }
  }

  /* ==========================================================================
     4. DASHBOARD TAB
     ========================================================================== */

  /**
   * Load dashboard data and render summary cards.
   */
  function loadDashboard() {
    ajaxRequest({
      data: { action: 'brz_static_get_dashboard' },
      container: '[data-panel="dashboard"]',
      onSuccess: function (data) {
        renderDashboardCards(data);
        renderAcknowledgmentStatus(data.acknowledgment || null);
        renderRecentActivity(data.regeneration_history || []);
      }
    });
  }

  /**
   * Render dashboard summary cards.
   * @param {object} data - Dashboard data from server
   */
  function renderDashboardCards(data) {
    // Total pages
    $('#brz-static-dash-total').text(data.total_pages || 0);

    // Sitemap count
    $('#brz-static-dash-sitemap').text(data.sitemap_count || 0);

    // Manual count
    $('#brz-static-dash-manual').text(data.manual_count || 0);

    // Pending count
    $('#brz-static-dash-pending').text(data.pending_count || 0);

    // Last sync
    var lastSync = data.last_sync || (config.strings.never || '—');
    if (data.last_sync) {
      // Show relative time if possible
      var syncDate = new Date(data.last_sync);
      var now = new Date();
      var diffMin = Math.floor((now - syncDate) / 60000);
      if (diffMin < 60) {
        lastSync = diffMin + ' دقیقه پیش';
      } else if (diffMin < 1440) {
        lastSync = Math.floor(diffMin / 60) + ' ساعت پیش';
      } else {
        lastSync = Math.floor(diffMin / 1440) + ' روز پیش';
      }
    }
    $('#brz-static-dash-last-sync').text(lastSync);

    // System status
    var statusMap = {
      healthy: config.strings.status_healthy || 'سالم',
      attention: config.strings.status_attention || 'نیاز به توجه',
      error: config.strings.status_error || 'خطا'
    };
    var statusText = statusMap[data.system_status] || data.system_status;
    $('#brz-static-dash-status')
      .text(statusText)
      .removeClass('brz-static-system-status--healthy brz-static-system-status--attention brz-static-system-status--error')
      .addClass('brz-static-system-status--' + (data.system_status || 'healthy'));
  }

  /**
   * Render acknowledgment status in the dashboard.
   * Shows a status badge (accepted/rejected), timestamp, and page count.
   * @param {object|null} ack - Acknowledgment data from server
   */
  function renderAcknowledgmentStatus(ack) {
    var $container = $('#brz-static-dash-acknowledgment');
    if (!$container.length) { return; }

    $container.empty();

    if (!ack) {
      $container.html(
        '<div class="brz-static-dashboard__ack-status">' +
          '<span class="brz-static-badge brz-static-badge--neutral">' +
            escapeHtml(config.strings.ack_unavailable || 'اطلاعات تأیید دریافت نشده') +
          '</span>' +
        '</div>'
      );
      return;
    }

    var statusClass = ack.status === 'accepted' ? 'brz-static-badge--success' : 'brz-static-badge--error';
    var statusLabel = ack.status === 'accepted'
      ? (config.strings.ack_accepted || 'پذیرفته شده')
      : (config.strings.ack_rejected || 'رد شده');

    var html =
      '<div class="brz-static-dashboard__ack-status">' +
        '<span class="brz-static-badge ' + statusClass + '">' + escapeHtml(statusLabel) + '</span>';

    // Timestamp
    if (ack.acknowledged_at) {
      html += '<span class="brz-static-dashboard__ack-time">' +
        escapeHtml((config.strings.ack_time || 'زمان') + ': ' + ack.acknowledged_at) +
      '</span>';
    }

    // Page count (only for accepted)
    if (ack.status === 'accepted' && ack.page_count !== undefined) {
      html += '<span class="brz-static-dashboard__ack-pages">' +
        escapeHtml((config.strings.ack_pages || 'تعداد صفحات') + ': ' + ack.page_count) +
      '</span>';
    }

    // Rejection reason
    if (ack.status === 'rejected' && ack.rejection_reason) {
      html += '<span class="brz-static-dashboard__ack-reason brz-static-text--error">' +
        escapeHtml((config.strings.ack_reason || 'دلیل رد') + ': ' + ack.rejection_reason) +
      '</span>';
    }

    html += '</div>';

    $container.html(html);
  }

  /**
   * Render recent activity list from regeneration history.
   * @param {Array} history - Array of regeneration events
   */
  function renderRecentActivity(history) {
    var $list = $('#brz-static-activity-list');
    if (!$list.length) { return; }

    $list.empty();

    if (!history || history.length === 0) {
      $list.html('<p class="brz-static-empty">' + escapeHtml(config.strings.no_activity || 'فعالیتی ثبت نشده') + '</p>');
      return;
    }

    var triggerLabels = {
      manual: config.strings.trigger_manual || 'دستی',
      auto: config.strings.trigger_auto || 'خودکار'
    };

    $.each(history, function (i, event) {
      var triggerLabel = triggerLabels[event.trigger_type] || event.trigger_type;
      var html =
        '<div class="brz-static-dashboard__activity-item">' +
          '<span class="brz-static-dashboard__activity-time">' + escapeHtml(event.timestamp) + '</span>' +
          '<span class="brz-static-dashboard__activity-type">' + escapeHtml(triggerLabel) + '</span>' +
          '<span class="brz-static-dashboard__activity-count">' +
            escapeHtml(event.pages_count + ' ' + (config.strings.pages || 'صفحه')) +
          '</span>' +
        '</div>';
      $list.append(html);
    });
  }

  /**
   * Dashboard quick actions event handlers.
   */
  function initDashboardActions() {
    // Sitemap sync quick action
    $(document).on('click', '#brz-static-sync-sitemap-btn', function () {
      triggerSitemapSync();
    });

    // Regenerate pending quick action
    $(document).on('click', '#brz-static-regenerate-pending-btn', function () {
      regeneratePending();
    });
  }

  /**
   * Regenerate pending pages.
   */
  function regeneratePending() {
    ajaxRequest({
      data: { action: 'brz_static_regenerate_pending' },
      container: '[data-panel="dashboard"]',
      onSuccess: function (data) {
        showSnackbar(config.strings.regenerate_ok || 'بازسازی با موفقیت انجام شد', 'success');
        // Refresh dashboard
        loadDashboard();
      }
    });
  }

  /* ==========================================================================
     5. SITEMAP PAGES TAB
     ========================================================================== */

  /**
   * Load sitemap pages with filters and pagination.
   * @param {number} page    - Page number
   * @param {object} filters - Optional filter overrides
   */
  function loadSitemapPages(page, filters) {
    var params = {
      action: 'brz_static_get_pages',
      tab: 'sitemap',
      page: page || 1,
      per_page: state.sitemapPages.perPage,
      search: filters && filters.search !== undefined ? filters.search : state.sitemapPages.search,
      filter_type: filters && filters.filterType !== undefined ? filters.filterType : state.sitemapPages.filterType,
      filter_status: filters && filters.filterStatus !== undefined ? filters.filterStatus : state.sitemapPages.filterStatus
    };

    // Update state
    state.sitemapPages.page = params.page;
    if (filters) {
      if (filters.search !== undefined) { state.sitemapPages.search = filters.search; }
      if (filters.filterType !== undefined) { state.sitemapPages.filterType = filters.filterType; }
      if (filters.filterStatus !== undefined) { state.sitemapPages.filterStatus = filters.filterStatus; }
    }

    ajaxRequest({
      data: params,
      container: '[data-panel="sitemap"]',
      onSuccess: function (data) {
        state.sitemapPages.total = data.total || 0;
        state.sitemapPages.totalPages = data.total_pages || 0;
        state.sitemapPages.selected = [];
        renderSitemapPageList(data.items || []);
        renderSitemapPagination();
        updateBulkCheckbox();
      }
    });
  }

  /**
   * Render the sitemap page list.
   * @param {Array} items - Array of page objects
   */
  function renderSitemapPageList(items) {
    var $list = $('#brz-static-sitemap-page-list');
    if (!$list.length) { return; }

    $list.empty();

    if (!items || items.length === 0) {
      $list.html('<p class="brz-static-empty">' + escapeHtml(config.strings.no_pages || 'صفحه‌ای یافت نشد') + '</p>');
      return;
    }

    $.each(items, function (i, item) {
      var statusIcon = '🟢';
      if (item.page_status === 'pending') { statusIcon = '🟠'; }
      if (item.page_status === 'error') { statusIcon = '🔴'; }

      var sourceLabel = item.page_source === 'manual'
        ? '<span class="brz-static-badge brz-static-badge--manual">' + escapeHtml(config.strings.manual || 'دستی') + '</span>'
        : '<span class="brz-static-badge brz-static-badge--sitemap">' + escapeHtml(config.strings.sitemap || 'سایت‌مپ') + '</span>';

      var html =
        '<div class="brz-static-page-item" data-url="' + escapeHtml(item.url) + '">' +
          '<label class="brz-static-page-item__checkbox">' +
            '<input type="checkbox" class="brz-static-bulk-item">' +
          '</label>' +
          '<span class="brz-static-page-item__status">' + statusIcon + '</span>' +
          '<span class="brz-static-page-item__title">' + escapeHtml(item.title || item.url) + '</span>' +
          '<span class="brz-static-page-item__type">' + escapeHtml(item.page_type || 'unknown') + '</span>' +
          sourceLabel +
          '<span class="brz-static-page-item__url" dir="ltr">' + escapeHtml(item.url) + '</span>' +
        '</div>';

      $list.append(html);
    });
  }

  /**
   * Render pagination controls for sitemap pages.
   */
  function renderSitemapPagination() {
    var $pagination = $('#brz-static-sitemap-pagination');
    if (!$pagination.length) { return; }

    $pagination.empty();

    var totalPages = state.sitemapPages.totalPages;
    var currentPage = state.sitemapPages.page;

    if (totalPages <= 1) { return; }

    // Previous button
    if (currentPage > 1) {
      $pagination.append(
        '<button type="button" class="brz-static-pagination__btn" data-page="' + (currentPage - 1) + '">&laquo;</button>'
      );
    }

    // Page numbers (show max 7 pages with ellipsis)
    var startPage = Math.max(1, currentPage - 3);
    var endPage = Math.min(totalPages, currentPage + 3);

    if (startPage > 1) {
      $pagination.append('<button type="button" class="brz-static-pagination__btn" data-page="1">1</button>');
      if (startPage > 2) {
        $pagination.append('<span class="brz-static-pagination__ellipsis">...</span>');
      }
    }

    for (var i = startPage; i <= endPage; i++) {
      var activeClass = (i === currentPage) ? ' is-active' : '';
      $pagination.append(
        '<button type="button" class="brz-static-pagination__btn' + activeClass + '" data-page="' + i + '">' + i + '</button>'
      );
    }

    if (endPage < totalPages) {
      if (endPage < totalPages - 1) {
        $pagination.append('<span class="brz-static-pagination__ellipsis">...</span>');
      }
      $pagination.append('<button type="button" class="brz-static-pagination__btn" data-page="' + totalPages + '">' + totalPages + '</button>');
    }

    // Next button
    if (currentPage < totalPages) {
      $pagination.append(
        '<button type="button" class="brz-static-pagination__btn" data-page="' + (currentPage + 1) + '">&raquo;</button>'
      );
    }
  }

  /**
   * Initialize sitemap tab event handlers.
   */
  function initSitemapHandlers() {
    // Filter dropdowns
    $(document).on('change', '#brz-static-filter-type', function () {
      loadSitemapPages(1, { filterType: $(this).val() });
    });

    $(document).on('change', '#brz-static-filter-status', function () {
      loadSitemapPages(1, { filterStatus: $(this).val() });
    });

    // Search input with 300ms debounce
    $(document).on('input', '#brz-static-filter-search', function () {
      var term = $.trim($(this).val());

      if (state.debounceTimer) {
        clearTimeout(state.debounceTimer);
      }

      state.debounceTimer = setTimeout(function () {
        loadSitemapPages(1, { search: term });
      }, 300);
    });

    // Per-page selector
    $(document).on('change', '.brz-static-pagination__per-page', function () {
      state.sitemapPages.perPage = parseInt($(this).val(), 10) || 25;
      loadSitemapPages(1, {});
    });

    // Pagination clicks
    $(document).on('click', '#brz-static-sitemap-pagination .brz-static-pagination__btn', function () {
      var page = parseInt($(this).data('page'), 10);
      if (page && page !== state.sitemapPages.page) {
        loadSitemapPages(page, {});
      }
    });
  }

  /**
   * Trigger sitemap sync — direct import without preview (sitemap is fully automatic).
   */
  function triggerSitemapSync() {
    var $btn = $('#brz-static-sync-sitemap-btn');
    $btn.prop('disabled', true).text(config.strings.loading || 'در حال بارگذاری...');

    ajaxRequest({
      data: { action: 'brz_static_sitemap_sync' },
      container: '[data-panel="dashboard"]',
      onSuccess: function (data) {
        var msg = (config.strings.sync_success || 'همگام‌سازی با موفقیت انجام شد');
        if (data && data.imported !== undefined) {
          msg += ' (' + data.imported + ' جدید، ' + data.updated + ' به‌روزرسانی)';
        }
        showSnackbar(msg, 'success');
        $btn.prop('disabled', false).text(config.strings.sync_btn || 'همگام‌سازی سایت‌مپ');
        loadDashboard();
      },
      onError: function () {
        $btn.prop('disabled', false).text(config.strings.sync_btn || 'همگام‌سازی سایت‌مپ');
      }
    });
  }

  /**
   * Confirm and execute sitemap import.
   */
  function confirmSitemapImport() {
    ajaxRequest({
      data: { action: 'brz_static_sitemap_confirm_import' },
      container: '[data-panel="dashboard"]',
      onSuccess: function (data) {
        showSnackbar(config.strings.sync_success || 'همگام‌سازی با موفقیت انجام شد', 'success');
        // Refresh dashboard
        loadDashboard();
      }
    });
  }

  /* ==========================================================================
     6. BULK ACTIONS
     ========================================================================== */

  /**
   * Initialize bulk action handlers.
   */
  function initBulkActions() {
    // Select all checkbox
    $(document).on('change', '#brz-static-bulk-select-all', function () {
      var isChecked = $(this).is(':checked');
      $('.brz-static-bulk-item').prop('checked', isChecked);
      updateBulkSelection();
    });

    // Individual checkbox change
    $(document).on('change', '.brz-static-bulk-item', function () {
      updateBulkSelection();
      updateBulkCheckbox();
    });

    // Execute bulk action
    $(document).on('click', '#brz-static-bulk-execute-btn', function () {
      executeBulkAction();
    });
  }

  /**
   * Update the selected URLs array from checkboxes.
   */
  function updateBulkSelection() {
    state.sitemapPages.selected = [];
    $('.brz-static-bulk-item:checked').each(function () {
      var url = $(this).closest('.brz-static-page-item').data('url');
      if (url) {
        state.sitemapPages.selected.push(url);
      }
    });
  }

  /**
   * Update the select-all checkbox state.
   */
  function updateBulkCheckbox() {
    var $all = $('.brz-static-bulk-item');
    var $checked = $('.brz-static-bulk-item:checked');
    var $selectAll = $('#brz-static-bulk-select-all');

    if ($all.length > 0 && $all.length === $checked.length) {
      $selectAll.prop('checked', true).prop('indeterminate', false);
    } else if ($checked.length > 0) {
      $selectAll.prop('checked', false).prop('indeterminate', true);
    } else {
      $selectAll.prop('checked', false).prop('indeterminate', false);
    }
  }

  /**
   * Execute the selected bulk action.
   */
  function executeBulkAction() {
    var action = $('#brz-static-bulk-action').val();
    var selected = state.sitemapPages.selected;

    if (!action) {
      showSnackbar(config.strings.select_action || 'لطفاً یک عملیات انتخاب کنید', 'error');
      return;
    }

    if (selected.length === 0) {
      showSnackbar(config.strings.select_pages || 'لطفاً صفحاتی را انتخاب کنید', 'error');
      return;
    }

    // For remove action, show confirmation
    if (action === 'remove') {
      showConfirmDialog(
        config.strings.remove_confirm_title || 'حذف صفحات',
        '<p>' + escapeHtml((config.strings.remove_confirm_message || 'آیا از حذف {count} صفحه اطمینان دارید؟').replace('{count}', selected.length)) + '</p>',
        function () {
          doBulkAction(action, selected);
        }
      );
      return;
    }

    doBulkAction(action, selected);
  }

  /**
   * Perform the bulk action AJAX call.
   * @param {string} action   - Bulk action type
   * @param {Array}  selected - Array of selected URLs
   */
  function doBulkAction(action, selected) {
    ajaxRequest({
      data: {
        action: 'brz_static_bulk_action',
        bulk_action: action,
        urls: JSON.stringify(selected)
      },
      container: '[data-panel="sitemap"]',
      onSuccess: function (data) {
        showSnackbar(
          (config.strings.bulk_success || 'عملیات روی {count} صفحه انجام شد').replace('{count}', data.affected || selected.length),
          'success'
        );
        // Reload page list
        loadSitemapPages(state.sitemapPages.page, {});
        // Refresh dashboard
        if (state.tabsLoaded.dashboard) {
          loadDashboard();
        }
      }
    });
  }

  /* ==========================================================================
     7. MANUAL PAGES TAB
     ========================================================================== */

  /**
   * Load manual pages list.
   */
  function loadManualPages() {
    ajaxRequest({
      data: {
        action: 'brz_static_get_pages',
        tab: 'manual',
        page: 1,
        per_page: 100
      },
      container: '[data-panel="manual"]',
      onSuccess: function (data) {
        renderManualPageList(data.items || []);
      }
    });
  }

  /**
   * Render the manual pages list.
   * @param {Array} items - Array of manual page objects
   */
  function renderManualPageList(items) {
    var $list = $('#brz-static-manual-page-list');
    if (!$list.length) { return; }

    $list.empty();

    if (!items || items.length === 0) {
      $list.html('<p class="brz-static-empty">' + escapeHtml(config.strings.no_manual_pages || 'صفحه دستی وجود ندارد') + '</p>');
      return;
    }

    $.each(items, function (i, item) {
      var statusIcon = '🟢';
      if (item.page_status === 'pending') { statusIcon = '🟠'; }
      if (item.page_status === 'error') { statusIcon = '🔴'; }

      var html =
        '<div class="brz-static-page-item brz-static-page-item--manual" data-url="' + escapeHtml(item.url) + '">' +
          '<span class="brz-static-page-item__status">' + statusIcon + '</span>' +
          '<span class="brz-static-page-item__url" dir="ltr">' + escapeHtml(item.url) + '</span>' +
          '<span class="brz-static-page-item__type">' + escapeHtml(item.page_type || 'unknown') + '</span>' +
          '<button type="button" class="brz-static-page-item__remove" title="' + escapeHtml(config.strings.remove || 'حذف') + '">&times;</button>' +
        '</div>';

      $list.append(html);
    });
  }

  /**
   * Add a manual page via URL input.
   */
  function addManualPage() {
    var $input = $('#brz-static-manual-url-input');
    var url = $.trim($input.val());

    if (!url) {
      showSnackbar(config.strings.enter_url || 'لطفاً یک URL وارد کنید', 'error');
      return;
    }

    ajaxRequest({
      data: {
        action: 'brz_static_add_manual_page',
        url: url
      },
      container: '[data-panel="manual"]',
      onSuccess: function (data) {
        showSnackbar(config.strings.page_added || 'صفحه با موفقیت اضافه شد', 'success');
        $input.val('');
        // Reload manual pages list
        loadManualPages();
        // Refresh dashboard
        if (state.tabsLoaded.dashboard) {
          loadDashboard();
        }
      }
    });
  }

  /**
   * Remove a manual page with confirmation.
   * @param {string} url - URL to remove
   */
  function removeManualPage(url) {
    showConfirmDialog(
      config.strings.remove_page_title || 'حذف صفحه',
      '<p>' + escapeHtml((config.strings.remove_page_message || 'آیا از حذف این صفحه اطمینان دارید؟')) + '</p>' +
      '<p dir="ltr"><code>' + escapeHtml(url) + '</code></p>',
      function () {
        ajaxRequest({
          data: {
            action: 'brz_static_remove_manual_page',
            url: url
          },
          container: '[data-panel="manual"]',
          onSuccess: function (data) {
            showSnackbar(config.strings.page_removed || 'صفحه حذف شد', 'success');
            // Reload manual pages list
            loadManualPages();
            // Refresh dashboard
            if (state.tabsLoaded.dashboard) {
              loadDashboard();
            }
          }
        });
      }
    );
  }

  /**
   * Initialize manual pages tab event handlers.
   */
  function initManualHandlers() {
    // Load pages by post type
    $(document).on('click', '#brz-static-manual-load-btn', function () {
      loadNonSitemapPages();
    });

    // Post type dropdown change also triggers load
    $(document).on('change', '#brz-static-manual-posttype', function () {
      if ($(this).val()) {
        loadNonSitemapPages();
      }
    });

    // Select all in available list
    $(document).on('change', '#brz-static-manual-select-all', function () {
      var isChecked = $(this).is(':checked');
      $('#brz-static-manual-available-list .brz-static-bulk-item').prop('checked', isChecked);
      updateManualSelectedCount();
    });

    // Individual checkbox in available list
    $(document).on('change', '#brz-static-manual-available-list .brz-static-bulk-item', function () {
      updateManualSelectedCount();
    });

    // Add selected pages button
    $(document).on('click', '#brz-static-manual-add-selected-btn', function () {
      addSelectedManualPages();
    });

    // Add manual page button (URL input)
    $(document).on('click', '#brz-static-manual-add-btn', function () {
      addManualPage();
    });

    // Enter key in URL input
    $(document).on('keypress', '#brz-static-manual-url-input', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        addManualPage();
      }
    });

    // Remove manual page button
    $(document).on('click', '.brz-static-page-item__remove', function () {
      var url = $(this).closest('.brz-static-page-item').data('url');
      if (url) {
        removeManualPage(url);
      }
    });
  }

  /**
   * Load pages of selected post type that are NOT in sitemap.
   */
  function loadNonSitemapPages() {
    var postType = $('#brz-static-manual-posttype').val();
    if (!postType) {
      showSnackbar(config.strings.select_posttype || 'لطفاً یک نوع محتوا انتخاب کنید', 'error');
      return;
    }

    ajaxRequest({
      data: {
        action: 'brz_static_get_non_sitemap_pages',
        post_type: postType
      },
      container: '[data-panel="manual"]',
      onSuccess: function (data) {
        var items = data.items || [];
        var $available = $('#brz-static-manual-available');
        var $list = $('#brz-static-manual-available-list');
        var $info = $('#brz-static-manual-info');

        if (items.length === 0) {
          $available.hide();
          $info.text('همه صفحات این نوع محتوا در سایت‌مپ هستند.').show();
          return;
        }

        $info.text(items.length + ' صفحه یافت شد که در سایت‌مپ نیستند:').show();
        $available.show();
        $list.empty();

        $.each(items, function (i, item) {
          var html =
            '<div class="brz-static-page-item" data-id="' + item.id + '" data-url="' + escapeHtml(item.url) + '">' +
              '<label class="brz-static-page-item__checkbox">' +
                '<input type="checkbox" class="brz-static-bulk-item">' +
              '</label>' +
              '<span class="brz-static-page-item__title">' + escapeHtml(item.title) + '</span>' +
              '<span class="brz-static-page-item__url" dir="ltr">' + escapeHtml(item.url) + '</span>' +
            '</div>';
          $list.append(html);
        });

        updateManualSelectedCount();
      }
    });
  }

  /**
   * Update the selected count display.
   */
  function updateManualSelectedCount() {
    var count = $('#brz-static-manual-available-list .brz-static-bulk-item:checked').length;
    $('#brz-static-manual-selected-count').text(count + ' انتخاب شده');
  }

  /**
   * Add selected pages from the post-type browser.
   */
  function addSelectedManualPages() {
    var postIds = [];
    $('#brz-static-manual-available-list .brz-static-bulk-item:checked').each(function () {
      var id = $(this).closest('.brz-static-page-item').data('id');
      if (id) {
        postIds.push(id);
      }
    });

    if (postIds.length === 0) {
      showSnackbar(config.strings.select_pages || 'لطفاً صفحاتی را انتخاب کنید', 'error');
      return;
    }

    ajaxRequest({
      data: {
        action: 'brz_static_add_manual_pages_bulk',
        post_ids: JSON.stringify(postIds)
      },
      container: '[data-panel="manual"]',
      onSuccess: function (data) {
        showSnackbar((data.added || 0) + ' صفحه اضافه شد', 'success');
        // Reload the available list (items will be removed since they're now added)
        loadNonSitemapPages();
        // Reload manual pages list
        loadManualPages();
        // Refresh dashboard
        if (state.tabsLoaded.dashboard) {
          loadDashboard();
        }
      }
    });
  }

  /* ==========================================================================
     8. SETTINGS TAB
     ========================================================================== */

  /**
   * Load settings from server.
   */
  function loadSettings() {
    ajaxRequest({
      data: { action: 'brz_static_get_settings' },
      container: '[data-panel="settings"]',
      onSuccess: function (data) {
        populateSettings(data);
      }
    });
  }

  /**
   * Populate settings form fields.
   * @param {object} data - Settings data from server
   */
  function populateSettings(data) {
    // Text fields
    $('#brz-static-output-path').val(data.output_path || '');
    $('#brz-static-shared-data-dir').val(data.shared_data_dir || '');
    $('#brz-static-sitemap-url').val(data.sitemap_url || '');

    // Toggle switches
    $('#brz-static-auto-sync').prop('checked', !!data.auto_sync_enabled);
    $('#brz-static-auto-regenerate').prop('checked', !!data.auto_regenerate_enabled);
    $('#brz-static-notify-sync').prop('checked', !!data.notify_on_sync);

    // Status info
    var lastGen = data.last_generated || (config.strings.never || 'هنوز تولید نشده');
    $('#brz-static-last-generated').text(lastGen);

    var statusLabels = {
      idle: config.strings.status_idle || 'بدون فعالیت',
      success: config.strings.status_success || 'موفق',
      error: config.strings.status_error || 'خطا',
      running: config.strings.status_running || 'در حال اجرا'
    };
    var statusLabel = statusLabels[data.generation_status] || data.generation_status || '';
    $('#brz-static-generation-status')
      .text(statusLabel)
      .removeClass()
      .addClass('brz-static-status brz-static-status--' + escapeHtml(data.generation_status || 'idle'));
  }

  /**
   * Save settings to server.
   */
  function saveSettings() {
    var settingsData = {
      action: 'brz_static_save_settings',
      output_path: $.trim($('#brz-static-output-path').val()),
      shared_data_dir: $.trim($('#brz-static-shared-data-dir').val()),
      sitemap_url: $.trim($('#brz-static-sitemap-url').val()),
      auto_sync: $('#brz-static-auto-sync').is(':checked') ? '1' : '0',
      auto_regenerate: $('#brz-static-auto-regenerate').is(':checked') ? '1' : '0',
      notify_on_sync: $('#brz-static-notify-sync').is(':checked') ? '1' : '0'
    };

    ajaxRequest({
      data: settingsData,
      container: '[data-panel="settings"]',
      onSuccess: function (data) {
        showSnackbar(config.strings.save_success || 'تنظیمات ذخیره شد', 'success');
      }
    });
  }

  /**
   * Initialize settings tab event handlers.
   */
  function initSettingsHandlers() {
    // Save settings button
    $(document).on('click', '#brz-static-save-settings-btn', function () {
      saveSettings();
    });

    // Toggle change handlers for immediate visual feedback
    $(document).on('change', '#brz-static-auto-sync, #brz-static-auto-regenerate, #brz-static-notify-sync', function () {
      var $toggle = $(this);
      var $label = $toggle.closest('.brz-static-toggle');
      if ($toggle.is(':checked')) {
        $label.addClass('is-active');
      } else {
        $label.removeClass('is-active');
      }
    });

    // Regenerate button in settings
    $(document).on('click', '#brz-static-regenerate-btn', function () {
      regeneratePending();
    });
  }

  /* ==========================================================================
     9. INITIALIZATION
     ========================================================================== */

  /**
   * Initialize all components.
   */
  function init() {
    initTabs();
    initDashboardActions();
    initSitemapHandlers();
    initBulkActions();
    initManualHandlers();
    initSettingsHandlers();

    // Fade in the UI container
    $('#brz-static-tabs').css('opacity', '1');
  }

  // Start the application
  init();

});
