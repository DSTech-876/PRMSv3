/**
 * DGC PIAMS — Theme controller (light / dark / system).
 *
 * The initial theme is applied by a tiny inline script in includes/header.php
 * before first paint to avoid a flash of the wrong theme. This file only owns
 * the toggle control and reacting to OS preference changes.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'piams.theme';
  var ORDER = ['light', 'dark', 'system'];
  var ICONS = { light: 'bi-sun', dark: 'bi-moon-stars', system: 'bi-circle-half' };
  var LABELS = { light: 'Light theme', dark: 'Dark theme', system: 'System theme' };

  function stored() {
    try {
      var value = localStorage.getItem(STORAGE_KEY);
      return ORDER.indexOf(value) !== -1 ? value : 'system';
    } catch (e) {
      return 'system';
    }
  }

  function prefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function resolve(pref) {
    return pref === 'system' ? (prefersDark() ? 'dark' : 'light') : pref;
  }

  function apply(pref) {
    var effective = resolve(pref);
    document.documentElement.setAttribute('data-theme', effective);
    document.documentElement.setAttribute('data-theme-pref', pref);
    syncButtons(pref);
  }

  function syncButtons(pref) {
    var buttons = document.querySelectorAll('[data-ui-theme-toggle]');
    for (var i = 0; i < buttons.length; i++) {
      var btn = buttons[i];
      var icon = btn.querySelector('i');
      if (icon) { icon.className = 'bi ' + ICONS[pref]; }
      btn.setAttribute('title', LABELS[pref] + ' (click to change)');
      btn.setAttribute('aria-label', LABELS[pref] + '. Activate to change theme.');
    }
  }

  window.PIAMSTheme = {
    get: stored,
    set: function (pref) {
      if (ORDER.indexOf(pref) === -1) { pref = 'system'; }
      try { localStorage.setItem(STORAGE_KEY, pref); } catch (e) { /* storage disabled */ }
      apply(pref);
    },
    cycle: function () {
      var next = ORDER[(ORDER.indexOf(stored()) + 1) % ORDER.length];
      window.PIAMSTheme.set(next);
      return next;
    }
  };

  document.addEventListener('click', function (event) {
    var toggle = event.target.closest && event.target.closest('[data-ui-theme-toggle]');
    if (!toggle) { return; }
    event.preventDefault();
    window.PIAMSTheme.cycle();
  });

  // Follow the OS when the user has not pinned a theme.
  if (window.matchMedia) {
    var query = window.matchMedia('(prefers-color-scheme: dark)');
    var onChange = function () {
      if (stored() === 'system') { apply('system'); }
    };
    if (query.addEventListener) {
      query.addEventListener('change', onChange);
    } else if (query.addListener) {
      query.addListener(onChange);
    }
  }

  function init() { apply(stored()); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
