// Theme switch (classic script, deferred). The active half of the sun/moon control follows <html data-theme> through CSS;
// this script persists the choice, keeps aria-pressed / labels in sync and still supports a single [data-theme-toggle] button.
(function () {
  var root = document.documentElement;

  function currentTheme() {
    if (root.dataset.theme === 'light' || root.dataset.theme === 'dark') {
      return root.dataset.theme;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function apply(theme) {
    root.dataset.theme = theme;
    try {
      localStorage.setItem('classpulse-theme', theme);
    } catch (e) {
      // Storage can be blocked; the choice lasts for this page only.
    }
    updateState(theme);
  }

  function updateState(theme) {
    document.querySelectorAll('[data-theme-set]').forEach(function (button) {
      button.setAttribute('aria-pressed', button.dataset.themeSet === theme ? 'true' : 'false');
    });
    var label = theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
      button.setAttribute('aria-label', label);
    });
  }

  document.querySelectorAll('[data-theme-set]').forEach(function (button) {
    button.addEventListener('click', function () {
      var wanted = button.dataset.themeSet;
      var other = button.parentElement.querySelector('[data-theme-set]:not([data-theme-set="' + wanted + '"])');
      // On narrow screens only the active half is visible: tapping it flips the theme.
      if (wanted === currentTheme() && other && other.offsetParent === null) {
        wanted = other.dataset.themeSet;
      }
      apply(wanted);
    });
  });

  document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      apply(currentTheme() === 'dark' ? 'light' : 'dark');
    });
  });

  var media = window.matchMedia('(prefers-color-scheme: dark)');
  if (media.addEventListener) {
    media.addEventListener('change', function () {
      updateState(currentTheme());
    });
  }

  updateState(currentTheme());
})();
