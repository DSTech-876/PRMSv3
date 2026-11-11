<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/ReminderService.php';

final class ReminderServiceTest extends PHPUnit\Framework\TestCase
{
    private PDO $pdo;
    private ReminderService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(
            "CREATE TABLE reminder_settings (
                reminder_type TEXT PRIMARY KEY,
                label TEXT,
                description TEXT,
                is_enabled INTEGER NOT NULL DEFAULT 1,
                initial_delay_days INTEGER NOT NULL DEFAULT 3,
                repeat_interval_days INTEGER NOT NULL DEFAULT 3,
                escalation_enabled INTEGER NOT NULL DEFAULT 1,
                escalation_after_days INTEGER NOT NULL DEFAULT 7,
                escalation_role TEXT,
                priority TEXT NOT NULL DEFAULT 'high',
                max_attempts INTEGER NOT NULL DEFAULT 3
            )"
        );

        $this->pdo->exec(
            "CREATE TABLE reminder_dispatch_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                execution_id INTEGER,
                reminder_type TEXT NOT NULL,
                request_id INTEGER,
                request_ref TEXT,
                user_id INTEGER,
                escalation_level INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'sent',
                attempts INTEGER NOT NULL DEFAULT 1,
                days_outstanding INTEGER,
                error_message TEXT,
                notification_id INTEGER,
                email_sent INTEGER NOT NULL DEFAULT 0,
                sent_on TEXT NOT NULL
            )"
        );

        $this->service = new ReminderService($this->pdo, '2026-01-20');
    }

    /** Settings-array shorthand for the pure due/escalation rules. */
    private function settings(array $overrides = []): array
    {
        return $overrides + [
            'is_enabled'            => 1,
            'initial_delay_days'    => 3,
            'repeat_interval_days'  => 3,
            'escalation_enabled'    => 1,
            'escalation_after_days' => 7,
        ];
    }

    public function testDefaultsAreUsedWhenNoSettingsRowExists(): void
    {
        $settings = $this->service->getSettings(ReminderService::TYPE_RFQ);

        $this->assertSame(
            ReminderService::DEFAULTS[ReminderService::TYPE_RFQ]['initial_delay_days'],
            (int)$settings['initial_delay_days']
        );
        $this->assertSame(1, (int)$settings['is_enabled']);
    }

    public function testSavedSettingsOverrideDefaultsAndUpdateInPlace(): void
    {
        $payload = [
            'label'                 => 'Purchase Order pending',
            'is_enabled'            => 0,
            'initial_delay_days'    => 10,
            'repeat_interval_days'  => 0,
            'escalation_enabled'    => 0,
            'escalation_after_days' => 21,
            'escalation_role'       => 'Branch Head',
            'priority'              => 'urgent',
            'max_attempts'          => 5,
        ];

        $this->assertTrue($this->service->saveSettings(ReminderService::TYPE_PO, $payload));

        $settings = $this->service->getSettings(ReminderService::TYPE_PO);
        $this->assertSame(0, (int)$settings['is_enabled']);
        $this->assertSame(10, (int)$settings['initial_delay_days']);
        $this->assertSame('urgent', $settings['priority']);
        $this->assertSame(5, (int)$settings['max_attempts']);

        /* Saving again must update the existing row, not insert a duplicate. */
        $payload['initial_delay_days'] = 12;
        $this->assertTrue($this->service->saveSettings(ReminderService::TYPE_PO, $payload));

        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM reminder_settings WHERE reminder_type = 'PO_PENDING'"
        )->fetchColumn();

        $this->assertSame(1, $count);
        $this->assertSame(
            12,
            (int)$this->service->getSettings(ReminderService::TYPE_PO)['initial_delay_days']
        );
    }

    public function testInvalidPriorityFallsBackToTheTypeDefault(): void
    {
        $this->service->saveSettings(ReminderService::TYPE_RFQ, [
            'is_enabled' => 1,
            'priority'   => 'catastrophic',
        ]);

        $this->assertSame(
            ReminderService::DEFAULTS[ReminderService::TYPE_RFQ]['priority'],
            $this->service->getSettings(ReminderService::TYPE_RFQ)['priority']
        );
    }

    public function testUnknownReminderTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->saveSettings('NOT_A_REMINDER', ['is_enabled' => 1]);
    }

    public function testInitialDelayMustElapseBeforeTheFirstReminder(): void
    {
        $settings = $this->settings();

        $this->assertFalse($this->service->isReminderDue($settings, 2, null));
        $this->assertTrue($this->service->isReminderDue($settings, 3, null));
        $this->assertTrue($this->service->isReminderDue($settings, 9, null));
    }

    public function testDisabledRemindersAreNeverDue(): void
    {
        $this->assertFalse(
            $this->service->isReminderDue($this->settings(['is_enabled' => 0]), 99, null)
        );
    }

    public function testRepeatIntervalThrottlesSubsequentReminders(): void
    {
        $settings = $this->settings();

        /* Last sent 2 days ago — too soon to repeat. */
        $this->assertFalse($this->service->isReminderDue($settings, 10, 2));
        /* Last sent 3 days ago — the interval has elapsed. */
        $this->assertTrue($this->service->isReminderDue($settings, 10, 3));
    }

    public function testZeroRepeatIntervalMeansSendOnceOnly(): void
    {
        $settings = $this->settings(['repeat_interval_days' => 0]);

        $this->assertTrue($this->service->isReminderDue($settings, 5, null));
        $this->assertFalse($this->service->isReminderDue($settings, 50, 40));
    }

    public function testEscalationOnlyTriggersWhenEnabledAndPastThreshold(): void
    {
        $this->assertFalse($this->service->shouldEscalate($this->settings(), 6));
        $this->assertTrue($this->service->shouldEscalate($this->settings(), 7));
        $this->assertFalse(
            $this->service->shouldEscalate($this->settings(['escalation_enabled' => 0]), 30)
        );
    }

    public function testRepeatedSameDayFailuresIncrementAttemptsOnOneRow(): void
    {
        $entry = [
            'reminder_type'    => ReminderService::TYPE_RFQ,
            'request_id'       => 1,
            'request_ref'      => 'PR001',
            'user_id'          => 7,
            'escalation_level' => 0,
            'status'           => 'failed',
            'error_message'    => 'SMTP unavailable',
        ];

        $this->assertTrue($this->service->logDispatch($entry));
        $this->assertTrue($this->service->logDispatch($entry));

        $rows = $this->pdo->query('SELECT * FROM reminder_dispatch_log')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows, 'Same-day retries must update the existing row');
        $this->assertSame(2, (int)$rows[0]['attempts']);
        $this->assertSame('failed', $rows[0]['status']);
        $this->assertSame('2026-01-20', $rows[0]['sent_on']);
    }

    public function testEscalationsAreLoggedSeparatelyFromOrdinaryReminders(): void
    {
        $base = [
            'reminder_type' => ReminderService::TYPE_RFQ,
            'request_id'    => 1,
            'user_id'       => 7,
            'status'        => 'sent',
        ];

        $this->service->logDispatch($base + ['escalation_level' => 0]);
        $this->service->logDispatch($base + ['escalation_level' => 1]);

        $this->assertSame(
            2,
            (int)$this->pdo->query('SELECT COUNT(*) FROM reminder_dispatch_log')->fetchColumn()
        );
    }

    public function testRetryableRowsRespectTheMaxAttemptsCeiling(): void
    {
        $this->pdo->exec(
            "INSERT INTO reminder_dispatch_log
                (reminder_type, request_id, request_ref, user_id, escalation_level, status, attempts, sent_on)
             VALUES ('RFQ_PENDING', 1, 'PR001', 7, 0, 'failed',   1, '2026-01-20'),
                    ('RFQ_PENDING', 2, 'PR002', 7, 0, 'failed',   5, '2026-01-20'),
                    ('RFQ_PENDING', 3, 'PR003', 7, 0, 'sent',     1, '2026-01-20'),
                    ('PO_PENDING',  4, 'PR004', 7, 0, 'retrying', 1, '2026-01-20')"
        );

        $refs = array_column(
            $this->service->findRetryable(ReminderService::TYPE_RFQ, 3),
            'request_ref'
        );

        $this->assertContains('PR001', $refs, 'Below the ceiling, so still retryable');
        $this->assertNotContains('PR002', $refs, 'Exhausted attempts must not retry forever');
        $this->assertNotContains('PR003', $refs, 'Successful deliveries are not retried');
        $this->assertNotContains('PR004', $refs, 'Retries are scoped to the requested type');
    }

    public function testDaysSinceLastReminderIsMeasuredFromTheLastDispatch(): void
    {
        $this->pdo->exec(
            "INSERT INTO reminder_dispatch_log
                (reminder_type, request_id, user_id, escalation_level, status, attempts, sent_on)
             VALUES ('RFQ_PENDING', 1, 7, 0, 'sent', 1, '2026-01-15')"
        );

        $this->assertSame(5, $this->service->daysSinceLastReminder(ReminderService::TYPE_RFQ, 1, 0));
        $this->assertNull(
            $this->service->daysSinceLastReminder(ReminderService::TYPE_RFQ, 999, 0),
            'Never-reminded requests report null, not zero'
        );
    }

    /** Create the procurement lifecycle tables the detection queries read. */
    private function createLifecycleSchema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE procurement_requests (
                request_id INTEGER PRIMARY KEY,
                request_number TEXT,
                description TEXT,
                branch_id INTEGER,
                estimated_value REAL,
                currency TEXT,
                status TEXT,
                requires_rfq INTEGER DEFAULT 1,
                approved_at TEXT
            )"
        );
        $this->pdo->exec('CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, branch_name TEXT)');
        $this->pdo->exec('CREATE TABLE rfqs (rfq_id INTEGER PRIMARY KEY, request_id INTEGER)');
        $this->pdo->exec(
            'CREATE TABLE commitments (commitment_id INTEGER PRIMARY KEY, request_id INTEGER, commitment_date TEXT)'
        );
        $this->pdo->exec(
            'CREATE TABLE purchase_orders
                (po_id INTEGER PRIMARY KEY, commitment_id INTEGER, po_number TEXT, po_date TEXT)'
        );
        $this->pdo->exec(
            'CREATE TABLE invoices
                (invoice_id INTEGER PRIMARY KEY, po_id INTEGER, status TEXT, invoice_date TEXT)'
        );
        $this->pdo->exec(
            'CREATE TABLE payments
                (payment_id INTEGER PRIMARY KEY, invoice_id INTEGER, payment_date TEXT)'
        );
        $this->pdo->exec(
            'CREATE TABLE inv_goods_received (grn_id INTEGER PRIMARY KEY, po_reference TEXT, status TEXT)'
        );
    }

    public function testEveryDetectionQueryIsValidSqlAgainstTheRealSchema(): void
    {
        $this->createLifecycleSchema();
        $this->assertNotEmpty(ReminderService::types());

        foreach (ReminderService::types() as $type) {
            /* findOutstanding traps SQL errors and returns [], so an empty
               result alone proves nothing. Assert the statement really
               prepares and executes against the live column names. */
            $sql = $this->service->detectionSqlFor($type);
            $this->assertNotNull($sql, "{$type} must map to a detection query");

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $this->assertIsArray($stmt->fetchAll(PDO::FETCH_ASSOC), $type);
        }
    }

    public function testRfqReminderDetectsApprovedRequestsWithNoRfq(): void
    {
        $this->createLifecycleSchema();
        $this->pdo->exec('INSERT INTO branches VALUES (1, \'Kingston\')');
        $this->pdo->exec(
            "INSERT INTO procurement_requests
                (request_id, request_number, description, branch_id, estimated_value, currency, status, requires_rfq, approved_at)
             VALUES (1, 'PR001', 'Chairs', 1, 100, 'USD', 'APPROVED', 1, date('now','-9 days')),
                    (2, 'PR002', 'Desks',  1, 100, 'JMD', 'APPROVED', 1, date('now','-9 days')),
                    (3, 'PR003', 'Draft',  1, 100, 'JMD', 'DRAFT',    1, date('now','-9 days'))"
        );
        /* PR002 already has an RFQ, PR003 is still a draft. */
        $this->pdo->exec('INSERT INTO rfqs VALUES (1, 2)');

        $rows = $this->service->findOutstanding(ReminderService::TYPE_RFQ);
        $refs = array_column($rows, 'request_number');

        $this->assertSame(['PR001'], $refs);
        $this->assertSame(9, (int)$rows[0]['days_outstanding']);
        $this->assertSame('USD', $rows[0]['currency'], 'Currency survives into the reminder');
    }

    public function testInventoryReminderDetectsPaidRequestsWithNoGoodsReceipt(): void
    {
        $this->createLifecycleSchema();
        $this->pdo->exec('INSERT INTO branches VALUES (1, \'Kingston\')');
        $this->pdo->exec(
            "INSERT INTO procurement_requests
                (request_id, request_number, description, branch_id, estimated_value, currency, status, requires_rfq, approved_at)
             VALUES (1, 'PR001', 'Chairs', 1, 100, 'JMD', 'APPROVED', 1, date('now','-20 days')),
                    (2, 'PR002', 'Desks',  1, 100, 'JMD', 'APPROVED', 1, date('now','-20 days'))"
        );
        $this->pdo->exec(
            "INSERT INTO commitments (commitment_id, request_id, commitment_date)
             VALUES (10, 1, date('now','-20 days')), (20, 2, date('now','-20 days'))"
        );
        $this->pdo->exec(
            "INSERT INTO purchase_orders (po_id, commitment_id, po_number, po_date)
             VALUES (100, 10, 'PO-1', date('now','-15 days')), (200, 20, 'PO-2', date('now','-15 days'))"
        );
        $this->pdo->exec(
            "INSERT INTO invoices (invoice_id, po_id, status, invoice_date)
             VALUES (1000, 100, 'Paid', date('now','-10 days')), (2000, 200, 'Paid', date('now','-10 days'))"
        );
        $this->pdo->exec(
            "INSERT INTO payments (payment_id, invoice_id, payment_date)
             VALUES (1, 1000, date('now','-7 days')), (2, 2000, date('now','-7 days'))"
        );
        /* PR002's goods were received; PR001's were not. */
        $this->pdo->exec("INSERT INTO inv_goods_received VALUES (1, 'PO-2', 'RECEIVED')");

        $rows = $this->service->findOutstanding(ReminderService::TYPE_INVENTORY);
        $refs = array_column($rows, 'request_number');

        $this->assertSame(['PR001'], $refs);
        $this->assertSame(
            7,
            (int)$rows[0]['days_outstanding'],
            'Inventory reminder ages from the payment date, not the original approval'
        );
    }

    public function testPoReminderAgesFromCommitmentDateNotApproval(): void
    {
        $this->createLifecycleSchema();
        $this->pdo->exec('INSERT INTO branches VALUES (1, \'Kingston\')');
        $this->pdo->exec(
            "INSERT INTO procurement_requests
                (request_id, request_number, description, branch_id, estimated_value, currency, status, requires_rfq, approved_at)
             VALUES (1, 'PR001', 'Chairs', 1, 100, 'JMD', 'APPROVED', 1, date('now','-60 days'))"
        );
        /* Commitment created yesterday for a request approved 60 days ago. */
        $this->pdo->exec(
            "INSERT INTO commitments (commitment_id, request_id, commitment_date)
             VALUES (10, 1, date('now','-1 days'))"
        );

        $rows = $this->service->findOutstanding(ReminderService::TYPE_PO);

        $this->assertSame(['PR001'], array_column($rows, 'request_number'));
        $this->assertSame(1, (int)$rows[0]['days_outstanding']);
    }

    public function testMessageCarriesTheRequiredReminderContext(): void
    {
        $item = [
            'request_id'       => 42,
            'request_number'   => 'PR001',
            'description'      => 'Office chairs',
            'branch_name'      => 'Kingston',
            'estimated_value'  => 1500,
            'currency'         => 'USD',
            'days_outstanding' => 9,
        ];
        $settings = $this->service->getSettings(ReminderService::TYPE_RFQ);

        $message = $this->service->buildMessage(ReminderService::TYPE_RFQ, $item, $settings, false);

        $this->assertStringContainsString('PR001', $message['title']);
        $this->assertStringContainsString('Office chairs', $message['body']);
        $this->assertStringContainsString('Kingston', $message['body']);
        $this->assertStringContainsString('9 days', $message['body']);
        /* Amounts keep their own currency rather than a hard-coded symbol. */
        $this->assertStringContainsString('USD US$1,500.00', $message['body']);
        $this->assertSame('/procurement/view.php?id=42', $message['link']);
    }

    public function testEscalationMessagesAreMarkedAsEscalations(): void
    {
        $item = ['request_id' => 42, 'request_number' => 'PR001', 'days_outstanding' => 15];
        $settings = $this->service->getSettings(ReminderService::TYPE_RFQ);

        $escalation = $this->service->buildMessage(ReminderService::TYPE_RFQ, $item, $settings, true);

        $this->assertStringContainsString('ESCALATION', $escalation['title']);
        $this->assertStringContainsString('escalation threshold', $escalation['body']);
    }
}
