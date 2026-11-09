<?php

namespace CookieManagerKit\Support;

use Concrete\Core\File\File;
use Concrete\Core\File\Type\Type;

/**
 * Resolves the file manager image chosen as the banner icon to a URL. Anything that is missing, deleted or not an
 * image resolves to an empty string, so the banner simply renders without an icon.
 */
final class BannerIcon
{
    public static function url(?int $fileId): string
    {
        if (!$fileId) {
            return '';
        }

        try {
            $file = File::getByID($fileId);
            $version = $file ? $file->getApprovedVersion() : null;

            if (!$version || $version->getTypeObject()->getGenericType() !== Type::T_IMAGE) {
                return '';
            }

            return Output::safeUrl((string) $version->getURL());
        } catch (\Throwable $e) {
            ErrorLog::record($e);

            return '';
        }
    }
}
