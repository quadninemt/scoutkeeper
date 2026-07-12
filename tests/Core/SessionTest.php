<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Session;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Core\Session.
 *
 * Session mechanics are intrusive in a CLI/PHPUnit context, so each test
 * that touches the real PHP session must guard against a session already
 * being active (started by a previous test in the same process) and must
 * clean up $_SESSION directly rather than relying on session_destroy(),
 * which closes the session and prevents further writes in the same process.
 *
 * Strategy:
 *  - Tests that only read/write $_SESSION values do NOT call Session::start().
 *    They populate $_SESSION directly before calling the method under test.
 *  - The three "lifecycle" tests (start, destroy, regenerate) each need a
 *    running session; they start one lazily and skip gracefully if headers
 *    have already been sent (which cannot happen in CLI but is defensive).
 */
class SessionTest extends TestCase
{
    private Session $session;
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'security' => ['session_timeout' => 7200],
        ];
        $this->session = new Session($this->config);

        // Give every test a clean slate without destroying the PHP session
        // (destroying it prevents further writes in the same process run).
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // -----------------------------------------------------------------------
    // get / set / remove / has-via-get
    // -----------------------------------------------------------------------

    public function testSetAndGet(): void
    {
        $this->session->set('foo', 'bar');
        $this->assertSame('bar', $this->session->get('foo'));
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $this->assertNull($this->session->get('missing'));
        $this->assertSame('default', $this->session->get('missing', 'default'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->session->set('key', 'first');
        $this->session->set('key', 'second');
        $this->assertSame('second', $this->session->get('key'));
    }

    public function testRemoveDeletesKey(): void
    {
        $this->session->set('key', 'value');
        $this->session->remove('key');
        $this->assertNull($this->session->get('key'));
    }

    public function testRemoveNonExistentKeyDoesNotThrow(): void
    {
        $this->session->remove('does_not_exist');
        $this->assertTrue(true); // No exception = pass
    }

    public function testSetAcceptsVariousTypes(): void
    {
        $this->session->set('int', 42);
        $this->session->set('bool', true);
        $this->session->set('arr', [1, 2, 3]);
        $this->session->set('null', null);

        $this->assertSame(42, $this->session->get('int'));
        $this->assertTrue($this->session->get('bool'));
        $this->assertSame([1, 2, 3], $this->session->get('arr'));
        $this->assertNull($this->session->get('null'));
    }

    // -----------------------------------------------------------------------
    // Flash messages
    // -----------------------------------------------------------------------

    public function testFlashStoresMessage(): void
    {
        $this->session->flash('success', 'Saved successfully');

        $this->assertArrayHasKey('_flash', $_SESSION);
        $this->assertContains('Saved successfully', $_SESSION['_flash']['success']);
    }

    public function testGetFlashReturnsAllAndClearsSession(): void
    {
        $this->session->flash('success', 'Done');
        $this->session->flash('error', 'Oops');

        $flash = $this->session->getFlash();

        $this->assertSame(['Done'], $flash['success']);
        $this->assertSame(['Oops'], $flash['error']);
        $this->assertArrayNotHasKey('_flash', $_SESSION);
    }

    public function testGetFlashReturnsEmptyArrayWhenNone(): void
    {
        $this->assertSame([], $this->session->getFlash());
    }

    public function testFlashAccumulatesMultipleMessagesOfSameType(): void
    {
        $this->session->flash('success', 'First');
        $this->session->flash('success', 'Second');

        $flash = $this->session->getFlash();
        $this->assertSame(['First', 'Second'], $flash['success']);
    }

    public function testTakeFlashOfTypeReturnsThatTypeOnly(): void
    {
        $this->session->flash('success', 'Great');
        $this->session->flash('error', 'Bad');

        $taken = $this->session->takeFlashOfType('success');

        $this->assertSame(['Great'], $taken);
        // error bucket must survive
        $this->assertArrayHasKey('_flash', $_SESSION);
        $this->assertArrayHasKey('error', $_SESSION['_flash']);
    }

    public function testTakeFlashOfTypeRemovesFlashKeyWhenLastBucket(): void
    {
        $this->session->flash('success', 'Only message');

        $taken = $this->session->takeFlashOfType('success');

        $this->assertSame(['Only message'], $taken);
        $this->assertArrayNotHasKey('_flash', $_SESSION);
    }

    public function testTakeFlashOfTypeReturnsEmptyWhenMissing(): void
    {
        $result = $this->session->takeFlashOfType('nonexistent');
        $this->assertSame([], $result);
    }

    // -----------------------------------------------------------------------
    // Authentication helpers
    // -----------------------------------------------------------------------

    public function testIsAuthenticatedReturnsFalseWhenNoUser(): void
    {
        $this->assertFalse($this->session->isAuthenticated());
    }

    public function testIsAuthenticatedReturnsTrueAfterSetUser(): void
    {
        $_SESSION['user'] = ['id' => 1, 'email' => 'test@example.com'];
        $this->assertTrue($this->session->isAuthenticated());
    }

    public function testIsAuthenticatedReturnsFalseWhenUserArrayMissingId(): void
    {
        $_SESSION['user'] = ['email' => 'no-id@example.com'];
        $this->assertFalse($this->session->isAuthenticated());
    }

    public function testGetUserReturnsNullWhenNotAuthenticated(): void
    {
        $this->assertNull($this->session->getUser());
    }

    public function testGetUserReturnsStoredArray(): void
    {
        $userData = ['id' => 7, 'email' => 'alice@example.com'];
        $_SESSION['user'] = $userData;
        $this->assertSame($userData, $this->session->getUser());
    }

    // -----------------------------------------------------------------------
    // Session start (requires a real PHP session — careful in test context)
    // -----------------------------------------------------------------------

    public function testStartDoesNotThrow(): void
    {
        // Ensure a session is available for testing
        if (session_status() === PHP_SESSION_NONE) {
            // Create the var/sessions directory that start() expects
            $sessionPath = ROOT_PATH . '/var/sessions';
            if (!is_dir($sessionPath)) {
                mkdir($sessionPath, 0700, true);
            }
            $this->session->start();
        }

        // Either we started it or it was already active; either is fine
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testStartIsIdempotent(): void
    {
        // Guard: only run when a session can be started
        if (session_status() === PHP_SESSION_NONE) {
            $sessionPath = ROOT_PATH . '/var/sessions';
            if (!is_dir($sessionPath)) {
                mkdir($sessionPath, 0700, true);
            }
        }

        // Calling start() twice must not throw
        $this->session->start();
        $this->session->start();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    // -----------------------------------------------------------------------
    // Session regenerate
    // -----------------------------------------------------------------------

    public function testRegenerateDoesNotThrowWhenSessionActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Cannot test regenerate without an active session.');
        }

        // Capture the current ID (may be the same after regenerate in CLI, but no exception)
        $this->session->regenerate();
        $this->assertTrue(true);
    }

    public function testRegenerateIsNoOpWhenSessionNotStarted(): void
    {
        // Create a fresh Session instance that has never been started
        $fresh = new Session($this->config);

        if (session_status() === PHP_SESSION_NONE) {
            // regenerate() must silently do nothing
            $fresh->regenerate();
            $this->assertSame(PHP_SESSION_NONE, session_status());
        } else {
            $this->markTestSkipped('Session already active; cannot test no-op branch.');
        }
    }

    // -----------------------------------------------------------------------
    // Timeout logic — tested via reflection because checkTimeout() is private
    // and start() short-circuits when a session is already running in the
    // same PHP process (as is always the case inside a PHPUnit run).
    // -----------------------------------------------------------------------

    private function invokeCheckTimeout(Session $session): void
    {
        $method = new \ReflectionMethod(Session::class, 'checkTimeout');
        $method->invoke($session);
    }

    public function testCheckTimeoutDestroysExpiredSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Cannot test timeout without an active session.');
        }

        // Simulate a session whose last activity was 3 hours ago
        $_SESSION['user']           = ['id' => 1];
        $_SESSION['_last_activity'] = time() - 10800;

        // Use a 1-second timeout so the session is immediately considered expired
        $shortConfig = ['security' => ['session_timeout' => 1]];
        $session = new Session($shortConfig);

        // Mark the instance as started so destroy() and the recursive start()
        // inside checkTimeout behave correctly without calling session_start() again.
        $startedProp = new \ReflectionProperty(Session::class, 'started');
        $startedProp->setValue($session, true);

        $this->invokeCheckTimeout($session);

        // After timeout the user data must be gone (session was destroyed + restarted)
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testCheckTimeoutUpdatesLastActivityForAuthenticatedUser(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Cannot test activity update without an active session.');
        }

        $before = time() - 5;
        $_SESSION['user']           = ['id' => 1];
        $_SESSION['_last_activity'] = $before;

        $session = new Session($this->config);

        $startedProp = new \ReflectionProperty(Session::class, 'started');
        $startedProp->setValue($session, true);

        $this->invokeCheckTimeout($session);

        $this->assertGreaterThan($before, $_SESSION['_last_activity'] ?? 0);
    }
}
