<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Spec §8.2: every dashboard action that changes data checks the request method and a valid CSRF token before it
 * touches the database, and no redirect target comes from the request.
 */
final class ControllerSecurityTest extends TestCase
{
    private const WRITE_ACTIONS = ['save', 'delete'];

    /**
     * @return list<string>
     */
    private static function controllers(): array
    {
        $files = glob(dirname(__DIR__) . '/controllers/single_page/dashboard/cookie_manager/*.php') ?: [];
        sort($files);

        return $files;
    }

    private static function body(string $source, string $method): ?string
    {
        if (preg_match('/function\s+' . $method . '\s*\([^)]*\)[^{]*\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}' && --$depth === 0) {
                return substr($source, $start, $i - $start);
            }
        }

        return null;
    }

    public function testThereAreDashboardControllersToCheck(): void
    {
        $this->assertNotSame([], self::controllers());
    }

    public function testEveryWriteActionChecksMethodAndTokenBeforeWriting(): void
    {
        $offenders = [];

        foreach (self::controllers() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::WRITE_ACTIONS as $action) {
                $body = self::body($source, $action);

                if ($body === null) {
                    continue;
                }

                $name = basename($file) . '::' . $action;
                $token = strpos($body, "\$this->token->validate('submit')");
                $post = strpos($body, '$this->request->isPost()');
                $write = PHP_INT_MAX;

                foreach (['->persist(', '->remove(', '->flush(', '->bumpPolicyVersion('] as $call) {
                    $at = strpos($body, $call);
                    $write = $at === false ? $write : min($write, $at);
                }

                if ($token === false) {
                    $offenders[] = $name . ' does not validate the CSRF token';
                } elseif ($token > $write) {
                    $offenders[] = $name . ' writes before validating the CSRF token';
                }

                if ($post === false) {
                    $offenders[] = $name . ' does not require POST';
                } elseif ($post > $write) {
                    $offenders[] = $name . ' writes before checking the method';
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testNoRedirectTargetComesFromTheRequest(): void
    {
        $offenders = [];

        foreach (self::controllers() as $file) {
            if (preg_match('/redirect\s*\([^;]*(?:->post\(|->get\(|->request|\$_(?:GET|POST|REQUEST)|\$id)/i', (string) file_get_contents($file)) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'redirect to fixed routes only');
    }

    public function testControllersNeverEchoOrRenderRequestDataDirectly(): void
    {
        $offenders = [];

        foreach (self::controllers() as $file) {
            if (preg_match('/\b(?:echo|print|die|exit)\b/', (string) file_get_contents($file)) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'controllers set view variables and flash constant messages only');
    }
}
