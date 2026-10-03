// Pure helpers for the Daily Tracker: status text, card state, panel text and summary counts.
// UMD footer so tests/js can require() it; the browser gets globalThis.ClassPulseDailyFormat.
(function () {
  function pointsLabel(points) {
    return points + (points === 1 ? ' pt' : ' pts');
  }

  // Domain status: 'none' | 'present' | 'absent'.
  function statusText(status, points) {
    if (status === 'absent') {
      return 'Absent';
    }
    if (status === 'present') {
      return points === 0 ? 'Present · 0' : 'Present';
    }
    return 'Not recorded';
  }

  // Visual state used by the card styles: 'untouched' | 'zero' | 'present' | 'absent'.
  function cardState(status, points) {
    if (status === 'absent') {
      return 'absent';
    }
    if (status === 'present') {
      return points === 0 ? 'zero' : 'present';
    }
    return 'untouched';
  }

  var BADGE_CLASSES = {
    untouched: 'badge-untouched',
    zero: 'badge-zero',
    present: 'badge-active',
    absent: 'badge-absent',
  };

  function badgeClass(state) {
    return BADGE_CLASSES[state] || BADGE_CLASSES.untouched;
  }

  // Sub line of the inspector header, e.g. "Present · 3 pts today".
  function panelSubText(status, points) {
    if (status === 'absent') {
      return 'Absent today';
    }
    if (status === 'present') {
      return 'Present · ' + pointsLabel(points) + ' today';
    }
    return 'Not recorded today';
  }

  // Value shown in a week-breakdown row for the mirrored day.
  function dayValueText(status, points) {
    if (status === 'present') {
      return points === 0 ? 'Present · 0' : pointsLabel(points);
    }
    return status === 'absent' ? 'Absent' : 'Not recorded';
  }

  // Counts for the KPI banner from a list of { status, points }.
  function summarize(items) {
    var present = 0;
    var absent = 0;
    var none = 0;
    var total = 0;
    items.forEach(function (item) {
      if (item.status === 'present') {
        present += 1;
        total += item.points || 0;
      } else if (item.status === 'absent') {
        absent += 1;
      } else {
        none += 1;
      }
    });
    return {
      total: total,
      present: present,
      absent: absent,
      none: none,
      active: items.length,
      mean: present === 0 ? null : total / present,
    };
  }

  // Filter chips: all | active (present, points > 0) | zero (present, 0 points) | absent | none (not recorded).
  function matchesFilter(filter, status, points) {
    if (filter === 'active') {
      return status === 'present' && (points || 0) > 0;
    }
    if (filter === 'zero') {
      return status === 'present' && (points || 0) === 0;
    }
    if (filter === 'absent') {
      return status === 'absent';
    }
    if (filter === 'none') {
      return status !== 'present' && status !== 'absent';
    }
    return true;
  }

  // Live chip counters from a list of { status, points }.
  function filterCounts(items) {
    var counts = { all: 0, active: 0, zero: 0, absent: 0, none: 0 };
    items.forEach(function (item) {
      counts.all += 1;
      ['active', 'zero', 'absent', 'none'].forEach(function (filter) {
        if (matchesFilter(filter, item.status, item.points)) {
          counts[filter] += 1;
        }
      });
    });
    return counts;
  }

  var api = {
    matchesFilter: matchesFilter,
    filterCounts: filterCounts,
    pointsLabel: pointsLabel,
    statusText: statusText,
    cardState: cardState,
    badgeClass: badgeClass,
    panelSubText: panelSubText,
    dayValueText: dayValueText,
    summarize: summarize,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  } else {
    globalThis.ClassPulseDailyFormat = api;
  }
})();
