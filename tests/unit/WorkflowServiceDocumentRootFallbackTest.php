<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/WorkflowService.php';

/**
 * Regression test for PR #244 review feedback: WorkflowService::canUserRevert()
 * must not depend on $_SERVER['DOCUMENT_ROOT'] being set, so it remains usable
 * from CLI/worker/test contexts where config/workflow.php has not already been
 * loaded via an HTTP document-root require.
 */
final class WorkflowServiceDocumentRootFallbackTest extends PHPUnit\Framework\TestCase
{
    private $originalDocumentRoot;

    protected function setUp(): void
    {
        $this->originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        unset($_SERVER['DOCUMENT_ROOT']);
    }

    protected function tearDown(): void
    {
        if ($this->originalDocumentRoot !== null) {
            $_SERVER['DOCUMENT_ROOT'] = $this->originalDocumentRoot;
        } else {
            unset($_SERVER['DOCUMENT_ROOT']);
        }
    }

    public function testCanUserRevertWorksWithoutDocumentRootSet(): void
    {
        $service = new WorkflowService(null);

        $this->assertTrue($service->canUserRevert('HOD', 'REGULAR'));
        $this->assertFalse($service->canUserRevert('Requestor', 'REGULAR'));
    }
}
