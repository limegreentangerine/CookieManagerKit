<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

/**
 * Stands in for Concrete's mail service and remembers every call made to it.
 */
final class RecordingMailer
{
    /** @var list<array<int, string>> */
    public array $calls = [];

    public function to(string $email): void
    {
        $this->calls[] = ['to', $email];
    }

    public function setSubject(string $subject): void
    {
        $this->calls[] = ['subject', $subject];
    }

    public function setBody(string $body): void
    {
        $this->calls[] = ['body', $body];
    }

    public function sendMail(): void
    {
        $this->calls[] = ['send'];
    }
}
