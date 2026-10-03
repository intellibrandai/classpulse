// Semester Analytics wiring: search, sort and "Show more" over the server-rendered table, the student inspector
// (lazy JSON from the server), qualitative notes through the notes API, report card comments and Print.
// Every number shown comes from the server; this file only paints strings and never computes a total.
(function () {
  const root = document.querySelector('[data-semester]');
  const lib = globalThis.ClassPulseSemester;
  if (!root || !lib) {
    return;
  }
  const noteLib = globalThis.ClassPulseDailyNotes;
  const dialogs = globalThis.ClassPulseDialogs;
  const csrf = document.querySelector('meta[name="csrf-token"]');
  const SVG_NS = 'http://www.w3.org/2000/svg';
  const LOG_PAGE = 10;

  const studentApi = root.dataset.studentApi;
  const noteApi = root.dataset.noteApi;
  const commentsApi = root.dataset.commentsApi;
  const commentApi = root.dataset.commentApi;
  const period = { key: root.dataset.period, label: root.dataset.periodLabel, from: root.dataset.periodFrom, to: root.dataset.periodTo };
  const today = root.dataset.today;
  const pageSize = parseInt(root.dataset.pageSize, 10) || 25;

  const body = root.querySelector('[data-body]');
  const searchInput = root.querySelector('[data-search]');
  const sortSelect = root.querySelector('[data-sort]');
  const showingText = root.querySelector('[data-showing]');
  const moreButton = root.querySelector('[data-more]');
  const noMatch = root.querySelector('[data-no-match]');
  const table = root.querySelector('[data-table]');
  const rankHead = root.querySelector('[data-rank-head]');
  const rankLabel = root.querySelector('[data-rank-label]');
  const inspector = root.querySelector('[data-inspector]');
  const toggleButton = root.querySelector('[data-inspector-toggle]');
  const pill = document.getElementById('save-pill');
  const commentsDialog = root.querySelector('[data-comments-dialog]');
  const deleteDialog = root.querySelector('[data-delete-dialog]');
  const printLog = root.querySelector('[data-print-log]');

  // ---- helpers -------------------------------------------------------------------

  function svgEl(name, attrs, text) {
    const node = document.createElementNS(SVG_NS, name);
    Object.keys(attrs || {}).forEach(function (key) {
      node.setAttribute(key, String(attrs[key]));
    });
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function isSheetLayout() {
    return window.matchMedia('(max-width: 1100px)').matches;
  }

  function setPill(state, text) {
    if (!pill) {
      return;
    }
    pill.dataset.state = state;
    pill.textContent = '';
    pill.append(el('span', 'sync-dot'), el('span', '', text));
    pill.firstChild.setAttribute('aria-hidden', 'true');
  }

  function apiHeaders(json) {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.content : '' };
    if (json) {
      headers['Content-Type'] = 'application/json';
    }
    return headers;
  }

  function errorMessage(payload, fallback) {
    return payload && payload.error && payload.error.message ? payload.error.message : fallback;
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).catch(function () {
        return fallbackCopy(text);
      });
    }
    return fallbackCopy(text);
  }

  function fallbackCopy(text) {
    return new Promise(function (resolve, reject) {
      const area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.className = 'visually-hidden';
      document.body.append(area);
      const previous = document.activeElement;
      area.select();
      let ok = false;
      try {
        ok = document.execCommand('copy');
      } catch (e) {
        ok = false;
      }
      area.remove();
      if (previous && previous.focus) {
        previous.focus();
      }
      if (ok) {
        resolve();
      } else {
        reject(new Error('copy failed'));
      }
    });
  }

  // ---- master table: search, sort, paging, rank ---------------------------------------

  const model = body
    ? Array.from(body.querySelectorAll('tr[data-row]')).map(function (tr) {
      const avg = tr.dataset.avg === '' ? null : parseFloat(tr.dataset.avg);
      return {
        id: tr.dataset.studentId,
        el: tr,
        search: tr.dataset.search,
        order: parseInt(tr.dataset.order, 10),
        total: parseInt(tr.dataset.total, 10),
        present: parseInt(tr.dataset.present, 10),
        absences: parseInt(tr.dataset.absences, 10),
        avg: Number.isNaN(avg) ? null : avg,
      };
    })
    : [];
  let shown = pageSize;
  let selection = { id: null };

  function renderTable() {
    if (!body) {
      return;
    }
    const key = sortSelect ? sortSelect.value : 'name-asc';
    const query = searchInput ? searchInput.value : '';
    const matchedRows = lib.sortRows(model.filter(function (m) {
      return lib.matches(m.search, query);
    }), key);
    const matchedIds = new Set(matchedRows.map(function (m) { return m.id; }));
    const ranks = lib.ranks(model, key);
    const showRank = Object.keys(ranks).length > 0;
    shown = lib.clampShown(shown, matchedRows.length, pageSize);

    matchedRows.forEach(function (m, index) {
      body.insertBefore(m.el, noMatch);
      m.el.classList.remove('is-filtered');
      m.el.classList.toggle('is-paged', index >= shown);
    });
    model.forEach(function (m) {
      if (!matchedIds.has(m.id)) {
        body.insertBefore(m.el, noMatch);
        m.el.classList.add('is-filtered');
        m.el.classList.remove('is-paged');
      }
    });

    table.classList.toggle('has-rank', showRank);
    rankHead.hidden = !showRank;
    rankLabel.textContent = lib.rankLabel(key) || 'Rank';
    model.forEach(function (m) {
      const cell = m.el.querySelector('[data-rank-cell]');
      cell.hidden = !showRank;
      cell.textContent = showRank ? (ranks[m.id] ? '#' + ranks[m.id] : '—') : '';
    });

    noMatch.hidden = matchedRows.length > 0;
    showingText.textContent = lib.showingText(shown, matchedRows.length, model.length);
    const remaining = matchedRows.length - shown;
    moreButton.hidden = remaining <= 0;
    moreButton.textContent = remaining > 0 ? 'Show more (' + remaining + ' left)' : 'Show more';
  }

  if (body) {
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        shown = pageSize;
        renderTable();
      });
    }
    sortSelect.addEventListener('change', function () {
      shown = pageSize;
      renderTable();
    });
    moreButton.addEventListener('click', function () {
      const matched = model.filter(function (m) { return !m.el.classList.contains('is-filtered'); }).length;
      const firstNew = shown;
      shown = lib.showMore(shown, matched, pageSize);
      renderTable();
      // The button hides itself when nothing is left: hand the focus to the first row that was just revealed.
      if (moreButton.hidden) {
        const revealed = body.querySelectorAll('tr[data-row]:not(.is-filtered)')[firstNew];
        const link = revealed ? revealed.querySelector('a, button') : null;
        if (link) {
          link.focus();
        }
      }
    });
    renderTable();
  }

  // ---- inspector: selection and lazy data ----------------------------------------------

  const f = function (name) {
    return inspector ? inspector.querySelector('[data-f="' + name + '"]') : null;
  };
  const q = function (name) {
    return inspector ? inspector.querySelector('[data-' + name + ']') : null;
  };
  let detail = null;
  let loadSeq = 0;
  let logShown = LOG_PAGE;
  const notesDirty = new Set();

  function rowOf(id) {
    return model.find(function (m) { return m.id === String(id); }) || null;
  }

  function paintSelection() {
    model.forEach(function (m) {
      const on = m.id === selection.id;
      m.el.classList.toggle('is-selected', on);
      const button = m.el.querySelector('[data-inspect]');
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
      button.classList.toggle('ui-btn-primary', on);
      button.querySelector('[data-inspect-text]').textContent = on ? 'Inspecting' : 'Inspect';
    });
  }

  function setSheet(open) {
    if (!inspector || !toggleButton) {
      return;
    }
    inspector.classList.toggle('is-open', open);
    toggleButton.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function showInspector(mode, message) {
    q('inspector-empty').hidden = mode !== 'empty';
    q('inspector-body').hidden = mode !== 'body';
    const status = q('inspector-status');
    status.hidden = mode !== 'status';
    status.textContent = mode === 'status' ? message : '';
    status.classList.toggle('is-error', mode === 'status' && message !== 'Loading student…');
  }

  function select(id, fromKeyboard) {
    selection = lib.selectionReduce(selection, { type: 'select', id: id });
    paintSelection();
    closeNoteForm();
    if (isSheetLayout()) {
      setSheet(true);
      inspector.focus();
    } else if (fromKeyboard) {
      inspector.focus({ preventScroll: true });
    }
    loadDetail(selection.id);
  }

  function clearSelection() {
    const previous = selection.id;
    selection = lib.selectionReduce(selection, { type: 'clear' });
    detail = null;
    loadSeq += 1;
    paintSelection();
    closeNoteForm();
    showInspector('empty');
    paintPrintLog();
    setSheet(false);
    const row = previous ? rowOf(previous) : null;
    if (row) {
      row.el.querySelector('[data-inspect]').focus();
    }
  }

  function loadDetail(id) {
    const seq = ++loadSeq;
    showInspector('status', 'Loading student…');
    const url = studentApi.replace('{student}', encodeURIComponent(id)) + '?period=' + encodeURIComponent(period.key);
    fetch(url, { headers: apiHeaders(false), credentials: 'same-origin' })
      .then(function (response) {
        return response.json().catch(function () { return null; }).then(function (payload) {
          return { ok: response.ok, payload: payload };
        });
      })
      .then(function (result) {
        if (seq !== loadSeq) {
          return;
        }
        if (!result.ok || !result.payload || !result.payload.data) {
          showInspector('status', errorMessage(result.payload, 'Could not load this student. Try again.'));
          return;
        }
        detail = {
          data: result.payload.data,
          notes: result.payload.data.notes.map(function (n) { return { date: n.date, body: n.body }; }),
        };
        logShown = LOG_PAGE;
        paintDetail();
      })
      .catch(function () {
        if (seq === loadSeq) {
          showInspector('status', 'Could not load this student. Check your connection and try again.');
        }
      });
  }

  function paintDetail() {
    const d = detail.data;
    const s = d.student;
    showInspector('body');
    f('initials').textContent = s.initials;
    f('name').textContent = s.name + (s.archived ? ' (archived)' : '');
    const preferred = f('preferred');
    preferred.hidden = !s.preferred_name;
    preferred.textContent = s.preferred_name ? 'Preferred: ' + s.preferred_name : '';
    const number = f('number');
    number.hidden = !s.student_number;
    number.textContent = s.student_number ? 'ID: ' + s.student_number : '';
    const todayBadge = f('today');
    todayBadge.textContent = d.today.text;
    todayBadge.className = 'ui-badge ' + (d.today.status === 'present' ? 'ui-badge-present' : (d.today.status === 'absent' ? 'ui-badge-absent' : 'ui-badge-none'));

    f('total').textContent = String(d.stats.total_points);
    f('present').textContent = String(d.stats.present_days);
    f('present-sub').textContent = d.stats.present_text;
    f('absences').textContent = String(d.stats.absences);
    f('average').textContent = d.stats.average_text;
    f('average-sub').hidden = !d.stats.has_data;

    paintChart(d.chart);
    paintLog();
    renderNotes();
    paintPrintLog();
  }

  function paintChart(chart) {
    const box = q('chart');
    const empty = q('chart-empty');
    const summary = q('chart-summary');
    box.textContent = '';
    q('chart-alt').textContent = chart.alt;
    if (!chart.enough) {
      empty.hidden = false;
      empty.textContent = chart.empty_text;
      summary.hidden = true;
      box.hidden = true;
      return;
    }
    box.hidden = false;
    empty.hidden = true;
    const svg = svgEl('svg', { class: 'ui-chart ui-chart-violet sem-chart-svg', viewBox: '0 0 ' + chart.width + ' ' + chart.height, role: 'img', 'aria-label': chart.alt, focusable: 'false' });
    svg.appendChild(svgEl('title', {}, chart.alt));
    svg.appendChild(svgEl('line', { class: 'sem-grid', x1: 0, x2: chart.width, y1: chart.top_y, y2: chart.top_y }));
    svg.appendChild(svgEl('line', { class: 'sem-grid', x1: 0, x2: chart.width, y1: chart.baseline_y, y2: chart.baseline_y }));
    svg.appendChild(svgEl('text', { class: 'sem-axis', x: 2, y: chart.top_y - 3 }, chart.max_text));
    if (chart.path) {
      svg.appendChild(svgEl('path', { d: chart.path }));
    }
    chart.dots.forEach(function (dot) {
      const circle = svgEl('circle', { class: 'ui-chart-dot', cx: dot.x, cy: dot.y, r: dot.is_last ? 3.5 : 2.5 });
      circle.appendChild(svgEl('title', {}, dot.title));
      svg.appendChild(circle);
    });
    chart.labels.forEach(function (label) {
      svg.appendChild(svgEl('text', { class: 'sem-axis sem-axis-x', x: label.x, y: chart.height - 2, 'text-anchor': 'middle' }, label.text));
    });
    box.appendChild(svg);
    if (chart.summary) {
      summary.hidden = false;
      f('lowest').textContent = chart.summary.lowest;
      f('peak').textContent = chart.summary.peak;
    } else {
      summary.hidden = true;
    }
  }

  function paintLog() {
    const list = q('log');
    const log = detail.data.log;
    list.textContent = '';
    log.slice(0, logShown).forEach(function (entry) {
      const item = el('li', 'sem-log-item' + (entry.status === 'absent' ? ' is-absent' : ''));
      const date = el('span', 'sem-log-date');
      date.append(el('strong', '', entry.label), el('span', '', ' (' + entry.weekday + ')'));
      const badge = el('span', 'ui-badge ' + (entry.status === 'absent' ? 'ui-badge-absent' : 'ui-badge-present'), entry.status_text);
      item.append(date, badge, el('span', 'sem-log-points', entry.points_text));
      list.appendChild(item);
    });
    q('log-empty').hidden = log.length > 0;
    const more = q('log-more');
    more.hidden = log.length <= logShown;
    more.textContent = 'Show more (' + (log.length - logShown) + ' left)';
  }

  function paintPrintLog() {
    if (!printLog) {
      return;
    }
    printLog.textContent = '';
    if (!detail) {
      return;
    }
    const d = detail.data;
    printLog.appendChild(el('h2', '', 'Audit log: ' + d.student.name + ' · ' + period.label));
    printLog.appendChild(el('p', '', d.stats.total_points + ' points · ' + d.stats.present_text + ' · ' + d.stats.absences + ' absences · average ' + d.stats.average_text));
    if (d.log.length > 0) {
      const tbl = el('table', 'sem-print-table');
      const head = el('tr');
      ['Date', 'Weekday', 'Status', 'Points'].forEach(function (text) {
        const th = el('th', '', text);
        th.scope = 'col';
        head.appendChild(th);
      });
      const thead = el('thead');
      thead.appendChild(head);
      tbl.appendChild(thead);
      const tbody = el('tbody');
      d.log.forEach(function (entry) {
        const tr = el('tr');
        [entry.label, entry.weekday, entry.status_text, entry.points_text].forEach(function (text) {
          tr.appendChild(el('td', '', text));
        });
        tbody.appendChild(tr);
      });
      tbl.appendChild(tbody);
      printLog.appendChild(tbl);
    }
    if (detail.notes.length > 0) {
      printLog.appendChild(el('h3', '', 'Notes'));
      const ul = el('ul');
      detail.notes.forEach(function (n) {
        ul.appendChild(el('li', '', lib.formatDate(n.date) + ': ' + n.body));
      });
      printLog.appendChild(ul);
    }
  }

  if (body) {
    body.addEventListener('click', function (event) {
      const button = event.target.closest('[data-inspect]');
      if (button) {
        select(button.closest('tr').dataset.studentId, event.detail === 0);
        return;
      }
      if (event.target.closest('a, button, input, select, textarea')) {
        return;
      }
      const row = event.target.closest('tr[data-row]');
      if (row) {
        select(row.dataset.studentId, false);
      }
    });
    q('log-more').addEventListener('click', function () {
      logShown += LOG_PAGE;
      paintLog();
    });
  }
  if (toggleButton) {
    toggleButton.addEventListener('click', function () {
      const open = !inspector.classList.contains('is-open');
      setSheet(open);
      if (open) {
        inspector.focus();
      }
    });
    // The bottom sheet (narrow screens) covers the page, so Tab stays inside it while it is open.
    inspector.addEventListener('keydown', function (event) {
      if (isSheetLayout() && inspector.classList.contains('is-open') && dialogs) {
        dialogs.trapTab(inspector, event);
      }
    });
    root.querySelector('[data-inspector-close]').addEventListener('click', function () {
      setSheet(false);
      toggleButton.focus();
    });
  }

  // ---- notes (qualitative log) --------------------------------------------------------------

  const noteForm = q('note-form');
  const noteDate = q('note-date');
  const noteBody = q('note-body');
  const noteCount = q('note-count');
  const noteStatus = q('note-status');
  const noteError = q('note-error');
  const noteSave = q('note-save');
  const noteReplace = q('note-replace');
  let noteMode = null; // {date: string|null} while the form is open; date set when editing
  let noteState = 'idle';

  function noteUrl(date) {
    return noteApi.replace('{student}', encodeURIComponent(selection.id)) + '/' + date;
  }

  function renderNotes() {
    const list = q('notes');
    list.textContent = '';
    detail.notes.forEach(function (note) {
      const item = el('li', 'sem-note');
      const head = el('div', 'sem-note-head');
      const time = el('time', 'sem-note-date', lib.formatDate(note.date));
      time.setAttribute('datetime', note.date);
      const actions = el('div', 'sem-note-actions');
      const name = detail.data.student.name;
      const edit = el('button', 'ui-btn ui-btn-ghost ui-btn-sm', 'Edit');
      edit.type = 'button';
      edit.dataset.noteEdit = note.date;
      edit.setAttribute('aria-label', 'Edit the note for ' + name + ' on ' + lib.formatDate(note.date));
      const del = el('button', 'ui-btn ui-btn-ghost ui-btn-sm', 'Delete');
      del.type = 'button';
      del.dataset.noteDelete = note.date;
      del.setAttribute('aria-label', 'Delete the note for ' + name + ' on ' + lib.formatDate(note.date));
      actions.append(edit, del);
      head.append(time, el('span', 'sem-note-weekday', lib.weekday(note.date)), actions);
      item.append(head, el('p', 'sem-note-body', note.body));
      list.appendChild(item);
    });
    q('notes-empty').hidden = detail.notes.length > 0;
  }

  function setNoteState(next, info) {
    noteState = next;
    noteStatus.dataset.state = next;
    noteStatus.textContent = noteLib ? noteLib.statusText(next, info) : '';
    const busy = next === 'saving';
    noteSave.disabled = busy;
  }

  function updateCount() {
    if (noteLib) {
      const c = noteLib.counter(noteBody.value.length, noteLib.MAX);
      noteCount.textContent = c.text;
      noteCount.dataset.level = c.level;
    }
  }

  function updateReplaceHint() {
    const replaced = noteMode && noteMode.date === null && detail && lib.hasNoteOn(detail.notes, noteDate.value);
    noteReplace.hidden = !replaced;
  }

  function openNoteForm(existing) {
    if (!detail) {
      return;
    }
    noteMode = { date: existing ? existing.date : null };
    q('note-form-title').textContent = existing ? 'Edit note' : 'Add note';
    noteDate.value = existing ? existing.date : today;
    noteDate.disabled = !!existing;
    noteBody.value = existing ? existing.body : '';
    noteError.hidden = true;
    noteError.textContent = '';
    setNoteState('idle');
    noteForm.hidden = false;
    updateCount();
    updateReplaceHint();
    (existing ? noteBody : noteDate).focus();
  }

  function closeNoteForm() {
    if (!noteForm) {
      return;
    }
    noteForm.hidden = true;
    noteMode = null;
    noteDate.disabled = false;
  }

  function showNoteError(message) {
    noteError.hidden = false;
    noteError.textContent = message;
    setNoteState('error', { message: message });
    noteStatus.textContent = '';
  }

  function afterNotesChange() {
    notesDirty.add(selection.id);
    renderNotes();
    paintPrintLog();
  }

  if (noteForm) {
    q('note-add').addEventListener('click', function () {
      openNoteForm(null);
    });
    q('note-cancel').addEventListener('click', function () {
      closeNoteForm();
      q('note-add').focus();
    });
    noteDate.addEventListener('input', updateReplaceHint);
    noteBody.addEventListener('input', function () {
      updateCount();
      noteError.hidden = true;
      if (noteState === 'error' || noteState === 'saved') {
        setNoteState('idle');
      }
    });
    noteForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!detail || !noteMode || noteState === 'saving') {
        return;
      }
      const date = noteMode.date || noteDate.value;
      const text = noteBody.value.trim();
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || lib.formatDate(date) === date) {
        showNoteError('Choose a valid date for the note.');
        return;
      }
      if (text === '') {
        showNoteError('Write a note before saving. To remove a note, use Delete.');
        return;
      }
      if (noteLib && text.length > noteLib.MAX) {
        showNoteError('A note can have at most ' + noteLib.MAX + ' characters.');
        return;
      }
      const studentId = selection.id;
      noteError.hidden = true;
      setNoteState('saving');
      setPill('saving', 'Saving…');
      fetch(noteUrl(date), { method: 'PUT', headers: apiHeaders(true), credentials: 'same-origin', body: JSON.stringify({ body: text }) })
        .then(function (response) {
          return response.json().catch(function () { return null; }).then(function (payload) {
            return { ok: response.ok, payload: payload };
          });
        })
        .then(function (result) {
          if (!result.ok || !result.payload || !result.payload.data || !result.payload.data.note) {
            setPill('failed', 'Save failed');
            showNoteError(errorMessage(result.payload, 'Could not save the note. Try again.'));
            return;
          }
          if (selection.id !== studentId || !detail) {
            setPill('saved', 'Saved');
            return;
          }
          const note = result.payload.data.note;
          detail.notes = lib.mergeNote(detail.notes, { date: note.date, body: note.body });
          afterNotesChange();
          closeNoteForm();
          const time = noteLib ? noteLib.clock(new Date(), 'America/Toronto') : '';
          setPill('saved', time ? 'Saved at ' + time : 'Saved');
          q('note-add').focus();
        })
        .catch(function () {
          setPill('failed', 'Save failed');
          showNoteError('Could not save the note. Check your connection and try again.');
        });
    });

    q('notes').addEventListener('click', function (event) {
      const edit = event.target.closest('[data-note-edit]');
      const del = event.target.closest('[data-note-delete]');
      if (edit && detail) {
        const note = detail.notes.find(function (n) { return n.date === edit.dataset.noteEdit; });
        if (note) {
          openNoteForm(note);
        }
        return;
      }
      if (del && detail) {
        confirmDelete(del.dataset.noteDelete, del);
      }
    });
  }

  function confirmDelete(date, trigger) {
    const studentId = selection.id;
    const name = detail.data.student.name;
    deleteDialog.querySelector('[data-delete-text]').textContent = 'The note for ' + name + ' on ' + lib.formatDate(date) + ' will be removed from the qualitative log. This cannot be undone.';
    dialogs.confirm(deleteDialog).then(function (confirmed) {
      if (!confirmed) {
        trigger.focus();
        return;
      }
      setPill('saving', 'Saving…');
      fetch(noteUrl(date), { method: 'DELETE', headers: apiHeaders(false), credentials: 'same-origin' })
        .then(function (response) {
          return response.json().catch(function () { return null; }).then(function (payload) {
            return { ok: response.ok, payload: payload };
          });
        })
        .then(function (result) {
          if (!result.ok) {
            setPill('failed', 'Delete failed');
            q('inspector-status').hidden = false;
            q('inspector-status').classList.add('is-error');
            q('inspector-status').textContent = errorMessage(result.payload, 'Could not delete the note. Try again.');
            return;
          }
          q('inspector-status').hidden = true;
          if (selection.id === studentId && detail) {
            detail.notes = lib.removeNote(detail.notes, date);
            afterNotesChange();
            q('note-add').focus();
          }
          setPill('saved', 'Note deleted');
        })
        .catch(function () {
          setPill('failed', 'Delete failed');
        });
    });
  }

  // ---- report card comments ----------------------------------------------------------------

  function commentArticles() {
    return commentsDialog ? Array.from(commentsDialog.querySelectorAll('[data-comment]')) : [];
  }

  function articleNotes(article) {
    return Array.from(article.querySelectorAll('[data-comment-notes] li')).map(function (li) {
      return { label: li.dataset.noteLabel, body: li.querySelector('[data-note-body]').textContent };
    });
  }

  function draftFor(article) {
    return lib.buildComment({
      name: article.dataset.name,
      preferred: article.dataset.preferred,
      total: article.dataset.total,
      present: article.dataset.present,
      average: article.dataset.average,
      periodLabel: period.label,
      notes: articleNotes(article),
    });
  }

  // One draft state (see ClassPulseSemester.draftReduce) per student, a debounce timer and a request chain so a
  // save, a reset and a later save never overtake each other.
  const SAVE_DELAY = 800;
  const drafts = new Map();
  let draftsLoaded = false;

  function slot(article) {
    const id = article.dataset.studentId;
    if (!drafts.has(id)) {
      drafts.set(id, { state: lib.draftInit(null, draftFor(article)), timer: null, chain: Promise.resolve() });
    }
    return drafts.get(id);
  }

  function commentUrl(article) {
    return commentApi.replace('{student}', encodeURIComponent(article.dataset.studentId));
  }

  // Grows the textarea (rows attribute, never a style) until the whole draft is visible.
  function autosize(input) {
    if (!input.offsetParent) {
      return;
    }
    input.rows = 4;
    while (input.scrollHeight > input.clientHeight + 1 && input.rows < 16) {
      input.rows += 1;
    }
  }

  function paintDraft(article) {
    const state = slot(article).state;
    const input = article.querySelector('[data-comment-input]');
    const heldFocus = document.activeElement;
    if (input.value !== state.text) {
      input.value = state.text;
    }
    autosize(input);
    const badge = article.querySelector('[data-comment-badge]');
    const stamp = state.source === 'saved' ? lib.formatStamp(state.updatedAt) : '';
    badge.textContent = lib.draftBadge(state) + (stamp ? ' · ' + stamp : '');
    badge.classList.toggle('ui-badge-present', state.source === 'saved');
    badge.classList.toggle('ui-badge-none', state.source !== 'saved');
    const status = article.querySelector('[data-comment-state]');
    status.textContent = lib.draftStatusText(state);
    status.dataset.state = state.save;
    article.querySelector('[data-comment-retry]').hidden = state.save !== 'error';
    article.querySelector('[data-comment-reset]').disabled = lib.resetDisabled(state);
    // Reset (now back on the template) and Retry (saved) switch themselves off: keep the focus in the draft, not on <body>.
    if (heldFocus && (heldFocus.matches('[data-comment-reset]') || heldFocus.matches('[data-comment-retry]')) && article.contains(heldFocus) && (heldFocus.disabled || heldFocus.hidden)) {
      input.focus();
    }
  }

  function apply(article, action) {
    const item = slot(article);
    item.state = lib.draftReduce(item.state, action);
    paintDraft(article);
  }

  // Sends whatever the current state needs (PUT text, DELETE when blank) once the previous request finished.
  function flushDraft(article) {
    const item = slot(article);
    window.clearTimeout(item.timer);
    item.timer = null;
    item.chain = item.chain.then(function () {
      const request = lib.draftRequest(item.state);
      if (!request) {
        return null;
      }
      apply(article, { type: 'save' });
      const options = { method: request.method, headers: apiHeaders(request.method === 'PUT') };
      if (request.method === 'PUT') {
        options.body = JSON.stringify({ body: request.body });
      }
      return fetch(commentUrl(article), options)
        .then(function (response) {
          return response.json().catch(function () { return null; }).then(function (payload) {
            if (!response.ok) {
              throw new Error(errorMessage(payload, 'Save failed'));
            }
            apply(article, { type: 'saved', draft: payload.data.draft, template: draftFor(article) });
            if (item.state.save === 'dirty') {
              flushDraft(article); // the text moved on while the request was out: queue one more save behind this one
            }
            return null;
          });
        })
        .catch(function () {
          apply(article, { type: 'failed' });
        });
    });
    return item.chain;
  }

  function scheduleDraft(article) {
    const item = slot(article);
    window.clearTimeout(item.timer);
    item.timer = window.setTimeout(function () { flushDraft(article); }, SAVE_DELAY);
  }

  function resetDraft(article) {
    const item = slot(article);
    window.clearTimeout(item.timer);
    item.timer = null;
    const template = draftFor(article);
    item.chain = item.chain.then(function () {
      if (item.state.source === 'template') {
        apply(article, { type: 'reset', template: template });
        return null;
      }
      apply(article, { type: 'save' });
      return fetch(commentUrl(article), { method: 'DELETE', headers: apiHeaders(false) })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Reset failed');
          }
          apply(article, { type: 'reset', template: template });
          root.querySelector('[data-comments-status]').textContent = 'Back to the template for ' + article.dataset.name + '.';
        })
        .catch(function () {
          apply(article, { type: 'failed' });
        });
    });
  }

  // Notes edited in the inspector since the page loaded: refresh that student's list in the dialog.
  function refreshCommentNotes(article) {
    const id = article.dataset.studentId;
    if (!notesDirty.has(id) || !detail || String(detail.data.student.id) !== id) {
      return;
    }
    const list = article.querySelector('[data-comment-notes]');
    list.textContent = '';
    const inside = detail.notes.filter(function (n) { return lib.inPeriod(n.date, period.from, period.to); });
    inside.forEach(function (n) {
      const li = el('li');
      li.dataset.noteDate = n.date;
      li.dataset.noteLabel = lib.formatDate(n.date);
      const time = el('time', '', lib.formatDate(n.date));
      time.setAttribute('datetime', n.date);
      const span = el('span', '', n.body);
      span.dataset.noteBody = '';
      li.append(time, span);
      list.appendChild(li);
    });
    article.querySelector('[data-comment-none]').hidden = inside.length > 0;
    notesDirty.delete(id);
    apply(article, { type: 'template', template: draftFor(article) });
  }

  // Loads the saved drafts of this class and period and merges them over the generated templates.
  function loadDrafts() {
    const status = root.querySelector('[data-comments-status]');
    const inputs = commentArticles().map(function (article) { return article.querySelector('[data-comment-input]'); });
    inputs.forEach(function (input) { input.readOnly = !draftsLoaded; });
    status.textContent = draftsLoaded ? '' : 'Loading saved drafts…';
    return fetch(commentsApi + '?period=' + encodeURIComponent(period.key), { headers: apiHeaders(false) })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('load');
        }
        return response.json();
      })
      .then(function (payload) {
        const saved = payload.data.drafts || {};
        commentArticles().forEach(function (article) {
          const item = slot(article);
          const untouched = item.state.save === 'idle' || item.state.save === 'saved';
          if (untouched) {
            item.state = lib.draftInit(saved[article.dataset.studentId], draftFor(article));
            paintDraft(article);
          }
        });
        draftsLoaded = true;
        status.textContent = '';
      })
      .catch(function () {
        status.textContent = 'Saved drafts could not be loaded. You can still edit; changes save when the connection is back.';
      })
      .then(function () {
        inputs.forEach(function (input) { input.readOnly = false; });
      });
  }

  function openComments() {
    commentArticles().forEach(function (article) {
      refreshCommentNotes(article);
      paintDraft(article);
    });
    root.querySelector('[data-comments-status]').textContent = '';
    commentsDialog.showModal();
    commentArticles().forEach(paintDraft);
    loadDrafts();
  }

  function flashCopied(button, label) {
    const text = button.querySelector('[data-copy-text]');
    const original = text ? text.textContent : '';
    if (text) {
      text.textContent = 'Copied';
    }
    root.querySelector('[data-comments-status]').textContent = label;
    window.setTimeout(function () {
      if (text) {
        text.textContent = original;
      }
    }, 1800);
  }

  function copyFailed() {
    root.querySelector('[data-comments-status]').textContent = 'Copy is not available here. Select the text and copy it manually.';
  }

  if (commentsDialog) {
    root.querySelector('[data-open-comments]').addEventListener('click', openComments);
    commentsDialog.addEventListener('input', function (event) {
      if (event.target.matches('[data-comment-input]')) {
        const article = event.target.closest('[data-comment]');
        apply(article, { type: 'input', text: event.target.value });
        scheduleDraft(article);
      }
    });
    commentsDialog.addEventListener('focusout', function (event) {
      if (event.target.matches('[data-comment-input]')) {
        flushDraft(event.target.closest('[data-comment]'));
      }
    });
    commentsDialog.addEventListener('close', function () {
      commentArticles().forEach(function (article) { flushDraft(article); });
    });
    commentsDialog.addEventListener('click', function (event) {
      const reset = event.target.closest('[data-comment-reset]');
      if (reset) {
        resetDraft(reset.closest('[data-comment]'));
        return;
      }
      const retry = event.target.closest('[data-comment-retry]');
      if (retry) {
        flushDraft(retry.closest('[data-comment]'));
        return;
      }
      const copy = event.target.closest('[data-comment-copy]');
      if (copy) {
        const article = copy.closest('[data-comment]');
        copyText(article.querySelector('[data-comment-input]').value).then(function () {
          flashCopied(copy, 'Copied the comment for ' + article.dataset.name + '.');
        }, copyFailed);
        return;
      }
      const all = event.target.closest('[data-comments-copy-all]');
      if (all) {
        const text = lib.joinAll(commentArticles().map(function (article) {
          return { name: article.dataset.name, text: article.querySelector('[data-comment-input]').value };
        }));
        copyText(text).then(function () {
          root.querySelector('[data-comments-status]').textContent = 'Copied every comment as plain text.';
        }, copyFailed);
      }
    });
  }

  // ---- print, keyboard ------------------------------------------------------------------------

  const printButton = root.querySelector('[data-print-summary]');
  if (printButton) {
    printButton.addEventListener('click', function () {
      window.print();
    });
  }

  document.addEventListener('keydown', function (event) {
    const target = event.target;
    const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
    if (event.key === 'Escape') {
      const tip = document.activeElement && document.activeElement.closest('.info-tip');
      if (tip) {
        tip.classList.add('is-dismissed');
        return;
      }
      if ((commentsDialog && commentsDialog.open) || (deleteDialog && deleteDialog.open)) {
        return;
      }
      if (noteForm && !noteForm.hidden && inspector.contains(target)) {
        closeNoteForm();
        q('note-add').focus();
        return;
      }
      if (selection.id !== null) {
        clearSelection();
      } else if (inspector && inspector.classList.contains('is-open')) {
        setSheet(false);
        // The sheet was opened with the toggle: Escape gives the focus back to it.
        if (toggleButton && inspector.contains(document.activeElement)) {
          toggleButton.focus();
        }
      }
      return;
    }
    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && searchInput && !typing) {
      if ((commentsDialog && commentsDialog.open) || (deleteDialog && deleteDialog.open)) {
        return;
      }
      event.preventDefault();
      searchInput.focus();
      searchInput.select();
    }
  });

  document.querySelectorAll('.info-tip').forEach(function (tip) {
    const reset = function () { tip.classList.remove('is-dismissed'); };
    tip.addEventListener('mouseleave', reset);
    tip.addEventListener('focusout', reset);
  });
})();
