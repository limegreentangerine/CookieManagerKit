<?php

declare(strict_types=1);

define('C5_EXECUTE', true);
define('DIR_BASE', __DIR__);
define('DIR_BASE_CORE', __DIR__ . '/vendor/concrete5/core');
define('DIR_APPLICATION', __DIR__);
define('DIR_CONFIG_SITE', __DIR__ . '/application/config');

require_once __DIR__ . '/vendor/autoload.php';
require_once DIR_BASE_CORE . '/bootstrap/helpers.php';

if (!class_exists('Core', false)) {
    class Core
    {
        public static function make(string $abstract): mixed
        {
            return null;
        }
    }
}

if (!class_exists('File', false)) {
    class_alias(Concrete\Core\File\File::class, 'File');
}

if (!class_exists('Events', false)) {
    class Events
    {
        public static function dispatch(string $eventName, ?object $event = null): mixed
        {
            return null;
        }
    }
}
