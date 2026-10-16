<?php

namespace Concrete\Package\CookieManagerKit\Controller\SinglePage\Dashboard\CookieManager;

use CookieManagerKit\Entity\Settings;
use Concrete\Core\Page\Controller\DashboardPageController;

/**
 * Scanner settings. Values are validated by the Settings entity (clamped, allow-listed or dropped), never trusted.
 */
class ScanSettings extends DashboardPageController
{
    private const URL = '/dashboard/cookie_manager/scan_settings';

    public $helpers = [
        'form',
        'concrete/ui',
    ];

    public function view()
    {
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

        $settings = Settings::get();
        $settings->setScanMaxPages($this->post('scanMaxPages'))
            ->setScanExcludesText($this->post('scanExcludes'))
            ->setScanScheduleEnabled($this->post('scanSchedule') !== null)
            ->setScanAlertEmail($this->post('scanAlertEmail'))
            ->setScanStaleDays($this->post('scanStaleDays'))
            ->setClassificationRefreshEnabled($this->post('classificationRefresh') !== null);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        $this->flash('success', t('Scanner settings saved.'));

        return $this->buildRedirect(self::URL)->send();
    }
}
