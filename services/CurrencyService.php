<?php
/**
 * CurrencyService
 * ===============
 * Single source of truth for multi-currency support.
 *
 * Supported currencies: USD, JMD, GBP, EUR, CAD.
 *
 * The service reads the `currencies` table when one is available so that
 * administrators can activate/deactivate codes, and falls back to a built-in
 * catalogue when the table is absent (fresh checkouts, unit tests, or an
 * environment where the migration has not been applied yet).  That fallback is
 * what keeps legacy installations working unchanged.
 */

class CurrencyService
{
    /** Code used whenever a record carries no usable currency. */
    public const FALLBACK_CURRENCY = 'JMD';

    /**
     * Built-in catalogue. Mirrors the rows seeded by
     * migrations/2026_11_12_notifications_reminders_multicurrency.sql
     */
    private const CATALOGUE = [
        'JMD' => ['name' => 'Jamaican Dollar',       'symbol' => 'J$',  'decimals' => 2, 'order' => 10],
        'USD' => ['name' => 'United States Dollar',  'symbol' => 'US$', 'decimals' => 2, 'order' => 20],
        'GBP' => ['name' => 'Pound Sterling',        'symbol' => '£',   'decimals' => 2, 'order' => 30],
        'EUR' => ['name' => 'Euro',                  'symbol' => '€',   'decimals' => 2, 'order' => 40],
        'CAD' => ['name' => 'Canadian Dollar',       'symbol' => 'C$',  'decimals' => 2, 'order' => 50],
    ];

    /**
     * Historical typos / aliases that must resolve to a real code instead of
     * silently falling back to JMD.
     */
    private const ALIASES = [
        'USB' => 'USD', // long-standing data-entry typo in legacy records
        'US'  => 'USD',
        'J$'  => 'JMD',
        'JA'  => 'JMD',
        'UK'  => 'GBP',
        'GBR' => 'GBP',
        'CAN' => 'CAD',
        'EU'  => 'EUR',
    ];

    /** @var array<string,array>|null Cached active catalogue for the request. */
    private static ?array $cache = null;

    /** @var string|null Cached system default currency. */
    private static ?string $defaultCache = null;

    /**
     * Clear memoised state. Used by tests and by the admin settings screen
     * after currencies or the default are changed.
     */
    public static function resetCache(): void
    {
        self::$cache = null;
        self::$defaultCache = null;
    }

    /**
     * All supported currencies keyed by ISO code.
     *
     * @return array<string,array{code:string,name:string,symbol:string,decimals:int}>
     */
    public static function all(?PDO $pdo = null): array
    {
        if ($pdo === null && self::$cache !== null) {
            return self::$cache;
        }

        $catalogue = self::fallbackCatalogue();

        $handle = $pdo;
        if ($handle === null) {
            $handle = $GLOBALS['pdo'] ?? null;
        }

        if ($handle instanceof PDO) {
            try {
                $stmt = $handle->query(
                    'SELECT currency_code, currency_name, currency_symbol, decimal_places
                       FROM currencies
                      WHERE is_active = 1
                      ORDER BY display_order ASC, currency_code ASC'
                );
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    $catalogue = [];
                    foreach ($rows as $row) {
                        $code = strtoupper(trim((string)$row['currency_code']));
                        if ($code === '') {
                            continue;
                        }
                        $catalogue[$code] = [
                            'code'     => $code,
                            'name'     => (string)$row['currency_name'],
                            'symbol'   => (string)$row['currency_symbol'],
                            'decimals' => (int)$row['decimal_places'],
                        ];
                    }
                }
            } catch (Throwable $e) {
                // Table missing or unreadable: keep the built-in catalogue so
                // the application continues to render amounts.
                $catalogue = self::fallbackCatalogue();
            }
        }

        if ($pdo === null) {
            self::$cache = $catalogue;
        }

        return $catalogue;
    }

    /**
     * @return array<string,array{code:string,name:string,symbol:string,decimals:int}>
     */
    private static function fallbackCatalogue(): array
    {
        $catalogue = [];
        foreach (self::CATALOGUE as $code => $meta) {
            $catalogue[$code] = [
                'code'     => $code,
                'name'     => $meta['name'],
                'symbol'   => $meta['symbol'],
                'decimals' => $meta['decimals'],
            ];
        }
        return $catalogue;
    }

    /**
     * List of supported ISO codes, in display order.
     *
     * @return string[]
     */
    public static function codes(?PDO $pdo = null): array
    {
        return array_keys(self::all($pdo));
    }

    /**
     * True when $code is a supported, active currency.
     */
    public static function isSupported(?string $code, ?PDO $pdo = null): bool
    {
        if ($code === null) {
            return false;
        }
        return isset(self::all($pdo)[strtoupper(trim($code))]);
    }

    /**
     * The configured system default currency (`system_config.default_currency`).
     * Falls back to JMD, which is what every legacy record implicitly used.
     */
    public static function defaultCurrency(?PDO $pdo = null): string
    {
        if ($pdo === null && self::$defaultCache !== null) {
            return self::$defaultCache;
        }

        $default = self::FALLBACK_CURRENCY;

        $handle = $pdo ?? ($GLOBALS['pdo'] ?? null);
        if ($handle instanceof PDO) {
            try {
                $stmt = $handle->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
                $stmt->execute(['default_currency']);
                $value = $stmt->fetchColumn();
                if (is_string($value) && trim($value) !== '') {
                    $candidate = strtoupper(trim($value));
                    if (self::isSupported($candidate, $pdo)) {
                        $default = $candidate;
                    }
                }
            } catch (Throwable $e) {
                $default = self::FALLBACK_CURRENCY;
            }
        }

        if ($pdo === null) {
            self::$defaultCache = $default;
        }

        return $default;
    }

    /**
     * Coerce any stored value into a supported currency code.
     *
     * Unknown, blank or legacy values resolve to the system default, which is
     * what makes existing records keep rendering correctly.
     */
    public static function normalize(?string $code, ?PDO $pdo = null): string
    {
        $candidate = strtoupper(trim((string)($code ?? '')));

        if ($candidate === '') {
            return self::defaultCurrency($pdo);
        }

        if (isset(self::ALIASES[$candidate])) {
            $candidate = self::ALIASES[$candidate];
        }

        if (self::isSupported($candidate, $pdo)) {
            return $candidate;
        }

        return self::defaultCurrency($pdo);
    }

    /**
     * Display symbol for a currency, e.g. "£" or "US$".
     */
    public static function symbol(?string $code, ?PDO $pdo = null): string
    {
        $normalized = self::normalize($code, $pdo);
        return self::all($pdo)[$normalized]['symbol'] ?? $normalized;
    }

    /**
     * Human-readable currency name.
     */
    public static function name(?string $code, ?PDO $pdo = null): string
    {
        $normalized = self::normalize($code, $pdo);
        return self::all($pdo)[$normalized]['name'] ?? $normalized;
    }

    /**
     * Number of decimal places configured for the currency.
     */
    public static function decimals(?string $code, ?PDO $pdo = null): int
    {
        $normalized = self::normalize($code, $pdo);
        return self::all($pdo)[$normalized]['decimals'] ?? 2;
    }

    /**
     * Symbol-prefixed amount, e.g. "£1,250.00".
     */
    public static function formatSymbol($amount, ?string $code = null, ?PDO $pdo = null): string
    {
        $normalized = self::normalize($code, $pdo);
        return self::symbol($normalized, $pdo)
            . number_format((float)$amount, self::decimals($normalized, $pdo));
    }

    /**
     * Code-prefixed amount, e.g. "GBP 1,250.00". Preferred for documents,
     * exports and anywhere mixed currencies may appear side by side.
     */
    public static function formatCode($amount, ?string $code = null, ?PDO $pdo = null): string
    {
        $normalized = self::normalize($code, $pdo);
        return $normalized . ' ' . number_format((float)$amount, self::decimals($normalized, $pdo));
    }

    /**
     * Full display used in headers and summaries, e.g. "GBP £1,250.00".
     */
    public static function formatFull($amount, ?string $code = null, ?PDO $pdo = null): string
    {
        $normalized = self::normalize($code, $pdo);
        return $normalized . ' ' . self::formatSymbol($amount, $normalized, $pdo);
    }

    /**
     * Code => symbol map, convenient for embedding in JavaScript.
     *
     * @return array<string,string>
     */
    public static function symbolMap(?PDO $pdo = null): array
    {
        $map = [];
        foreach (self::all($pdo) as $code => $meta) {
            $map[$code] = $meta['symbol'];
        }
        return $map;
    }

    /**
     * Options for a <select>, ordered for display.
     *
     * @return array<int,array{code:string,label:string}>
     */
    public static function selectOptions(?PDO $pdo = null): array
    {
        $options = [];
        foreach (self::all($pdo) as $code => $meta) {
            $options[] = [
                'code'  => $code,
                'label' => $code . ' — ' . $meta['name'] . ' (' . $meta['symbol'] . ')',
            ];
        }
        return $options;
    }

    /**
     * Render a ready-to-use currency <select> element.
     *
     * @param string      $name     Field name
     * @param string|null $selected Currently selected code
     */
    public static function renderSelect(
        string $name = 'currency',
        ?string $selected = null,
        array $attributes = [],
        ?PDO $pdo = null
    ): string {
        $selected = self::normalize($selected, $pdo);

        $attributes = array_merge(
            ['class' => 'form-select', 'id' => $name],
            $attributes
        );

        $attrHtml = '';
        foreach ($attributes as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $attrHtml .= ' ' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8');
                continue;
            }
            $attrHtml .= ' ' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8')
                . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
        }

        $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' . $attrHtml . '>';
        foreach (self::selectOptions($pdo) as $option) {
            $isSelected = $option['code'] === $selected ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($option['code'], ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>'
                . htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8')
                . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * Group amounts by currency so dashboards and reports never sum across
     * different currencies.
     *
     * @param iterable $rows           Result rows
     * @param string   $amountKey      Column holding the amount
     * @param string   $currencyKey    Column holding the currency code
     * @return array<string,array{currency:string,total:float,count:int}>
     */
    public static function groupTotals(
        iterable $rows,
        string $amountKey = 'estimated_value',
        string $currencyKey = 'currency',
        ?PDO $pdo = null
    ): array {
        $totals = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = self::normalize($row[$currencyKey] ?? null, $pdo);
            if (!isset($totals[$code])) {
                $totals[$code] = ['currency' => $code, 'total' => 0.0, 'count' => 0];
            }
            $totals[$code]['total'] += (float)($row[$amountKey] ?? 0);
            $totals[$code]['count']++;
        }

        uksort($totals, static function (string $a, string $b) use ($pdo): int {
            $order = array_keys(self::all($pdo));
            return array_search($a, $order, true) <=> array_search($b, $order, true);
        });

        return $totals;
    }

    /**
     * Render grouped totals as a readable single-line summary, e.g.
     * "JMD J$1,000.00 • USD US$250.00".
     */
    public static function formatGroupedTotals(array $totals, string $separator = ' • ', ?PDO $pdo = null): string
    {
        if (empty($totals)) {
            return self::formatFull(0, null, $pdo);
        }

        $parts = [];
        foreach ($totals as $entry) {
            $parts[] = self::formatFull($entry['total'] ?? 0, $entry['currency'] ?? null, $pdo);
        }

        return implode($separator, $parts);
    }

    /**
     * Conservative fallback exchange rates to JMD, used only when the
     * system_config table has no configured rate for the currency yet.
     * Administrators should configure accurate rates via system_config
     * (e.g. `usd_to_jmd_rate`, `gbp_to_jmd_rate`, `eur_to_jmd_rate`,
     * `cad_to_jmd_rate`).
     */
    private const FALLBACK_JMD_RATES = [
        'USD' => 155.00,
        'GBP' => 196.00,
        'EUR' => 168.00,
        'CAD' => 113.00,
    ];

    /**
     * Configured JMD exchange rate for a non-JMD currency. Reads
     * `{code}_to_jmd_rate` from system_config, falling back to a
     * conservative built-in rate when no row has been configured.
     */
    public static function jmdRate(string $currency, PDO $pdo): float
    {
        $code = self::normalize($currency, $pdo);
        if ($code === 'JMD') {
            return 1.0;
        }

        require_once __DIR__ . '/SystemConfigService.php';
        $default = self::FALLBACK_JMD_RATES[$code] ?? 1.0;
        return SystemConfigService::getFloat($pdo, strtolower($code) . '_to_jmd_rate', $default);
    }

    /**
     * Convert an amount in the given currency to its JMD equivalent, using
     * the configured exchange rate. Supports every currency returned by
     * self::codes(), not only USD, so threshold comparisons made in JMD are
     * meaningful for GBP/EUR/CAD requests too.
     *
     * @param float|string $amount   The amount expressed in $currency
     * @param string|null  $currency Source currency code (defaults to JMD)
     * @param float|null   $rate     Optional pre-resolved exchange rate
     *                                (e.g. the rate captured on the record);
     *                                when omitted the configured system rate
     *                                is used.
     */
    public static function toJmd($amount, ?string $currency, ?PDO $pdo = null, ?float $rate = null): float
    {
        $amount = (float)$amount;
        $code = self::normalize($currency, $pdo);
        if ($code === 'JMD') {
            return $amount;
        }

        if ($rate === null || $rate <= 0) {
            $rate = $pdo !== null ? self::jmdRate($code, $pdo) : (self::FALLBACK_JMD_RATES[$code] ?? 1.0);
        }

        return $amount * $rate;
    }
}
