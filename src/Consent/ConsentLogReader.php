<?php

namespace CookieManagerKit\Consent;

interface ConsentLogReader
{
    /**
     * @return array{rows: list<array{createdAt: int, consentId: string, version: int, categories: list<string>, trigger: string, locale: string, userAgentFamily: string, reason: string}>, total: int}
     */
    public function page(ConsentLogFilter $filter, int $limit, int $offset): array;

    /**
     * @return list<array{createdAt: int, consentId: string, version: int, categories: list<string>, trigger: string, locale: string, userAgentFamily: string, reason: string}>
     */
    public function export(ConsentLogFilter $filter, int $max): array;
}
