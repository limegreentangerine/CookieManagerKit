<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\ScanToken;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScanTokenTest extends TestCase
{
    private const SECRET = 'server-side-pepper';

    public function testAFreshTokenVerifiesAndCarriesTheRunAndExpiry(): void
    {
        $token = ScanToken::issue(42, 1700000000, self::SECRET);

        $this->assertSame(['run' => 42, 'expires' => 1700000000 + ScanToken::LIFETIME], ScanToken::verify($token, 1700000001, self::SECRET));
    }

    public function testItExpires(): void
    {
        $token = ScanToken::issue(42, 1700000000, self::SECRET);

        $this->assertNotNull(ScanToken::verify($token, 1700000000 + ScanToken::LIFETIME - 1, self::SECRET));
        $this->assertNull(ScanToken::verify($token, 1700000000 + ScanToken::LIFETIME, self::SECRET));
        $this->assertNull(ScanToken::verify($token, 1700000000 + ScanToken::LIFETIME + 1, self::SECRET));
    }

    public function testAnotherSecretOrATamperedPartIsRejected(): void
    {
        $token = ScanToken::issue(42, 1700000000, self::SECRET);
        [$run, $expires, $signature] = explode('.', $token);

        $this->assertNull(ScanToken::verify($token, 1700000001, 'another-secret'));
        $this->assertNull(ScanToken::verify('43.' . $expires . '.' . $signature, 1700000001, self::SECRET));
        $this->assertNull(ScanToken::verify($run . '.' . ((int) $expires + 9999) . '.' . $signature, 1700000001, self::SECRET));
        $this->assertNull(ScanToken::verify($run . '.' . $expires . '.' . str_repeat('0', 64), 1700000001, self::SECRET));
    }

    public function testAnEmptySecretNeverVerifiesAnything(): void
    {
        $token = ScanToken::issue(1, 1700000000, '');

        $this->assertNull(ScanToken::verify($token, 1700000001, ''));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformed(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'array' => [['1.2.3']],
            'two parts' => ['1.1700000000'],
            'four parts' => ['1.1700000000.0.x'],
            'letters in run' => ['a.1700000000.0'],
            'short signature' => ['1.1700000000.abcd'],
            'upper case signature' => ['1.1700000000.ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789'],
            'trailing newline' => ['1.1700000000.' . str_repeat('a', 64) . "\n"],
            'huge run' => ['99999999999.1700000000.a'],
        ];
    }

    #[DataProvider('malformed')]
    public function testMalformedTokensAreRejected(mixed $token): void
    {
        $this->assertNull(ScanToken::verify($token, 1700000001, self::SECRET));
    }
}
