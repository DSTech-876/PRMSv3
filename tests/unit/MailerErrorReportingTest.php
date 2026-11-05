<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/mailer.php';

final class MailerErrorReportingTest extends PHPUnit\Framework\TestCase
{
    public function testTrimsSurroundingWhitespaceFromCredentials(): void
    {
        $this->assertSame('user@example.com', normalizeMailCredential("  user@example.com\n"));
    }

    public function testStripsSpacesFromGmailStyleAppPassword(): void
    {
        $this->assertSame('abcdefghijklmnop', normalizeMailCredential('abcd efgh ijkl mnop'));
    }

    public function testPreservesSpacesInOtherPasswords(): void
    {
        $this->assertSame('my secret pass', normalizeMailCredential('my secret pass'));
    }

    public function testAuthFailureExplainsQuitCommandNoise(): void
    {
        $hint = describeMailFailure(
            'SMTP Error: Could not authenticate. SMTP server error: QUIT command failed',
            'smtp.gmail.com',
            587
        );

        $this->assertNotNull($hint);
        $this->assertStringContainsString('MAIL_USER/MAIL_PASS', $hint);
        $this->assertStringContainsString('App Password', $hint);
        $this->assertStringContainsString('QUIT command failed', $hint);
    }

    public function testAuthFailureOmitsGoogleAdviceForOtherHosts(): void
    {
        $hint = describeMailFailure('SMTP Error: Could not authenticate.', 'mail.example.com', 587);

        $this->assertNotNull($hint);
        $this->assertStringContainsString('mail.example.com:587', $hint);
        $this->assertStringNotContainsString('App Password', $hint);
    }

    public function testConnectionFailureIsDescribed(): void
    {
        $hint = describeMailFailure('SMTP Error: Could not connect to SMTP host.', 'smtp.gmail.com', 465);

        $this->assertNotNull($hint);
        $this->assertStringContainsString('MAIL_HOST/MAIL_PORT', $hint);
    }

    public function testUnrelatedErrorsReturnNoHint(): void
    {
        $this->assertNull(describeMailFailure('Invalid address: (to) not-an-email', 'smtp.gmail.com', 587));
    }
}
