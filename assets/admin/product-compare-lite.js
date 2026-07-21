/* هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید. */
(function() {
  // Fix for duplicate metaboxes:
  // If the Tab panel exists (even if hidden), we should prioritize it and remove the Fallback metabox
  // to prevent duplicate inputs which break saving.
  var tabPanel = document.getElementById('brz_compare_table_panel');
  var fallbackMetabox = document.getElementById('brz-compare-table-fallback');

  if (tabPanel && fallbackMetabox) {
    // Remove the entire fallback metabox container
    fallbackMetabox.remove();
  }

  var box = document.querySelector('.brz-compare-box');
  if (!box) { return; }

  var table = box.querySelector('#brz-compare-table');
  if (!table) { return; }

  var thead = table.querySelector('thead');
  var tbody = table.querySelector('tbody');
  var maxColumns = parseInt(box.dataset.maxCols || '6', 10);
  var defaultColumns = 2;

  if (!thead) {
    thead = document.createElement('thead');
    table.appendChild(thead);
  }
  if (!tbody) {
    tbody = document.createElement('tbody');
    table.appendChild(tbody);
  }

  function headerRow() {
    return thead.querySelector('tr');
  }

  function headerCells() {
    var row = headerRow();
    // Exclude the first cell which is for row actions
    return row ? Array.prototype.slice.call(row.querySelectorAll('th.brz-compare-th')) : [];
  }

  function dataRows() {
    return tbody.querySelectorAll('tr');
  }

  function columnCount() {
    return headerCells().length;
  }

  function ensureHeaderRow() {
    var row = headerRow();
    if (row) { return; }
    row = document.createElement('tr');
    row.className = 'brz-compare-row--header';
    row.setAttribute('data-row', 'header');

    var actionTh = document.createElement('th');
    actionTh.className = 'brz-compare-actions-head';
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'brz-compare-btn brz-compare-btn--success';
    addBtn.textContent = '+';
    addBtn.setAttribute('data-add-row', 'header');
    addBtn.setAttribute('aria-label', 'افزودن ردیف');
    actionTh.appendChild(addBtn);
    row.appendChild(actionTh);

    thead.appendChild(row);
  }

  function buildHeaderCell(value) {
    var th = document.createElement('th');
    th.className = 'brz-compare-th';

    var content = document.createElement('div');
    content.className = 'brz-compare-th-content';

    var actions = document.createElement('div');
    actions.className = 'brz-compare-col-actions';

    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'brz-compare-mini-btn';
    addBtn.textContent = '+';
    addBtn.setAttribute('data-add-col', '0');
    addBtn.setAttribute('aria-label', 'افزودن ستون');

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'brz-compare-mini-btn brz-compare-danger';
    removeBtn.innerHTML = '&times;';
    removeBtn.setAttribute('data-remove-col', '0');
    removeBtn.setAttribute('aria-label', 'حذف ستون');

    actions.appendChild(addBtn);
    actions.appendChild(removeBtn);
    content.appendChild(actions);

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'brz_compare_columns[]';
    input.placeholder = 'هدر';
    input.value = value || '';
    content.appendChild(input);

    th.appendChild(content);
    return th;
  }

  var siteProducts = [];
  try {
    siteProducts = JSON.parse(box.dataset.siteProducts || '[]');
  } catch (e) {}

  function buildDataCell(colIndex, value, selectedVal, customUrlVal) {
    var td = document.createElement('td');
    td.className = 'brz-compare-td';
    if (colIndex === 0) {
      td.classList.add('brz-compare-td--product');
    }

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'brz_compare_rows[0][' + colIndex + ']';
    input.value = value || '';
    td.appendChild(input);

    if (colIndex === 0) {
      var pickerWrap = document.createElement('div');
      pickerWrap.className = 'brz-compare-product-picker-wrap';

      var select = document.createElement('select');
      select.className = 'brz-compare-product-select';
      select.name = 'brz_compare_links[0]';

      var optDefault = document.createElement('option');
      optDefault.value = '';
      optDefault.textContent = '-- بدون لینک (متن ساده) --';
      select.appendChild(optDefault);

      var optCustom = document.createElement('option');
      optCustom.value = 'custom';
      optCustom.textContent = '🔗 لینک سفارشی...';
      if (selectedVal === 'custom') { optCustom.selected = true; }
      select.appendChild(optCustom);

      siteProducts.forEach(function(p) {
        var opt = document.createElement('option');
        opt.value = String(p.id);
        opt.textContent = '📦 ' + p.title + ' (ID: ' + p.id + ')' + (p.status_tag || '');
        if (String(selectedVal) === String(p.id)) { opt.selected = true; }
        select.appendChild(opt);
      });

      pickerWrap.appendChild(select);

      var customInput = document.createElement('input');
      customInput.type = 'text';
      customInput.className = 'brz-compare-custom-link-input' + (selectedVal === 'custom' ? '' : ' brz-hidden');
      customInput.name = 'brz_compare_custom_links[0]';
      customInput.placeholder = 'https://...';
      customInput.value = customUrlVal || '';
      pickerWrap.appendChild(customInput);

      td.appendChild(pickerWrap);
    }

    return td;
  }

  function buildRow(values) {
    ensureHeaderRow();
    var row = document.createElement('tr');
    row.className = 'brz-compare-row';

    var actionTd = document.createElement('td');
    actionTd.className = 'brz-compare-row-actions-cell';
    var actions = document.createElement('div');
    actions.className = 'brz-compare-row-actions';

    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'brz-compare-btn brz-compare-btn--success';
    addBtn.textContent = '+';
    addBtn.setAttribute('data-add-row', '0');
    addBtn.setAttribute('aria-label', 'افزودن ردیف');

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'brz-compare-btn brz-compare-btn--danger';
    removeBtn.innerHTML = '&minus;';
    removeBtn.setAttribute('data-remove-row', '0');
    removeBtn.setAttribute('aria-label', 'حذف ردیف');

    actions.appendChild(addBtn);
    actions.appendChild(removeBtn);
    actionTd.appendChild(actions);
    row.appendChild(actionTd);

    var cols = columnCount() || defaultColumns;
    if (columnCount() === 0) {
      var hRow = headerRow();
      for (var x = 0; x < defaultColumns; x++) {
        hRow.appendChild(buildHeaderCell(''));
      }
      cols = columnCount();
    }

    for (var i = 0; i < cols; i++) {
      var cell = buildDataCell(i, values && values[i] ? values[i] : '');
      row.appendChild(cell);
    }

    return row;
  }

  function addRow(afterKey, values) {
    ensureHeaderRow();
    var row = buildRow(values || null);
    var rows = Array.prototype.slice.call(dataRows());

    if (afterKey === 'header') {
      if (rows.length > 0) {
        tbody.insertBefore(row, rows[0]);
      } else {
        tbody.appendChild(row);
      }
    } else {
      var index = parseInt(afterKey, 10);
      if (isNaN(index) || index < 0 || index >= rows.length) {
        tbody.appendChild(row);
      } else {
        var anchor = rows[index];
        if (anchor && anchor.nextSibling) {
          tbody.insertBefore(row, anchor.nextSibling);
        } else {
          tbody.appendChild(row);
        }
      }
    }
    renumber();
  }

  function removeRow(index) {
    var rows = Array.prototype.slice.call(dataRows());
    if (rows.length <= 1) { return; }
    var targetIndex = parseInt(index, 10);
    if (isNaN(targetIndex) || targetIndex < 0 || targetIndex >= rows.length) {
      return;
    }
    rows[targetIndex].remove();
    renumber();
  }

  function addColumn(afterIndex) {
    ensureHeaderRow();
    var current = columnCount();
    if (current >= maxColumns) { return; }
    var idx = parseInt(afterIndex, 10);
    if (isNaN(idx) || idx < 0 || idx >= current) {
      idx = current - 1;
    }

    var headerCell = buildHeaderCell('');
    var headers = headerCells();
    var target = headers[idx];
    var hRow = headerRow();
    
    if (target && target.nextSibling) {
      hRow.insertBefore(headerCell, target.nextSibling);
    } else {
      hRow.appendChild(headerCell);
    }

    Array.prototype.slice.call(dataRows()).forEach(function(row) {
      var cells = Array.prototype.slice.call(row.querySelectorAll('td.brz-compare-td'));
      var dataCell = buildDataCell(cells.length, '');
      var anchor = cells[idx];
      if (anchor && anchor.nextSibling) {
        row.insertBefore(dataCell, anchor.nextSibling);
      } else {
        row.appendChild(dataCell);
      }
    });
    renumber();
  }

  function removeColumn(index) {
    ensureHeaderRow();
    var current = columnCount();
    if (current <= 1) { return; }
    var idx = parseInt(index, 10);
    if (isNaN(idx) || idx < 0 || idx >= current) {
      idx = current - 1;
    }

    var headers = headerCells();
    if (headers[idx]) { headers[idx].remove(); }

    Array.prototype.slice.call(dataRows()).forEach(function(row) {
      var cells = row.querySelectorAll('td.brz-compare-td');
      if (cells[idx]) {
        cells[idx].remove();
      }
    });
    renumber();
  }

  function ensureAtLeastOneRow() {
    if (dataRows().length === 0) {
      addRow('header');
    }
  }

  function renumber() {
    ensureHeaderRow();
    var hRow = headerRow();
    var headerAdd = hRow.querySelector('[data-add-row]');
    if (headerAdd) {
      headerAdd.setAttribute('data-add-row', 'header');
    }
    
    var headers = headerCells();
    Array.prototype.slice.call(headers).forEach(function(cell, idx) {
      cell.setAttribute('data-col', idx);
      var addBtn = cell.querySelector('[data-add-col]');
      if (addBtn) {
        addBtn.setAttribute('data-add-col', idx);
        addBtn.setAttribute('aria-label', 'افزودن ستون بعد از ستون ' + (idx + 1));
        addBtn.disabled = headers.length >= maxColumns;
      }
      var removeBtn = cell.querySelector('[data-remove-col]');
      if (removeBtn) {
        removeBtn.setAttribute('data-remove-col', idx);
        removeBtn.disabled = headers.length <= 1;
        removeBtn.setAttribute('aria-label', 'حذف ستون ' + (idx + 1));
      }
      var input = cell.querySelector('input');
      if (input) {
        input.name = 'brz_compare_columns[]';
        input.placeholder = 'هدر ' + (idx + 1);
      }
    });

    var rows = Array.prototype.slice.call(dataRows());
    Array.prototype.forEach.call(rows, function(row, rIndex) {
      row.setAttribute('data-row', rIndex);
      var addBtn = row.querySelector('[data-add-row]');
      if (addBtn) {
        addBtn.setAttribute('data-add-row', rIndex);
      }
      var removeBtn = row.querySelector('[data-remove-row]');
      if (removeBtn) {
        removeBtn.setAttribute('data-remove-row', rIndex);
        removeBtn.disabled = rows.length <= 1;
      }

      var select = row.querySelector('.brz-compare-product-select');
      if (select) {
        select.name = 'brz_compare_links[' + rIndex + ']';
      }
      var customInput = row.querySelector('.brz-compare-custom-link-input');
      if (customInput) {
        customInput.name = 'brz_compare_custom_links[' + rIndex + ']';
      }

      var cells = row.querySelectorAll('td.brz-compare-td');
      for (var c = 0; c < cells.length; c++) {
        var input = cells[c].querySelector('input[type="text"]:not(.brz-compare-custom-link-input)');
        if (input) {
          input.name = 'brz_compare_rows[' + rIndex + '][' + c + ']';
        }
      }

      // Ensure row has correct number of cells
      while (cells.length < columnCount()) {
        var filler = buildDataCell(cells.length, '');
        row.appendChild(filler);
        cells = row.querySelectorAll('td.brz-compare-td');
      }
      while (cells.length > columnCount() && cells.length > 0) {
        var last = cells[cells.length - 1];
        if (last) { last.remove(); }
        cells = row.querySelectorAll('td.brz-compare-td');
      }
    });
  }

  box.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('brz-compare-product-select')) {
      var select = e.target;
      var customInput = select.parentNode ? select.parentNode.querySelector('.brz-compare-custom-link-input') : null;
      if (customInput) {
        if (select.value === 'custom') {
          customInput.classList.remove('brz-hidden');
          customInput.focus();
        } else {
          customInput.classList.add('brz-hidden');
        }
      }
    }
  });

  table.addEventListener('click', function(e) {
    var addColBtn = e.target.closest('[data-add-col]');
    if (addColBtn) {
      e.preventDefault();
      addColumn(addColBtn.getAttribute('data-add-col'));
      return;
    }

    var removeColBtn = e.target.closest('[data-remove-col]');
    if (removeColBtn) {
      e.preventDefault();
      removeColumn(removeColBtn.getAttribute('data-remove-col'));
      return;
    }

    var addRowBtn = e.target.closest('[data-add-row]');
    if (addRowBtn) {
      e.preventDefault();
      addRow(addRowBtn.getAttribute('data-add-row'));
      return;
    }

    var removeRowBtn = e.target.closest('[data-remove-row]');
    if (removeRowBtn) {
      e.preventDefault();
      removeRow(removeRowBtn.getAttribute('data-remove-row'));
    }
  });

  ensureHeaderRow();
  if (columnCount() === 0) {
    var hRow = headerRow();
    for (var i = 0; i < defaultColumns; i++) {
      hRow.appendChild(buildHeaderCell(''));
    }
  }
  ensureAtLeastOneRow();
  renumber();

  table.classList.add('brz-compare-table--hydrated');
})();
