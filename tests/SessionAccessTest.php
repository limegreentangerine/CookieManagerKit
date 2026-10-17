<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Concrete does not attach a session to the request object, so Request::getSession() throws
 * SessionNotFoundException (400) on every page that calls it. The session is the 'session' service.
 */
final class SessionAccessTest extends TestCase
{
    public function testNoControllerReadsTheSessionFromTheRequest(): void
    {
        $files = array_merge(
            glob(dirname(__DIR__) . '/controllers/single_page/dashboard/cookie_manager/*.php') ?: [],
            glob(dirname(__DIR__) . '/src/*/*.php') ?: [],
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertStringNotContainsString('->getSession()', (string) file_get_contents($file), basename($file));
        }
    }
}
