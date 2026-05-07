<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\ErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Core\ErrorHandler.
 *
 * ErrorHandler registers global PHP error/exception handlers and renders
 * HTML error pages. The tests verify:
 *  - register() sets the handlers without throwing
 *  - handleException() returns debug HTML in debug mode
 *  - handleException() returns a generic page in production mode
 *  - handleError() converts PHP errors into ErrorException
 *  - handleShutdown() does not throw even when no fatal error occurred
 *
 * Note: handleException() calls Logger::log() internally. Logger silently
 * skips writes when var/logs/ does not exist (no ROOT_PATH guard needed),
 * so we do not need to mock it — the directory is pre-created in setUp.
 */
class ErrorHandlerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = ROOT_PATH . '/var/logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Restore default PHP error / exception handlers
        restore_error_handler();
        restore_exception_handler();
    }

    // -----------------------------------------------------------------------
    // register()
    // -----------------------------------------------------------------------

    public function testRegisterDoesNotThrow(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => false]]);
        $handler->register();

        // If we get here, register() succeeded
        $this->assertTrue(true);
    }

    public function testRegisterSetsExceptionHandler(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => false]]);
        $handler->register();

        $current = set_exception_handler(null); // read current, reset to null
        $this->assertIsArray($current);
        $this->assertInstanceOf(ErrorHandler::class, $current[0]);
        $this->assertSame('handleException', $current[1]);

        // Restore so tearDown's restore_exception_handler works correctly
        set_exception_handler($current);
    }

    public function testRegisterSetsErrorHandler(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => false]]);
        $handler->register();

        $current = set_error_handler(null); // read current, reset to null
        $this->assertIsArray($current);
        $this->assertInstanceOf(ErrorHandler::class, $current[0]);
        $this->assertSame('handleError', $current[1]);

        // Restore
        set_error_handler($current);
    }

    // -----------------------------------------------------------------------
    // handleException() — output buffering so HTML does not pollute test output
    // -----------------------------------------------------------------------

    public function testHandleExceptionInDebugModeContainsExceptionClass(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => true]]);
        $exception = new \RuntimeException('Something broke');

        ob_start();
        $handler->handleException($exception);
        $output = ob_get_clean();

        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringContainsString('Something broke', $output);
    }

    public function testHandleExceptionInDebugModeContainsStackTrace(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => true]]);
        $exception = new \InvalidArgumentException('Bad arg');

        ob_start();
        $handler->handleException($exception);
        $output = ob_get_clean();

        $this->assertStringContainsString('Stack Trace', $output);
    }

    public function testHandleExceptionInProductionModeShowsGenericPage(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => false]]);
        $exception = new \RuntimeException('Internal detail');

        ob_start();
        $handler->handleException($exception);
        $output = ob_get_clean();

        // Must NOT leak internal details
        $this->assertStringNotContainsString('Internal detail', $output);
        $this->assertStringNotContainsString('RuntimeException', $output);

        // Must show friendly copy
        $this->assertStringContainsString('Something went wrong', $output);
    }

    public function testHandleExceptionOutputIsValidHtml(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => false]]);

        ob_start();
        $handler->handleException(new \Exception('Test'));
        $output = ob_get_clean();

        $this->assertStringContainsString('<!DOCTYPE html>', $output);
        $this->assertStringContainsString('</html>', $output);
    }

    public function testHandleExceptionInDebugModeEscapesHtmlInMessage(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => true]]);
        $exception = new \RuntimeException('<script>alert(1)</script>');

        ob_start();
        $handler->handleException($exception);
        $output = ob_get_clean();

        // Raw script tag must not appear in output
        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
        // The escaped version must be present
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testHandleExceptionWithMissingDebugKey(): void
    {
        // When the config has no 'app.debug' key it should default to production mode
        $handler = new ErrorHandler([]);
        $exception = new \RuntimeException('Secret details');

        ob_start();
        $handler->handleException($exception);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('Secret details', $output);
        $this->assertStringContainsString('Something went wrong', $output);
    }

    // -----------------------------------------------------------------------
    // handleError()
    // -----------------------------------------------------------------------

    public function testHandleErrorThrowsErrorException(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => false]]);

        // Explicitly set error_reporting to include E_USER_ERROR before calling,
        // because PHPUnit's failOnWarning configuration may reduce the mask.
        $previous = error_reporting(E_ALL);
        $caught = null;
        try {
            $handler->handleError(E_USER_ERROR, 'Test error message', __FILE__, __LINE__);
        } catch (\ErrorException $e) {
            $caught = $e;
        } finally {
            error_reporting($previous);
        }

        $this->assertNotNull($caught, 'Expected ErrorException was not thrown');
        $this->assertSame('Test error message', $caught->getMessage());
    }

    public function testHandleErrorPreservesOriginalSeverity(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => false]]);

        $previous = error_reporting(E_ALL);
        $caught = null;
        try {
            $handler->handleError(E_USER_NOTICE, 'A notice', __FILE__, __LINE__);
        } catch (\ErrorException $e) {
            $caught = $e;
        } finally {
            error_reporting($previous);
        }

        $this->assertNotNull($caught, 'Expected ErrorException was not thrown');
        $this->assertSame(E_USER_NOTICE, $caught->getSeverity());
    }

    public function testHandleErrorReturnsFalseWhenSeveritySuppressed(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => false]]);

        // Temporarily suppress all errors (@ operator equivalent)
        $previous = error_reporting(0);
        try {
            $result = $handler->handleError(E_WARNING, 'Suppressed', __FILE__, __LINE__);
            $this->assertFalse($result);
        } finally {
            error_reporting($previous);
        }
    }

    // -----------------------------------------------------------------------
    // handleShutdown()
    // -----------------------------------------------------------------------

    public function testHandleShutdownDoesNotThrowWhenNoFatalError(): void
    {
        $handler = new ErrorHandler(['app' => ['debug' => false]]);

        // error_get_last() will return whatever the last error was; as long as
        // it isn't one of the fatal types, handleShutdown() must silently return
        $handler->handleShutdown();

        $this->assertTrue(true); // No exception = pass
    }
}
