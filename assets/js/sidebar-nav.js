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
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  }
  init();
})();
