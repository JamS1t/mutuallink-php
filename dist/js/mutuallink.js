/* ==========================================================
   MutualLink front-end behavior (jQuery + Fetch API).
   No inline scripts anywhere: pages pass data through data-* attributes,
   and all server data is rendered with textContent (never innerHTML)
   to prevent DOM-based XSS.
   ========================================================== */
(function ($) {
  'use strict';

  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var CSRF = csrfMeta ? csrfMeta.getAttribute('content') : '';

  function peso(n) {
    return '₱ ' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function el(tag, text, className) {
    var node = document.createElement(tag);
    if (text !== undefined && text !== null) node.textContent = String(text);
    if (className) node.className = className;
    return node;
  }

  /**
   * Fetch wrapper for the JSON endpoints: sends the CSRF header and the
   * session cookie (same origin), checks response.ok, parses the envelope.
   */
  async function apiFetch(url, options) {
    options = options || {};
    var init = {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF }
    };
    if (options.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }
    var response = await fetch(url, init);
    var payload;
    try { payload = await response.json(); } catch (e) { payload = { success: false, errors: ['Unexpected server response.'] }; }
    if (!response.ok) {
      if (response.status === 401) { window.location.href = 'index.php'; }
      var err = new Error((payload.errors || ['Request failed.']).join(' '));
      err.payload = payload;
      throw err;
    }
    return payload.data;
  }

  /* ---------- Toastr flash messages ---------- */
  toastr.options = { closeButton: true, progressBar: true, newestOnTop: true, positionClass: 'toast-top-right', timeOut: 5000, preventDuplicates: true };
  var flashEl = document.getElementById('ml-flash');
  if (flashEl) {
    try {
      JSON.parse(flashEl.getAttribute('data-messages') || '[]').forEach(function (m) {
        var type = { success: 'success', error: 'error', warning: 'warning', info: 'info' }[m.type] || 'info';
        toastr[type](m.message);
      });
    } catch (e) { /* ignore malformed flash data */ }
  }

  /* ---------- Show / hide password ---------- */
  $(document).on('click', '[data-toggle-password]', function () {
    var input = document.querySelector(this.getAttribute('data-toggle-password'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    $(this).find('i').toggleClass('fa-eye fa-eye-slash');
  });

  /* ---------- Loading state on submit (prevents double posting) ---------- */
  $(document).on('submit', 'form.ml-form', function () {
    // Forms needing confirmation only show the spinner once the user has confirmed.
    if (this.hasAttribute('data-confirm') && this.dataset.confirmed !== '1') return;
    var $btn = $(this).find('button[type="submit"]').not('[formnovalidate]');
    if ($btn.prop('disabled')) return false;
    $btn.prop('disabled', true).each(function () {
      this.dataset.label = this.innerHTML;
      $(this).prepend('<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>');
    });
  });

  /* ---------- Confirmation modal for destructive actions ---------- */
  var $modal = null;
  var pendingForm = null;
  function buildModal() {
    $modal = $(
      '<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="ml-confirm-title" aria-hidden="true">' +
      '<div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">' +
      '<div class="modal-header"><h5 class="modal-title" id="ml-confirm-title">Please confirm</h5>' +
      '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>' +
      '<div class="modal-body"><p class="ml-confirm-text mb-0"></p>' +
      '<div class="ml-confirm-reason mt-3" hidden><label for="ml-reason" class="small font-weight-bold">Reason (required)</label>' +
      '<textarea id="ml-reason" class="form-control" rows="2" maxlength="255"></textarea></div></div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>' +
      '<button type="button" class="btn btn-danger ml-confirm-ok">Confirm</button></div>' +
      '</div></div></div>'
    ).appendTo('body');

    $modal.on('click', '.ml-confirm-ok', function () {
      if (!pendingForm) return;
      var needsReason = pendingForm.hasAttribute('data-confirm-reason');
      var reason = $modal.find('#ml-reason').val().trim();
      if (needsReason && reason.length < 3) {
        $modal.find('#ml-reason').addClass('is-invalid').trigger('focus');
        return;
      }
      if (needsReason) {
        var field = pendingForm.querySelector('input[name="reason"]');
        if (!field) { field = el('input'); field.type = 'hidden'; field.name = 'reason'; pendingForm.appendChild(field); }
        field.value = reason;
      }
      pendingForm.dataset.confirmed = '1';
      $modal.modal('hide');
      if (typeof pendingForm.requestSubmit === 'function') { pendingForm.requestSubmit(); } else { pendingForm.submit(); }
    });
  }

  $(document).on('submit', 'form[data-confirm]', function (e) {
    if (this.dataset.confirmed === '1') { this.dataset.confirmed = ''; return true; }
    e.preventDefault();
    e.stopImmediatePropagation();
    if (!$modal) buildModal();
    pendingForm = this;
    $modal.find('.ml-confirm-text').text(this.getAttribute('data-confirm'));
    var needsReason = this.hasAttribute('data-confirm-reason');
    $modal.find('.ml-confirm-reason').prop('hidden', !needsReason);
    $modal.find('#ml-reason').val('').removeClass('is-invalid');
    $modal.find('.ml-confirm-ok').text(this.getAttribute('data-confirm-button') || 'Confirm');
    $modal.modal('show');
    return false;
  });

  /* ---------- DataTables ---------- */
  $('table.js-datatable').each(function () {
    var $t = $(this);
    var exportable = $t.data('export') === true || $t.data('export') === 'true';
    var order = $t.data('order') || [];
    var dt = $t.DataTable({
      responsive: true,
      autoWidth: false,
      pageLength: Number($t.data('page-length')) || 25,
      order: order,
      language: { search: '', searchPlaceholder: 'Search…', emptyTable: $t.data('empty') || 'No records yet.' },
      columnDefs: [{ targets: 'no-sort', orderable: false }],
      dom: exportable ? "<'row'<'col-md-6'B><'col-md-6'f>>rt<'row'<'col-md-5'i><'col-md-7'p>>" : "<'row'<'col-md-6'l><'col-md-6'f>>rt<'row'<'col-md-5'i><'col-md-7'p>>",
      buttons: exportable ? [
        { extend: 'copy', className: 'btn-sm btn-light' },
        { extend: 'csv', className: 'btn-sm btn-light' },
        { extend: 'excel', className: 'btn-sm btn-light' },
        { extend: 'print', className: 'btn-sm btn-light', title: $t.data('title') || document.title }
      ] : []
    });
    return dt;
  });

  /* ---------- Print buttons ---------- */
  $(document).on('click', '[data-print]', function () { window.print(); });

  /* ---------- Live existence check (username, email, member no.) ---------- */
  var checkTimers = {};
  $(document).on('input blur', '[data-check]', function () {
    var input = this;
    var field = input.getAttribute('data-check');
    var value = input.value.trim();
    var $feedback = $(input).siblings('.invalid-feedback.js-exists');
    var form = input.form;
    clearTimeout(checkTimers[field]);
    if (value.length < 3) {
      input.classList.remove('is-invalid', 'is-valid');
      input.dataset.exists = '';
      toggleSubmit(form);
      return;
    }
    checkTimers[field] = setTimeout(async function () {
      try {
        var data = await apiFetch('api/check_existence.php', {
          method: 'POST',
          body: { field: field, value: value, exclude_id: Number(input.getAttribute('data-exclude-id') || 0) }
        });
        input.dataset.exists = data.exists ? '1' : '';
        input.classList.toggle('is-invalid', data.exists);
        input.classList.toggle('is-valid', !data.exists);
        $feedback.text(data.exists ? input.getAttribute('data-check-message') : '');
      } catch (e) {
        input.dataset.exists = '';
        input.classList.remove('is-invalid', 'is-valid');
      }
      toggleSubmit(form);
    }, 350);
  });
  function toggleSubmit(form) {
    if (!form) return;
    var blocked = form.querySelector('[data-check][data-exists="1"]') !== null;
    $(form).find('button[type="submit"]').prop('disabled', blocked);
  }

  /* ---------- Member lookup (search box → results list) ---------- */
  var lookupTimer = null;
  $(document).on('input', '[data-member-lookup]', function () {
    var input = this;
    var list = document.querySelector(input.getAttribute('data-member-lookup'));
    var template = input.getAttribute('data-select-url') || '';
    var q = input.value.trim();
    clearTimeout(lookupTimer);
    if (!list) return;
    if (q.length < 2) { list.replaceChildren(); return; }
    lookupTimer = setTimeout(async function () {
      try {
        var rows = await apiFetch('api/member_lookup.php?q=' + encodeURIComponent(q));
        list.replaceChildren();
        if (!rows.length) { list.appendChild(el('div', 'No active member matches “' + q + '”.', 'list-group-item text-muted small')); return; }
        rows.forEach(function (m) {
          var a = el('a', null, 'list-group-item list-group-item-action');
          a.href = template.replace('{id}', encodeURIComponent(m.member_id));
          a.appendChild(el('strong', m.name));
          a.appendChild(el('span', ' · ' + m.member_no, 'text-muted small'));
          list.appendChild(a);
        });
      } catch (e) {
        list.replaceChildren(el('div', e.message, 'list-group-item text-danger small'));
      }
    }, 300);
  });

  /* ---------- Amortization preview on the loan application form ---------- */
  var loanForm = document.getElementById('loan-form');
  if (loanForm) {
    var previewBody = document.getElementById('preview-body');
    var previewTotals = document.getElementById('preview-totals');
    var previewTimer = null;
    var runPreview = function () {
      clearTimeout(previewTimer);
      previewTimer = setTimeout(async function () {
        var product = loanForm.querySelector('[name="product_id"]');
        var principal = loanForm.querySelector('[name="principal"]').value;
        var term = loanForm.querySelector('[name="term_months"]').value;
        var opt = product.options[product.selectedIndex];
        var hint = document.getElementById('product-hint');
        if (hint && opt && opt.dataset.min) {
          hint.textContent = 'Allowed: ' + peso(opt.dataset.min) + ' – ' + peso(opt.dataset.max) + ', up to ' + opt.dataset.term + ' months at ' + opt.dataset.rate + '% per month.';
        }
        if (!product.value || !principal || !term) { previewBody.replaceChildren(); previewTotals.textContent = ''; return; }
        try {
          var data = await apiFetch('api/amortization_preview.php', {
            method: 'POST',
            body: { product_id: Number(product.value), principal: principal, term_months: Number(term) }
          });
          previewBody.replaceChildren();
          data.schedule.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.appendChild(el('td', r.installment_no));
            tr.appendChild(el('td', r.due_date));
            tr.appendChild(el('td', peso(r.principal_due), 'num'));
            tr.appendChild(el('td', peso(r.interest_due), 'num'));
            tr.appendChild(el('td', peso(r.total_due), 'num font-weight-bold'));
            tr.appendChild(el('td', peso(r.balance), 'num'));
            previewBody.appendChild(tr);
          });
          previewTotals.textContent = 'Total interest ' + peso(data.total_interest) + ' · Total payable ' + peso(data.total_payable) +
            ' · Est. net proceeds ' + peso(data.deductions.net);
        } catch (e) {
          previewBody.replaceChildren();
          previewTotals.textContent = e.message;
        }
      }, 350);
    };
    $(loanForm).on('input change', 'input, select', runPreview);
    runPreview();
  }

  /* ---------- Payment split preview (display only; server recomputes) ---------- */
  var amountInput = document.getElementById('payment-amount');
  if (amountInput) {
    var dues = {
      penalty: Number(amountInput.dataset.penalty),
      interest: Number(amountInput.dataset.interest),
      principal: Number(amountInput.dataset.principal)
    };
    var cents = function (n) { return Math.round(n * 100); };
    var render = function () {
      var left = cents(Number(amountInput.value || 0));
      var parts = {};
      ['penalty', 'interest', 'principal'].forEach(function (k) {
        var take = Math.min(left, cents(dues[k]));
        parts[k] = take; left -= take;
      });
      document.getElementById('split-penalty').textContent = peso(parts.penalty / 100);
      document.getElementById('split-interest').textContent = peso(parts.interest / 100);
      document.getElementById('split-principal').textContent = peso(parts.principal / 100);
      var warn = document.getElementById('split-excess');
      warn.hidden = left <= 0;
      warn.textContent = left > 0 ? 'Amount exceeds this installment by ' + peso(left / 100) + '. Post the extra as a separate payment on the next installment.' : '';
    };
    $(amountInput).on('input', render);
    $(document).on('click', '[data-fill-amount]', function () { amountInput.value = this.getAttribute('data-fill-amount'); render(); });
    render();
  }

  /* ---------- Charts (Chart.js 2.x) ---------- */
  document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
    var labels = JSON.parse(canvas.getAttribute('data-labels') || '[]');
    var values = JSON.parse(canvas.getAttribute('data-values') || '[]');
    new Chart(canvas.getContext('2d'), {
      type: canvas.getAttribute('data-chart'),
      data: { labels: labels, datasets: [{ label: canvas.getAttribute('data-label') || '', data: values, backgroundColor: 'rgba(31,111,80,.75)', borderRadius: 4 }] },
      options: {
        maintainAspectRatio: false,
        legend: { display: false },
        scales: { yAxes: [{ ticks: { beginAtZero: true, callback: function (v) { return peso(v).replace('.00', ''); } } }], xAxes: [{ gridLines: { display: false } }] },
        tooltips: { callbacks: { label: function (item) { return peso(item.yLabel); } } }
      }
    });
  });
})(jQuery);
