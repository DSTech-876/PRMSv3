/**
 * Collapsible sidebar navigation.
 *
 * Section headings act as accordion toggles: their items stay hidden until the
 * heading is clicked. The group containing the current page is opened on load,
 * and the open/closed state is persisted across page loads.
 *
 * This script runs inline (before first paint) so groups never flash open.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'piams.sidebarOpenGroups';

  function readOpenGroups() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      var parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function writeOpenGroups(ids) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
    } catch (e) {
      /* storage unavailable - state simply is not persisted */
    }
  }

  function setGroupState(toggle, panel, open) {
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.classList.toggle('is-open', open);
  }

  function init() {
    var sidebar = document.getElementById('sidebarMenu');
    if (!sidebar || sidebar.dataset.sidebarGroupsReady === '1') return;
    sidebar.dataset.sidebarGroupsReady = '1';

    var toggles = Array.prototype.slice.call(
      sidebar.querySelectorAll('.sidebar-group-toggle')
    );
    if (!toggles.length) return;

    var stored = readOpenGroups();
    var groups = [];

    toggles.forEach(function (toggle) {
      var id = toggle.getAttribute('data-sidebar-group');
      var panel = id ? document.getElementById(id) : null;
      if (!panel) return;

      var hasActiveChild = !!panel.querySelector('.sidebar-active');
      var open = hasActiveChild || stored.indexOf(id) !== -1;
      toggle.classList.toggle('has-active-child', hasActiveChild && !open);
      setGroupState(toggle, panel, open);
      groups.push({ id: id, toggle: toggle, panel: panel });
    });

    function persist() {
      writeOpenGroups(
        groups
          .filter(function (g) {
            return g.panel.classList.contains('is-open');
          })
          .map(function (g) {
            return g.id;
          })
      );
    }

    groups.forEach(function (group) {
      group.toggle.addEventListener('click', function () {
        var willOpen = !group.panel.classList.contains('is-open');
        setGroupState(group.toggle, group.panel, willOpen);
        if (willOpen) {
          group.toggle.classList.remove('has-active-child');
        } else if (group.panel.querySelector('.sidebar-active')) {
          group.toggle.classList.add('has-active-child');
        }
        persist();
      });
    });

    persist();
    initFilter(sidebar, groups, persist);
  }

  /**
   * Progressive-enhancement quick filter: typing narrows the sidebar to the
   * matching links and auto-opens the groups that still have visible items.
   */
  function initFilter(sidebar, groups, persist) {
    var wrap = sidebar.querySelector('[data-sidebar-filter]');
    if (!wrap) return;
    var input = wrap.querySelector('.sidebar-filter__input');
    var status = wrap.querySelector('.sidebar-filter__status');
    if (!input) return;

    wrap.hidden = false;

    var preFilterState = null;

    /* Links that live outside any collapsible group (e.g. Dashboard). */
    var looseLinks = Array.prototype.slice
      .call(sidebar.querySelectorAll('.sidebar-link'))
      .filter(function (link) { return !link.closest('.sidebar-group-items'); });

    function restore() {
      sidebar.querySelectorAll('.is-filtered-out').forEach(function (el) {
        el.classList.remove('is-filtered-out');
      });
      if (preFilterState) {
        groups.forEach(function (group) {
          setGroupState(group.toggle, group.panel, preFilterState.indexOf(group.id) !== -1);
        });
      }
      preFilterState = null;
      if (status) status.textContent = '';
    }

    function apply(term) {
      if (preFilterState === null) {
        preFilterState = groups
          .filter(function (g) { return g.panel.classList.contains('is-open'); })
          .map(function (g) { return g.id; });
      }

      var matches = 0;
      groups.forEach(function (group) {
        var visible = 0;
        group.panel.querySelectorAll('.sidebar-link').forEach(function (link) {
          var hit = link.textContent.toLowerCase().indexOf(term) !== -1;
          link.classList.toggle('is-filtered-out', !hit);
          var item = link.closest('.nav-item');
          if (item && item !== group.toggle.closest('.nav-item')) {
            item.classList.toggle('is-filtered-out', !hit);
          }
          if (hit) visible++;
        });
        matches += visible;
        var host = group.toggle.closest('.nav-item');
        if (host) host.classList.toggle('is-filtered-out', visible === 0);
        setGroupState(group.toggle, group.panel, visible > 0);
      });

      looseLinks.forEach(function (link) {
        var hit = link.textContent.toLowerCase().indexOf(term) !== -1;
        link.classList.toggle('is-filtered-out', !hit);
        var item = link.closest('.nav-item');
        if (item) item.classList.toggle('is-filtered-out', !hit);
        if (hit) matches++;
      });

      if (status) {
        status.textContent = matches
          ? matches + ' link' + (matches === 1 ? '' : 's') + ' match'
          : 'No matching links';
      }
    }

    input.addEventListener('input', function () {
      var term = input.value.trim().toLowerCase();
      if (!term) {
        restore();
        persist();
        return;
      }
      apply(term);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      input.value = '';
      restore();
      persist();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  }
  init();
})();
