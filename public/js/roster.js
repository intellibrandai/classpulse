// Class Roster wiring: live search and sort over the server-rendered rows, the edit dialog, the create form toggle,
// print. Every name and figure shown comes from the server; this script only shows, hides and reorders rows.
(function () {
  const root = document.querySelector('[data-roster]');
  const lib = globalThis.ClassPulseRoster;
  if (!root || !lib) {
    return;
  }

  const body = root.querySelector('[data-roster-body]');
  const searchInput = root.querySelector('[data-roster-search]');
  const sortSelect = root.querySelector('[data-roster-sort]');
  const counter = root.querySelector('[data-roster-count]');
  const emptyRow = root.querySelector('[data-roster-empty]');
  const noMatchRow = root.querySelector('[data-roster-nomatch]');

  // ---- search, sort and counter -----------------------------------------------------

  const trs = body ? Array.prototype.slice.call(body.querySelectorAll('[data-student-row]')) : [];
  const rows = trs.map(function (tr) {
    const avg = tr.dataset.avg;
    return {
      id: Number(tr.dataset.id),
      order: Number(tr.dataset.order),
      name: tr.dataset.name || '',
      preferred: tr.dataset.preferred || '',
      number: tr.dataset.number || '',
      avg: avg === '' || avg === undefined ? null : Number(avg),
      tr: tr,
    };
  });

  function refresh() {
    const term = searchInput ? searchInput.value : '';
    const key = sortSelect ? sortSelect.value : 'last-asc';
    const result = lib.plan(rows, term, key);
    const shown = new Set(result.visible);
    // Append in sorted order in front of the two empty-state rows; hidden rows stay in the DOM.
    result.order.forEach(function (row) { body.insertBefore(row.tr, emptyRow); });
    let n = 0;
    result.order.forEach(function (row) {
      const visible = shown.has(row);
      row.tr.hidden = !visible;
      if (visible) {
        n += 1;
        const cell = row.tr.querySelector('[data-order-cell]');
        if (cell) { cell.textContent = String(n); }
      }
    });
    if (noMatchRow) { noMatchRow.hidden = !(rows.length > 0 && n === 0); }
    if (counter) {
      const text = lib.countText(n, rows.length);
      const target = counter.querySelector('[data-count-text]');
      if (target) {
        target.textContent = '';
        target.appendChild(document.createTextNode(text.parts[0]));
        const strong = document.createElement('strong');
        strong.textContent = text.parts[1];
        target.appendChild(strong);
        target.appendChild(document.createTextNode(text.parts[2]));
      }
    }
  }

  if (searchInput) { searchInput.addEventListener('input', refresh); }
  if (sortSelect) { sortSelect.addEventListener('change', refresh); }

  document.addEventListener('keydown', function (event) {
    const target = event.target;
    const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
    if (event.key === '/' && !typing && searchInput && !event.metaKey && !event.ctrlKey && !event.altKey) {
      event.preventDefault();
      searchInput.focus();
      searchInput.select();
    }
  });

  // ---- edit dialog -------------------------------------------------------------------

  const dialog = document.getElementById('edit-dialog');
  if (dialog) {
    const form = dialog.querySelector('[data-edit-form]');
    const idField = dialog.querySelector('[data-edit-id]');

    function openDialog() {
      if (dialog.open) { dialog.close(); }
      if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
      const first = dialog.querySelector('[aria-invalid="true"]') || dialog.querySelector('#edit-display');
      if (first) { first.focus(); }
    }

    function fill(button) {
      form.action = button.dataset.action;
      idField.value = button.dataset.action.split('/').pop();
      form.querySelector('#edit-display').value = button.dataset.name || '';
      form.querySelector('#edit-preferred').value = button.dataset.preferred || '';
      form.querySelector('#edit-number').value = button.dataset.number || '';
      form.querySelector('#edit-observations').value = button.dataset.observations || '';
      // A fresh open never shows the previous attempt's errors.
      form.querySelectorAll('.ro-error').forEach(function (node) { node.remove(); });
      form.querySelectorAll('[aria-invalid]').forEach(function (node) { node.removeAttribute('aria-invalid'); });
    }

    root.querySelectorAll('[data-edit-student]').forEach(function (button) {
      button.addEventListener('click', function () {
        fill(button);
        openDialog();
      });
    });
    dialog.querySelectorAll('[data-edit-close]').forEach(function (button) {
      button.addEventListener('click', function () { dialog.close(); });
    });
    // Click on the backdrop (outside the form box) closes the dialog.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) { dialog.close(); }
    });
    form.addEventListener('submit', function (event) {
      const name = form.querySelector('#edit-display');
      if (name && name.value.trim() === '') {
        event.preventDefault();
        name.setAttribute('aria-invalid', 'true');
        name.focus();
      }
    });
    if (dialog.hasAttribute('data-open-on-load')) {
      dialog.removeAttribute('data-open-on-load');
      openDialog();
    }
  }

  // ---- create form -------------------------------------------------------------------

  const createPanel = document.getElementById('create-class');
  function openCreate() {
    if (!createPanel) { return; }
    createPanel.open = true;
    const first = createPanel.querySelector('input:not([type="hidden"])');
    createPanel.scrollIntoView({ block: 'center' });
    if (first) { first.focus({ preventScroll: true }); }
  }
  document.querySelectorAll('[data-open-create]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      event.preventDefault();
      openCreate();
    });
  });
  if (createPanel && window.location.hash === '#create-class') {
    openCreate();
  }
  // A closed create form is hidden entirely (CSS), so closing it from its own summary must hand the focus to the
  // "Create New Class" card that reopens it instead of dropping it on <body>.
  if (createPanel) {
    createPanel.addEventListener('toggle', function () {
      if (createPanel.open) { return; }
      const card = document.querySelector('[data-open-create]');
      if (card && (document.activeElement === document.body || createPanel.contains(document.activeElement))) {
        card.focus();
      }
    });
  }

  // ---- settings groups -----------------------------------------------------------------
  // #periods (from Semester Analytics "Set period dates") opens its group; a group that holds the focus target is opened too.
  function openGroupFor(node) {
    const group = node && node.closest ? node.closest('details.ro-group') : null;
    if (group) { group.open = true; }
  }
  function syncHash() {
    if (window.location.hash === '#periods') {
      const group = document.getElementById('periods');
      if (group) {
        group.open = true;
        group.scrollIntoView({ block: 'start' });
      }
    }
  }
  syncHash();
  window.addEventListener('hashchange', syncHash);

  // The first invalid field of a failed save gets focus (period errors sit low in the column).
  if (!(dialog && dialog.open)) {
    const invalid = root.querySelector('.ro-card [aria-invalid="true"], .ro-create [aria-invalid="true"]');
    if (invalid) {
      openGroupFor(invalid);
      invalid.focus();
    }
  }

  // ---- print -------------------------------------------------------------------------

  root.querySelectorAll('[data-print-roster]').forEach(function (button) {
    button.addEventListener('click', function () { window.print(); });
  });

  // Only one archive confirmation is open at a time.
  root.querySelectorAll('.ro-archive').forEach(function (details) {
    details.addEventListener('toggle', function () {
      if (!details.open) { return; }
      root.querySelectorAll('.ro-archive[open]').forEach(function (other) { if (other !== details) { other.open = false; } });
    });
  });

  refresh();
})();
