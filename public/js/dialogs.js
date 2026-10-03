// <dialog> confirm helper: resolves true only when the dialog closes with returnValue 'confirm'.
// Also the keyboard helpers shared by the non-modal sheets (Daily details, Semester inspector): a Tab trap and an
// "opener that is no longer focusable" fallback.
(function () {
  function confirm(dialog) {
    return new Promise(function (resolve) {
      dialog.returnValue = '';
      function onClose() {
        dialog.removeEventListener('close', onClose);
        resolve(dialog.returnValue === 'confirm');
      }
      dialog.addEventListener('close', onClose);
      dialog.showModal();
    });
  }

  // Pure: index of the control that gets focus next while the Tab key is trapped, wrapping at both ends.
  // current is -1 (or out of range) when the focus is on the container itself or outside it.
  function trapIndex(current, count, backwards) {
    if (count <= 0) {
      return -1;
    }
    if (current < 0 || current >= count) {
      return backwards ? count - 1 : 0;
    }
    return backwards ? (current - 1 + count) % count : (current + 1) % count;
  }

  var FOCUSABLE = 'a[href], button, input, select, textarea, summary, [tabindex]';

  function focusableIn(container) {
    return Array.prototype.filter.call(container.querySelectorAll(FOCUSABLE), function (el) {
      if (el.disabled || el.getAttribute('tabindex') === '-1' || el.type === 'hidden') {
        return false;
      }
      return el.getClientRects().length > 0 && !el.closest('[hidden]');
    });
  }

  // Keydown handler body: keeps Tab and Shift+Tab inside container. Returns true when it moved the focus.
  function trapTab(container, event) {
    if (event.key !== 'Tab' || event.altKey || event.ctrlKey || event.metaKey) {
      return false;
    }
    var list = focusableIn(container);
    var next = trapIndex(list.indexOf(document.activeElement), list.length, event.shiftKey);
    var current = list.indexOf(document.activeElement);
    // Only intercept at the ends (or when the focus is not on a listed control); the browser handles the rest.
    var atEnd = event.shiftKey ? current <= 0 : current === list.length - 1;
    if (next === -1 || (current !== -1 && !atEnd)) {
      return false;
    }
    event.preventDefault();
    list[next].focus();
    return true;
  }

  var api = { confirm: confirm, trapIndex: trapIndex, trapTab: trapTab };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { globalThis.ClassPulseDialogs = api; }
})();
