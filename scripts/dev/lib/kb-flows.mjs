// DEV-ONLY keyboard flows for scripts/dev/keyboard-audit.mjs. Each flow navigates to a screen and then drives it with
// REAL key events (Tab, Shift+Tab, Enter, Space, Escape, arrows, typed text). A control is first focused with
// element.focus() only as a shortcut to arrive there (the Tab order itself is swept separately); every action under
// test (open, edit, save, close) is a real key press. Flows that write use the scratch class only.
const Q = (s) => JSON.stringify(s);

// ---- small helpers on the test context `t` ---------------------------------------------------------------------
const H = {
  async focus(t, sel) {
    const ok = await t.ev(`(() => { const el = document.querySelector(${Q(sel)}); if (!el) return false; el.scrollIntoView({ block: 'center' }); el.focus(); return document.activeElement === el; })()`);
    if (!ok) throw new Error('cannot focus ' + sel);
  },
  isActive: (t, sel) => t.ev(`Boolean(document.activeElement && document.activeElement.matches(${Q(sel)}))`),
  activeIn: (t, sel) => t.ev(`(() => { const c = document.querySelector(${Q(sel)}); return Boolean(c && c.contains(document.activeElement) && document.activeElement !== c); })()`),
  activeInOrIs: (t, sel) => t.ev(`(() => { const c = document.querySelector(${Q(sel)}); return Boolean(c && c.contains(document.activeElement)); })()`),
  bodyFocused: (t) => t.ev(`document.activeElement === document.body || document.activeElement === null`),
  visible: (t, sel) => t.ev(`(() => { const el = document.querySelector(${Q(sel)}); if (!el) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden' && !el.closest('[hidden]'); })()`),
  attr: (t, sel, name) => t.ev(`document.querySelector(${Q(sel)}).getAttribute(${Q(name)})`),
  text: (t, sel) => t.ev(`(document.querySelector(${Q(sel)}) || {}).textContent || ''`),
  wait: (t, ms = 250) => t.sleep(ms),
  // Tab n times (or Shift+Tab) and report whether focus ever left the container.
  async trap(t, sel, n = 12, shift = false) {
    let left = 0;
    for (let i = 0; i < n; i++) {
      await t.key('Tab', { shift });
      // A modal dialog may pass through <body> (the document boundary) before wrapping; any other outside element is a leak.
      if (!(await H.activeInOrIs(t, sel)) && !(await H.bodyFocused(t))) left++;
    }
    return left === 0;
  },
  async nav(t, action) {
    const loaded = t.browser.waitEvent('Page.loadEventFired', 15000);
    await action();
    await loaded;
    await t.browser.send('Page.bringToFront');
    await t.browser.settle(250);
  },
  stubPrint: (t) => t.ev(`window.__printed = 0; window.print = () => { window.__printed += 1; }; true`),
};

const dailyPath = (c) => `/daily?class=${c.scratchId}`;
const weeklyPath = (c) => `/weekly?class=${c.scratchId}`;
const semesterPath = (c) => `/semester?class=${c.scratchId}`;
const rosterPath = (c) => `/roster?class=${c.scratchId}`;

export const FLOWS = [
  // ---------------------------------------------------------------- shell (header)
  {
    id: 'account-menu', screen: 'Shell', title: 'Account menu: open with Enter/Space/ArrowDown, arrows, Escape returns focus', path: dailyPath,
    async run(t) {
      await H.focus(t, '[data-account-toggle]');
      await t.key('Enter');
      t.check(await H.attr(t, '[data-account-toggle]', 'aria-expanded') === 'true', 'Enter sets aria-expanded=true');
      t.check(await H.visible(t, '#account-menu'), 'menu is visible');
      t.check(await H.isActive(t, '#account-menu [role=menuitem]'), 'focus moves to the first menu item');
      await t.key('ArrowDown');
      t.check(await H.activeIn(t, '#account-menu'), 'ArrowDown keeps focus inside the menu');
      await t.key('Escape');
      t.check(await H.attr(t, '[data-account-toggle]', 'aria-expanded') === 'false', 'Escape sets aria-expanded=false');
      t.check(await H.isActive(t, '[data-account-toggle]'), 'Escape returns focus to the account button');
      await t.key('Space');
      t.check(await H.attr(t, '[data-account-toggle]', 'aria-expanded') === 'true', 'Space opens it too');
      await t.key('Escape');
      await t.key('ArrowDown');
      t.check(await H.attr(t, '[data-account-toggle]', 'aria-expanded') === 'true', 'ArrowDown on the closed button opens it');
      await t.key('Tab'); // Account settings -> Log out (still inside the menu)
      t.check(await H.attr(t, '[data-account-toggle]', 'aria-expanded') === 'true' && await H.isActive(t, '#account-menu .logout-form [role=menuitem]'), 'Tab moves from Account settings to Log out inside the menu');
      await t.key('Tab');
      t.check(await H.attr(t, '[data-account-toggle]', 'aria-expanded') === 'false', 'Tab out of the menu closes it (no trap)');
    },
  },
  {
    id: 'class-selector', screen: 'Shell', title: 'Class menu (details/summary): Enter opens, arrows, Enter on another class navigates, Escape closes and refocuses', path: dailyPath,
    async run(t) {
      const SUM = '.class-menu-header > summary';
      const OPEN = `document.querySelector('.class-menu-header').open`;
      const LINK = '.class-menu-header .class-menu-item';
      await H.focus(t, SUM);
      t.check(/^Active class: .+\. Choose a class$/.test(await H.attr(t, SUM, 'aria-label')), 'the summary is named "Active class: <name>. Choose a class"');
      t.check(await H.attr(t, SUM, 'aria-haspopup') === 'true' && await H.attr(t, SUM, 'aria-expanded') === 'false', 'aria-haspopup="true" and aria-expanded="false" while closed');
      await t.key('Enter');
      await H.wait(t, 150);
      t.check(await t.ev(OPEN), 'Enter opens the menu');
      t.check(await H.attr(t, SUM, 'aria-expanded') === 'true', 'aria-expanded becomes "true"');
      t.check(await H.isActive(t, LINK + '[aria-current="true"]'), 'focus moves to the current class link');
      const count = await t.ev(`document.querySelectorAll(${Q(LINK)}).length`);
      t.check(count >= 2, 'the menu lists every class as a link (' + count + ')');
      const index = () => t.ev(`Array.from(document.querySelectorAll(${Q(LINK)})).indexOf(document.activeElement)`);
      await t.key('Home');
      t.check((await index()) === 0, 'Home moves to the first class');
      await t.key('End');
      t.check((await index()) === count - 1, 'End moves to the last class');
      await t.key('ArrowDown');
      t.check((await index()) === 0, 'ArrowDown on the last class wraps to the first');
      await t.key('ArrowUp');
      t.check((await index()) === count - 1, 'ArrowUp on the first class wraps to the last');
      await t.key('Escape');
      await H.wait(t, 150);
      t.check(!(await t.ev(OPEN)) && await H.attr(t, SUM, 'aria-expanded') === 'false', 'Escape closes the menu');
      t.check(await H.isActive(t, SUM), 'Escape returns focus to the summary');
      await t.key('Space');
      await H.wait(t, 150);
      t.check(await t.ev(OPEN), 'Space opens the menu too');
      await t.key('Escape');
      await H.wait(t, 100);
      await t.key('ArrowDown');
      await H.wait(t, 150);
      t.check(await t.ev(OPEN), 'ArrowDown on the closed summary opens it');
      // Tab moves normally and leaving the menu closes it (no trap): Tab from the last link reaches the "+" link.
      await t.key('End');
      await t.key('Tab');
      await H.wait(t, 100);
      t.check(await H.isActive(t, '.class-add'), 'Tab from the last class moves on to the "+" link');
      t.check(!(await t.ev(OPEN)), 'leaving the menu with Tab closes it');
      // Enter on another class navigates and the new class is current.
      await H.focus(t, SUM);
      await t.key('Enter');
      await H.wait(t, 150);
      const before = await t.ev(`new URL(document.querySelector(${Q(LINK + '[aria-current="true"]')}).href).searchParams.get('class')`);
      await t.key('ArrowDown');
      const picked = await t.ev(`new URL(document.activeElement.href).searchParams.get('class')`);
      await H.nav(t, () => t.key('Enter'));
      const now = await t.ev(`new URL(location.href).searchParams.get('class')`);
      t.check(before !== picked && now === picked, 'Enter on another class navigated to it (class ' + before + ' -> ' + now + ')');
      t.check(await t.ev(`document.querySelector('.class-menu-header .class-menu-item[aria-current="true"]').href.endsWith('class=' + ${Q(picked)})`), 'the newly selected class is the current one after the reload');
      t.check(!(await t.ev(OPEN)), 'the menu is closed after the reload');
    },
  },
  {
    id: 'class-chip-semester', screen: 'Semester', title: 'Semester course chip (same component): Enter opens, Escape closes and refocuses', path: semesterPath,
    async run(t) {
      const SUM = '.class-menu-chip > summary';
      await H.focus(t, SUM);
      await t.key('Enter');
      await H.wait(t, 150);
      t.check(await t.ev(`document.querySelector('.class-menu-chip').open`) && await H.isActive(t, '.class-menu-chip .class-menu-item[aria-current="true"]'), 'Enter opens the chip menu and focuses the current class');
      await t.key('ArrowDown');
      t.check(await H.activeIn(t, '.class-menu-chip'), 'ArrowDown stays inside the menu');
      await t.key('Escape');
      await H.wait(t, 150);
      t.check(!(await t.ev(`document.querySelector('.class-menu-chip').open`)) && await H.isActive(t, SUM), 'Escape closes it and returns focus to the chip');
    },
  },

  // ---------------------------------------------------------------- Daily
  {
    id: 'daily-student-dialog', screen: 'Daily', title: '+ Student dialog: Enter opens, trap, Escape and Cancel return focus', path: dailyPath,
    async run(t) {
      await H.focus(t, '[data-student-dialog]');
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('#student-dialog').open`), 'Enter opens the dialog');
      t.check(await H.isActive(t, '#new-student-name'), 'focus starts on the Name field');
      t.check(await H.trap(t, '#student-dialog', 8), 'Tab stays inside the open dialog');
      t.check(await H.trap(t, '#student-dialog', 8, true), 'Shift+Tab stays inside the open dialog');
      await t.key('Escape');
      await H.wait(t);
      t.check(!(await t.ev(`document.querySelector('#student-dialog').open`)), 'Escape closes the dialog');
      t.check(await H.isActive(t, '[data-student-dialog]'), 'Escape returns focus to the + Student button');
      await t.key('Space');
      t.check(await t.ev(`document.querySelector('#student-dialog').open`), 'Space opens the dialog');
      await t.key('Tab'); await t.key('Tab');
      t.check(await H.isActive(t, '#student-dialog [data-dialog-close]'), 'Tab reaches Cancel');
      await t.key('Enter');
      await H.wait(t);
      t.check(!(await t.ev(`document.querySelector('#student-dialog').open`)), 'Enter on Cancel closes the dialog');
      t.check(await H.isActive(t, '[data-student-dialog]'), 'Cancel returns focus to the + Student button');
      // Empty name: Enter in the field keeps the dialog open and keeps focus on the invalid field.
      await t.key('Enter');
      await t.key('Tab'); await t.key('Tab'); await t.key('Tab');
      t.check(await H.isActive(t, '#student-dialog button[type=submit]'), 'Tab reaches Add student');
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('#student-dialog').open`) && await H.isActive(t, '#new-student-name'), 'submitting an empty name keeps focus on the invalid field');
      t.check(await H.attr(t, '#new-student-name', 'aria-invalid') === 'true', 'the field gets aria-invalid=true');
    },
  },
  {
    id: 'daily-student-add', screen: 'Daily', title: 'Add student by keyboard: focus after the page reloads', path: dailyPath,
    async run(t) {
      await H.focus(t, '[data-student-dialog]');
      await t.key('Enter');
      await t.type('Kb Tester');
      await H.nav(t, () => t.key('Enter'));
      t.check(await t.ev(`Array.from(document.querySelectorAll('.student-name')).some(b => b.textContent.includes('Kb Tester'))`), 'the new student appears after Enter submits the form');
      t.info('focus after the reload: ' + (await t.active()));
    },
  },
  {
    id: 'daily-day-slip', screen: 'Daily', title: 'Day Slip: Enter/Space call print, focus stays', path: dailyPath,
    async run(t) {
      await H.stubPrint(t);
      await H.focus(t, '[data-print-slip]');
      await t.key('Enter');
      t.check((await t.ev('window.__printed')) === 1, 'Enter calls window.print once');
      await t.key('Space');
      await H.wait(t, 100);
      t.check((await t.ev('window.__printed')) === 2, 'Space calls window.print');
      t.check(await H.isActive(t, '[data-print-slip]'), 'focus stays on the Day Slip button');
    },
  },
  {
    id: 'daily-day-menu', screen: 'Daily', title: 'More day actions menu and the Mark remaining / Reset Day dialogs', path: dailyPath,
    async run(t) {
      await H.focus(t, '[data-day-menu] > summary');
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('[data-day-menu]').open`), 'Enter opens the menu');
      await t.key('Escape');
      t.check(!(await t.ev(`document.querySelector('[data-day-menu]').open`)), 'Escape closes the menu');
      t.check(await H.isActive(t, '[data-day-menu] > summary'), 'Escape returns focus to the menu button');
      await t.key('Space');
      t.check(await t.ev(`document.querySelector('[data-day-menu]').open`), 'Space opens the menu');
      await t.key('Tab');
      t.check(await H.isActive(t, '[data-dialog=zero-dialog]'), 'Tab moves to Mark remaining as 0');
      await t.key('Enter');
      await H.wait(t, 200);
      t.check(await t.ev(`document.querySelector('#zero-dialog').open`), 'Enter opens the Mark remaining dialog');
      t.check(await H.trap(t, '#zero-dialog', 6), 'Tab stays inside the Mark remaining dialog');
      await t.key('Escape');
      await H.wait(t, 200);
      t.check(!(await t.ev(`document.querySelector('#zero-dialog').open`)), 'Escape closes the Mark remaining dialog');
      t.check(await H.isActive(t, '[data-day-menu] > summary'), 'focus returns to More day actions after Escape (Mark remaining)');
      // Reset Day: Escape and Cancel.
      await t.key('Enter');
      await t.key('Tab'); await t.key('Tab');
      t.check(await H.isActive(t, '[data-dialog=reset-dialog]'), 'Tab reaches Reset Day');
      await t.key('Enter');
      await H.wait(t, 200);
      t.check(await t.ev(`document.querySelector('#reset-dialog').open`), 'Enter opens the Reset Day dialog');
      t.check(await H.trap(t, '#reset-dialog', 6), 'Tab stays inside the Reset Day dialog');
      await t.key('Escape');
      await H.wait(t, 200);
      t.check(await H.isActive(t, '[data-day-menu] > summary'), 'focus returns to More day actions after Escape (Reset Day)');
      await t.key('Enter'); await t.key('Tab'); await t.key('Tab'); await t.key('Enter');
      await H.wait(t, 200);
      await t.key('Enter'); // the focused control of a fresh dialog is Cancel
      await H.wait(t, 200);
      t.check(!(await t.ev(`document.querySelector('#reset-dialog').open`)), 'Enter on Cancel closes Reset Day');
      t.check(await H.isActive(t, '[data-day-menu] > summary'), 'focus returns to More day actions after Cancel');
      // Confirm path on the scratch class: Mark remaining as 0.
      await t.key('Enter'); await t.key('Tab');
      await t.key('Enter');
      await H.wait(t, 200);
      await t.key('Tab'); // Cancel -> confirm
      t.check(await H.isActive(t, '#zero-dialog [value=confirm]'), 'Tab reaches the confirm button');
      await t.key('Enter');
      await H.wait(t, 700);
      t.check(!(await t.ev(`document.querySelector('#zero-dialog').open`)), 'confirming closes the dialog');
      t.check(await H.isActive(t, '[data-day-menu] > summary'), 'focus returns to More day actions after confirming');
      t.check(await t.ev(`document.querySelector('[data-count=none]').textContent.trim() === '0'`), 'the confirmed action recorded the students (Not recorded = 0)');
      // Confirm path: Reset Day (scratch class only).
      await t.key('Enter'); await t.key('Tab'); await t.key('Tab');
      await t.key('Enter');
      await H.wait(t, 200);
      await t.key('Tab');
      t.check(await H.isActive(t, '#reset-dialog [value=confirm]'), 'Tab reaches the Reset day confirm button');
      await t.key('Enter');
      await H.wait(t, 700);
      t.check(!(await t.ev(`document.querySelector('#reset-dialog').open`)) && await H.isActive(t, '[data-day-menu] > summary'), 'confirming Reset Day closes the dialog and returns focus to More day actions');
      t.check(await t.ev(`document.querySelector('[data-count=none]').textContent.trim() !== '0'`), 'Reset Day cleared the entries');
    },
  },
  {
    id: 'daily-filter-chips', screen: 'Daily', title: 'Filter chips: Space/Enter toggle aria-pressed; filtering never drops focus', path: dailyPath,
    async run(t) {
      await H.focus(t, '[data-filter=zero]');
      await t.key('Space');
      t.check(await H.attr(t, '[data-filter=zero]', 'aria-pressed') === 'true', 'Space presses the Zero chip');
      t.check(await H.attr(t, '[data-filter=all]', 'aria-pressed') === 'false', 'All is no longer pressed');
      t.check(await H.isActive(t, '[data-filter=zero]'), 'focus stays on the chip');
      await H.focus(t, '[data-filter=all]');
      await t.key('Enter');
      t.check(await H.attr(t, '[data-filter=all]', 'aria-pressed') === 'true', 'Enter presses All again');
      // A card that leaves the active filter while its button has focus must not drop focus to <body>.
      await H.focus(t, 'article.student-card [data-action=increment]:not(:disabled)');
      await t.key('Enter'); // make sure one card is Present with points, so the Active filter shows it
      await H.wait(t, 300);
      await H.focus(t, '[data-filter=active]');
      await t.key('Enter');
      await H.focus(t, 'article.student-card:not([hidden]) [data-action=absent_on]');
      await t.key('Enter'); // marks absent: the card leaves the Active filter
      await H.wait(t, 300);
      t.check(!(await H.bodyFocused(t)), 'focus is not lost to <body> when the focused card leaves the active filter');
      await H.focus(t, '[data-filter=all]');
      await t.key('Enter');
    },
  },
  {
    id: 'daily-card-keys', screen: 'Daily', title: 'Card buttons: Enter/Space change points; a control that becomes disabled does not drop focus', path: dailyPath,
    async run(t) {
      await H.focus(t, 'article.student-card:not([hidden]) [data-action=increment]:not(:disabled)');
      await t.key('Enter');
      await H.wait(t, 200);
      t.check(await t.ev(`document.activeElement.matches('[data-action=increment]')`), 'Enter on + keeps focus on +');
      await t.key('Space');
      await H.wait(t, 200);
      const card = `article.student-card[data-student-id="${await t.ev(`document.activeElement.closest('article').dataset.studentId`)}"]`;
      t.check(Number(await t.ev(`document.querySelector(${Q(card + ' .score-number')}).textContent`)) >= 2, 'Enter and Space each add a point');
      // Count the card down to 0 with the minus button: it becomes disabled under the keyboard.
      await H.focus(t, `${card} [data-action=decrement]`);
      for (let i = 0; i < 12; i++) {
        const disabled = await t.ev(`document.querySelector(${Q(card + ' [data-action=decrement]')}).disabled`);
        if (disabled) break;
        await t.key('Enter');
        await H.wait(t, 160);
      }
      await H.wait(t, 300);
      t.check(!(await H.bodyFocused(t)), 'focus is not lost to <body> when "-" becomes disabled at 0 points (focus: ' + (await t.active()) + ')');
      await H.focus(t, `${card} [data-action=absent_on]`);
      await t.key('Enter');
      await H.wait(t, 300);
      t.check(await t.ev(`document.querySelector(${Q(card)}).dataset.status === 'absent'`), 'Enter on Mark Absent marks the student absent');
      t.check(await t.ev(`document.querySelector(${Q(card + ' [data-action=increment]')}).disabled`), '+ is disabled while absent');
      t.check(!(await H.bodyFocused(t)), 'focus stays on the card after marking absent (focus: ' + (await t.active()) + ')');
    },
  },
  {
    id: 'daily-panel', screen: 'Daily', title: 'Student card select, details panel / bottom sheet, Escape', path: dailyPath,
    async run(t) {
      const sheet = t.width <= 1100;
      await H.focus(t, 'article.student-card .student-name');
      const sid = await t.ev(`document.activeElement.closest('article').dataset.studentId`);
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('article[data-student-id="${sid}"]').classList.contains('card-selected')`), 'Enter on the name selects the card');
      t.check(await H.attr(t, 'article[data-student-id="' + sid + '"] .student-name', 'aria-pressed') === 'true', 'the name button reports aria-pressed=true');
      t.check(await t.ev(`!document.querySelector('[data-panel-student="${sid}"]').hidden`), 'the panel shows the selected student');
      if (!sheet) {
        await t.key('Escape');
        t.check(!(await t.ev(`document.querySelector('article[data-student-id="${sid}"]').classList.contains('card-selected')`)), 'Escape clears the selection');
        t.check(await H.attr(t, 'article[data-student-id="' + sid + '"] .student-name', 'aria-pressed') === 'false', 'aria-pressed returns to false');
        t.check(!(await H.bodyFocused(t)), 'focus is not lost to <body> after Escape clears the selection (focus: ' + (await t.active()) + ')');
        return;
      }
      await H.focus(t, '#panel-toggle');
      await t.key('Enter');
      t.check(await H.attr(t, '#panel-toggle', 'aria-expanded') === 'true', 'Enter on Student details sets aria-expanded=true');
      t.check(await t.ev(`document.querySelector('#detail-panel').classList.contains('is-open')`), 'the sheet opens');
      t.check(await H.isActive(t, '#detail-panel'), 'focus moves into the sheet');
      t.check(await H.trap(t, '#detail-panel', 14), 'Tab stays inside the open sheet');
      t.check(await H.trap(t, '#detail-panel', 14, true), 'Shift+Tab stays inside the open sheet');
      await t.key('Escape');
      t.check(!(await t.ev(`document.querySelector('#detail-panel').classList.contains('is-open')`)), 'Escape closes the sheet');
      t.check(await H.attr(t, '#panel-toggle', 'aria-expanded') === 'false', 'aria-expanded returns to false');
      t.check(await H.isActive(t, '#panel-toggle'), 'Escape returns focus to Student details');
      await t.key('Enter');
      await H.focus(t, '[data-panel-close]');
      await t.key('Enter');
      t.check(!(await t.ev(`document.querySelector('#detail-panel').classList.contains('is-open')`)) && await H.isActive(t, '#panel-toggle'), 'Close button closes the sheet and returns focus to Student details');
    },
  },
  {
    id: 'daily-note', screen: 'Daily', title: 'Note textarea: type, Save with Enter, focus is not lost', path: dailyPath,
    async run(t) {
      const sheet = t.width <= 1100;
      await H.focus(t, 'article.student-card .student-name');
      const sid = await t.ev(`document.activeElement.closest('article').dataset.studentId`);
      await t.key('Enter');
      if (sheet) { await H.focus(t, '#panel-toggle'); await t.key('Enter'); }
      await H.focus(t, `[data-panel-student="${sid}"] [data-note-input]`);
      await t.type('Keyboard note');
      t.check(!(await t.ev(`document.querySelector('[data-panel-student="${sid}"] [data-note-save]').disabled`)), 'typing enables Save Student Note');
      await t.key('Tab');
      t.check(await H.isActive(t, `[data-panel-student="${sid}"] [data-note-save]`), 'Tab from the textarea reaches the Save button');
      await t.key('Enter');
      await H.wait(t, 800);
      t.check(/saved/i.test(await H.text(t, `[data-panel-student="${sid}"] [data-note-status]`)), 'the status line says the note was saved');
      t.check(!(await H.bodyFocused(t)), 'focus is not lost to <body> after saving (focus: ' + (await t.active()) + ')');
      // Clear it again with Ctrl+Enter from the textarea.
      await H.focus(t, `[data-panel-student="${sid}"] [data-note-input]`);
      await t.key('a', { commands: ['selectAll'] });
      await t.key('Backspace');
      await t.key('Enter', { ctrl: true });
      await H.wait(t, 700);
      t.check(!(await H.bodyFocused(t)), 'focus stays on the textarea after Ctrl+Enter (focus: ' + (await t.active()) + ')');
    },
  },

  // ---------------------------------------------------------------- Weekly
  {
    id: 'weekly-cell-editor', screen: 'Weekly', title: 'Cell quick editor: open with Enter, edit, Set, Escape returns focus to the cell', path: weeklyPath,
    async run(t) {
      const sel = 'tr[data-student-row] .wm-cell:not([disabled])';
      await H.focus(t, sel);
      const cellId = await t.ev(`(() => { const a = document.activeElement; return a.dataset.studentId + '|' + a.dataset.date; })()`);
      const cell = `[data-cell][data-student-id="${cellId.split('|')[0]}"][data-date="${cellId.split('|')[1]}"]`;
      await t.key('Enter');
      t.check(await H.visible(t, '#cell-editor'), 'Enter opens the quick editor');
      t.check(await H.isActive(t, '[data-editor-input]'), 'focus moves to the points field');
      t.check(await H.trap(t, '#cell-editor', 14), 'Tab stays inside the editor');
      t.check(await H.trap(t, '#cell-editor', 14, true), 'Shift+Tab stays inside the editor');
      await H.focus(t, '[data-editor-input]');
      await t.key('a', { commands: ['selectAll'] });
      await t.type('4');
      await t.key('Enter');
      await H.wait(t, 700);
      t.check((await H.text(t, cell + ' .wm-cell-text')).trim() === '4', 'Enter in the field saves the value (cell shows 4)');
      t.check(await H.activeInOrIs(t, '#cell-editor') || await H.isActive(t, cell), 'focus stays in the editor after saving');
      await t.key('Escape');
      t.check(!(await H.visible(t, '#cell-editor')), 'Escape closes the editor');
      t.check(await H.isActive(t, cell), 'focus returns to the cell that opened the editor');
      await t.key('Space');
      t.check(await H.visible(t, '#cell-editor'), 'Space opens the editor too');
      await H.focus(t, '[data-editor-action=set_points]');
      await t.key('Enter');
      await H.wait(t, 500);
      await H.focus(t, '[data-editor-close]');
      await t.key('Enter');
      t.check(!(await H.visible(t, '#cell-editor')) && await H.isActive(t, cell), 'the Close button closes it and returns focus to the cell');
    },
  },
  {
    id: 'weekly-tooltips', screen: 'Weekly', title: 'Info tooltips: focus shows, Escape dismisses', path: weeklyPath,
    async run(t) {
      await H.focus(t, '.info-tip-btn');
      t.check(await t.ev(`(() => { const tip = document.activeElement.closest('.info-tip'); const b = tip.querySelector('.info-tip-text'); return getComputedStyle(b).visibility === 'visible' && Number(getComputedStyle(b).opacity) > 0.5; })()`), 'focusing the info button shows the definition');
      t.check(Boolean(await t.ev(`document.activeElement.getAttribute('aria-describedby') || document.activeElement.closest('.info-tip').querySelector('[role=tooltip]')`)), 'the button is linked to its tooltip text (aria-describedby or role=tooltip)');
      await t.key('Escape');
      t.check(await t.ev(`(() => { const b = document.activeElement.closest('.info-tip').querySelector('.info-tip-text'); return getComputedStyle(b).visibility === 'hidden' || Number(getComputedStyle(b).opacity) < 0.1; })()`), 'Escape hides the definition');
      t.check(await H.isActive(t, '.info-tip-btn'), 'focus stays on the info button');
    },
  },
  {
    id: 'weekly-copy', screen: 'Weekly', title: 'Copy Summary: Enter/Space, status announced, focus stays', path: weeklyPath,
    async run(t) {
      await t.ev(`Object.defineProperty(navigator, 'clipboard', { value: { writeText: () => Promise.resolve() }, configurable: true }); true`);
      await H.focus(t, '[data-copy-summary]');
      await t.key('Enter');
      await H.wait(t, 300);
      t.check(/copied/i.test(await H.text(t, '[data-copy-label]')), 'the label says Copied');
      t.check(/copied/i.test(await H.text(t, '[data-copy-status]')), 'the live region announces the copy');
      t.check(await H.isActive(t, '[data-copy-summary]'), 'focus stays on Copy Summary');
    },
  },

  // ---------------------------------------------------------------- Semester
  {
    id: 'semester-period', screen: 'Semester', title: 'Reporting period links: Enter changes period, aria-current updates', path: semesterPath,
    async run(t) {
      const q1 = await t.ev(`(() => { const a = Array.from(document.querySelectorAll('.ui-segmented-item')); return a.length; })()`);
      t.check(q1 >= 1, 'the period control has ' + q1 + ' options');
      await H.focus(t, '.ui-segmented-item:not([aria-current])');
      const label = await t.ev(`document.activeElement.textContent.trim()`);
      await H.nav(t, () => t.key('Enter'));
      t.check(await t.ev(`document.querySelector('.ui-segmented-item[aria-current=page]').textContent.trim()`) === label, 'after Enter, "' + label + '" carries aria-current=page');
    },
  },
  {
    id: 'semester-inspect', screen: 'Semester', title: 'Inspect a student: Enter, inspector / sheet, Escape', path: semesterPath,
    async run(t) {
      const sheet = t.width <= 1100;
      await H.focus(t, '[data-inspect]');
      const name = await t.ev(`document.activeElement.getAttribute('aria-label')`);
      await t.key('Enter');
      await H.wait(t, 500);
      t.check(await t.ev(`document.activeElement.getAttribute('aria-pressed') === 'true'`) || await t.ev(`document.querySelector('[data-inspect][aria-pressed=true]') !== null`), 'Inspect reports aria-pressed=true');
      t.check(await H.activeInOrIs(t, '#sem-inspector'), 'focus moves into the inspector (' + name + ')');
      if (sheet) {
        t.check(await t.ev(`document.querySelector('#sem-inspector').classList.contains('is-open')`), 'the inspector sheet opens');
        t.check(await H.trap(t, '#sem-inspector', 16), 'Tab stays inside the open inspector sheet');
        t.check(await H.trap(t, '#sem-inspector', 16, true), 'Shift+Tab stays inside the open inspector sheet');
      }
      await t.key('Escape');
      await H.wait(t, 200);
      t.check(!(await t.ev(`document.querySelector('#sem-inspector').classList.contains('is-open')`)) || !sheet, 'Escape closes the sheet');
      t.check(await t.ev(`document.querySelector('[data-inspect][aria-pressed=true]') === null`), 'Escape clears the selection (aria-pressed=false)');
      t.check(await H.isActive(t, '[data-inspect]'), 'Escape returns focus to the Inspect button');
      if (sheet) {
        await H.focus(t, '#sem-inspector-toggle');
        await t.key('Enter');
        t.check(await H.attr(t, '#sem-inspector-toggle', 'aria-expanded') === 'true', 'the toggle opens the sheet (aria-expanded=true)');
        await t.key('Escape');
        t.check(await H.attr(t, '#sem-inspector-toggle', 'aria-expanded') === 'false', 'Escape closes it (aria-expanded=false)');
        t.check(await H.isActive(t, '#sem-inspector-toggle'), 'Escape returns focus to the Student inspector toggle');
        await t.key('Enter');
        await H.focus(t, '[data-inspector-close]');
        await t.key('Enter');
        t.check(await H.isActive(t, '#sem-inspector-toggle'), 'the Close button returns focus to the toggle');
      }
    },
  },
  {
    id: 'semester-notes', screen: 'Semester', title: 'Notes: add, edit, delete with the confirm dialog', path: semesterPath,
    async run(t) {
      const sheet = t.width <= 1100;
      await H.focus(t, '[data-inspect]');
      await t.key('Enter');
      await H.wait(t, 700);
      await H.focus(t, '[data-note-add]');
      await t.key('Enter');
      t.check(await H.visible(t, '[data-note-form]'), 'Enter on Add note opens the note form');
      t.check(await H.activeIn(t, '[data-note-form]'), 'focus moves into the form');
      await H.focus(t, '[data-note-body]');
      await t.type('Keyboard semester note');
      await H.focus(t, '[data-note-save]');
      await t.key('Enter');
      await H.wait(t, 800);
      t.check(!(await H.visible(t, '[data-note-form]')), 'saving closes the form');
      t.check(await H.isActive(t, '[data-note-add]'), 'focus returns to Add note after saving');
      t.check(await t.ev(`document.querySelectorAll('[data-notes] li').length >= 1`), 'the note is listed');
      // Edit, then cancel with Escape.
      await H.focus(t, '[data-note-edit]');
      await t.key('Enter');
      t.check(await H.visible(t, '[data-note-form]') && await H.isActive(t, '[data-note-body]'), 'Enter on Edit opens the form on the note text');
      await t.key('Escape');
      t.check(!(await H.visible(t, '[data-note-form]')), 'Escape closes the form (the sheet stays open)');
      t.check(!(await H.bodyFocused(t)), 'focus is not lost to <body> after Escape (focus: ' + (await t.active()) + ')');
      if (sheet) t.check(await t.ev(`document.querySelector('#sem-inspector').classList.contains('is-open')`), 'Escape in the note form does not close the sheet');
      // Delete: Escape keeps the note and returns focus to Delete, then confirm.
      await H.focus(t, '[data-note-delete]');
      await t.key('Enter');
      await H.wait(t, 200);
      t.check(await t.ev(`document.querySelector('#sem-delete-dialog').open`), 'Enter on Delete opens the confirm dialog');
      t.check(await H.trap(t, '#sem-delete-dialog', 6), 'Tab stays inside the confirm dialog');
      await t.key('Escape');
      await H.wait(t, 200);
      t.check(!(await t.ev(`document.querySelector('#sem-delete-dialog').open`)) && await H.isActive(t, '[data-note-delete]'), 'Escape closes it and returns focus to the Delete button');
      const notesBefore = await t.ev(`document.querySelectorAll('[data-notes] li').length`);
      await t.key('Enter');
      await H.wait(t, 200);
      await t.key('Tab');
      t.check(await H.isActive(t, '#sem-delete-dialog [value=confirm]'), 'Tab reaches Delete note');
      await t.key('Enter');
      await H.wait(t, 800);
      t.check(!(await t.ev(`document.querySelector('#sem-delete-dialog').open`)), 'confirming closes the dialog');
      t.check((await t.ev(`document.querySelectorAll('[data-notes] li').length`)) === notesBefore - 1, 'the note is deleted (' + notesBefore + ' -> ' + (notesBefore - 1) + ')');
      t.check(await H.isActive(t, '[data-note-add]'), 'focus lands on Add note after deleting (focus: ' + (await t.active()) + ')');
    },
  },
  {
    id: 'semester-show-more', screen: 'Semester', title: 'Show more (needs a class with more than 25 students): focus after the button hides itself', path: (c) => (c.fullId ? `/semester?class=${c.fullId}` : semesterPath(c)),
    async run(t) {
      if (!(await t.ev(`Boolean(document.querySelector('[data-more]') && !document.querySelector('[data-more]').hidden)`))) {
        t.info('skipped: no class with more than 25 students exists right now (create the scratch full roster with print-pdfs.mjs --full-roster --keep)');
        return;
      }
      await H.focus(t, '[data-more]');
      await t.key('Enter');
      await H.wait(t, 250);
      t.check(await t.ev(`document.querySelector('[data-more]').hidden`), 'Enter on Show more reveals the remaining rows');
      t.check(!(await H.bodyFocused(t)), 'focus is not lost to <body> when the button hides itself (focus: ' + (await t.active()) + ')');
    },
  },
  {
    id: 'semester-comments', screen: 'Semester', title: 'Report Card Comments dialog: edit, Reset, Copy, Escape/Close return focus', path: semesterPath,
    async run(t) {
      await t.ev(`Object.defineProperty(navigator, 'clipboard', { value: { writeText: () => Promise.resolve() }, configurable: true }); true`);
      await H.focus(t, '[data-open-comments]');
      await t.key('Enter');
      await H.wait(t, 300);
      t.check(await t.ev(`document.querySelector('#sem-comments').open`), 'Enter opens the dialog');
      t.check(await H.activeInOrIs(t, '#sem-comments'), 'focus is inside the dialog');
      t.check(await H.trap(t, '#sem-comments', 10), 'Tab stays inside the dialog');
      t.check(await H.trap(t, '#sem-comments', 10, true), 'Shift+Tab stays inside the dialog');
      await H.focus(t, '[data-comment-input]');
      await t.key('End', { commands: ['moveToEndOfDocument'] });
      await t.type(' Keyboard edit.');
      await H.wait(t, 300);
      t.check(/edited|saving|saved|draft/i.test(await t.ev(`document.querySelector('[data-comment-badge]').textContent`)) || (await t.ev(`document.querySelector('[data-comment-input]').value.includes('Keyboard edit.')`)), 'typing edits the draft');
      await H.focus(t, '[data-comment-reset]');
      await t.key('Enter');
      for (let i = 0; i < 20 && await t.ev(`document.querySelector('[data-comment-input]').value.includes('Keyboard edit.')`); i++) await H.wait(t, 200);
      t.check(!(await t.ev(`document.querySelector('[data-comment-input]').value.includes('Keyboard edit.')`)), 'Reset to template restores the template text');
      t.check(await H.isActive(t, '[data-comment-input]'), 'Reset switches itself off, so focus moves to the draft, not <body> (focus: ' + (await t.active()) + ')');
      await H.focus(t, '[data-comment-copy]');
      await t.key('Enter');
      await H.wait(t, 300);
      t.check(/copied/i.test(await H.text(t, '[data-comments-status]')), 'Copy comment announces "Copied"');
      t.check(await H.isActive(t, '[data-comment-copy]'), 'focus stays on Copy comment');
      await t.key('Escape');
      await H.wait(t, 300);
      t.check(!(await t.ev(`document.querySelector('#sem-comments').open`)), 'Escape closes the dialog');
      t.check(await H.isActive(t, '[data-open-comments]'), 'Escape returns focus to Report Card Comments');
      await t.key('Space');
      await H.wait(t, 300);
      await H.focus(t, '.sem-dialog-foot button.ui-btn-primary');
      await t.key('Enter');
      await H.wait(t, 300);
      t.check(!(await t.ev(`document.querySelector('#sem-comments').open`)) && await H.isActive(t, '[data-open-comments]'), 'the Close button closes it and returns focus to Report Card Comments');
      await t.key('Enter');
      await H.wait(t, 300);
      await H.focus(t, '[aria-label="Close report card comments"]');
      await t.key('Space');
      await H.wait(t, 300);
      t.check(!(await t.ev(`document.querySelector('#sem-comments').open`)) && await H.isActive(t, '[data-open-comments]'), 'the X button (Space) closes it and returns focus to Report Card Comments');
    },
  },

  // ---------------------------------------------------------------- Roster
  {
    id: 'roster-classes-create', screen: 'Roster', title: 'Class cards and the create-class form', path: rosterPath,
    async run(t) {
      await H.focus(t, 'a.ro-class-card:not(.is-current):not(.ro-class-new)');
      const href = await t.ev(`document.activeElement.getAttribute('href')`);
      await H.nav(t, () => t.key('Enter'));
      t.check((await t.ev('location.search')).includes(href.split('?')[1]), 'Enter on a class card opens that class');
      t.check(await t.ev(`document.querySelector('a.ro-class-card[aria-current=true]') !== null`), 'the open class card has aria-current=true');
      await H.focus(t, '[data-open-create]');
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('#create-class').open`), 'Enter on Create New Class opens the form');
      t.check(await H.activeIn(t, '#create-class'), 'focus moves to the first field of the form');
      await H.focus(t, '#create-class > summary');
      await t.key('Enter');
      await H.wait(t, 150);
      t.check(!(await t.ev(`document.querySelector('#create-class').open`)), 'Enter on the summary closes it');
      t.check(await H.isActive(t, '[data-open-create]'), 'closing it hands the focus to the Create New Class card (the closed form is hidden) (focus: ' + (await t.active()) + ')');
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('#create-class').open`) && await H.activeIn(t, '#create-class'), 'Enter on the card reopens it with focus in the form');
    },
  },
  {
    id: 'roster-accordions', screen: 'Roster', title: 'Settings accordions: Enter/Space toggle', path: rosterPath,
    async run(t) {
      const groups = await t.ev(`document.querySelectorAll('details.ro-group').length`);
      t.check(groups >= 2, groups + ' settings groups');
      for (const id of ['class-details', 'periods']) {
        await H.focus(t, `#${id} > summary`);
        const was = await t.ev(`document.querySelector('#${id}').open`);
        await t.key('Enter');
        t.check(await t.ev(`document.querySelector('#${id}').open`) === !was, id + ': Enter toggles');
        await t.key('Space');
        t.check(await t.ev(`document.querySelector('#${id}').open`) === was, id + ': Space toggles back');
        t.check(await H.isActive(t, `#${id} > summary`), id + ': focus stays on the summary');
      }
    },
  },
  {
    id: 'roster-files-backups', screen: 'Roster', title: 'Files & backups group: Enter/Space toggle, Tab reaches the import and export links in order', path: rosterPath,
    async run(t) {
      await H.focus(t, '#files > summary');
      t.check(await t.ev(`document.querySelector('#files').open`) === false, 'the group starts collapsed like Calculation Rules');
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('#files').open`) === true, 'Enter opens Files & backups');
      t.check(await H.isActive(t, '#files > summary'), 'focus stays on the summary');
      await t.key('Space');
      t.check(await t.ev(`document.querySelector('#files').open`) === false, 'Space closes it');
      await t.key('Space');
      const expected = await t.ev(`Array.from(document.querySelectorAll('#files a[href]')).map((a) => a.getAttribute('href'))`);
      t.check(expected.length >= 3, expected.length + ' links inside (import, weekly, semester...)');
      const seen = [];
      for (let i = 0; i < expected.length; i++) {
        await t.key('Tab');
        seen.push(await t.ev(`document.activeElement && document.activeElement.closest('#files') ? document.activeElement.getAttribute('href') : null`));
      }
      t.check(JSON.stringify(seen) === JSON.stringify(expected), 'Tab visits the links in reading order');
      t.check(expected[0].includes('/import'), 'the first link is the import flow');
    },
  },
  {
    id: 'account-via-menu', screen: 'Roster', title: 'Account group: avatar menu link opens it from any page and from the Roster page, focus on its heading', path: dailyPath,
    async run(t) {
      await H.focus(t, '[data-account-toggle]');
      await t.key('Enter');
      t.check(await H.isActive(t, '#account-menu a[data-account-link]'), 'the first menu item is the Account settings link');
      await H.nav(t, () => t.key('Enter'));
      t.check((await t.ev('location.pathname + location.hash')) === '/roster#account', 'Enter on the link opens /roster#account');
      t.check(await t.ev(`document.getElementById('account').open`), 'the Account group is open');
      await t.ev(`document.getElementById('account').open = false; true`);
      await H.focus(t, '[data-account-toggle]');
      await t.key('Enter');
      await t.key('Space');
      t.check(await t.ev(`document.getElementById('account').open`), 'Space on the link reopens the group on the Roster page');
      t.check(await H.isActive(t, '#account > summary'), 'focus is on the Account heading');
      await t.key('Escape');
    },
  },
  {
    id: 'account-fields-toggles', screen: 'Roster', title: 'Account group by keyboard: Tab order, show/hide with Space and Enter, Enter submits, errors focus the first invalid field', path: (c) => `/roster?class=${c.scratchId}#account`,
    async run(t) {
      t.check(await t.ev(`document.getElementById('account').open`), 'opens from the #account hash');
      await H.focus(t, '#account > summary');
      const order = [];
      for (let i = 0; i < 11; i++) { await t.key('Tab'); order.push(await t.ev(`document.activeElement.id || document.activeElement.getAttribute('aria-controls') || document.activeElement.type`)); }
      t.check(JSON.stringify(order) === JSON.stringify(['account-new-email', 'account-email-password', 'account-email-password', 'submit', 'account-current-password', 'account-current-password', 'account-new-password', 'account-new-password', 'account-confirm-password', 'account-confirm-password', 'submit']), 'Tab order: email, password+toggle, Change email, then three password fields with toggles, Change password (' + order.join(' > ') + ')');
      await H.focus(t, '[data-pw-toggle][aria-controls=account-current-password]');
      await t.key('Space');
      t.check(await t.ev(`document.getElementById('account-current-password').type === 'text'`) && await H.isActive(t, '[data-pw-toggle][aria-controls=account-current-password]'), 'Space shows the password and focus stays on the toggle');
      await t.key('Enter');
      t.check(await t.ev(`document.getElementById('account-current-password').type === 'password'`) && await t.ev(`document.activeElement.getAttribute('aria-label') === 'Show password'`), 'Enter hides it again (label back to Show password)');
      // Empty submit with Enter: server errors, focus moves to the first invalid field (no credentials are changed)
      await H.focus(t, '#account-new-email');
      await H.nav(t, () => t.key('Enter'));
      t.check(await t.ev(`document.getElementById('account').open`), 'after the failed submit the group is open');
      t.check(await H.isActive(t, '#account-new-email[aria-invalid=true]'), 'focus moves to the first invalid field (' + (await t.active()) + ')');
      t.check(await t.ev(`Boolean(document.querySelector('#account-new-email-error[role=alert]')) && document.getElementById('account-new-email').getAttribute('aria-describedby') === 'account-new-email-error'`), 'the error is announced (role=alert) and linked to the field');
      t.check(await t.ev(`Array.from(document.querySelectorAll('#account input[type=password]')).every((i) => i.value === '')`), 'password fields are empty after the reload');
    },
  },
  {
    id: 'roster-save-details', screen: 'Roster', title: 'Save class details with Enter: focus after the reload', path: rosterPath,
    async run(t) {
      await H.focus(t, '.ro-save-bar button[type=submit]');
      await H.nav(t, () => t.key('Enter'));
      t.check(await t.ev(`Boolean(document.querySelector('.form-success'))`), 'a success notice is shown after saving');
      const where = await t.active();
      t.check(await H.isActive(t, '.form-success') || !(await H.bodyFocused(t)), 'focus after the save lands on the notice or a control, not nowhere (focus: ' + where + ')');
    },
  },
  {
    id: 'roster-edit-dialog', screen: 'Roster', title: 'Edit student dialog: Enter opens, trap, Escape/Cancel return focus, Save', path: rosterPath,
    async run(t) {
      const edit = '[data-edit-student]';
      await H.focus(t, edit);
      const aria = await t.ev(`document.activeElement.getAttribute('aria-label')`);
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('#edit-dialog').open`), 'Enter opens the edit dialog');
      t.check(await H.isActive(t, '#edit-display'), 'focus starts on Display name');
      t.check(await H.trap(t, '#edit-dialog', 10), 'Tab stays inside the dialog');
      t.check(await H.trap(t, '#edit-dialog', 10, true), 'Shift+Tab stays inside the dialog');
      await t.key('Escape');
      await H.wait(t, 200);
      t.check(!(await t.ev(`document.querySelector('#edit-dialog').open`)), 'Escape closes the dialog');
      t.check(await t.ev(`document.activeElement.getAttribute('aria-label') === ${Q(aria)}`), 'Escape returns focus to the same Edit button (' + aria + ')');
      await t.key('Space');
      await H.focus(t, '#edit-dialog [data-edit-close].ui-btn:not(.ui-btn-ghost)');
      await t.key('Enter');
      await H.wait(t, 200);
      t.check(!(await t.ev(`document.querySelector('#edit-dialog').open`)) && await t.ev(`document.activeElement.getAttribute('aria-label') === ${Q(aria)}`), 'Cancel closes it and returns focus to the Edit button');
      await t.key('Enter');
      await t.key('End', { commands: ['moveToEndOfDocument'] });
      await t.type('x');
      await H.focus(t, '#edit-dialog button[type=submit]');
      await H.nav(t, () => t.key('Enter'));
      t.check(await t.ev(`Boolean(document.querySelector('.form-success'))`), 'Save student submits and shows a success notice');
      t.check(!(await H.bodyFocused(t)), 'focus after the save is not lost to <body> (focus: ' + (await t.active()) + ')');
    },
  },
  {
    id: 'roster-archive-restore', screen: 'Roster', title: 'Archive confirm and Restore by keyboard', path: rosterPath,
    async run(t) {
      const before = await t.ev(`document.querySelectorAll('[data-student-row]').length`);
      await H.focus(t, '.ro-archive > summary');
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('.ro-archive').open`), 'Enter opens the archive confirmation');
      await t.key('Tab');
      t.check(await H.isActive(t, '.ro-archive[open] button[type=submit]'), 'Tab moves to Confirm archive');
      await H.focus(t, '.ro-archive > summary');
      await t.key('Space');
      t.check(!(await t.ev(`document.querySelector('.ro-archive').open`)), 'Space closes it again');
      await t.key('Enter');
      await t.key('Tab');
      await H.nav(t, () => t.key('Enter'));
      t.check((await t.ev(`document.querySelectorAll('[data-student-row]').length`)) === before - 1, 'Enter on Confirm archive archives the student');
      t.info('focus after the archive reload: ' + (await t.active()));
      await H.focus(t, '#archived > summary');
      await t.key('Enter');
      t.check(await t.ev(`document.querySelector('#archived').open`), 'Enter opens Archived students');
      await t.key('Tab');
      t.check(await H.isActive(t, '#archived button[type=submit]'), 'Tab moves to Restore');
      await H.nav(t, () => t.key('Enter'));
      t.check((await t.ev(`document.querySelectorAll('[data-student-row]').length`)) === before, 'Enter on Restore brings the student back');
    },
  },

  // ---------------------------------------------------------------- Import
  {
    id: 'import-upload-preview', screen: 'Import', title: 'Import upload form and preview table by keyboard', path: (c) => `/classes/${c.scratchId}/import`,
    async run(t) {
      await H.focus(t, '#import-pasted');
      await t.type('Kb One');
      await t.key('Enter');
      await t.type('Kb Two');
      await t.key('Tab');
      t.check(await H.isActive(t, '.imp-panel button[type=submit]'), 'Tab from the textarea reaches Preview import');
      await H.nav(t, () => t.key('Enter'));
      t.check(await t.ev(`document.querySelectorAll('.imp-table tbody tr').length === 2`), 'the preview lists both pasted rows');
      await H.focus(t, '.imp-table input[type=checkbox]');
      const was = await t.ev(`document.activeElement.checked`);
      await t.key('Space');
      t.check((await t.ev(`document.activeElement.checked`)) === !was, 'Space toggles a row checkbox');
      t.check(await H.isActive(t, '.imp-table input[type=checkbox]'), 'focus stays on the checkbox');
      await H.focus(t, '.imp-table-wrap');
      t.check(await H.isActive(t, '.imp-table-wrap'), 'the scrollable table region is focusable');
      await t.key('Tab'); // leaves the region forward
      t.check(!(await H.isActive(t, '.imp-table-wrap')), 'Tab leaves the table region (no trap)');
    },
  },

  // ---------------------------------------------------------------- Student history
  {
    id: 'student-history', screen: 'Student history', title: 'Student history: back link and scroll regions', path: (c) => `/semester?class=${c.scratchId}`,
    async run(t) {
      await H.focus(t, 'a.link-plain[data-student-name]');
      await H.nav(t, () => t.key('Enter'));
      t.check(/\/students\/\d+/.test(await t.ev('location.pathname')), 'Enter on a student name opens the history page');
      await H.focus(t, 'a.action-btn');
      await H.nav(t, () => t.key('Enter'));
      t.check((await t.ev('location.pathname')) === '/semester', 'Enter on "Back to Semester Analytics" returns to Semester');
    },
  },
];
