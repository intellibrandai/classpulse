// Pure helpers for Semester Analytics: search, sort, rank, "Show more" paging, selection state, date labels and the
// report card comment template. No DOM, no network, no totals: every number comes from the server as text or data.
// UMD footer so tests/js can require() it; the browser gets globalThis.ClassPulseSemester.
(function () {
  var SORTS = {
    'name-asc': { metric: null, label: null },
    'total-desc': { metric: 'total', label: 'Total points' },
    'avg-desc': { metric: 'avg', label: 'Mean per present day' },
    'absences-desc': { metric: 'absences', label: 'Absences' },
    'present-desc': { metric: 'present', label: 'Present days' },
  };

  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  function normalize(text) {
    return String(text || '').trim().toLowerCase().replace(/\s+/g, ' ');
  }

  // Every word of the query must appear in the row's search text (name, preferred name, number).
  function matches(searchText, query) {
    var words = normalize(query).split(' ').filter(Boolean);
    var haystack = normalize(searchText);
    return words.every(function (word) {
      return haystack.indexOf(word) !== -1;
    });
  }

  function metricValue(row, metric) {
    var value = row[metric];
    return typeof value === 'number' && !Number.isNaN(value) ? value : null;
  }

  // rows: [{id, name, order, total, avg, absences, present}]; avg is null for "No data". Returns a new array.
  // Metric sorts are descending; ties and missing values fall back to the server's A-Z order.
  function sortRows(rows, key) {
    var sort = SORTS[key] || SORTS['name-asc'];
    var copy = rows.slice();
    copy.sort(function (a, b) {
      if (sort.metric !== null) {
        var av = metricValue(a, sort.metric);
        var bv = metricValue(b, sort.metric);
        if (av === null && bv !== null) {
          return 1;
        }
        if (bv === null && av !== null) {
          return -1;
        }
        if (av !== null && bv !== null && av !== bv) {
          return bv - av;
        }
      }
      return a.order - b.order;
    });
    return copy;
  }

  // Rank by the chosen metric over ALL rows (a search does not renumber): equal values share a rank (1, 1, 3);
  // rows without a value get none. Returns {} for the name sort: no rank is shown unless a metric was chosen.
  function ranks(rows, key) {
    var sort = SORTS[key] || SORTS['name-asc'];
    var out = {};
    if (sort.metric === null) {
      return out;
    }
    var ordered = sortRows(rows, key);
    var previous = null;
    var rank = 0;
    ordered.forEach(function (row, index) {
      var value = metricValue(row, sort.metric);
      if (value === null) {
        return;
      }
      if (previous === null || value !== previous) {
        rank = index + 1;
        previous = value;
      }
      out[row.id] = rank;
    });
    return out;
  }

  function rankLabel(key) {
    var sort = SORTS[key] || SORTS['name-asc'];
    return sort.label === null ? null : 'Rank by ' + sort.label;
  }

  function sortLabel(key) {
    var sort = SORTS[key] || SORTS['name-asc'];
    return sort.label;
  }

  function clampShown(shown, matched, pageSize) {
    var start = shown > 0 ? shown : pageSize;
    return Math.min(start, matched);
  }

  function showMore(shown, matched, pageSize) {
    return Math.min(shown + pageSize, matched);
  }

  function showingText(shown, matched, total) {
    var noun = function (n) {
      return n === 1 ? 'student' : 'students';
    };
    if (matched === total) {
      return 'Showing ' + shown + ' of ' + total + ' ' + noun(total);
    }
    return 'Showing ' + shown + ' of ' + matched + ' matching ' + noun(matched) + ' (' + total + ' in this class)';
  }

  // Selection state: one selected student id or null. Esc clears it.
  function selectionReduce(state, action) {
    if (action.type === 'select') {
      return { id: String(action.id) };
    }
    if (action.type === 'clear') {
      return { id: null };
    }
    return state;
  }

  function parseIso(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
    if (!m) {
      return null;
    }
    var date = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    return date.getUTCFullYear() === +m[1] && date.getUTCMonth() === +m[2] - 1 && date.getUTCDate() === +m[3] ? date : null;
  }

  // "Oct 14, 2026" and "Wed" from a Y-m-d string (no time zones involved).
  function formatDate(iso) {
    var date = parseIso(iso);
    return date ? MONTHS[date.getUTCMonth()] + ' ' + date.getUTCDate() + ', ' + date.getUTCFullYear() : String(iso || '');
  }

  function weekday(iso) {
    var date = parseIso(iso);
    return date ? WEEKDAYS[date.getUTCDay()] : '';
  }

  function inPeriod(date, from, to) {
    if (!from || !to) {
      return true;
    }
    return date >= from && date <= to;
  }

  // Notes newest first; one note per date (the last one given wins).
  function mergeNote(notes, note) {
    var rest = notes.filter(function (n) {
      return n.date !== note.date;
    });
    rest.push({ date: note.date, body: note.body });
    rest.sort(function (a, b) {
      return a.date < b.date ? 1 : (a.date > b.date ? -1 : 0);
    });
    return rest;
  }

  function removeNote(notes, date) {
    return notes.filter(function (n) {
      return n.date !== date;
    });
  }

  function hasNoteOn(notes, date) {
    return notes.some(function (n) {
      return n.date === date;
    });
  }

  function excerpt(text, max) {
    var flat = String(text || '').replace(/\s+/g, ' ').trim();
    var limit = max || 160;
    if (flat.length <= limit) {
      return flat;
    }
    return flat.slice(0, limit - 1).replace(/\s+\S*$/, '').replace(/[.,;:\s]+$/, '') + '…';
  }

  function plural(n, one, many) {
    return n + ' ' + (Number(n) === 1 ? one : many);
  }

  // A neutral, editable draft built only from the period's real numbers and the teacher's own saved notes.
  // info: {name, preferred, total, present, average (text, '' when none), periodLabel, notes: [{label, body}]}
  function buildComment(info, options) {
    var settings = options || {};
    var maxNotes = settings.maxNotes || 3;
    var maxChars = settings.maxChars || 160;
    var who = info.preferred || info.name;
    var present = Number(info.present) || 0;
    var text;
    if (present === 0) {
      text = who + ' has no present days recorded in ' + info.periodLabel + ' yet.';
    } else {
      text = who + ' recorded ' + plural(Number(info.total) || 0, 'participation point', 'participation points') +
        ' over ' + plural(present, 'present day', 'present days') + ' (average ' + info.average + ') in ' + info.periodLabel + '.';
    }
    var notes = (info.notes || []).slice(0, maxNotes).map(function (note) {
      return note.label + ': ' + excerpt(note.body, maxChars);
    });
    if (notes.length > 0) {
      text += ' Notes: ' + notes.join(' ');
    }
    return text;
  }

  // Plain text for "Copy all": name line, then the (possibly edited) comment, blank line between students.
  function joinAll(items) {
    return items
      .filter(function (item) {
        return String(item.text || '').trim() !== '';
      })
      .map(function (item) {
        return item.name + '\n' + String(item.text).trim();
      })
      .join('\n\n');
  }

  // ---- report comment draft state machine ------------------------------------------------------
  // state: {source: 'template'|'saved', text, baseline, updatedAt, save: 'idle'|'dirty'|'saving'|'saved'|'error'}
  // baseline = what the server would show for this student now (the saved body, or the generated template).

  // Merge of the generated template with the saved draft: a saved body (non blank) always wins.
  function draftInit(saved, template) {
    if (saved && String(saved.body || '').trim() !== '') {
      return { source: 'saved', text: saved.body, baseline: saved.body, updatedAt: saved.updated_at || null, save: 'saved' };
    }
    return { source: 'template', text: template, baseline: template, updatedAt: null, save: 'idle' };
  }

  function isDirty(state) {
    return state.text.trim() !== String(state.baseline).trim();
  }

  // "Reset to template" is pointless only while the draft is the untouched template. It must stay enabled while an
  // edit is being saved (the save starts as soon as the focus leaves the text, e.g. on the way to this very button).
  function resetDisabled(state) {
    return state.source === 'template' && !isDirty(state);
  }

  // actions: {type: 'input', text} | 'save' | {type: 'saved', draft|null, template} | 'failed' | {type: 'reset', template} |
  //          {type: 'template', template} (the notes changed: a still-untouched template is regenerated)
  function draftReduce(state, action) {
    var next = {};
    Object.keys(state).forEach(function (key) {
      next[key] = state[key];
    });
    switch (action.type) {
      case 'input':
        next.text = String(action.text);
        if (next.save !== 'saving') {
          next.save = isDirty(next) ? 'dirty' : (next.source === 'saved' ? 'saved' : 'idle');
        }
        return next;
      case 'save':
        next.save = 'saving';
        return next;
      case 'saved':
        if (action.draft) {
          next.source = 'saved';
          next.baseline = action.draft.body;
          next.updatedAt = action.draft.updated_at || null;
        } else {
          // A blank text deletes the draft: back to the generated template.
          next.source = 'template';
          next.baseline = action.template;
          next.updatedAt = null;
          if (next.text.trim() === '') {
            next.text = action.template;
          }
        }
        next.save = isDirty(next) ? 'dirty' : (next.source === 'saved' ? 'saved' : 'idle');
        return next;
      case 'failed':
        next.save = 'error';
        return next;
      case 'reset':
        return { source: 'template', text: action.template, baseline: action.template, updatedAt: null, save: 'idle' };
      case 'template':
        if (next.source === 'template' && next.save === 'idle') {
          next.text = action.template;
          next.baseline = action.template;
        }
        return next;
      default:
        return state;
    }
  }

  // What to send: null when nothing needs saving, {method: 'PUT', body} for text, {method: 'DELETE'} for a blank text.
  function draftRequest(state) {
    if (state.save === 'saving' || !isDirty(state)) {
      return null;
    }
    var body = state.text.trim();
    return body === '' ? { method: 'DELETE' } : { method: 'PUT', body: body };
  }

  function draftBadge(state) {
    return state.source === 'saved' ? 'Saved draft' : 'Template';
  }

  function draftStatusText(state) {
    return { idle: '', dirty: 'Unsaved changes', saving: 'Saving…', saved: 'Saved', error: 'Save failed' }[state.save] || '';
  }

  // "Oct 19, 2:45 PM" in the school timezone, from an ISO-8601 timestamp; '' when unknown.
  function formatStamp(iso) {
    if (!iso) {
      return '';
    }
    var date = new Date(iso);
    if (isNaN(date.getTime())) {
      return '';
    }
    return date.toLocaleString('en-CA', { timeZone: 'America/Toronto', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })
      .replace(/ /g, ' ').replace(/\s*([ap])\.m\./i, function (_m, l) { return ' ' + l.toUpperCase() + 'M'; });
  }

  var api = {
    SORTS: SORTS,
    normalize: normalize,
    matches: matches,
    sortRows: sortRows,
    ranks: ranks,
    rankLabel: rankLabel,
    sortLabel: sortLabel,
    clampShown: clampShown,
    showMore: showMore,
    showingText: showingText,
    selectionReduce: selectionReduce,
    formatDate: formatDate,
    weekday: weekday,
    inPeriod: inPeriod,
    mergeNote: mergeNote,
    removeNote: removeNote,
    hasNoteOn: hasNoteOn,
    excerpt: excerpt,
    buildComment: buildComment,
    joinAll: joinAll,
    draftInit: draftInit,
    draftReduce: draftReduce,
    resetDisabled: resetDisabled,
    draftRequest: draftRequest,
    draftBadge: draftBadge,
    draftStatusText: draftStatusText,
    formatStamp: formatStamp,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  } else {
    globalThis.ClassPulseSemester = api;
  }
})();
