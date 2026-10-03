// Daily Tracker wiring: card clicks, save queue, dialogs, search, filters, inspector panel and shortcuts.
const CARD_ACTIONS = ['increment', 'decrement', 'set_zero', 'absent_on', 'absent_off'];

function initDailyTracker() {
  const main = document.querySelector('main[data-api]');
  if (!main || !globalThis.ClassPulseSaveQueue || !globalThis.ClassPulseDailyFormat || !globalThis.ClassPulseDailyNotes) {
    return;
  }
  const format = globalThis.ClassPulseDailyFormat;
  const noteLib = globalThis.ClassPulseDailyNotes;

  const endpoint = main.dataset.api;
  const csrf = document.querySelector('meta[name="csrf-token"]');
  const pill = document.getElementById('save-pill');
  const undoButton = document.getElementById('undo-btn');
  const searchInput = document.getElementById('student-search');
  const searchCount = document.getElementById('search-count');
  const errorBox = document.getElementById('day-error');
  const panel = document.getElementById('detail-panel');
  const panelToggle = document.getElementById('panel-toggle');
  const panelEmpty = document.querySelector('[data-panel-empty]');
  const meanBox = document.querySelector('[data-summary="mean"]');
  const studentDialog = document.getElementById('student-dialog');
  const dayMenu = document.querySelector('[data-day-menu]');
  const slip = document.querySelector('[data-day-slip]');
  const noteFlash = document.querySelector('[data-note-flash]');
  const dialogs = { 'reset-dialog': 'reset_day', 'zero-dialog': 'zero_remaining' };
  const maxPoints = 99;
  const restorePoints = new Map();
  let activeFilter = 'all';
  let selectedId = null;

  // ---- helpers -----------------------------------------------------------

  function cards() {
    return Array.from(document.querySelectorAll('article.student-card[data-student-id]'));
  }

  function cardName(card) {
    const link = card.querySelector('.student-name');
    return link ? link.textContent.trim() : '';
  }

  function cardPoints(card) {
    const value = card.querySelector('.score-number');
    const number = value ? parseInt(value.textContent, 10) : NaN;
    return Number.isNaN(number) ? null : number;
  }

  function showError(message) {
    if (!errorBox) {
      return;
    }
    errorBox.textContent = message || '';
    errorBox.hidden = !message;
  }

  // Keyboard safety net: when a repaint disables or hides the control that has focus (minus at 0 points, a card that
  // leaves the active filter, Undo with nothing left to undo), move the focus to the nearest usable control so it
  // never falls back to <body>.
  function rescueFocus(previous, card) {
    if (!previous || previous === document.body || previous !== document.activeElement && document.activeElement !== document.body) {
      return;
    }
    const gone = !previous.isConnected || previous.disabled === true || previous.closest('[hidden]') !== null;
    if (!gone) {
      return;
    }
    let target = null;
    if (card && card.isConnected && !card.hidden) {
      target = card.querySelector('[data-action="increment"]:not(:disabled), [data-action="decrement"]:not(:disabled), .btn-absent-toggle');
    }
    if (!target) {
      const visible = cards().filter(function (c) { return !c.hidden; });
      const after = card ? visible.find(function (c) { return card.compareDocumentPosition(c) & Node.DOCUMENT_POSITION_FOLLOWING; }) : null;
      const pick = after || (card ? visible[visible.length - 1] : visible[0]) || null;
      target = pick ? pick.querySelector('.student-name') : null;
    }
    if (!target) {
      target = document.querySelector('button[data-filter][aria-pressed="true"]');
    }
    if (target) {
      target.focus();
    }
  }

  // ---- rendering ---------------------------------------------------------

  function paintCard(card, status, points) {
    const name = cardName(card);
    const state = format.cardState(status, points);
    card.dataset.status = status;
    card.dataset.state = state;

    const score = card.querySelector('.score-number');
    if (score) {
      score.textContent = status === 'present' ? String(points) : '—';
    }
    const badge = card.querySelector('[data-role="status"]');
    if (badge) {
      badge.textContent = format.statusText(status, points);
      badge.className = 'status-badge ' + format.badgeClass(state);
    }
    // Minimum is zero: "−" only works on a recorded present card above 0.
    const minus = card.querySelector('[data-action="decrement"]');
    if (minus) {
      minus.disabled = !(status === 'present' && points > 0);
    }
    const plus = card.querySelector('[data-action="increment"]');
    if (plus) {
      plus.disabled = status === 'absent';
    }

    const toggle = card.querySelector('.btn-absent-toggle');
    const zeroButton = card.querySelector('[data-action="set_zero"]');
    if (status === 'none' && !zeroButton && toggle) {
      const created = document.createElement('button');
      created.type = 'button';
      created.className = 'btn-record-zero';
      created.dataset.action = 'set_zero';
      created.setAttribute('aria-label', 'Record 0 for ' + name);
      created.textContent = 'Record 0';
      toggle.before(created);
    } else if (status !== 'none' && zeroButton) {
      const hadFocus = document.activeElement === zeroButton;
      zeroButton.remove();
      if (hadFocus) {
        const next = card.querySelector('[data-action="increment"]');
        if (next) {
          next.focus();
        }
      }
    }
    if (toggle) {
      if (status === 'absent') {
        toggle.dataset.action = 'absent_off';
        toggle.textContent = 'Marked Absent (Tap to clear)';
        toggle.setAttribute('aria-label', 'Marked Absent (Tap to clear) for ' + name);
      } else {
        toggle.dataset.action = 'absent_on';
        toggle.textContent = 'Mark Absent';
        toggle.setAttribute('aria-label', 'Mark Absent for ' + name);
      }
    }
    paintPanel(card.dataset.studentId, status, points);
  }

  // The side panel only mirrors the selected day's state of the card; week data is rendered by the server.
  function paintPanel(studentId, status, points) {
    if (!panel) {
      return;
    }
    const section = panel.querySelector('[data-panel-student="' + studentId + '"]');
    if (!section) {
      return;
    }
    section.dataset.status = status;
    const badge = section.querySelector('[data-panel-status]');
    if (badge) {
      badge.textContent = format.statusText(status, points);
      badge.className = 'status-badge ' + format.badgeClass(format.cardState(status, points));
    }
    const sub = section.querySelector('[data-panel-sub]');
    if (sub) {
      sub.textContent = format.panelSubText(status, points);
    }
    const row = section.querySelector('[data-day-row]');
    const value = section.querySelector('[data-day-value]');
    if (row && value) {
      row.dataset.state = status;
      value.textContent = format.dayValueText(status, points);
    }
  }

  // Nothing is selected by default; selecting the selected student again clears the selection.
  function selectStudent(studentId) {
    // Leaving a student with unsaved note text saves it first (visible in the panel flash line).
    if (selectedId !== null && selectedId !== studentId) {
      autoSaveNote(selectedId);
    }
    selectedId = studentId;
    cards().forEach(function (card) {
      const on = card.dataset.studentId === studentId;
      card.classList.toggle('card-selected', on);
      const name = card.querySelector('.student-name');
      if (name) {
        name.setAttribute('aria-pressed', on ? 'true' : 'false');
      }
    });
    if (panel) {
      panel.querySelectorAll('[data-panel-student]').forEach(function (section) {
        section.hidden = section.dataset.panelStudent !== studentId;
      });
    }
    if (panelEmpty) {
      panelEmpty.hidden = selectedId !== null;
    }
  }

  function toggleSelection(studentId) {
    selectStudent(selectedId === studentId ? null : studentId);
  }

  function setPanelOpen(open) {
    if (!panel || !panelToggle) {
      return;
    }
    panel.classList.toggle('is-open', open);
    panelToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function renderSummary() {
    const totals = format.summarize(cards().map(function (card) {
      return { status: card.dataset.status, points: cardPoints(card) };
    }));
    const values = {
      total: String(totals.total),
      mean: totals.mean === null ? 'No data' : totals.mean.toFixed(2),
      present: String(totals.present),
      absent: String(totals.absent),
      none: String(totals.none),
      active: String(totals.active),
    };
    Object.keys(values).forEach(function (key) {
      const tile = document.querySelector('[data-summary="' + key + '"]');
      if (tile) {
        tile.textContent = values[key];
      }
    });
    if (meanBox) {
      meanBox.classList.toggle('is-empty', totals.mean === null);
    }
    renderCounts();
    renderSlip(totals);
  }

  // Filter chip counters mirror the cards' current state.
  function renderCounts() {
    const counts = format.filterCounts(cards().map(function (card) {
      return { status: card.dataset.status, points: cardPoints(card) };
    }));
    Object.keys(counts).forEach(function (key) {
      const badge = document.querySelector('[data-count="' + key + '"]');
      if (badge) {
        badge.textContent = String(counts[key]);
      }
    });
  }

  // Day Slip table (print only) is rebuilt from the cards so it always matches the screen.
  function renderSlip(totals) {
    if (!slip) {
      return;
    }
    const body = slip.querySelector('[data-slip-rows]');
    if (body) {
      body.textContent = '';
      cards().forEach(function (card, index) {
        const status = card.dataset.status;
        const points = cardPoints(card);
        const row = document.createElement('tr');
        const num = document.createElement('td');
        num.textContent = String(index + 1);
        const name = document.createElement('th');
        name.scope = 'row';
        name.textContent = cardName(card);
        const state = document.createElement('td');
        state.textContent = format.statusText(status, points);
        const value = document.createElement('td');
        value.className = 'num';
        value.textContent = status === 'present' ? String(points) : '—';
        const note = document.createElement('td');
        const indicator = card.querySelector('[data-note-indicator]');
        note.textContent = indicator && !indicator.hidden ? 'Note' : '';
        row.append(num, name, state, value, note);
        body.append(row);
      });
    }
    const fields = { present: totals.present, absent: totals.absent, none: totals.none, total: totals.total };
    Object.keys(fields).forEach(function (key) {
      const el = slip.querySelector('[data-slip="' + key + '"]');
      if (el) {
        el.textContent = String(fields[key]);
      }
    });
  }

  function updateDialogs() {
    const all = cards();
    const recorded = all.filter(function (card) { return card.dataset.status !== 'none'; }).length;
    const remaining = all.length - recorded;
    const texts = {
      'reset-dialog': function (className, dateText) {
        return 'Reset all entries for ' + className + ' on ' + dateText + '? This removes ' + recorded + ' recorded entries.';
      },
      'zero-dialog': function (className, dateText) {
        return 'Record 0 for ' + remaining + ' students without an entry for ' + className + ' on ' + dateText + '?';
      },
    };
    Object.keys(texts).forEach(function (id) {
      const dialog = document.getElementById(id);
      const message = dialog && dialog.querySelector('[data-dialog-message]');
      if (message) {
        message.textContent = texts[id](dialog.dataset.className || '', dialog.dataset.dateText || '');
      }
    });
  }

  function renderDay(day) {
    const before = document.activeElement;
    const beforeCard = before && before.closest ? before.closest('article.student-card') : null;
    const byStudent = new Map();
    day.entries.forEach(function (entry) { byStudent.set(String(entry.student_id), entry); });
    cards().forEach(function (card) {
      const entry = byStudent.get(card.dataset.studentId);
      if (entry) {
        paintCard(card, entry.status, entry.points);
        if (entry.status === 'present') {
          restorePoints.set(card.dataset.studentId, entry.points);
        }
      }
    });
    renderSummary();
    if (undoButton) {
      undoButton.disabled = !day.can_undo;
    }
    main.dataset.dayVersion = String(day.day_version);
    applyFilter();
    updateDialogs();
    rescueFocus(before, beforeCard);
  }

  // The footer save status: dot + text, driven by the save queue state.
  function renderPill(state) {
    if (!pill) {
      return;
    }
    pill.dataset.state = state;
    pill.textContent = '';
    const dot = document.createElement('span');
    dot.className = 'sync-dot';
    dot.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.dataset.saveText = '';
    pill.append(dot, text);
    if (state === 'saving') {
      text.textContent = 'Saving…';
    } else if (state === 'saved') {
      text.textContent = 'Saved';
    } else if (state === 'failed') {
      text.textContent = 'Error — save failed';
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'sync-action';
      retry.textContent = 'Retry';
      retry.addEventListener('click', function () { queue.retry(); });
      pill.append(retry);
    } else if (state === 'expired') {
      text.textContent = 'Error — session expired';
      const link = document.createElement('a');
      link.href = '/login';
      link.textContent = 'Sign in again';
      pill.append(link);
    }
  }

  // ---- save queue --------------------------------------------------------

  function send(op) {
    const controller = new AbortController();
    const timer = setTimeout(function () { controller.abort(); }, 10000);
    return fetch(endpoint + '/operations', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf ? csrf.content : '',
      },
      body: JSON.stringify(op),
      credentials: 'same-origin',
      signal: controller.signal,
    }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (body) {
        clearTimeout(timer);
        return { status: response.status, body: body };
      });
    }, function (error) {
      clearTimeout(timer);
      throw error;
    });
  }

  const queue = globalThis.ClassPulseSaveQueue.createSaveQueue({
    send: send,
    newId: function () {
      // crypto.randomUUID needs Safari/iOS 15.4+ (and a secure context); fall back to getRandomValues.
      if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
      }
      const bytes = crypto.getRandomValues(new Uint8Array(16));
      bytes[6] = (bytes[6] & 0x0f) | 0x40;
      bytes[8] = (bytes[8] & 0x3f) | 0x80;
      const hex = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
      return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    },
    onApply: function (day, remaining) {
      // While later operations are still pending, keep the optimistic screen; the last response syncs it.
      if (remaining === 0) {
        renderDay(day);
      }
    },
    onStateChange: function (state) {
      renderPill(state);
      if (state === 'saved') {
        showError('');
      }
    },
    onError: showError,
  });

  // ---- optimistic updates ------------------------------------------------

  function optimistic(card, action) {
    const status = card.dataset.status;
    const points = cardPoints(card);
    const id = card.dataset.studentId;

    if (action === 'increment' && status !== 'absent' && (points === null || points < maxPoints)) {
      paintCard(card, 'present', (points || 0) + 1);
    } else if (action === 'decrement' && status === 'present' && points > 0) {
      paintCard(card, 'present', points - 1);
    } else if (action === 'set_zero' && status === 'none') {
      paintCard(card, 'present', 0);
    } else if (action === 'absent_on' && status !== 'absent') {
      restorePoints.set(id, status === 'present' ? points : null);
      paintCard(card, 'absent', null);
    } else if (action === 'absent_off' && status === 'absent') {
      // The remembered points are only known when this page session recorded the absence.
      if (restorePoints.has(id)) {
        const restored = restorePoints.get(id);
        paintCard(card, restored === null ? 'none' : 'present', restored);
      }
    }
    renderSummary();
    if (undoButton) {
      undoButton.disabled = false;
    }
    applyFilter();
    updateDialogs();
  }

  function optimisticBatch(kind) {
    cards().forEach(function (card) {
      if (kind === 'reset_day') {
        paintCard(card, 'none', null);
      } else if (kind === 'zero_remaining' && card.dataset.status === 'none') {
        paintCard(card, 'present', 0);
      }
    });
    renderSummary();
    applyFilter();
    updateDialogs();
  }

  // ---- search and filters ------------------------------------------------

  function clearMarks(card) {
    const link = card.querySelector('.student-name');
    if (link && link.querySelector('mark')) {
      link.textContent = link.textContent;
    }
    card.classList.remove('search-match');
  }

  function applySearch() {
    const term = searchInput ? searchInput.value.trim().toLowerCase() : '';
    let matches = 0;
    cards().forEach(function (card) {
      clearMarks(card);
      if (term === '') {
        return;
      }
      const link = card.querySelector('.student-name');
      const text = link.textContent;
      const index = text.toLowerCase().indexOf(term);
      if (index === -1) {
        return;
      }
      matches += 1;
      card.classList.add('search-match');
      const mark = document.createElement('mark');
      mark.textContent = text.slice(index, index + term.length);
      link.textContent = '';
      link.append(text.slice(0, index), mark, text.slice(index + term.length));
    });
    if (searchCount) {
      searchCount.textContent = term === '' ? '' : matches + (matches === 1 ? ' match' : ' matches');
    }
  }

  function applyFilter() {
    let visibleCount = 0;
    cards().forEach(function (card) {
      const visible = format.matchesFilter(activeFilter, card.dataset.status, cardPoints(card));
      card.hidden = !visible;
      if (visible) {
        visibleCount += 1;
      }
    });
    document.querySelectorAll('button[data-filter]').forEach(function (chip) {
      chip.setAttribute('aria-pressed', chip.dataset.filter === activeFilter ? 'true' : 'false');
    });
    const empty = document.getElementById('filter-empty');
    if (empty) {
      empty.hidden = visibleCount > 0;
    }
  }

  // ---- student notes ----------------------------------------------------

  const noteSaves = new Map();

  function noteParts(section) {
    return {
      editor: section.querySelector('[data-note-editor]'),
      input: section.querySelector('[data-note-input]'),
      count: section.querySelector('[data-note-count]'),
      status: section.querySelector('[data-note-status]'),
      save: section.querySelector('[data-note-save]'),
    };
  }

  function noteSection(studentId) {
    return panel ? panel.querySelector('[data-panel-student="' + studentId + '"]') : null;
  }

  function flash(message) {
    if (!noteFlash) {
      return;
    }
    noteFlash.textContent = message || '';
    noteFlash.hidden = !message;
  }

  function setNoteState(section, state, info) {
    const parts = noteParts(section);
    const details = info || {};
    section.dataset.noteState = state;
    if (parts.status) {
      parts.status.textContent = '';
      parts.status.dataset.state = state;
      parts.status.append(noteLib.statusText(state, details));
      if (state === 'error') {
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'sync-action note-retry';
        retry.textContent = 'Retry';
        retry.addEventListener('click', function () { saveNote(section); });
        parts.status.append(' ', retry);
        if (details.expired) {
          const link = document.createElement('a');
          link.href = '/login';
          link.textContent = 'Sign in again';
          parts.status.append(' ', link);
        }
      }
    }
    if (parts.save) {
      const hadFocus = document.activeElement === parts.save;
      parts.save.disabled = state === 'saving' || state === 'idle' || state === 'saved';
      // The Save button disables itself once the note is saved: keep the focus in the note rather than on <body>.
      if (hadFocus && parts.save.disabled && parts.input) {
        parts.input.focus();
      }
    }
  }

  function noteIndicator(studentId, present) {
    const card = document.querySelector('article.student-card[data-student-id="' + studentId + '"]');
    const indicator = card ? card.querySelector('[data-note-indicator]') : null;
    if (indicator) {
      indicator.hidden = !present;
    }
    renderSlip(format.summarize(cards().map(function (c) { return { status: c.dataset.status, points: cardPoints(c) }; })));
  }

  function onNoteInput(section) {
    const parts = noteParts(section);
    const counter = noteLib.counter(parts.input.value.length, noteLib.MAX);
    parts.count.textContent = counter.text;
    parts.count.dataset.level = counter.level;
    const dirty = noteLib.isDirty(parts.input.value, section.dataset.noteSavedText || '');
    const current = section.dataset.noteState || 'idle';
    const next = noteLib.transition(current, 'edit', dirty);
    if (next !== current || current === 'error') {
      setNoteState(section, current === 'error' && dirty ? 'dirty' : next);
    }
  }

  function saveNote(section) {
    const parts = noteParts(section);
    if (!parts.input || section.dataset.noteState === 'saving') {
      return Promise.resolve();
    }
    const sent = parts.input.value.trim();
    const studentId = section.dataset.panelStudent;
    const name = parts.editor.dataset.studentName || 'student';
    setNoteState(section, 'saving');
    const request = fetch(parts.editor.dataset.noteUrl, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf ? csrf.content : '',
      },
      body: JSON.stringify({ body: sent }),
      credentials: 'same-origin',
    }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (body) {
        if (response.ok && body && body.data) {
          section.dataset.noteSavedText = sent;
          if (sent === '' && parts.input.value.trim() === '') {
            parts.input.value = '';
          }
          const dirty = noteLib.isDirty(parts.input.value, sent);
          const time = noteLib.clock(new Date(), slip ? slip.dataset.noteTz : undefined);
          setNoteState(section, noteLib.transition('saving', 'success', dirty), { time: time, cleared: sent === '' });
          onNoteInput(section);
          noteIndicator(studentId, sent !== '');
          flash(sent === '' ? 'Note cleared for ' + name + ' at ' + time + '.' : 'Note saved for ' + name + ' at ' + time + '.');
          return;
        }
        const expired = response.status === 401 || response.status === 419;
        const message = expired ? 'Session expired — note not saved.' : (body && body.error && body.error.message ? 'Not saved: ' + body.error.message : 'Not saved — server error.');
        setNoteState(section, 'error', { message: message, expired: expired });
        flash('Note for ' + name + ' was not saved.');
      });
    }).catch(function () {
      setNoteState(section, 'error', { message: 'Not saved — no connection.' });
      flash('Note for ' + name + ' was not saved.');
    });
    noteSaves.set(studentId, request);
    return request;
  }

  function noteIsDirty(section) {
    const input = section.querySelector('[data-note-input]');
    return !!input && noteLib.isDirty(input.value, section.dataset.noteSavedText || '');
  }

  function autoSaveNote(studentId) {
    const section = noteSection(studentId);
    if (section && noteIsDirty(section) && section.dataset.noteState !== 'saving') {
      flash('Saving note for ' + (noteParts(section).editor.dataset.studentName || 'student') + '…');
      saveNote(section);
    }
  }

  function anyUnsavedNote() {
    return panel ? Array.from(panel.querySelectorAll('[data-panel-student]')).some(function (section) {
      return noteIsDirty(section) || section.dataset.noteState === 'saving' || section.dataset.noteState === 'error';
    }) : false;
  }

  if (panel && noteLib) {
    panel.querySelectorAll('[data-panel-student]').forEach(function (section) {
      const parts = noteParts(section);
      if (!parts.input) {
        return;
      }
      section.dataset.noteSavedText = (parts.editor.dataset.noteSaved || '').trim();
      section.dataset.noteState = 'idle';
      parts.count.textContent = noteLib.counter(parts.input.value.length, noteLib.MAX).text;
      parts.input.addEventListener('input', function () { onNoteInput(section); });
      parts.input.addEventListener('keydown', function (event) {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
          event.preventDefault();
          saveNote(section);
        }
      });
      parts.save.addEventListener('click', function () { saveNote(section); });
    });
  }

  // ---- toolbar: add student, day slip, overflow menu -----------------------

  function openStudentDialog() {
    if (!studentDialog) {
      return;
    }
    if (dayMenu) {
      dayMenu.open = false;
    }
    studentDialog.showModal();
    const field = studentDialog.querySelector('input[name="display_name"]');
    if (field) {
      field.focus();
    }
  }

  document.querySelectorAll('[data-student-dialog]').forEach(function (button) {
    button.addEventListener('click', openStudentDialog);
  });
  if (studentDialog) {
    studentDialog.querySelectorAll('[data-dialog-close]').forEach(function (button) {
      button.addEventListener('click', function () { studentDialog.close(); });
    });
    // Backdrop click closes; clicks inside the form do not reach the dialog element itself.
    studentDialog.addEventListener('click', function (event) {
      if (event.target === studentDialog) {
        studentDialog.close();
      }
    });
    const studentForm = studentDialog.querySelector('form');
    if (studentForm) {
      studentForm.addEventListener('submit', function (event) {
        const name = studentForm.querySelector('input[name="display_name"]');
        if (name && name.value.trim() === '') {
          event.preventDefault();
          name.setAttribute('aria-invalid', 'true');
          name.focus();
        }
      });
    }
    if (studentDialog.hasAttribute('data-open-on-load')) {
      openStudentDialog();
    }
  }

  function printSlip() {
    renderSummary();
    window.print();
  }
  document.querySelectorAll('[data-print-slip]').forEach(function (button) {
    button.addEventListener('click', printSlip);
  });
  window.addEventListener('beforeprint', function () { renderSummary(); });

  if (dayMenu) {
    dayMenu.addEventListener('click', function (event) {
      if (event.target.closest('button[data-dialog]')) {
        dayMenu.open = false;
      }
    });
    document.addEventListener('click', function (event) {
      if (dayMenu.open && !dayMenu.contains(event.target)) {
        dayMenu.open = false;
      }
    });
    // Opening this menu closes the header menus and the other way round (see shell.js).
    dayMenu.addEventListener('toggle', function () {
      if (dayMenu.open) {
        document.dispatchEvent(new CustomEvent('classpulse:menu-open', { detail: dayMenu }));
      }
    });
    document.addEventListener('classpulse:menu-open', function (event) {
      if (event.detail !== dayMenu) {
        dayMenu.open = false;
      }
    });
    dayMenu.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && dayMenu.open) {
        event.stopPropagation();
        dayMenu.open = false;
        const summary = dayMenu.querySelector('summary');
        if (summary) {
          summary.focus();
        }
      }
    });
  }

  // ---- events ------------------------------------------------------------

  main.addEventListener('click', function (event) {
    const button = event.target.closest('button[data-action]');
    if (!button || button.disabled) {
      return;
    }
    const action = button.dataset.action;
    if (!CARD_ACTIONS.includes(action)) {
      return;
    }
    const card = button.closest('article.student-card[data-student-id]');
    if (!card) {
      return;
    }
    showError('');
    optimistic(card, action);
    queue.enqueue(action, Number(card.dataset.studentId));
    rescueFocus(button, card);
  });

  // Clicking a card (its name or empty area) selects it; buttons and links keep their own job.
  main.addEventListener('click', function (event) {
    if (event.target.closest('a') || event.target.closest('button[data-action]')) {
      return;
    }
    const card = event.target.closest('article.student-card[data-student-id]');
    if (card) {
      toggleSelection(card.dataset.studentId);
    }
  });

  function panelIsOpen() {
    return !!panel && panel.classList.contains('is-open');
  }

  if (panelToggle && panel) {
    panelToggle.addEventListener('click', function () {
      const open = !panelIsOpen();
      setPanelOpen(open);
      if (open) {
        panel.focus();
      }
    });
    // The bottom sheet (narrow screens) covers the page, so Tab stays inside it while it is open.
    panel.addEventListener('keydown', function (event) {
      if (panelIsOpen() && window.matchMedia('(max-width: 1100px)').matches && globalThis.ClassPulseDialogs) {
        globalThis.ClassPulseDialogs.trapTab(panel, event);
      }
    });
    const closeButton = panel.querySelector('[data-panel-close]');
    if (closeButton) {
      closeButton.addEventListener('click', function () {
        setPanelOpen(false);
        panelToggle.focus();
      });
    }
  }

  function isTypingTarget(target) {
    if (!target || !target.tagName) {
      return false;
    }
    const tag = target.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable === true;
  }

  document.addEventListener('keydown', function (event) {
    if (document.querySelector('dialog[open]')) {
      return;
    }
    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && searchInput && !isTypingTarget(event.target)) {
      event.preventDefault();
      searchInput.focus();
      searchInput.select();
      return;
    }
    if (event.key !== 'Escape') {
      return;
    }
    if (panelIsOpen()) {
      setPanelOpen(false);
      if (panelToggle) {
        panelToggle.focus();
      }
    } else if (searchInput && (document.activeElement === searchInput || searchInput.value !== '')) {
      searchInput.value = '';
      applySearch();
      searchInput.blur();
    } else if (selectedId !== null) {
      const selectedCard = document.querySelector('article.student-card[data-student-id="' + selectedId + '"]');
      const focusWasInPanel = !!panel && panel.contains(document.activeElement);
      selectStudent(null);
      // The panel section being cleared may hold the focus: hand it back to the card that was selected.
      const name = selectedCard ? selectedCard.querySelector('.student-name') : null;
      if (focusWasInPanel && name) {
        name.focus();
      }
    }
  });

  document.querySelectorAll('button[data-dialog]').forEach(function (button) {
    button.addEventListener('click', function () {
      const dialog = document.getElementById(button.dataset.dialog);
      const kind = dialogs[button.dataset.dialog];
      if (!dialog || !kind || !globalThis.ClassPulseDialogs) {
        return;
      }
      updateDialogs();
      // The menu closes when a dialog opens, so its item cannot take the focus back: return it to the menu button.
      const opener = dayMenu && dayMenu.contains(button) ? dayMenu.querySelector('summary') : button;
      globalThis.ClassPulseDialogs.confirm(dialog).then(function (confirmed) {
        if (opener && opener.isConnected) {
          opener.focus();
        }
        if (confirmed) {
          showError('');
          optimisticBatch(kind);
          queue.enqueue(kind);
        }
      });
    });
  });

  if (undoButton) {
    undoButton.addEventListener('click', function () {
      showError('');
      queue.enqueue('undo');
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', applySearch);
    searchInput.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') {
        return;
      }
      event.preventDefault();
      const first = document.querySelector('article.student-card.search-match .student-name');
      if (first) {
        first.focus();
      }
    });
  }

  document.querySelectorAll('button[data-filter]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      activeFilter = chip.dataset.filter;
      applyFilter();
    });
  });

  window.addEventListener('beforeunload', function (event) {
    if (globalThis.ClassPulseSaveQueue.shouldWarnBeforeUnload(queue) || anyUnsavedNote()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  // ---- initial load ------------------------------------------------------

  fetch(endpoint, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
    .then(function (response) {
      if (response.status === 401 || response.status === 419) {
        renderPill('expired');
        return null;
      }
      return response.ok ? response.json() : null;
    })
    .then(function (body) {
      if (body && body.data) {
        queue.applyServerState(body.data);
      }
    })
    .catch(function () {
      // The server-rendered page stays usable; saving reports its own failures.
    });

  applyFilter();
  updateDialogs();
}

initDailyTracker();
