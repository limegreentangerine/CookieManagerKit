<?php

namespace Concrete\Package\CookieManagerKit\Block\CookieDeclaration;

use Concrete\Core\Block\BlockController;
use Concrete\Core\Localization\Localization;
use CookieManagerKit\Support\{Catalog, CookieDeclaration, EssentialCookies, ErrorLog};

class Controller extends BlockController
{
    protected $btTable = 'btCookieDeclaration';
    protected $btInterfaceWidth = 400;
    protected $btInterfaceHeight = 200;
    protected $btCacheBlockOutput = false;

    public function getBlockTypeName()
    {
        return t('Cookie Declaration');
    }

    public function getBlockTypeDescription()
    {
        return t('Lists the cookies this site sets, by category, with a link to change consent.');
    }

    public function view()
    {
        $sections = [];

        try {
            $sections = CookieDeclaration::build(
                Catalog::snapshot(),
                Catalog::resolveLocale((string) Localization::activeLocale()),
                Catalog::defaultLocale(),
                EssentialCookies::describe(EssentialCookies::names(), Catalog::lifetimeDays()),
                static fn(int $days): string => $days === 0 ? t('Session') : t2('%d day', '%d days', $days),
            );
        } catch (\Throwable $e) {
            // A page must never fail to render because the declaration could not be built (for example before the Update has run).
            ErrorLog::record($e);
        }

        $this->set('sections', $sections);
    }
}
