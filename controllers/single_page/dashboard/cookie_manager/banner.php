<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use Concrete\Core\Page\Page;
use CookieManagerKit\Support\{Catalog, SiteLocales, StyleRules, Text};
use CookieManagerKit\Entity\{BannerContent, Settings};
use Concrete\Core\Page\Controller\DashboardPageController;

class Banner extends DashboardPageController
{
    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private const URL = '/dashboard/cookie_manager/banner';

    public function view()
    {
        $contents = [];

        foreach ($this->entityManager->getRepository(BannerContent::class)->findAll() as $row) {
            $contents[$row->getLocale()] = $row;
        }

        $this->set('locales', SiteLocales::options());
        $this->set('defaultLocale', SiteLocales::defaultLocale());
        $this->set('contents', $contents);
        $this->set('settings', Settings::get());
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->buildRedirect(self::URL)->send();
        }

        if (!$this->token->validate('submit')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect(self::URL)->send();
        }

        $lifetime = $this->post('lifetimeDays');

        if (!StyleRules::isValidLifetime($lifetime)) {
            $this->flash('error', t('Remember a visitor\'s choice for a whole number of days between %s and %s.', StyleRules::MIN_LIFETIME_DAYS, StyleRules::MAX_LIFETIME_DAYS));

            return $this->buildRedirect(self::URL)->send();
        }

        $locales = SiteLocales::options();
        $default = SiteLocales::defaultLocale();
        $posted = $this->post('content');
        $posted = is_array($posted) ? $posted : [];
        $values = [];

        foreach (array_keys($locales) as $locale) {
            $input = is_array($posted[$locale] ?? null) ? $posted[$locale] : [];
            $values[$locale] = [
                'title' => Text::plain($input['title'] ?? ''),
                'body' => Text::multiline($input['body'] ?? ''),
                'acceptAll' => Text::plain($input['acceptAll'] ?? '', 64),
                'rejectAll' => Text::plain($input['rejectAll'] ?? '', 64),
                'customise' => Text::plain($input['customise'] ?? '', 64),
                'save' => Text::plain($input['save'] ?? '', 64),
                'widget' => Text::plain($input['widget'] ?? '', 64),
                'policyLinkText' => Text::plain($input['policyLinkText'] ?? ''),
                'policyPage' => $this->existingPageId($this->post('policyPage_' . $locale)),
            ];
        }

        if (!isset($values[$default]) || $values[$default]['title'] === '' || $values[$default]['body'] === '') {
            $this->flash('error', t('The default language needs a heading and a message.'));

            return $this->buildRedirect(self::URL)->send();
        }

        foreach ($values as $locale => $value) {
            $row = $this->entityManager->getRepository(BannerContent::class)->findOneBy(['locale' => $locale]);
            $blank = $value['policyPage'] === null && implode('', array_diff_key($value, ['policyPage' => 1])) === '';

            // A language with nothing filled in is removed, so visitors in that language get the default language.
            if ($blank && $locale !== $default) {
                if ($row) {
                    $this->entityManager->remove($row);
                }

                continue;
            }

            $row ??= (new BannerContent())->setLocale($locale);
            $row->setTitle($value['title'])
                ->setBody($value['body'])
                ->setAcceptAllLabel($value['acceptAll'])
                ->setRejectAllLabel($value['rejectAll'])
                ->setCustomiseLabel($value['customise'])
                ->setSaveLabel($value['save'])
                ->setWidgetLabel($value['widget'])
                ->setPolicyLinkText($value['policyLinkText'])
                ->setPolicyPageId($value['policyPage']);

            $this->entityManager->persist($row);
        }

        $settings = Settings::get();
        $settings->setLifetimeDays($lifetime)->setWidgetEnabled($this->post('widgetEnabled') !== null);

        if ($this->post('askAgain') !== null) {
            $settings->bumpPolicyVersion();
        }

        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Banner saved.'));

        return $this->buildRedirect(self::URL)->send();
    }

    private function existingPageId(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $cid = preg_match('/^\d+\z/', (string) $value) === 1 ? (int) $value : 0;

        if ($cid < 1) {
            return null;
        }

        $page = Page::getByID($cid);

        return $page && !$page->isError() ? $cid : null;
    }
}
