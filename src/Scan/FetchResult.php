<?php

namespace CookieManagerKit\Scan;

final readonly class FetchResult
{
    /**
     * @param array<string, list<string>> $headers keyed by lower-case header name
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function cookies(): array
    {
        return array_values(array_map('strval', $this->headers['set-cookie'] ?? []));
    }

    public function isHtml(): bool
    {
        return preg_match('#^(?:text/html|application/xhtml\+xml)\b#i', trim((string) $this->header('content-type'))) === 1;
    }
}
