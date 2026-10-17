<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Concrete\Core\Page\Controller\DashboardPageController;
use CookieManagerKit\Support\{Catalog, ErrorLog, SiteLocales};
use CookieManagerKit\Translation\{TargetLocale, TranslationCsv, TranslationReport, TranslationSlots};

/**
 * Translation completeness and bulk tools. Viewing reads only; copy, export and import are POSTs with a CSRF token,
 * and the target language is always checked against the site's own locales (and never the default).
 */
class Translations extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/translations';

    public $helpers = [
        'form',
        'concrete/ui',
    ];

    /**
     * @return list<\CookieManagerKit\Translation\Slot>
     */
    private function slots(): array
    {
        return TranslationSlots::collect($this->entityManager);
    }

    private function targetLocale(): ?string
    {
        return TargetLocale::resolve($this->post('locale'), SiteLocales::options(), SiteLocales::defaultLocale());
    }

    public function view()
    {
        $locales = SiteLocales::options();
        $default = SiteLocales::defaultLocale();

        $this->set('locales', $locales);
        $this->set('defaultLocale', $default);
        $this->set('matrix', TranslationReport::matrix($this->slots(), array_keys($locales), $default));
        $this->set('areas', [
            'banner' => [t('Banner'), '/dashboard/cookie_manager/banner'],
            'category' => [t('Categories'), '/dashboard/cookie_manager/categories'],
            'service' => [t('Services'), '/dashboard/cookie_manager/services'],
            'cookie' => [t('Cookies'), '/dashboard/cookie_manager/services'],
        ]);
    }

    public function copy()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $locale = $this->targetLocale();
        $area = $this->post('area');

        if ($locale === null) {
            $this->flash('error', t('Choose a language other than the default.'));

            return $this->buildRedirect(self::URL)->send();
        }

        if (!is_string($area) || ($area !== 'all' && !in_array($area, TranslationReport::AREAS, true))) {
            $this->flash('error', t('Choose a valid area.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $default = SiteLocales::defaultLocale();
        $slots = $this->slots();
        $count = 0;

        try {
            foreach ($area === 'all' ? TranslationReport::AREAS : [$area] as $one) {
                $count += TranslationReport::copyFromDefault($slots, $one, $locale, $default);
            }

            $this->entityManager->flush();
            Catalog::flush();
        } catch (\Throwable $e) {
            ErrorLog::record($e);
            $this->flash('error', t('The fields could not be copied because of an unexpected error.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $this->flash('success', t('Copied %s fields from the default language.', $count));

        return $this->buildRedirect(self::URL)->send();
    }

    public function export()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $locale = $this->targetLocale();

        if ($locale === null) {
            $this->flash('error', t('Choose a language other than the default.'));

            return $this->buildRedirect(self::URL)->send();
        }

        return new Response(TranslationCsv::export($this->slots(), $locale, SiteLocales::defaultLocale()), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="translations-' . $locale . '.csv"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function import()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $locale = $this->targetLocale();

        if ($locale === null) {
            $this->flash('error', t('Choose a language other than the default.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $file = $this->request->files->get('csv');

        if (!$file instanceof UploadedFile || !$file->isValid() || (int) $file->getSize() > TranslationCsv::MAX_BYTES) {
            $this->flash('error', t('Choose a valid CSV file to import.'));

            return $this->buildRedirect(self::URL)->send();
        }

        // Only the temporary path is used; the client-supplied file name is never read.
        $contents = (string) file_get_contents($file->getPathname());

        try {
            $result = TranslationCsv::apply($this->slots(), TranslationCsv::parse($contents, $locale), $locale, SiteLocales::defaultLocale());
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', t('The file could not be imported. %s', $e->getMessage()));

            return $this->buildRedirect(self::URL)->send();
        } catch (\Throwable $e) {
            ErrorLog::record($e);
            $this->flash('error', t('The file could not be imported because of an unexpected error.'));

            return $this->buildRedirect(self::URL)->send();
        }

        Catalog::flush();

        $this->flash('success', t('Imported %1$s fields; skipped %2$s blank values.', $result['applied'], $result['skipped']));

        return $this->buildRedirect(self::URL)->send();
    }
}
