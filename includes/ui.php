<?php
/**
 * DGC PIAMS — Shared UI component helpers.
 *
 * Presentation-only helpers that render the markup defined by
 * assets/css/design-system.css. These functions never query the
 * database and never perform permission checks: callers remain
 * responsible for authorisation and data retrieval exactly as before.
 *
 * All helpers escape their input.
 */

if (!function_exists('ui_e')) {
    /** Escape a value for HTML output. */
    function ui_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ui_attr')) {
    /**
     * Render an associative array as HTML attributes.
     * Boolean `true` renders a valueless attribute; `false`/`null` are skipped.
     */
    function ui_attr(array $attributes): string
    {
        $out = [];
        foreach ($attributes as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            if ($value === true) {
                $out[] = ui_e($name);
                continue;
            }
            $out[] = ui_e($name) . '="' . ui_e($value) . '"';
        }
        return $out ? ' ' . implode(' ', $out) : '';
    }
}

if (!function_exists('ui_breadcrumbs')) {
    /**
     * Render a breadcrumb trail.
     *
     * @param array $items List of ['label' => string, 'href' => ?string].
     *                     The final item is rendered as the current page.
     */
    function ui_breadcrumbs(array $items): void
    {
        if (!$items) {
            return;
        }
        $last = count($items) - 1;
        echo '<nav aria-label="Breadcrumb"><ol class="ui-breadcrumb">';
        foreach ($items as $index => $item) {
            $label = ui_e($item['label'] ?? '');
            if ($index === $last || empty($item['href'])) {
                echo '<li><span aria-current="page">' . $label . '</span></li>';
            } else {
                echo '<li><a href="' . ui_e($item['href']) . '">' . $label . '</a></li>';
            }
        }
        echo '</ol></nav>';
    }
}

if (!function_exists('ui_page_header')) {
    /**
     * Render the standard dashboard page header.
     *
     * @param array $options {
     *   @type string $title       Required page title.
     *   @type string $subtitle    Optional supporting line.
     *   @type string $icon        Bootstrap icon class, e.g. 'bi-speedometer2'.
     *   @type array  $breadcrumbs Items for ui_breadcrumbs().
     *   @type array  $actions     List of ['label','href','icon','variant'=>'primary'|null,
     *                             'onclick','type'=>'button'] rendered as pills.
     * }
     */
    function ui_page_header(array $options): void
    {
        $title       = (string)($options['title'] ?? '');
        $subtitle    = (string)($options['subtitle'] ?? '');
        $icon        = (string)($options['icon'] ?? 'bi-grid-1x2');
        $breadcrumbs = $options['breadcrumbs'] ?? [];
        $actions     = $options['actions'] ?? [];

        if ($breadcrumbs) {
            ui_breadcrumbs($breadcrumbs);
        }

        echo '<header class="ui-page-header">';
        echo '<div class="ui-page-header__main">';
        echo '<span class="ui-page-header__icon" aria-hidden="true"><i class="bi ' . ui_e($icon) . '"></i></span>';
        echo '<div>';
        echo '<h1 class="ui-page-header__title">' . ui_e($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="ui-page-header__subtitle">' . ui_e($subtitle) . '</p>';
        }
        echo '</div></div>';

        if ($actions) {
            echo '<div class="ui-page-header__actions">';
            ui_actions($actions);
            echo '</div>';
        }
        echo '</header>';
    }
}

if (!function_exists('ui_actions')) {
    /**
     * Render a row of quick-action pills.
     *
     * @param array $actions List of ['label','href','icon','variant','onclick','title'].
     */
    function ui_actions(array $actions): void
    {
        foreach ($actions as $action) {
            $classes = 'ui-action';
            if (($action['variant'] ?? '') === 'primary') {
                $classes .= ' ui-action--primary';
            }
            $icon  = !empty($action['icon']) ? '<i class="bi ' . ui_e($action['icon']) . '" aria-hidden="true"></i>' : '';
            $label = ui_e($action['label'] ?? '');

            if (!empty($action['href'])) {
                echo '<a class="' . $classes . '" href="' . ui_e($action['href']) . '"'
                    . (!empty($action['title']) ? ' title="' . ui_e($action['title']) . '"' : '')
                    . '>' . $icon . '<span>' . $label . '</span></a>';
            } else {
                echo '<button type="button" class="' . $classes . '"'
                    . (!empty($action['onclick']) ? ' onclick="' . ui_e($action['onclick']) . '"' : '')
                    . (!empty($action['title']) ? ' title="' . ui_e($action['title']) . '"' : '')
                    . '>' . $icon . '<span>' . $label . '</span></button>';
            }
        }
    }
}

if (!function_exists('ui_action_tiles')) {
    /**
     * Render quick actions as a dense tile grid (quick actions panel).
     *
     * @param array $actions List of ['label','href','icon'].
     */
    function ui_action_tiles(array $actions): void
    {
        echo '<div class="ui-action-grid">';
        foreach ($actions as $action) {
            $hasValue = isset($action['value']) && $action['value'] !== null && $action['value'] !== '';
            $variant  = (string)($action['variant'] ?? '');
            $class    = 'ui-action-tile' . ($variant !== '' ? ' ui-action-tile--' . ui_e($variant) : '');
            $tag      = !empty($action['href']) ? 'a' : 'div';
            $href     = !empty($action['href']) ? ' href="' . ui_e($action['href']) . '"' : '';

            echo '<' . $tag . ' class="' . $class . '"' . $href . '>';
            if (!empty($action['icon'])) {
                echo '<i class="bi ' . ui_e($action['icon']) . '" aria-hidden="true"></i>';
            }
            if ($hasValue) {
                echo '<span class="ui-action-tile__value">' . ui_e((string)$action['value']) . '</span>';
            }
            echo '<span class="ui-truncate">' . ui_e($action['label'] ?? '') . '</span>';
            echo '</' . $tag . '>';
        }
        echo '</div>';
    }
}

if (!function_exists('ui_kpi_grid')) {
    /**
     * Render a grid of KPI tiles.
     *
     * @param array $kpis List of [
     *   'label','value','meta','icon','href',
     *   'variant' => green|blue|amber|red|purple|slate
     * ]. `value` is printed verbatim after escaping so callers can
     * pre-format currency/compact numbers.
     */
    function ui_kpi_grid(array $kpis): void
    {
        echo '<div class="ui-kpi-grid">';
        foreach ($kpis as $kpi) {
            $variant = (string)($kpi['variant'] ?? 'slate');
            $classes = 'ui-kpi ui-kpi--' . ui_e($variant);
            $tag     = !empty($kpi['href']) ? 'a' : 'div';
            $href    = !empty($kpi['href']) ? ' href="' . ui_e($kpi['href']) . '"' : '';

            echo '<' . $tag . ' class="' . $classes . '"' . $href . '>';
            echo '<span class="ui-kpi__icon" aria-hidden="true"><i class="bi ' . ui_e($kpi['icon'] ?? 'bi-dot') . '"></i></span>';
            echo '<span class="ui-kpi__body">';
            echo '<span class="ui-kpi__label">' . ui_e($kpi['label'] ?? '') . '</span>';
            echo '<span class="ui-kpi__value">' . ui_e($kpi['value'] ?? '0') . '</span>';
            if (isset($kpi['meta']) && $kpi['meta'] !== '') {
                echo '<span class="ui-kpi__meta">' . ui_e($kpi['meta']) . '</span>';
            }
            echo '</span>';
            echo '</' . $tag . '>';
        }
        echo '</div>';
    }
}

if (!function_exists('ui_card_open')) {
    /**
     * Open a card. Must be paired with ui_card_close().
     *
     * @param array $options {
     *   @type string $title    Card heading.
     *   @type string $icon     Bootstrap icon class.
     *   @type string $tools    Raw HTML rendered in the header's right slot.
     *                          Callers are responsible for escaping it.
     *   @type bool   $flush    Remove body padding (for tables).
     *   @type string $class    Extra classes for the card element.
     *   @type string $bodyClass Extra classes for the body element.
     *   @type string $id       Element id.
     * }
     */
    function ui_card_open(array $options = []): void
    {
        $class = trim('ui-card ' . (string)($options['class'] ?? ''));
        echo '<section class="' . ui_e($class) . '"'
            . (!empty($options['id']) ? ' id="' . ui_e($options['id']) . '"' : '') . '>';

        if (!empty($options['title'])) {
            echo '<div class="ui-card__header">';
            echo '<div class="ui-card__heading">';
            echo '<h2 class="ui-card__title">';
            if (!empty($options['icon'])) {
                echo '<i class="bi ' . ui_e($options['icon']) . '" aria-hidden="true"></i>';
            }
            echo ui_e($options['title']);
            echo '</h2>';
            if (!empty($options['subtitle'])) {
                echo '<p class="ui-card__subtitle">' . ui_e($options['subtitle']) . '</p>';
            }
            echo '</div>';
            if (!empty($options['tools'])) {
                echo '<div class="ui-card__tools">' . $options['tools'] . '</div>';
            }
            echo '</div>';
        }

        $bodyClass = 'ui-card__body'
            . (!empty($options['flush']) ? ' ui-card__body--flush' : '')
            . (!empty($options['bodyClass']) ? ' ' . (string)$options['bodyClass'] : '');
        echo '<div class="' . ui_e($bodyClass) . '">';
    }
}

if (!function_exists('ui_card_close')) {
    /**
     * Close a card opened with ui_card_open().
     *
     * @param string $footerHtml Optional raw footer HTML (caller-escaped).
     */
    function ui_card_close(string $footerHtml = ''): void
    {
        echo '</div>';
        if ($footerHtml !== '') {
            echo '<div class="ui-card__footer">' . $footerHtml . '</div>';
        }
        echo '</section>';
    }
}

if (!function_exists('ui_section_open')) {
    /**
     * Open a collapsible <details> section.
     *
     * @param string $title Section heading.
     * @param bool   $open  Whether the section starts expanded.
     * @param string $icon  Bootstrap icon class.
     */
    function ui_section_open(string $title, bool $open = true, string $icon = ''): void
    {
        echo '<details class="ui-section"' . ($open ? ' open' : '') . '>';
        echo '<summary>';
        if ($icon !== '') {
            echo '<i class="bi ' . ui_e($icon) . '" aria-hidden="true"></i>';
        }
        echo ui_e($title) . '</summary>';
        echo '<div class="ui-section__body">';
    }
}

if (!function_exists('ui_section_close')) {
    /** Close a section opened with ui_section_open(). */
    function ui_section_close(): void
    {
        echo '</div></details>';
    }
}

if (!function_exists('ui_badge')) {
    /**
     * Render a status badge.
     *
     * @param string $label   Visible text.
     * @param string $variant success|info|warning|danger|purple|neutral.
     * @param bool   $withDot Prefix with a status dot.
     */
    function ui_badge(string $label, string $variant = 'neutral', bool $withDot = false): string
    {
        $allowed = ['success', 'info', 'warning', 'danger', 'purple', 'neutral'];
        if (!in_array($variant, $allowed, true)) {
            $variant = 'neutral';
        }
        return '<span class="ui-badge ui-badge--' . $variant . '">'
            . ($withDot ? '<span class="ui-dot" aria-hidden="true"></span>' : '')
            . ui_e($label) . '</span>';
    }
}

if (!function_exists('ui_status_variant')) {
    /**
     * Map a workflow status string onto a badge variant so status
     * colours stay consistent across every module.
     */
    function ui_status_variant(?string $status): string
    {
        $s = strtoupper(trim((string)$status));
        if ($s === '') {
            return 'neutral';
        }
        $map = [
            'success' => ['APPROVED', 'COMPLETED', 'PAID', 'AWARDED', 'ACTIVE', 'RECEIVED', 'CLOSED', 'ISSUED', 'VERIFIED', 'DELIVERED'],
            'danger'  => ['REJECTED', 'DECLINED', 'CANCELLED', 'CANCELED', 'OVERDUE', 'FAILED', 'EXPIRED', 'BLOCKED'],
            'warning' => ['PENDING', 'SUBMITTED', 'AWAITING', 'ON_HOLD', 'HOLD', 'RETURNED', 'CLARIFICATION', 'DRAFT', 'UNPAID', 'PARTIAL'],
            'info'    => ['IN_PROGRESS', 'PROCESSING', 'OPEN', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED', 'RFQ_ISSUED', 'UNDER_REVIEW'],
        ];
        foreach ($map as $variant => $values) {
            if (in_array($s, $values, true)) {
                return $variant;
            }
        }
        // Fall back to substring matching for composite statuses such as
        // "PENDING_FINANCE_VERIFICATION" or "RFQ_SPEC_REVIEW".
        foreach ($map as $variant => $values) {
            foreach ($values as $value) {
                if (str_contains($s, $value)) {
                    return $variant;
                }
            }
        }
        return 'info';
    }
}

if (!function_exists('ui_status_badge')) {
    /** Render a status badge whose colour is derived from the status text. */
    function ui_status_badge(?string $status, ?string $label = null): string
    {
        $text = $label ?? str_replace('_', ' ', strtoupper(trim((string)$status)));
        if (trim((string)$text) === '') {
            $text = '—';
        }
        return ui_badge($text, ui_status_variant($status), true);
    }
}

if (!function_exists('ui_empty')) {
    /** Render an empty-state block. */
    function ui_empty(string $message, string $icon = 'bi-inbox'): void
    {
        echo '<div class="ui-empty"><i class="bi ' . ui_e($icon) . '" aria-hidden="true"></i>'
            . ui_e($message) . '</div>';
    }
}

if (!function_exists('ui_tabs')) {
    /**
     * Render a tablist. Panels are rendered separately with
     * ui_tabpanel_open()/ui_tabpanel_close() using the same ids.
     *
     * @param string $group Unique group name; also used for deep-linking (#group=id).
     * @param array  $tabs  List of ['id','label','icon','count','active'=>bool].
     */
    function ui_tabs(string $group, array $tabs): void
    {
        echo '<div class="ui-tabs" role="tablist" data-ui-tabs="' . ui_e($group) . '"'
            . ' aria-label="' . ui_e(ucfirst(str_replace(['-', '_'], ' ', $group))) . ' sections">';
        $hasActive = false;
        foreach ($tabs as $tab) {
            if (!empty($tab['active'])) {
                $hasActive = true;
                break;
            }
        }
        foreach ($tabs as $index => $tab) {
            $id      = (string)($tab['id'] ?? ('tab' . $index));
            $active  = !empty($tab['active']) || (!$hasActive && $index === 0);
            echo '<button type="button" class="ui-tab" role="tab"'
                . ' id="' . ui_e($group . '-tab-' . $id) . '"'
                . ' aria-controls="' . ui_e($group . '-panel-' . $id) . '"'
                . ' aria-selected="' . ($active ? 'true' : 'false') . '"'
                . ' tabindex="' . ($active ? '0' : '-1') . '"'
                . ' data-ui-tab="' . ui_e($id) . '">';
            if (!empty($tab['icon'])) {
                echo '<i class="bi ' . ui_e($tab['icon']) . '" aria-hidden="true"></i>';
            }
            echo '<span>' . ui_e($tab['label'] ?? $id) . '</span>';
            if (isset($tab['count']) && $tab['count'] !== null && $tab['count'] !== '') {
                echo '<span class="ui-tab__count">' . ui_e($tab['count']) . '</span>';
            }
            echo '</button>';
        }
        echo '</div>';
    }
}

if (!function_exists('ui_tabpanel_open')) {
    /**
     * Open a tab panel. `$active` must match the corresponding tab so the
     * correct panel is visible before JavaScript runs (no flash of content).
     */
    function ui_tabpanel_open(string $group, string $id, bool $active = false): void
    {
        echo '<div class="ui-tabpanel" role="tabpanel"'
            . ' id="' . ui_e($group . '-panel-' . $id) . '"'
            . ' aria-labelledby="' . ui_e($group . '-tab-' . $id) . '"'
            . ' data-ui-tabpanel="' . ui_e($id) . '"'
            . ' data-ui-tabs-group="' . ui_e($group) . '"'
            . ' tabindex="0"'
            . ($active ? '' : ' hidden') . '>';
    }
}

if (!function_exists('ui_tabpanel_close')) {
    /** Close a tab panel opened with ui_tabpanel_open(). */
    function ui_tabpanel_close(): void
    {
        echo '</div>';
    }
}

if (!function_exists('ui_chart_bars')) {
    /**
     * Render a horizontal bar chart from label/value pairs.
     *
     * @param array  $rows   List of ['label','value','href','color'].
     * @param string $format 'number' or 'currency'.
     */
    function ui_chart_bars(array $rows, string $format = 'number'): void
    {
        if (!$rows) {
            ui_empty('No data available.', 'bi-bar-chart');
            return;
        }
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (float)($row['value'] ?? 0));
        }
        $max = $max > 0 ? $max : 1;

        echo '<div class="ui-chart">';
        foreach ($rows as $row) {
            $value   = (float)($row['value'] ?? 0);
            $percent = round(($value / $max) * 100, 1);
            $display = $format === 'currency'
                ? number_format($value, 0)
                : number_format($value);
            $label   = ui_e($row['label'] ?? '');
            $style   = !empty($row['color']) ? ' style="--ui-bar-color:' . ui_e($row['color']) . '"' : '';

            echo '<div class="ui-chart__row"' . $style . '>';
            if (!empty($row['href'])) {
                echo '<a class="ui-chart__label" href="' . ui_e($row['href']) . '" title="' . $label . '">' . $label . '</a>';
            } else {
                echo '<span class="ui-chart__label" title="' . $label . '">' . $label . '</span>';
            }
            echo '<span class="ui-chart__track" role="img" aria-label="' . $label . ': ' . ui_e($display) . '">'
                . '<span class="ui-chart__bar" style="width:' . $percent . '%"></span></span>';
            echo '<span class="ui-chart__value">' . ui_e($display) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('ui_chart_columns')) {
    /**
     * Render a vertical column chart (e.g. a monthly trend).
     *
     * @param array $rows List of ['label','value'].
     */
    function ui_chart_columns(array $rows): void
    {
        if (!$rows) {
            ui_empty('No trend data available.', 'bi-graph-up');
            return;
        }
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (float)($row['value'] ?? 0));
        }
        $max = $max > 0 ? $max : 1;

        echo '<div class="ui-columns">';
        foreach ($rows as $row) {
            $value   = (float)($row['value'] ?? 0);
            $percent = max(2, round(($value / $max) * 100));
            $label   = ui_e($row['label'] ?? '');
            echo '<div class="ui-columns__item" title="' . $label . ': ' . ui_e(number_format($value)) . '">'
                . '<span class="ui-columns__cap">' . ui_e(number_format($value)) . '</span>'
                . '<span class="ui-columns__bar" style="height:' . $percent . '%"></span>'
                . '<span class="ui-columns__cap">' . $label . '</span>'
                . '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('ui_distribution')) {
    /**
     * Render a status distribution strip with a legend.
     *
     * @param array $segments List of ['label','value','color'].
     */
    function ui_distribution(array $segments): void
    {
        $total = 0;
        foreach ($segments as $segment) {
            $total += (float)($segment['value'] ?? 0);
        }
        if ($total <= 0) {
            ui_empty('No distribution data available.', 'bi-pie-chart');
            return;
        }

        echo '<div class="ui-distribution" role="img" aria-label="Status distribution">';
        foreach ($segments as $segment) {
            $percent = round(((float)($segment['value'] ?? 0) / $total) * 100, 2);
            if ($percent <= 0) {
                continue;
            }
            echo '<span class="ui-distribution__seg" style="width:' . $percent . '%;background:'
                . ui_e($segment['color'] ?? '#94a3b8') . '"'
                . ' title="' . ui_e($segment['label'] ?? '') . ': ' . ui_e(number_format((float)$segment['value'])) . '"></span>';
        }
        echo '</div>';

        echo '<div class="ui-legend">';
        foreach ($segments as $segment) {
            echo '<span class="ui-legend__item">'
                . '<span class="ui-legend__swatch" style="background:' . ui_e($segment['color'] ?? '#94a3b8') . '"></span>'
                . ui_e($segment['label'] ?? '') . ' (' . ui_e(number_format((float)($segment['value'] ?? 0))) . ')'
                . '</span>';
        }
        echo '</div>';
    }
}

if (!function_exists('ui_table_toolbar')) {
    /**
     * Render the sticky toolbar for an enhanced table: search box,
     * optional extra controls, a column-visibility menu and an export menu.
     *
     * The toolbar is wired up client-side by assets/js/ui-datatable.js via
     * the shared `$tableId`.
     *
     * @param string $tableId  Id of the table being controlled.
     * @param array  $options {
     *   @type string $placeholder Search placeholder text.
     *   @type bool   $search      Show the search field (default true).
     *   @type bool   $columns     Show the column-visibility menu (default true).
     *   @type bool   $export      Show the export menu (default true).
     *   @type string $extra       Raw HTML inserted before the menus (caller-escaped).
     * }
     */
    function ui_table_toolbar(string $tableId, array $options = []): void
    {
        $showSearch  = $options['search']  ?? true;
        $showColumns = $options['columns'] ?? true;
        $showExport  = $options['export']  ?? true;
        $placeholder = (string)($options['placeholder'] ?? 'Search table…');

        echo '<div class="ui-table-toolbar" data-ui-toolbar-for="' . ui_e($tableId) . '">';

        if ($showSearch) {
            $searchId = $tableId . '-search';
            echo '<div class="ui-table-toolbar__search">';
            echo '<i class="bi bi-search" aria-hidden="true"></i>';
            echo '<label class="ui-visually-hidden" for="' . ui_e($searchId) . '">Search table</label>';
            echo '<input type="search" id="' . ui_e($searchId) . '" data-ui-table-search="' . ui_e($tableId) . '"'
                . ' placeholder="' . ui_e($placeholder) . '" autocomplete="off">';
            echo '</div>';
        }

        if (!empty($options['extra'])) {
            echo $options['extra'];
        }

        echo '<span class="ui-table-toolbar__spacer"></span>';

        if ($showColumns) {
            echo '<div class="ui-menu" data-ui-menu>';
            echo '<button type="button" class="ui-menu__btn" data-ui-menu-toggle aria-expanded="false" aria-haspopup="true">'
                . '<i class="bi bi-layout-three-columns" aria-hidden="true"></i><span>Columns</span></button>';
            echo '<div class="ui-menu__panel" data-ui-menu-panel data-ui-column-menu="' . ui_e($tableId) . '" hidden></div>';
            echo '</div>';
        }

        if ($showExport) {
            echo '<div class="ui-menu" data-ui-menu>';
            echo '<button type="button" class="ui-menu__btn" data-ui-menu-toggle aria-expanded="false" aria-haspopup="true">'
                . '<i class="bi bi-download" aria-hidden="true"></i><span>Export</span></button>';
            echo '<div class="ui-menu__panel" data-ui-menu-panel hidden>'
                . '<button type="button" class="ui-menu__item" data-ui-export="csv" data-ui-export-for="' . ui_e($tableId) . '">'
                . '<i class="bi bi-filetype-csv" aria-hidden="true"></i>Export CSV</button>'
                . '<button type="button" class="ui-menu__item" data-ui-export="excel" data-ui-export-for="' . ui_e($tableId) . '">'
                . '<i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>Export Excel</button>'
                . '<div class="ui-menu__sep"></div>'
                . '<button type="button" class="ui-menu__item" data-ui-export="print" data-ui-export-for="' . ui_e($tableId) . '">'
                . '<i class="bi bi-printer" aria-hidden="true"></i>Print</button>'
                . '</div>';
            echo '</div>';
        }

        echo '</div>';
    }
}

if (!function_exists('ui_table_open')) {
    /**
     * Open an enhanced table. Pair with ui_table_close().
     *
     * @param string $tableId Unique table id shared with ui_table_toolbar().
     * @param array  $options {
     *   @type bool $paginate Enable client-side pagination (default true).
     *   @type int  $perPage  Rows per page (default 15).
     *   @type bool $sortable Enable client-side sorting (default true).
     *   @type bool $virtual  Enable row virtualisation for very large tables.
     *   @type string $label  Accessible table caption/label.
     * }
     */
    function ui_table_open(string $tableId, array $options = []): void
    {
        $attrs = [
            'id'                 => $tableId,
            'class'              => 'ui-table',
            'data-ui-table'      => '1',
            'data-ui-paginate'   => ($options['paginate'] ?? true) ? '1' : '0',
            'data-ui-per-page'   => (string)(int)($options['perPage'] ?? 15),
            'data-ui-sortable'   => ($options['sortable'] ?? true) ? '1' : '0',
            'data-ui-virtual'    => !empty($options['virtual']) ? '1' : '0',
        ];
        if (!empty($options['label'])) {
            $attrs['aria-label'] = (string)$options['label'];
        }
        echo '<div class="ui-table-wrap">';
        echo '<table' . ui_attr($attrs) . '>';
    }
}

if (!function_exists('ui_table_close')) {
    /** Close a table opened with ui_table_open() and add its pagination bar. */
    function ui_table_close(string $tableId, bool $paginate = true): void
    {
        echo '</table></div>';
        if ($paginate) {
            echo '<div class="ui-pagination" data-ui-pagination-for="' . ui_e($tableId) . '" hidden></div>';
        }
    }
}
