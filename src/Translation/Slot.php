<?php

namespace CookieManagerKit\Translation;

/**
 * One translatable field: a stable key, where it lives, and how to read and write one locale of it.
 */
final class Slot
{
    /**
     * @param \Closure(string): string       $read
     * @param \Closure(string, string): void $write
     */
    public function __construct(
        public readonly string $area,
        public readonly string $key,
        public readonly string $label,
        private readonly \Closure $read,
        private readonly \Closure $write,
    ) {}

    public function read(string $locale): string
    {
        return ($this->read)($locale);
    }

    public function write(string $locale, string $value): void
    {
        ($this->write)($locale, $value);
    }
}
