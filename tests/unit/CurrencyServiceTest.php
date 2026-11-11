<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/CurrencyService.php';

final class CurrencyServiceTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        // Each test gets a clean memoised cache: CurrencyService caches the
        // catalogue/default per-process when called without an explicit PDO.
        CurrencyService::resetCache();
    }

    protected function tearDown(): void
    {
        CurrencyService::resetCache();
    }

    public function testFallbackCatalogueCoversAllFiveSupportedCurrencies(): void
    {
        // No PDO/currencies table available: must use the built-in catalogue.
        $codes = CurrencyService::codes(null);
        sort($codes);
        $this->assertSame(['CAD', 'EUR', 'GBP', 'JMD', 'USD'], $codes);
    }

    public function testDefaultCurrencyFallsBackToJmdWithoutConfig(): void
    {
        $this->assertSame('JMD', CurrencyService::defaultCurrency(null));
    }

    public function testNormalizeResolvesKnownAliases(): void
    {
        // Historical data-entry typos must resolve to the real code, not silently
        // fall back to the default currency.
        $this->assertSame('USD', CurrencyService::normalize('USB', null));
        $this->assertSame('GBP', CurrencyService::normalize('uk', null));
        $this->assertSame('CAD', CurrencyService::normalize('CAN', null));
    }

    public function testNormalizeFallsBackToDefaultForUnknownOrBlankCodes(): void
    {
        $this->assertSame('JMD', CurrencyService::normalize('', null));
        $this->assertSame('JMD', CurrencyService::normalize(null, null));
        $this->assertSame('JMD', CurrencyService::normalize('XYZ', null));
    }

    public function testSymbolAndFormatSymbolForEachSupportedCurrency(): void
    {
        $this->assertSame('J$', CurrencyService::symbol('JMD', null));
        $this->assertSame('US$', CurrencyService::symbol('USD', null));
        $this->assertSame('£', CurrencyService::symbol('GBP', null));
        $this->assertSame('€', CurrencyService::symbol('EUR', null));
        $this->assertSame('C$', CurrencyService::symbol('CAD', null));

        $this->assertSame('US$1,500.00', CurrencyService::formatSymbol(1500, 'USD', null));
        $this->assertSame('£1,500.00', CurrencyService::formatSymbol(1500, 'GBP', null));
    }

    public function testFormatFullPrefixesTheIsoCode(): void
    {
        $this->assertSame('GBP £1,500.00', CurrencyService::formatFull(1500, 'GBP', null));
        $this->assertSame('CAD C$2,000.00', CurrencyService::formatFull(2000, 'CAD', null));
    }

    public function testSymbolMapContainsAllFiveCurrencies(): void
    {
        $map = CurrencyService::symbolMap(null);
        $this->assertSame(
            ['JMD' => 'J$', 'USD' => 'US$', 'GBP' => '£', 'EUR' => '€', 'CAD' => 'C$'],
            $map
        );
    }

    public function testDefaultCurrencyReadsSystemConfigWhenPdoProvided(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE system_config (config_key TEXT PRIMARY KEY, config_value TEXT)');
        $pdo->exec("INSERT INTO system_config (config_key, config_value) VALUES ('default_currency', 'USD')");

        $this->assertSame('USD', CurrencyService::defaultCurrency($pdo));
        // Legacy/blank values must now normalize to the configured default, not JMD.
        $this->assertSame('USD', CurrencyService::normalize('', $pdo));
        $this->assertSame('USD', CurrencyService::normalize(null, $pdo));
    }

    public function testCatalogueIsOverriddenByActiveCurrenciesTable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE currencies (
                currency_code TEXT PRIMARY KEY,
                currency_name TEXT,
                currency_symbol TEXT,
                decimal_places INTEGER NOT NULL DEFAULT 2,
                is_active INTEGER NOT NULL DEFAULT 1,
                display_order INTEGER NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec("INSERT INTO currencies VALUES ('JMD', 'Jamaican Dollar', 'J$', 2, 1, 10)");
        $pdo->exec("INSERT INTO currencies VALUES ('USD', 'United States Dollar', 'US$', 2, 1, 20)");
        // Inactive currency must not appear in the active catalogue.
        $pdo->exec("INSERT INTO currencies VALUES ('GBP', 'Pound Sterling', '£', 2, 0, 30)");

        $codes = CurrencyService::codes($pdo);
        sort($codes);
        $this->assertSame(['JMD', 'USD'], $codes);
        $this->assertFalse(CurrencyService::isSupported('GBP', $pdo));
    }

    public function testGroupTotalsNeverSumsAcrossCurrencies(): void
    {
        $rows = [
            ['currency' => 'USD', 'estimated_value' => 100],
            ['currency' => 'USD', 'estimated_value' => 50],
            ['currency' => 'GBP', 'estimated_value' => 200],
        ];

        $totals = CurrencyService::groupTotals($rows, 'estimated_value', 'currency', null);

        $this->assertArrayHasKey('USD', $totals);
        $this->assertArrayHasKey('GBP', $totals);
        $this->assertSame(150.0, $totals['USD']['total']);
        $this->assertSame(2, $totals['USD']['count']);
        $this->assertSame(200.0, $totals['GBP']['total']);
        $this->assertSame(1, $totals['GBP']['count']);
    }

    public function testGroupTotalsTreatsMissingCurrencyAsDefault(): void
    {
        $rows = [
            ['currency' => null, 'estimated_value' => 75],
            ['currency' => '', 'estimated_value' => 25],
        ];

        $totals = CurrencyService::groupTotals($rows, 'estimated_value', 'currency', null);

        $this->assertArrayHasKey('JMD', $totals);
        $this->assertSame(100.0, $totals['JMD']['total']);
        $this->assertSame(2, $totals['JMD']['count']);
    }

    public function testFormatGroupedTotalsJoinsEachCurrencySeparately(): void
    {
        $totals = CurrencyService::groupTotals(
            [
                ['currency' => 'USD', 'estimated_value' => 1500],
                ['currency' => 'GBP', 'estimated_value' => 1500],
            ],
            'estimated_value',
            'currency',
            null
        );

        $display = CurrencyService::formatGroupedTotals($totals, ' • ', null);

        $this->assertSame('USD US$1,500.00 • GBP £1,500.00', $display);
    }

    public function testFormatGroupedTotalsOfEmptyArrayReturnsZero(): void
    {
        $this->assertSame('JMD J$0.00', CurrencyService::formatGroupedTotals([], ' • ', null));
    }
}
