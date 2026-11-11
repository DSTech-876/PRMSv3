<?php
/**
 * SidebarNavigationGroupsTest
 * ===========================
 * Guards the collapsible sidebar navigation contract: section headings are
 * rendered as accordion toggles, their items live inside a collapsible group
 * that is closed by default, and the controlling script/styles stay wired up.
 */

$root = dirname(__DIR__, 2);

$sidebar = file_get_contents($root . '/includes/sidebar.php');
$header = file_get_contents($root . '/includes/header.php');
$script = file_get_contents($root . '/assets/js/sidebar-nav.js');
$css = file_get_contents($root . '/assets/css/app.css');

$passed = 0;
$failed = 0;

function sidebarAssert(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS  {$name}\n";
        $passed++;
        return;
    }

    echo "  FAIL  {$name}\n";
    $failed++;
}

echo "SidebarNavigationGroupsTest\n";

$toggleCount = preg_match_all('/class="sidebar-group-toggle"/', $sidebar);
$panelCount = preg_match_all('/class="sidebar-group-items"/', $sidebar);
$bodyCount = preg_match_all('/class="sidebar-group-body"/', $sidebar);

sidebarAssert('sidebar renders at least one collapsible group', $toggleCount > 0);

sidebarAssert(
    'every heading toggle has a matching group panel and body wrapper',
    $toggleCount === $panelCount && $panelCount === $bodyCount
);

sidebarAssert(
    'legacy static section labels are fully replaced',
    strpos($sidebar, 'sidebar-section-label') === false
);

preg_match_all(
    '/<button class="sidebar-group-toggle" type="button" data-sidebar-group="(sidebar-group-[a-z0-9-]+)" aria-expanded="false" aria-controls="\1">/',
    $sidebar,
    $toggleMatches
);
sidebarAssert(
    'toggles start collapsed and reference their own panel id',
    count($toggleMatches[1]) === $toggleCount
);

preg_match_all('/<div class="sidebar-group-items" id="(sidebar-group-[a-z0-9-]+)"/', $sidebar, $panelMatches);
sidebarAssert(
    'each group panel id is unique and matched by a toggle',
    count(array_unique($panelMatches[1])) === $panelCount
        && array_diff($panelMatches[1], $toggleMatches[1]) === []
);

sidebarAssert(
    'div tags remain balanced after grouping',
    preg_match_all('/<div\b/', $sidebar) === substr_count($sidebar, '</div>')
);

sidebarAssert(
    'no-script fallback keeps every group readable',
    strpos($sidebar, '<noscript><style>.sidebar-group-items{grid-template-rows:1fr !important;}</style></noscript>') !== false
);

sidebarAssert(
    'sidebar group script is loaded before first paint in the header',
    strpos($header, '/assets/js/sidebar-nav.js') !== false
        && strpos($header, '/assets/js/sidebar-nav.js') > strpos($header, "includes/sidebar.php")
);

sidebarAssert(
    'groups collapse by default and expand via the is-open class',
    preg_match('/\.sidebar-group-items\s*\{[^}]*grid-template-rows:\s*0fr;/', $css) === 1
        && strpos($css, '.sidebar-group-items.is-open { grid-template-rows: 1fr; }') !== false
);

sidebarAssert(
    'script opens the group containing the active link',
    strpos($script, "panel.querySelector('.sidebar-active')") !== false
);

sidebarAssert(
    'script persists open groups across page loads',
    strpos($script, "'piams.sidebarOpenGroups'") !== false
        && strpos($script, 'sessionStorage.setItem(STORAGE_KEY') !== false
);

sidebarAssert(
    'script keeps aria-expanded in sync with the panel state',
    strpos($script, "toggle.setAttribute('aria-expanded', open ? 'true' : 'false')") !== false
);

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
