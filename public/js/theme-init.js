// Blocking script in <head>: applies a stored theme before first paint.
(function () {
  try {
    var stored = localStorage.getItem('classpulse-theme');
    if (stored === 'light' || stored === 'dark') {
      document.documentElement.dataset.theme = stored;
    }
  } catch (e) {
    // Storage can be blocked; the OS preference applies.
  }
})();
