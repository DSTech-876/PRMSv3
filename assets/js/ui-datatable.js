/**
 * DGC PIAMS — Client-side table enhancement.
 *
 * Enhances any `<table data-ui-table>` with search, sorting, pagination,
 * column visibility (persisted per table) and export actions. The table
 * markup is rendered normally by PHP, so without JavaScript every row is
 * still present and readable — this layer only adds navigation affordances.
 *
 * Tables marked `data-ui-virtual="1"` render only the rows near the
 * viewport, keeping very large result sets responsive.
 */
(function () {
  'use strict';

  var registry = {};

  function text(cell) {
    return (cell.textContent || '').trim();
  }

  /** Numeric-aware comparison so amounts and dates sort correctly. */
  function compare(a, b) {
    var aTrimmed = String(a).trim();
    var bTrimmed = String(b).trim();
    var na = parseFloat(aTrimmed.replace(/[^0-9.\-]/g, ''));
    var nb = parseFloat(bTrimmed.replace(/[^0-9.\-]/g, ''));
    var aNumeric = !isNaN(na) && /^\d/.test(aTrimmed) && /\d$/.test(aTrimmed);
    var bNumeric = !isNaN(nb) && /^\d/.test(bTrimmed) && /\d$/.test(bTrimmed);
    if (aNumeric && bNumeric && na !== nb) { return na - nb; }

    var da = Date.parse(a);
    var db = Date.parse(b);
    if (!isNaN(da) && !isNaN(db) && da !== db) { return da - db; }

    return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
  }

  function Enhanced(table) {
    this.table = table;
    this.id = table.id;
    this.tbody = table.tBodies[0];
    this.headers = Array.prototype.slice.call(table.tHead ? table.tHead.rows[0].cells : []);
    this.allRows = Array.prototype.slice.call(this.tbody ? this.tbody.rows : []);
    this.rowText = this.allRows.map(function (row) {
      return (row.textContent || '').toLowerCase();
    });
    this.filtered = this.allRows.slice();
    this.query = '';
    this.sortIndex = -1;
    this.sortDir = 1;
    this.page = 1;
    this.perPage = parseInt(table.getAttribute('data-ui-per-page'), 10) || 15;
    this.paginate = table.getAttribute('data-ui-paginate') !== '0';
    this.sortable = table.getAttribute('data-ui-sortable') !== '0';
    this.virtual = table.getAttribute('data-ui-virtual') === '1';
    this.storageKey = 'piams.cols.' + (window.location.pathname + '#' + this.id);

    // An "empty state" row spans the full table and must never be treated
    // as data (it would otherwise be filtered away or paginated).
    this.emptyRow = this.allRows.length === 1
      && this.allRows[0].cells.length === 1
      && this.allRows[0].cells[0].hasAttribute('colspan')
      ? this.allRows[0]
      : null;
    if (this.emptyRow) {
      this.allRows = [];
      this.rowText = [];
      this.filtered = [];
    }

    this.buildControls();
    this.restoreColumns();
    this.render();
  }

  Enhanced.prototype.buildControls = function () {
    var self = this;

    if (this.sortable) {
      this.headers.forEach(function (header, index) {
        if (header.hasAttribute('data-ui-no-sort')) { return; }
        header.setAttribute('data-sortable', '1');
        header.setAttribute('tabindex', '0');
        header.setAttribute('role', 'columnheader');

        var activate = function () { self.sortBy(index); };
        header.addEventListener('click', activate);
        header.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            activate();
          }
        });
      });
    }

    var search = document.querySelector('[data-ui-table-search="' + this.id + '"]');
    if (search) {
      var timer = null;
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          self.query = search.value.trim().toLowerCase();
          self.page = 1;
          self.render();
        }, 150);
      });
    }

    var columnMenu = document.querySelector('[data-ui-column-menu="' + this.id + '"]');
    if (columnMenu) {
      this.headers.forEach(function (header, index) {
        var label = text(header) || ('Column ' + (index + 1));
        var item = document.createElement('label');
        item.className = 'ui-menu__item';
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = true;
        box.setAttribute('data-ui-column-index', String(index));
        box.addEventListener('change', function () {
          self.setColumnVisible(index, box.checked);
          self.persistColumns();
        });
        item.appendChild(box);
        item.appendChild(document.createTextNode(label));
        columnMenu.appendChild(item);
      });
    }

    var exporters = document.querySelectorAll('[data-ui-export-for="' + this.id + '"]');
    Array.prototype.forEach.call(exporters, function (button) {
      button.addEventListener('click', function () {
        self.exportAs(button.getAttribute('data-ui-export'));
      });
    });

    this.paginationEl = document.querySelector('[data-ui-pagination-for="' + this.id + '"]');

    if (this.virtual) {
      this.scroller = this.table.closest('.ui-table-wrap');
      if (this.scroller) {
        this.scroller.addEventListener('scroll', function () {
          if (self.virtualFrame) { return; }
          self.virtualFrame = window.requestAnimationFrame(function () {
            self.virtualFrame = null;
            self.renderRows();
          });
        });
      }
    }
  };

  Enhanced.prototype.setColumnVisible = function (index, visible) {
    var display = visible ? '' : 'none';
    if (this.headers[index]) { this.headers[index].style.display = display; }
    this.allRows.forEach(function (row) {
      if (row.cells[index]) { row.cells[index].style.display = display; }
    });
  };

  Enhanced.prototype.persistColumns = function () {
    var hidden = [];
    this.headers.forEach(function (header, index) {
      if (header.style.display === 'none') { hidden.push(index); }
    });
    try { localStorage.setItem(this.storageKey, JSON.stringify(hidden)); } catch (e) { /* ignore */ }
  };

  Enhanced.prototype.restoreColumns = function () {
    var hidden;
    try { hidden = JSON.parse(localStorage.getItem(this.storageKey) || '[]'); } catch (e) { hidden = []; }
    if (!Array.isArray(hidden)) { return; }
    var self = this;
    hidden.forEach(function (index) {
      self.setColumnVisible(index, false);
      var box = document.querySelector(
        '[data-ui-column-menu="' + self.id + '"] [data-ui-column-index="' + index + '"]'
      );
      if (box) { box.checked = false; }
    });
  };

  Enhanced.prototype.sortBy = function (index) {
    if (this.sortIndex === index) {
      this.sortDir = -this.sortDir;
    } else {
      this.sortIndex = index;
      this.sortDir = 1;
    }
    this.headers.forEach(function (header, i) {
      if (i === index) { return; }
      header.removeAttribute('aria-sort');
    });
    if (this.headers[index]) {
      this.headers[index].setAttribute('aria-sort', this.sortDir === 1 ? 'ascending' : 'descending');
    }
    this.page = 1;
    this.render();
  };

  Enhanced.prototype.applyFilter = function () {
    if (!this.query) {
      this.filtered = this.allRows.slice();
      return;
    }
    var query = this.query;
    var rowText = this.rowText;
    this.filtered = this.allRows.filter(function (row, index) {
      return rowText[index].indexOf(query) !== -1;
    });
  };

  Enhanced.prototype.applySort = function () {
    if (this.sortIndex < 0) { return; }
    var index = this.sortIndex;
    var dir = this.sortDir;
    this.filtered.sort(function (a, b) {
      var av = a.cells[index] ? text(a.cells[index]) : '';
      var bv = b.cells[index] ? text(b.cells[index]) : '';
      return compare(av, bv) * dir;
    });
  };

  Enhanced.prototype.visibleSlice = function () {
    if (!this.paginate) { return this.filtered; }
    var start = (this.page - 1) * this.perPage;
    return this.filtered.slice(start, start + this.perPage);
  };

  Enhanced.prototype.renderRows = function () {
    var rows = this.visibleSlice();
    var fragment = document.createDocumentFragment();

    if (!rows.length) {
      if (this.emptyRow) {
        fragment.appendChild(this.emptyRow);
      } else {
        var tr = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = this.headers.length || 1;
        td.className = 'ui-empty';
        td.textContent = this.query ? 'No rows match your search.' : 'No records found.';
        tr.appendChild(td);
        fragment.appendChild(tr);
      }
    } else if (this.virtual && this.scroller && rows.length > 60) {
      // Render a window of rows around the scroll position, padded with
      // spacer rows so the scrollbar keeps its natural size.
      var rowHeight = this.measuredRowHeight || 34;
      var viewport = this.scroller.clientHeight || 400;
      var first = Math.max(0, Math.floor(this.scroller.scrollTop / rowHeight) - 10);
      var count = Math.ceil(viewport / rowHeight) + 20;
      var last = Math.min(rows.length, first + count);

      if (first > 0) { fragment.appendChild(this.spacer(first * rowHeight)); }
      for (var i = first; i < last; i++) { fragment.appendChild(rows[i]); }
      if (last < rows.length) { fragment.appendChild(this.spacer((rows.length - last) * rowHeight)); }
    } else {
      rows.forEach(function (row) { fragment.appendChild(row); });
    }

    this.tbody.textContent = '';
    this.tbody.appendChild(fragment);

    if (!this.measuredRowHeight && this.tbody.rows.length) {
      var height = this.tbody.rows[0].getBoundingClientRect().height;
      if (height > 0) { this.measuredRowHeight = height; }
    }
  };

  Enhanced.prototype.spacer = function (height) {
    var tr = document.createElement('tr');
    tr.setAttribute('aria-hidden', 'true');
    var td = document.createElement('td');
    td.colSpan = this.headers.length || 1;
    td.style.height = height + 'px';
    td.style.padding = '0';
    td.style.border = '0';
    tr.appendChild(td);
    return tr;
  };

  Enhanced.prototype.renderPagination = function () {
    if (!this.paginationEl) { return; }
    var total = this.filtered.length;
    var pages = Math.max(1, Math.ceil(total / this.perPage));

    if (!this.paginate || (pages <= 1 && !this.query)) {
      this.paginationEl.hidden = total === 0;
      if (total === 0) { return; }
    }
    this.paginationEl.hidden = false;
    this.page = Math.min(this.page, pages);

    var start = total === 0 ? 0 : (this.page - 1) * this.perPage + 1;
    var end = Math.min(total, this.page * this.perPage);

    var self = this;
    this.paginationEl.textContent = '';

    var info = document.createElement('span');
    info.textContent = total === 0
      ? 'No rows'
      : 'Showing ' + start + '–' + end + ' of ' + total;
    this.paginationEl.appendChild(info);

    if (!this.paginate || pages <= 1) { return; }

    var nav = document.createElement('div');
    nav.className = 'ui-pagination__pages';
    nav.setAttribute('role', 'navigation');
    nav.setAttribute('aria-label', 'Table pagination');

    function button(label, targetPage, options) {
      options = options || {};
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ui-page-btn';
      btn.innerHTML = label;
      if (options.current) { btn.setAttribute('aria-current', 'page'); }
      if (options.disabled) { btn.disabled = true; }
      if (options.label) { btn.setAttribute('aria-label', options.label); }
      btn.addEventListener('click', function () {
        self.page = targetPage;
        self.render();
        var wrap = self.table.closest('.ui-table-wrap');
        if (wrap) { wrap.scrollTop = 0; }
      });
      return btn;
    }

    nav.appendChild(button('<i class="bi bi-chevron-left"></i>', this.page - 1, {
      disabled: this.page === 1, label: 'Previous page'
    }));

    // Windowed page numbers keep the control compact on long result sets.
    var from = Math.max(1, this.page - 2);
    var to = Math.min(pages, from + 4);
    from = Math.max(1, to - 4);

    if (from > 1) {
      nav.appendChild(button('1', 1, {}));
      if (from > 2) {
        var lead = document.createElement('span');
        lead.textContent = '…';
        nav.appendChild(lead);
      }
    }
    for (var p = from; p <= to; p++) {
      nav.appendChild(button(String(p), p, { current: p === this.page, label: 'Page ' + p }));
    }
    if (to < pages) {
      if (to < pages - 1) {
        var trail = document.createElement('span');
        trail.textContent = '…';
        nav.appendChild(trail);
      }
      nav.appendChild(button(String(pages), pages, {}));
    }

    nav.appendChild(button('<i class="bi bi-chevron-right"></i>', this.page + 1, {
      disabled: this.page === pages, label: 'Next page'
    }));

    this.paginationEl.appendChild(nav);
  };

  Enhanced.prototype.render = function () {
    this.applyFilter();
    this.applySort();
    this.renderPagination();
    this.renderRows();
  };

  Enhanced.prototype.exportRows = function () {
    var self = this;
    var visibleIndexes = this.headers
      .map(function (header, index) { return header.style.display === 'none' || header.hasAttribute('data-ui-noexport') ? -1 : index; })
      .filter(function (index) { return index >= 0; });

    var head = visibleIndexes.map(function (index) { return text(self.headers[index]); });
    var body = this.filtered.map(function (row) {
      return visibleIndexes.map(function (index) {
        return row.cells[index] ? text(row.cells[index]) : '';
      });
    });
    return { head: head, body: body };
  };

  Enhanced.prototype.fileName = function (extension) {
    var title = document.querySelector('.ui-page-header__title, main h1, main h2');
    var base = ((title && title.textContent) || document.title || 'export')
      .trim()
      .replace(/[^a-z0-9]+/gi, '_')
      .replace(/^_+|_+$/g, '')
      .toLowerCase();
    return (base || 'export') + '.' + extension;
  };

  Enhanced.prototype.download = function (blob, name) {
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
  };

  Enhanced.prototype.exportAs = function (format) {
    var data = this.exportRows();

    if (format === 'csv') {
      var escapeCell = function (value) { return '"' + String(value).replace(/"/g, '""') + '"'; };
      var csv = [data.head].concat(data.body)
        .map(function (row) { return row.map(escapeCell).join(','); })
        .join('\r\n');
      // UTF-8 BOM keeps accented characters intact in Excel.
      this.download(new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' }), this.fileName('csv'));
      return;
    }

    var escapeHtml = function (value) {
      return String(value).replace(/[&<>]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
      });
    };
    var html = '<table><thead><tr>'
      + data.head.map(function (cell) { return '<th>' + escapeHtml(cell) + '</th>'; }).join('')
      + '</tr></thead><tbody>'
      + data.body.map(function (row) {
        return '<tr>' + row.map(function (cell) { return '<td>' + escapeHtml(cell) + '</td>'; }).join('') + '</tr>';
      }).join('')
      + '</tbody></table>';

    if (format === 'excel') {
      this.download(
        new Blob(['<html><head><meta charset="UTF-8"></head><body>' + html + '</body></html>'],
          { type: 'application/vnd.ms-excel' }),
        this.fileName('xls')
      );
      return;
    }

    if (format === 'print') {
      var win = window.open('', '_blank');
      if (!win) { return; }
      win.document.write(
        '<html><head><title>' + escapeHtml(document.title) + '</title>'
        + '<style>body{font-family:Arial,sans-serif;font-size:12px}'
        + 'table{width:100%;border-collapse:collapse}'
        + 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
        + 'th{background:#eee}</style></head><body>' + html + '</body></html>'
      );
      win.document.close();
      win.focus();
      win.print();
    }
  };

  function enhance(root) {
    var tables = (root || document).querySelectorAll('table[data-ui-table]:not([data-ui-enhanced])');
    Array.prototype.forEach.call(tables, function (table) {
      if (!table.id) {
        table.id = 'ui-table-' + Math.random().toString(36).slice(2, 9);
      }
      table.setAttribute('data-ui-enhanced', '1');
      registry[table.id] = new Enhanced(table);
    });
  }

  window.PIAMSTable = {
    enhance: enhance,
    get: function (id) { return registry[id]; }
  };

  // Re-measure virtualised tables once their panel becomes visible.
  window.addEventListener('ui:panelshown', function (event) {
    var tables = event.detail.panel.querySelectorAll('table[data-ui-table]');
    Array.prototype.forEach.call(tables, function (table) {
      var instance = registry[table.id];
      if (instance) { instance.render(); }
    });
  });

  // Enhance tables delivered by lazily loaded fragments.
  document.addEventListener('ui:lazyloaded', function (event) {
    enhance(event.detail.container);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { enhance(document); });
  } else {
    enhance(document);
  }
})();
