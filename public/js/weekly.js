// Weekly Matrix wiring: search and sort, quick cell editor (popover), save queues per date, undo across dates,
// Copy Summary and Print. Every number shown comes from the server (server-rendered page or the week JSON).
(function () {
  const root = document.querySelector('[data-weekly]');
  const lib = globalThis.ClassPulseWeekly;
  const SaveQueue = globalThis.ClassPulseSaveQueue;
  if (!root || !lib || !SaveQueue) {
    return;
  }

  const weekApi = root.dataset.weekApi;
  const dayApi = root.dataset.dayApi;
  const csrf = document.querySelector('meta[name="csrf-token"]');
  const pill = document.getElementById('save-pill');
  const undoButton = document.getElementById('undo-btn');
  const weekError = document.getElementById('week-error');
  const searchInput = root.querySelector('[data-matrix-search]');
  const sortSelect = root.querySelector('[data-matrix-sort]');
  const counter = root.querySelector('[data-matrix-count]');
  const body = root.querySelector('[data-matrix-body]');
  const noMatch = root.querySelector('[data-no-match]');
  const editor = root.querySelector('[data-cell-editor]');
  const editorTitle = editor.querySelector('[data-editor-title]');
  const editorState = editor.querySelector('[data-editor-state]');
  const editorInput = editor.querySelector('[data-editor-input]');
  const editorError = editor.querySelector('[data-editor-error]');
  const summarySource = root.querySelector('[data-summary-source]');
  const copyButton = root.querySelector('[data-copy-summary]');
  const copyLabel = root.querySelector('[data-copy-label]');
  const copyStatus = root.querySelector('[data-copy-status]');

  const undoStack = lib.createUndoStack(20);
  const queues = new Map();
  const queueStates = new Map();
  let dirty = false;
  let refreshSeq = 0;
  let sortDirty = false;
  let inputDirty = false;
  let pop = lib.popoverInitial();
  let currentButton = null;

  // ---- small helpers -----------------------------------------------------------

  function q(selector, scope) {
    return (scope || root).querySelector(selector);
  }

  function setText(selector, text, scope) {
    const node = q(selector, scope);
    if (node) {
      node.textContent = text;
    }
    return node;
  }

  function rows() {
    return Array.from(body.querySelectorAll('tr[data-student-row]'));
  }

  function rowName(row) {
    const link = row.querySelector('[data-student-name]');
    return link ? link.textContent.trim() : '';
  }

  function showError(message) {
    if (!message) {
      weekError.hidden = true;
      weekError.textContent = '';
      return;
    }
    if (pop.open) {
      editorError.textContent = message;
      editorError.hidden = false;
      return;
    }
    weekError.textContent = message;
    weekError.hidden = false;
  }

  function clearEditorError() {
    editorError.hidden = true;
    editorError.textContent = '';
  }

  function cellsOf(studentId, date) {
    return root.querySelector('[data-cell][data-student-id="' + studentId + '"][data-date="' + date + '"]');
  }

  function cellModel(button) {
    const points = parseInt(button.dataset.points, 10);
    return {
      studentId: parseInt(button.dataset.studentId, 10),
      date: button.dataset.date,
      status: button.dataset.status,
      points: Number.isNaN(points) ? null : points,
    };
  }

  // ---- painting (values come from the server) --------------------------------------

  function paintCell(button, status, points) {
    const row = button.closest('tr');
    const name = row ? rowName(row) : '';
    button.dataset.status = status;
    button.dataset.points = status === 'present' ? String(points) : '';
    button.classList.remove('heat-0', 'heat-low', 'heat-mid', 'heat-high', 'heat-absent');
    const heat = lib.heatClass(status, points);
    if (heat) {
      button.classList.add(heat);
    }
    button.classList.toggle('wm-cell-none', status === 'none');
    const text = button.querySelector('.wm-cell-text');
    if (text) {
      text.textContent = lib.cellText(status, points);
    }
    button.setAttribute('aria-label', lib.cellLabel(name, button.dataset.when || '', status, points, 'Open editor'));
    button.title = lib.stateText(status, points);
  }

  function paintDay(date, day) {
    day.entries.forEach(function (entry) {
      const button = cellsOf(entry.student_id, date);
      if (button && !button.disabled) {
        paintCell(button, entry.status, entry.points);
      }
    });
    syncEditor();
  }

  function paintRow(row, data) {
    data.cells.forEach(function (cell) {
      const button = row.querySelector('[data-cell][data-date="' + cell.date + '"]');
      if (button && !button.disabled) {
        paintCell(button, cell.status, cell.points);
      }
    });
    setText('[data-row-total]', data.total_text, row);
    setText('[data-row-avg]', data.average_text, row);
    setText('[data-row-avg-sub]', data.average_sub || '', row);
    const badge = setText('[data-row-absences]', data.absences_text, row);
    if (badge) {
      badge.classList.toggle('ui-badge-absent', data.absences > 0);
      badge.classList.toggle('ui-badge-zero', data.absences === 0);
    }
    row.dataset.total = String(data.total_points);
    row.dataset.avg = data.average === null ? '' : String(data.average);
    row.dataset.absences = String(data.absences);
  }

  function paintChart(name, node) {
    const holder = q('[data-kpi-chart="' + name + '"]');
    if (holder) {
      holder.replaceChildren(lib.materialize(document, node));
    }
  }

  function paintKpis(kpis) {
    const average = kpis.average;
    setText('[data-kpi-value="average"]', average.value);
    const averageValue = q('[data-kpi-value="average"]').closest('.ui-kpi-value');
    averageValue.classList.toggle('is-empty', !average.has_data);
    q('[data-kpi-unit]').hidden = !average.has_data;
    paintChart('average', lib.lineChartNode(average.chart));
    const delta = q('[data-kpi-delta]');
    delta.hidden = average.delta === null;
    if (average.delta !== null) {
      setText('[data-delta-text]', average.delta.text);
      setText('[data-delta-label]', average.delta.label);
      delta.classList.toggle('is-up', average.delta.direction === 'up');
      delta.classList.toggle('is-down', average.delta.direction === 'down');
      delta.querySelectorAll('[data-delta-icon]').forEach(function (icon) {
        icon.hidden = icon.dataset.deltaIcon !== average.delta.direction;
      });
    }

    setText('[data-kpi-value="total"]', String(kpis.total_points.value));
    setText('[data-kpi-total-sub]', kpis.total_points.sub);
    paintChart('total', lib.barChartNode(kpis.total_points.chart));

    const attendance = kpis.attendance;
    setText('[data-kpi-value="rate"]', attendance.rate_text);
    q('[data-kpi-value="rate"]').closest('.ui-kpi-value').classList.toggle('is-empty', !attendance.has_data);
    const progress = q('[data-att-progress]');
    progress.hidden = !attendance.has_data;
    progress.value = attendance.percent === null ? 0 : attendance.percent;
    setText('[data-att-detail]', attendance.detail);
    setText('[data-att-not]', attendance.not_recorded_text);

    const peak = kpis.peak;
    setText('[data-peak-day]', peak === null ? 'No data' : peak.weekday);
    q('[data-peak-day]').closest('.ui-kpi-value').classList.toggle('is-empty', peak === null);
    const badge = q('[data-peak-points]');
    const sub = q('[data-peak-sub]');
    badge.hidden = peak === null;
    sub.hidden = peak === null;
    if (peak !== null) {
      badge.textContent = peak.points_text;
      sub.textContent = peak.present_text;
    }
  }

  function paintWeek(data) {
    const byId = new Map();
    rows().forEach(function (row) { byId.set(row.dataset.studentId, row); });
    data.students.forEach(function (student) {
      const row = byId.get(String(student.id));
      if (row) {
        paintRow(row, student);
      }
    });
    paintKpis(data.kpis);
    setText('[data-foot="total"]', String(data.footer.total_points));
    setText('[data-foot="average"]', data.footer.average_text);
    setText('[data-foot="absences"]', String(data.footer.absences));
    if (summarySource) {
      summarySource.textContent = data.summary_text;
    }
    const printList = q('[data-print-summary]');
    if (printList) {
      printList.replaceChildren();
      lib.summaryLines(data.summary_text).forEach(function (line) {
        const item = document.createElement('li');
        item.textContent = line;
        printList.append(item);
      });
    }
    syncEditor();
    if (!pop.open) {
      applyView();
    } else {
      sortDirty = true;
    }
  }

  function refreshWeek() {
    const seq = ++refreshSeq;
    dirty = false;
    return fetch(weekApi, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (response) {
        return response.json().catch(function () { return null; }).then(function (json) {
          if (seq !== refreshSeq) {
            return;
          }
          if (response.ok && json && json.data) {
            paintWeek(json.data);
          } else {
            showError('Totals could not be refreshed. Reload the page to see the latest numbers.');
          }
        });
      })
      .catch(function () {
        if (seq === refreshSeq) {
          showError('Totals could not be refreshed. Check the connection and reload.');
        }
      });
  }

  // ---- search and sort ---------------------------------------------------------------

  function applyView() {
    const term = searchInput ? searchInput.value : '';
    const all = rows();
    const data = all.map(function (row, index) {
      const avg = row.dataset.avg;
      return {
        order: parseInt(row.dataset.order, 10) || index,
        name: rowName(row),
        total: parseFloat(row.dataset.total) || 0,
        avg: avg === '' || avg === undefined ? null : parseFloat(avg),
        absences: parseInt(row.dataset.absences, 10) || 0,
        row: row,
      };
    });
    const sorted = lib.sortRows(data, sortSelect ? sortSelect.value : 'name-asc');
    let visible = 0;
    let activeTotal = 0;
    const inPlace = sorted.every(function (item, index) { return item.row === all[index]; });
    sorted.forEach(function (item) {
      if (!inPlace) {
        body.insertBefore(item.row, noMatch);
      }
      const match = lib.matchesName(item.name, term);
      item.row.hidden = !match;
      if (item.row.dataset.archived !== '1') {
        activeTotal += 1;
        if (match) {
          visible += 1;
        }
      }
    });
    const shown = sorted.filter(function (item) { return !item.row.hidden; }).length;
    noMatch.hidden = shown > 0 || all.length === 0;
    if (counter) {
      const info = lib.countText(visible, activeTotal, term);
      const target = counter.querySelector('[data-count-text]');
      const strong = document.createElement('strong');
      strong.textContent = info.parts[1];
      target.replaceChildren(info.parts[0], strong, info.parts[2]);
    }
    sortDirty = false;
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyView);
    searchInput.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        if (searchInput.value !== '') {
          searchInput.value = '';
          applyView();
        } else {
          searchInput.blur();
        }
      }
    });
  }
  if (sortSelect) {
    sortSelect.addEventListener('change', applyView);
  }

  // ---- save queues (one per date, so each keeps its own day version) -----------------------

  function newId() {
    if (globalThis.crypto && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID();
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
  }

  function send(date, op) {
    const controller = new AbortController();
    const timer = setTimeout(function () { controller.abort(); }, 10000);
    return fetch(dayApi + '/' + date + '/operations', {
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
      return response.json().catch(function () { return null; }).then(function (json) {
        clearTimeout(timer);
        return { status: response.status, body: json };
      });
    }, function (error) {
      clearTimeout(timer);
      throw error;
    });
  }

  function allIdle() {
    return Array.from(queues.values()).every(function (queue) { return queue.pendingCount() === 0; });
  }

  function queueFor(date) {
    if (queues.has(date)) {
      return queues.get(date);
    }
    const queue = SaveQueue.createSaveQueue({
      send: function (op) { return send(date, op); },
      newId: newId,
      onApply: function (day) {
        dirty = true;
        paintDay(date, day);
      },
      onStateChange: function (state) {
        queueStates.set(date, state);
        renderPill();
        if (state === 'saved' && dirty && allIdle()) {
          refreshWeek();
        }
      },
      onError: function (message, op) {
        if (op && op.op_id) {
          undoStack.discard(op.op_id);
          renderUndo();
        }
        showError(message);
      },
    });
    queues.set(date, queue);
    return queue;
  }

  function renderPill() {
    if (!pill) {
      return;
    }
    const state = lib.aggregateSaveState(Array.from(queueStates.values()));
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
      retry.addEventListener('click', function () {
        queues.forEach(function (queue) { queue.retry(); });
      });
      pill.append(retry);
    } else {
      text.textContent = 'Error — session expired';
      const link = document.createElement('a');
      link.href = '/login';
      link.textContent = 'Sign in again';
      pill.append(link);
    }
  }

  function renderUndo() {
    if (undoButton) {
      undoButton.disabled = undoStack.size() === 0;
    }
  }

  function enqueueEdit(date, op) {
    clearEditorError();
    showError('');
    const queue = queueFor(date);
    const extra = typeof op.points === 'number' ? { points: op.points } : undefined;
    const sent = queue.enqueue(op.kind, op.student_id, extra);
    undoStack.push(date, sent.op_id);
    renderUndo();
  }

  if (undoButton) {
    undoButton.addEventListener('click', function () {
      const date = undoStack.pop();
      renderUndo();
      if (date === undefined) {
        return;
      }
      showError('');
      queueFor(date).enqueue('undo');
    });
  }

  window.addEventListener('beforeunload', function (event) {
    if (!allIdle()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  // ---- quick editor popover ---------------------------------------------------------------

  function editorControls() {
    return Array.from(editor.querySelectorAll('button, input')).filter(function (node) {
      return !node.disabled && !node.hidden && !node.closest('[hidden]');
    });
  }

  function syncEditor() {
    if (!pop.open || !currentButton) {
      return;
    }
    const model = cellModel(currentButton);
    const view = lib.editorState(model.status, model.points);
    const row = currentButton.closest('tr');
    editorTitle.textContent = 'Edit ' + rowName(row) + ' · ' + currentButton.dataset.when;
    editorState.textContent = view.text + (view.hint ? '. ' + view.hint : '');
    editor.dataset.status = model.status;
    const actions = {
      decrement: view.minusDisabled,
      increment: view.plusDisabled,
    };
    editor.querySelectorAll('[data-editor-action]').forEach(function (button) {
      const action = button.dataset.editorAction;
      if (action in actions) {
        button.disabled = actions[action];
      } else if (action === 'set_points') {
        button.disabled = !view.pointsEnabled;
      }
    });
    editor.querySelector('[data-editor-action="set_zero"]').hidden = !view.showRecordZero;
    editor.querySelector('[data-editor-action="absent_on"]').hidden = !view.showMarkAbsent;
    editor.querySelector('[data-editor-action="absent_off"]').hidden = !view.showMarkPresent;
    editor.querySelector('[data-editor-action="clear"]').hidden = !view.showClear;
    editorInput.disabled = !view.pointsEnabled;
    if (!inputDirty) {
      editorInput.value = view.inputValue;
    }
    // A control that just became disabled or hidden must not keep the focus.
    const active = document.activeElement;
    if (active && editor.contains(active) && (active.disabled || active.closest('[hidden]'))) {
      const next = editorControls()[0];
      if (next) {
        next.focus();
      }
    }
    positionEditor();
  }

  function positionEditor() {
    if (!pop.open || !currentButton) {
      return;
    }
    if (window.matchMedia('(max-width: 768px)').matches) {
      editor.style.removeProperty('--wm-left');
      editor.style.removeProperty('--wm-top');
      return;
    }
    const rect = currentButton.getBoundingClientRect();
    const width = editor.offsetWidth || 300;
    const height = editor.offsetHeight || 220;
    const left = Math.min(Math.max(8, rect.left + rect.width / 2 - width / 2), window.innerWidth - width - 8);
    let top = rect.bottom + 8;
    if (top + height > window.innerHeight - 8) {
      top = rect.top - height - 8;
    }
    top = Math.max(8, top);
    editor.style.setProperty('--wm-left', Math.round(left) + 'px');
    editor.style.setProperty('--wm-top', Math.round(top) + 'px');
  }

  function dispatch(event) {
    const wasOpen = pop.open;
    pop = lib.popoverReduce(pop, event);
    if (pop.open) {
      editor.hidden = false;
      if (!wasOpen || (event.type === 'open')) {
        inputDirty = false;
        clearEditorError();
      }
      root.querySelectorAll('[data-cell].is-editing').forEach(function (node) { node.classList.remove('is-editing'); });
      currentButton.classList.add('is-editing');
      syncEditor();
      if (event.type === 'open') {
        if (window.matchMedia('(max-width: 768px)').matches) {
          currentButton.scrollIntoView({ block: 'center', inline: 'nearest' });
        }
        const first = editorInput.disabled ? editorControls()[0] : editorInput;
        if (first) {
          first.focus();
          if (first === editorInput) {
            editorInput.select();
          }
        }
        positionEditor();
      }
    } else {
      editor.hidden = true;
      root.querySelectorAll('[data-cell].is-editing').forEach(function (node) { node.classList.remove('is-editing'); });
      const back = currentButton;
      currentButton = null;
      inputDirty = false;
      clearEditorError();
      // Re-sort first: moving a row would drop the focus we are about to restore.
      if (sortDirty) {
        applyView();
      }
      if (wasOpen && pop.returnFocus && back) {
        back.focus();
      }
    }
  }

  root.addEventListener('click', function (event) {
    const button = event.target.closest('[data-cell]');
    if (!button || button.disabled) {
      return;
    }
    currentButton = button;
    dispatch({ type: 'open', target: { studentId: button.dataset.studentId, date: button.dataset.date } });
  });

  document.addEventListener('click', function (event) {
    if (pop.open && !editor.contains(event.target) && !event.target.closest('[data-cell]')) {
      dispatch({ type: 'outside' });
    }
  });

  editor.querySelector('[data-editor-close]').addEventListener('click', function () {
    dispatch({ type: 'close' });
  });

  editor.addEventListener('click', function (event) {
    const button = event.target.closest('[data-editor-action]');
    if (!button || button.disabled || !currentButton) {
      return;
    }
    runAction(button.dataset.editorAction);
  });

  function runAction(action) {
    const model = cellModel(currentButton);
    const plan = lib.planAction(model, action, editorInput.value);
    if (plan.error) {
      editorError.textContent = plan.error;
      editorError.hidden = false;
      return;
    }
    if (plan.noop) {
      return;
    }
    inputDirty = false;
    enqueueEdit(model.date, plan.op);
  }

  editorInput.addEventListener('input', function () {
    inputDirty = true;
    clearEditorError();
  });
  editorInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      runAction('set_points');
    }
  });

  editor.addEventListener('keydown', function (event) {
    if (event.key === 'Tab') {
      const list = editorControls();
      const next = lib.trapIndex(list.indexOf(document.activeElement), list.length, event.shiftKey);
      if (next !== -1) {
        event.preventDefault();
        list[next].focus();
      }
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && pop.open) {
      event.preventDefault();
      dispatch({ type: 'escape' });
      return;
    }
    if (event.key === 'Escape') {
      const tip = document.activeElement && document.activeElement.closest('.info-tip');
      if (tip) {
        tip.classList.add('is-dismissed');
      }
      return;
    }
    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && searchInput && !pop.open) {
      const target = event.target;
      const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
      if (!typing) {
        event.preventDefault();
        searchInput.focus();
        searchInput.select();
      }
    }
  });

  window.addEventListener('resize', positionEditor);
  window.addEventListener('scroll', positionEditor, true);

  // Tooltips: hover and focus show them (CSS); Esc hides until the pointer or focus leaves.
  document.querySelectorAll('.info-tip').forEach(function (tip) {
    const reset = function () { tip.classList.remove('is-dismissed'); };
    tip.addEventListener('mouseleave', reset);
    tip.addEventListener('focusout', reset);
  });

  // ---- Copy Summary and Print ------------------------------------------------------------------

  function fallbackCopy(text) {
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
    } catch (error) {
      ok = false;
    }
    area.remove();
    if (previous && previous.focus) {
      previous.focus();
    }
    return ok;
  }

  let copyTimer = null;
  if (copyButton) {
    copyButton.addEventListener('click', function () {
      const text = summarySource ? summarySource.textContent : '';
      lib.copyText(text, { clipboard: navigator.clipboard || null, fallback: fallbackCopy }).then(function (how) {
        const ok = how !== 'failed';
        copyLabel.textContent = ok ? 'Copied' : 'Copy failed';
        copyStatus.textContent = ok ? 'Weekly summary copied to the clipboard.' : 'The summary could not be copied. Select the text manually.';
        copyButton.classList.toggle('is-copied', ok);
        clearTimeout(copyTimer);
        copyTimer = setTimeout(function () {
          copyLabel.textContent = 'Copy Summary';
          copyButton.classList.remove('is-copied');
        }, 2200);
      });
    });
  }

  root.querySelectorAll('[data-print-week]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (pop.open) {
        dispatch({ type: 'close' });
      }
      window.print();
    });
  });

  applyView();
  renderUndo();
})();
