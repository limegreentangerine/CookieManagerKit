<?php

namespace CookieManagerKit\Consent;

/**
 * The body of the consent endpoint's answer. Fixed shapes only; nothing from the request is ever included, and an
 * id is included only if it is a well-formed GUID (it comes from our own database, but defence is cheap).
 */
final class ConsentReply
{
    /**
     * @return array<string, mixed>
     */
    public static function body(int $status, ?string $id): array
    {
        if ($status !== 200) {
            return ['error' => 'invalid'];
        }

        if ($id !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id) === 1) {
            return ['ok' => true, 'id' => $id];
        }

        return ['ok' => true];
    }
}
