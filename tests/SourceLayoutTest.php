<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Concrete scans every PHP file under src/ when it builds entity metadata, so a file there that isn't a class
 * (a template, a script) is executed during a schema comparison or package upgrade, and breaks both.
 */
final class SourceLayoutTest extends TestCase
{
    public function testEveryPhpFileUnderSrcDeclaresAClassLikeType(): void
    {
        $offenders = [];
        $src = dirname(__DIR__) . '/src';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            $declares = false;

            foreach ($tokens as $i => $token) {
                if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    // `Foo::class` is not a declaration.
                    $previous = $i > 0 ? $tokens[$i - 1] : null;
                    if (!(is_array($previous) && $previous[0] === T_DOUBLE_COLON)) {
                        $declares = true;
                        break;
                    }
                }
            }

            if (!$declares) {
                $offenders[] = substr($file->getPathname(), strlen($src) + 1);
            }
        }

        $this->assertSame([], $offenders, 'move these out of src/ (eg. into elements/)');
    }
}
