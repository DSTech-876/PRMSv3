/**
 * DGC PIAMS — Tabs, collapsible sections, lazy loading and form auto-save.
 *
 * Progressive enhancement only: every panel is real markup that remains
 * reachable (and printable) when JavaScript is unavailable.
 */
(function () {
  'use strict';

  /* ── Tabs ─────────────────────────────────────────────────────────── */

  var TAB_STORAGE_PREFIX = 'piams.tab.';

  function panelsFor(group) {
    return Array.prototype.slice.call(
      document.querySelectorAll('[data-ui-tabs-group="' + group + '"]')
    );
  }

  function tabsFor(group) {
    var list = document.querySelector('[data-ui-tabs="' + group + '"]');
    return list
      ? Array.prototype.slice.call(list.querySelectorAll('[data-ui-tab]'))
      : [];
  }

  function activate(group, id, options) {
    options = options || {};
    var tabs = tabsFor(group);
    var found = false;

    tabs.forEach(function (tab) {
      var selected = tab.getAttribute('data-ui-tab') === id;
      if (selected) { found = true; }
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
      tab.setAttribute('tabindex', selected ? '0' : '-1');
      if (selected && options.focus) { tab.focus(); }
    });

    if (!found) { return false; }

    panelsFor(group).forEach(function (panel) {
      var selected = panel.getAttribute('data-ui-tabpanel') === id;
      panel.hidden = !selected;
      if (selected) {
        hydrateLazy(panel);
        // Tables measured while hidden have zero height, so re-measure now.
        window.dispatchEvent(new CustomEvent('ui:panelshown', { detail: { panel: panel } }));
      }
    });

    try { sessionStorage.setItem(TAB_STORAGE_PREFIX + group, id); } catch (e) { /* ignore */ }
    if (options.updateHash !== false) {
      var hash = '#' + group + '=' + id;
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search + hash);
      }
    }
    return true;
  }

  function initTabGroup(list) {
    var group = list.getAttribute('data-ui-tabs');
    var tabs = tabsFor(group);
    if (!tabs.length) { return; }

    list.addEventListener('click', function (event) {
      var tab = event.target.closest('[data-ui-tab]');
      if (!tab || !list.contains(tab)) { return; }
      activate(group, tab.getAttribute('data-ui-tab'));
    });

    // Roving tabindex keyboard navigation (WAI-ARIA tabs pattern).
    list.addEventListener('keydown', function (event) {
      var keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
      if (keys.indexOf(event.key) === -1) { return; }
      var current = tabs.findIndex(function (tab) {
        return tab.getAttribute('aria-selected') === 'true';
      });
      if (current < 0) { current = 0; }

      var next = current;
      if (event.key === 'ArrowRight') { next = (current + 1) % tabs.length; }
      else if (event.key === 'ArrowLeft') { next = (current - 1 + tabs.length) % tabs.length; }
      else if (event.key === 'Home') { next = 0; }
      else if (event.key === 'End') { next = tabs.length - 1; }

      event.preventDefault();
      activate(group, tabs[next].getAttribute('data-ui-tab'), { focus: true });
    });

    // Restore from the URL hash first, then the last session selection.
    var applyHash = function (updateHash) {
      var match = /^#([^=]+)=(.+)$/.exec(window.location.hash || '');
      if (match && decodeURIComponent(match[1]) === group) {
        return activate(group, decodeURIComponent(match[2]), { updateHash: updateHash === true });
      }
      return false;
    };

    // Same-document hash navigation must still switch the panel.
    window.addEventListener('hashchange', function () { applyHash(false); });

    if (applyHash(false)) { return; }
    var remembered = null;
    try { remembered = sessionStorage.getItem(TAB_STORAGE_PREFIX + group); } catch (e) { /* ignore */ }
    if (remembered && activate(group, remembered, { updateHash: false })) { return; }

    // Otherwise hydrate whatever the server marked active.
    panelsFor(group).forEach(function (panel) {
      if (!panel.hidden) { hydrateLazy(panel); }
    });
  }

  /* ── Lazy loading ─────────────────────────────────────────────────── */

  /**
   * Hydrate `[data-ui-lazy-src]` containers inside a newly visible region by
   * fetching their HTML fragment once. Keeps the initial dashboard payload
   * small when a panel is not being looked at.
   */
  function hydrateLazy(root) {
    var targets = (root || document).querySelectorAll('[data-ui-lazy-src]:not([data-ui-lazy-loaded])');
    Array.prototype.forEach.call(targets, function (el) {
      el.setAttribute('data-ui-lazy-loaded', 'pending');
      fetch(el.getAttribute('data-ui-lazy-src'), { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) { throw new Error('HTTP ' + response.status); }
          return response.text();
        })
        .then(function (html) {
          el.innerHTML = html;
          el.setAttribute('data-ui-lazy-loaded', '1');
          document.dispatchEvent(new CustomEvent('ui:lazyloaded', { detail: { container: el } }));
        })
        .catch(function () {
          el.setAttribute('data-ui-lazy-loaded', 'error');
          el.innerHTML = '<div class="ui-empty"><i class="bi bi-exclamation-triangle"></i>'
            + 'Could not load this section. <button type="button" class="ui-action" '
            + 'onclick="location.reload()">Retry</button></div>';
        });
    });
  }

  /* Images/iframes inside dashboards get native lazy loading. */
  function applyNativeLazyLoading() {
    var media = document.querySelectorAll('main img:not([loading]), main iframe:not([loading])');
    Array.prototype.forEach.call(media, function (el) {
      el.setAttribute('loading', 'lazy');
      el.setAttribute('decoding', 'async');
    });
  }

  /* Defer offscreen widgets until they scroll into view. */
  function observeDeferredWidgets() {
    var deferred = document.querySelectorAll('[data-ui-lazy-src][data-ui-lazy-when="visible"]');
    if (!deferred.length) { return; }
    if (!('IntersectionObserver' in window)) {
      hydrateLazy(document);
      return;
    }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) { return; }
        observer.unobserve(entry.target);
        hydrateLazy(entry.target.parentNode || document);
      });
    }, { rootMargin: '200px' });
    Array.prototype.forEach.call(deferred, function (el) { observer.observe(el); });
  }

  /* ── Collapsible section persistence ──────────────────────────────── */

  function initSections() {
    var sections = document.querySelectorAll('.ui-section[data-ui-remember]');
    Array.prototype.forEach.call(sections, function (section) {
      var key = 'piams.section.' + section.getAttribute('data-ui-remember');
      try {
        var saved = localStorage.getItem(key);
        if (saved === 'open') { section.open = true; }
        else if (saved === 'closed') { section.open = false; }
      } catch (e) { /* ignore */ }

      section.addEventListener('toggle', function () {
        try { localStorage.setItem(key, section.open ? 'open' : 'closed'); } catch (e) { /* ignore */ }
        if (section.open) { hydrateLazy(section); }
      });
    });
  }

  /* ── Dropdown menus (column visibility / export) ──────────────────── */

  function closeAllMenus(except) {
    var menus = document.querySelectorAll('[data-ui-menu]');
    Array.prototype.forEach.call(menus, function (menu) {
      if (menu === except) { return; }
      var panel = menu.querySelector('[data-ui-menu-panel]');
      var toggle = menu.querySelector('[data-ui-menu-toggle]');
      if (panel) { panel.hidden = true; }
      if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
    });
  }

  function initMenus() {
    document.addEventListener('click', function (event) {
      var toggle = event.target.closest && event.target.closest('[data-ui-menu-toggle]');
      if (toggle) {
        var menu = toggle.closest('[data-ui-menu]');
        var panel = menu.querySelector('[data-ui-menu-panel]');
        var willOpen = panel.hidden;
        closeAllMenus(menu);
        panel.hidden = !willOpen;
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        return;
      }
      if (!event.target.closest || !event.target.closest('[data-ui-menu-panel]')) {
        closeAllMenus(null);
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { closeAllMenus(null); }
    });
  }

  /* ── Form auto-save (drafts in localStorage) ──────────────────────── */

  /**
   * Opt in with `<form data-ui-autosave="unique-key">`. Field values are
   * persisted locally and restored on return; the draft is cleared once the
   * form is submitted. Password, file and CSRF fields are never stored.
   */
  function initAutosave() {
    var forms = document.querySelectorAll('form[data-ui-autosave]');
    Array.prototype.forEach.call(forms, function (form) {
      var key = 'piams.draft.' + form.getAttribute('data-ui-autosave');
      var indicator = form.querySelector('[data-ui-autosave-status]');
      var timer = null;

      function skip(field) {
        return !field.name
          || field.type === 'password'
          || field.type === 'file'
          || field.type === 'hidden'
          || /csrf|token/i.test(field.name);
      }

      function setStatus(state, text) {
        if (!indicator) { return; }
        indicator.setAttribute('data-state', state);
        indicator.textContent = text;
      }

      try {
        var saved = JSON.parse(localStorage.getItem(key) || 'null');
        if (saved && typeof saved === 'object') {
          Object.keys(saved).forEach(function (name) {
            var field = form.elements[name];
            if (!field || skip(field)) { return; }
            if (field.type === 'checkbox' || field.type === 'radio') {
              field.checked = !!saved[name];
            } else if (!field.value) {
              field.value = saved[name];
            }
          });
          setStatus('saved', 'Draft restored');
        }
      } catch (e) { /* ignore malformed drafts */ }

      form.addEventListener('input', function () {
        setStatus('saving', 'Saving…');
        clearTimeout(timer);
        timer = setTimeout(function () {
          var data = {};
          Array.prototype.forEach.call(form.elements, function (field) {
            if (skip(field)) { return; }
            data[field.name] = (field.type === 'checkbox' || field.type === 'radio')
              ? field.checked
              : field.value;
          });
          try {
            localStorage.setItem(key, JSON.stringify(data));
            setStatus('saved', 'Draft saved');
          } catch (e) {
            setStatus('error', 'Could not save draft');
          }
        }, 600);
      });

      form.addEventListener('submit', function () {
        try { localStorage.removeItem(key); } catch (e) { /* ignore */ }
      });
    });
  }

  /* ── Sticky offsets ───────────────────────────────────────────────── */

  /**
   * Keep sticky tab bars and table toolbars clear of the global topbar so
   * they never overlap while scrolling.
   */
  function updateStickyOffset() {
    var topbar = document.querySelector('.global-topbar');
    var offset = 0;
    if (topbar) {
      var style = window.getComputedStyle(topbar);
      if (style.position === 'sticky' || style.position === 'fixed') {
        offset = topbar.getBoundingClientRect().height;
      }
    }
    document.documentElement.style.setProperty('--ui-sticky-top', offset + 'px');
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-ui-tabs]'), initTabGroup);
    initSections();
    initMenus();
    initAutosave();
    applyNativeLazyLoading();
    observeDeferredWidgets();
    updateStickyOffset();
    window.addEventListener('resize', updateStickyOffset);
  }

  window.PIAMSTabs = { activate: activate, hydrateLazy: hydrateLazy };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
