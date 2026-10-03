// App shell (classic script, deferred): class menu, account menu and the print timestamp.
(function () {
  // Opening one menu closes the others: every menu announces itself and the rest close without stealing focus.
  function announceOpen(source) {
    document.dispatchEvent(new CustomEvent('classpulse:menu-open', { detail: source }));
  }

  // Class menu: <details class="class-menu"> whose <summary> is the whole pill and whose panel holds one real link per class.
  // Without JavaScript the <details> still opens and closes natively and the links work; this adds aria-expanded, focus
  // handling, the arrow keys, Escape, and closing on an outside click or when the focus leaves.
  document.querySelectorAll('[data-class-menu]').forEach(function (menu) {
    var summary = menu.querySelector('summary');
    if (!summary) {
      return;
    }

    function links() {
      return Array.prototype.slice.call(menu.querySelectorAll('.class-menu-panel a[href]'));
    }
    function close(returnFocus) {
      if (!menu.open) {
        return;
      }
      menu.open = false;
      summary.setAttribute('aria-expanded', 'false');
      if (returnFocus) {
        summary.focus();
      }
    }

    menu.addEventListener('toggle', function () {
      summary.setAttribute('aria-expanded', menu.open ? 'true' : 'false');
      if (!menu.open) {
        return;
      }
      announceOpen(menu);
      var list = links();
      var target = menu.querySelector('.class-menu-panel a[aria-current="true"]') || list[0];
      if (target) {
        target.focus();
      }
    });
    summary.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' && !menu.open) {
        event.preventDefault();
        menu.open = true;
      }
    });
    menu.addEventListener('keydown', function (event) {
      if (!menu.open) {
        return;
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close(true);
        return;
      }
      var list = links();
      if (!list.length || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Home' && event.key !== 'End')) {
        return;
      }
      var index = list.indexOf(document.activeElement);
      var next;
      if (event.key === 'Home') {
        next = 0;
      } else if (event.key === 'End') {
        next = list.length - 1;
      } else if (event.key === 'ArrowDown') {
        next = index < 0 ? 0 : (index + 1) % list.length;
      } else {
        next = index < 0 ? list.length - 1 : (index - 1 + list.length) % list.length;
      }
      event.preventDefault();
      list[next].focus();
    });
    menu.addEventListener('focusout', function (event) {
      if (menu.open && event.relatedTarget && !menu.contains(event.relatedTarget)) {
        close(false);
      }
    });
    document.addEventListener('click', function (event) {
      if (menu.open && !menu.contains(event.target)) {
        close(false);
      }
    });
    document.addEventListener('classpulse:menu-open', function (event) {
      if (event.detail !== menu) {
        close(false);
      }
    });
  });

  // Account menu: a button that opens a small popover (signed-in e-mail and Log out).
  document.querySelectorAll('[data-account-menu]').forEach(function (wrap) {
    var toggle = wrap.querySelector('[data-account-toggle]');
    var popover = wrap.querySelector('[role="menu"]');
    if (!toggle || !popover) {
      return;
    }

    function items() {
      return Array.prototype.slice.call(popover.querySelectorAll('[role="menuitem"]'));
    }
    function isOpen() {
      return !popover.hidden;
    }
    function close(returnFocus) {
      if (!isOpen()) {
        return;
      }
      popover.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      if (returnFocus) {
        toggle.focus();
      }
    }
    function open() {
      announceOpen(wrap);
      popover.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      var first = items()[0];
      if (first) {
        first.focus();
      }
    }

    toggle.addEventListener('click', function () {
      if (isOpen()) {
        close(false);
      } else {
        open();
      }
    });
    toggle.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' && !isOpen()) {
        event.preventDefault();
        open();
      }
    });
    popover.addEventListener('keydown', function (event) {
      var list = items();
      var index = list.indexOf(document.activeElement);
      if (event.key === 'ArrowDown' && list.length) {
        event.preventDefault();
        list[(index + 1) % list.length].focus();
      } else if (event.key === 'ArrowUp' && list.length) {
        event.preventDefault();
        list[(index - 1 + list.length) % list.length].focus();
      } else if (event.key === ' ' && document.activeElement && document.activeElement.tagName === 'A') {
        // A link menu item activates with Space as well as Enter (menu pattern).
        event.preventDefault();
        document.activeElement.click();
      }
    });
    wrap.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) {
        event.stopPropagation();
        close(true);
      }
    });
    wrap.addEventListener('focusout', function (event) {
      if (isOpen() && event.relatedTarget && !wrap.contains(event.relatedTarget)) {
        close(false);
      }
    });
    document.addEventListener('click', function (event) {
      if (!wrap.contains(event.target)) {
        close(false);
      }
    });
    document.addEventListener('classpulse:menu-open', function (event) {
      if (event.detail !== wrap) {
        close(false);
      }
    });
  });

  // After a save that reloads the page, the focus would start at the top of the document. Put it on the status
  // message instead (screen readers read it, and Tab continues from there).
  (function focusFlash() {
    var flash = document.querySelector('main .form-success.notice[role="status"], main .flash-status[role="status"]');
    if (!flash || document.activeElement !== document.body || document.querySelector('dialog[open]')) {
      return;
    }
    flash.setAttribute('tabindex', '-1');
    flash.focus();
  })();

  // Print header: stamp the moment of printing, in the school's time zone.
  function stampPrintTime() {
    document.querySelectorAll('[data-printed-at]').forEach(function (node) {
      try {
        var now = new Date();
        node.textContent = new Intl.DateTimeFormat('en-CA', {
          timeZone: node.dataset.tz || 'America/Toronto',
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: 'numeric',
          minute: '2-digit',
        }).format(now);
        node.setAttribute('datetime', now.toISOString());
      } catch (e) {
        // Keep the server-rendered time.
      }
    });
  }
  window.addEventListener('beforeprint', stampPrintTime);
})();
