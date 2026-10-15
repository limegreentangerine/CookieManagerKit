<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Entity\{BannerContent, Settings};
use CookieManagerKit\Events\ConsentManager;
use CookieManagerKit\Support\{Catalog, SiteLocales, StyleRules};
use Concrete\Core\Page\Controller\DashboardPageController;

class Appearance extends DashboardPageController
{
    public $helpers = [
        'form',
        'concrete/ui',
    ];

    private const URL = '/dashboard/cookie_manager/appearance';
    private const TOKENS = ['tokenBg' => 'bg', 'tokenFg' => 'fg', 'tokenAccent' => 'accent', 'tokenButtonBg' => 'buttonBg', 'tokenButtonFg' => 'buttonFg'];

    public function view()
    {
        ConsentManager::registerAssets();
        $this->requireAsset('css', 'cookie-manager-kit/css');
        $this->requireAsset('javascript', 'cookie-manager-kit/dashboard');

        $banner = $this->entityManager->getRepository(BannerContent::class)->findOneBy(['locale' => SiteLocales::defaultLocale()]);

        $this->set('settings', Settings::get());
        $this->set('banner', $banner instanceof BannerContent ? $banner : null);
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

        foreach (array_keys(self::TOKENS) as $field) {
            if (!StyleRules::isHex($this->post($field))) {
                $this->flash('error', t('Colours must be six digit hex codes such as #0b5fff.'));

                return $this->buildRedirect(self::URL)->send();
            }
        }

        $radius = $this->post('radius');

        if (!is_string($radius) || preg_match('/^\d{1,2}\z/', $radius) !== 1 || (int) $radius > 32) {
            $this->flash('error', t('The corner radius must be a whole number between 0 and 32.'));

            return $this->buildRedirect(self::URL)->send();
        }

        $choices = [
            'position' => StyleRules::POSITIONS,
            'layout' => StyleRules::LAYOUTS,
            'font' => StyleRules::FONTS,
        ];

        foreach ($choices as $field => $allowed) {
            if (!is_string($this->post($field)) || !in_array($this->post($field), $allowed, true)) {
                $this->flash('error', t('Please choose one of the listed options for position, layout and font.'));

                return $this->buildRedirect(self::URL)->send();
            }
        }

        $settings = Settings::get();
        $settings->setPosition($this->post('position'))
            ->setLayout($this->post('layout'))
            ->setFont($this->post('font'))
            ->setRadius($radius)
            ->setShadow($this->post('shadow') !== null);

        foreach (self::TOKENS as $field => $token) {
            $settings->setToken($token, $this->post($field));
        }

        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        Catalog::flush();

        $this->flash('success', t('Appearance saved.'));

        return $this->buildRedirect(self::URL)->send();
    }
}
