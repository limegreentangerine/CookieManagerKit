<?php

namespace Concrete\Package\CookieManagerKit;

use Concrete\Core\Entity\Package;
use ClassKit\Package\PackageController;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Events\ConsentManager;
use CookieManagerKit\Scan\ClassificationStore;
use Concrete\Core\Support\Facade\{Events, Route};
use CookieManagerKit\Support\{Catalog, Installer};
use ClassKit\Package\Traits\{AttributeTrait, BlockTrait, PageTrait};
use CookieManagerKit\Consent\{ConsentRecorder, DoctrineConsentLogStore, PersistingConsentRecorder};

class Controller extends PackageController
{
    use AttributeTrait;
    use BlockTrait;
    use PageTrait;
    /**
     * The packages handle.
     * Note that this must be unique in the
     * entire concrete5 package ecosystem.
     *
     * @var string
     */
    protected $pkgHandle = 'cookie_manager_kit';

    /**
     * The packages version.
     *
     * @var string
     */
    protected $pkgVersion = '0.4.0';

    /**
     * The minimum Concrete version compatible with the package.
     * Override this value according to the minimum required version for your package.
     *
     * @var string
     */
    protected $appVersionRequired = '9.5.0';

    /**
     * The minimum PHP version compatible with the package.
     * Override this value according to the minimum required version for your package.
     *
     * @var string
     * @var string
     */
    protected $phpVersionRequired = '8.4';

    /**
     * Package service providers to register.
     *
     * eg. 'Concrete\Package\PackageHandle\Src\Providers\PackageServiceProvider'
     *
     * @var array
     */
    protected $providers = [];

    /**
     * An array describing the package dependencies.
     * Keys are package handles.
     * Values may be:
     * - false: this package can't be installed if the other package is already installed.
     * - true: this package can't be installed of the other package is not installed
     * - a string: this package can't be installed of the other package is not installed or it's installed with an older version
     * - an array with two strings, representing the minimum and the maximum version of the other package to be installed.
     *
     * @var array
     *
     * @example [
     *     // This package can't be installed if a package with handle other_package_1 is already installed.
     *     'other_package_1' => false,
     *     // This package can't be installed if a package with handle other_package_2 is not installed.
     *     'other_package_2' => true,
     *     // This package can't be installed if a package with handle other_package_3 is not installed, or it has a version prior to 1.0
     *     'other_package_3' => '1.0',
     *     // This package can't be installed if a package with handle other_package_4 is not installed, or it has a version prior to 2.0, or it has a version after 2.9
     *     'other_package_4' => ['2.0', '2.9'],
     * ]
     */
    protected $packageDependencies = [
        'class_kit' => true,
    ];

    /**
     * Package class autoloader registrations
     * The package install helper class, included with this boilerplate,
     * is activated by default.
     *
     * @see https://goo.gl/4wyRtH
     * @var array
     */
    protected $pkgAutoloaderRegistries = [
        'src' => '\CookieManagerKit',
    ];

    /**
     * Tables dropped on uninstall when "purge database tables" is ticked, children before parents.
     *
     * @var array
     */
    protected $databaseTables = [
        'cmkConsentLog',
        'cmkConsentSubjects',
        'cmkPolicyChanges',
        'cmkScanFindings',
        'cmkScanRuns',
        'cmkClassificationEntries',
        'cmkStats',
        'cmkCookieContents',
        'cmkCookies',
        'cmkServiceContents',
        'cmkCategoryContents',
        'cmkServices',
        'cmkCategories',
        'cmkBannerContents',
        'cmkSettings',
    ];

    /**
     * Package tasks to register.
     *
     * eg. 'task_handle' => \PackageHandle\Command\Task\Controller\TaskHandleController::class,
     *
     * @var array
     */
    protected $tasks = [
        'cmk_scan_crawl' => \CookieManagerKit\Command\Task\Controller\CrawlController::class,
        'cmk_refresh_classification' => \CookieManagerKit\Command\Task\Controller\RefreshClassificationController::class,
        'cmk_purge_consent_log' => \CookieManagerKit\Command\Task\Controller\PurgeConsentLogController::class,
    ];

    public function getPackageName()
    {
        return t('Cookie Manager');
    }

    public function getPackageDescription()
    {
        return t('ConcreteCMS package to manage Cookie Banner');
    }

    public function installOrUpgrade(Package $pkg)
    {
        $this->installContentFile('tasks.xml');

        $this->addSinglePage('/dashboard/cookie_manager', $pkg, t('Cookie Manager'), t('Manage the cookie banner, categories and appearance'));
        $this->addSinglePage('/dashboard/cookie_manager/banner', $pkg, t('Banner'), t('Banner text for each language'));
        $this->addSinglePage('/dashboard/cookie_manager/categories', $pkg, t('Categories'), t('Consent categories'));
        $this->addSinglePage('/dashboard/cookie_manager/services', $pkg, t('Services'), t('Third-party services listed to visitors'));
        $this->addSinglePage('/dashboard/cookie_manager/appearance', $pkg, t('Appearance'), t('Colours, position and layout'));
        $this->addSinglePage('/dashboard/cookie_manager/statistics', $pkg, t('Statistics'), t('How visitors respond to the banner'));
        $this->addSinglePage('/dashboard/cookie_manager/scanner', $pkg, t('Scanner'), t('Scan the site for cookies and scripts'));
        $this->addSinglePage('/dashboard/cookie_manager/findings', $pkg, t('Findings'), t('Review what the scanner found'));
        $this->addSinglePage('/dashboard/cookie_manager/scan_settings', $pkg, t('Scanner Settings'), t('Crawl limits, alerts and classification data'));
        $this->addSinglePage('/dashboard/cookie_manager/consent_log', $pkg, t('Consent Log'), t('Who consented to what, and when'));
        $this->addSinglePage('/dashboard/cookie_manager/translations', $pkg, t('Translations'), t('See what is translated in each language'));

        $this->autoInstallBlocks($pkg);

        $em = app(EntityManagerInterface::class);

        Installer::seed($em, Catalog::defaultLocale());
        // Deliberately not caught: a failed seed must fail the install or upgrade loudly.
        (new ClassificationStore($em))->syncBundled();
    }

    public function registerRoutes(): void
    {
        // Public and read-only: identical for every visitor, so it is cacheable.
        Route::register(
            '/cookie-manager/config/{locale}.json',
            '\CookieManagerKit\Ajax\Config::serve',
            'cookie_manager_kit.config',
            ['locale' => '[A-Za-z0-9_\-]{1,32}'],
            [],
            '',
            [],
            ['GET', 'HEAD'],
        );

        // Public: pages are cached and anonymous, so it is defended by origin, schema and rate limit. See Ajax\Consent.
        Route::register(
            '/cookie-manager/consent',
            '\CookieManagerKit\Ajax\Consent::record',
            'cookie_manager_kit.consent',
            [],
            [],
            '',
            [],
            ['POST'],
        );

        // Public: anonymous banner statistics. Same defences as the consent endpoint. See Ajax\Event.
        Route::register(
            '/cookie-manager/event',
            '\CookieManagerKit\Ajax\Event::record',
            'cookie_manager_kit.event',
            [],
            [],
            '',
            [],
            ['POST'],
        );

        // Public, but only useful with the signed scan-mode token an admin gets from the dashboard. See Ajax\ScanReport.
        Route::register(
            '/cookie-manager/scan/report',
            '\CookieManagerKit\Ajax\ScanReport::record',
            'cookie_manager_kit.scan_report',
            [],
            [],
            '',
            [],
            ['POST'],
        );

        // Empty for everyone without the scan-mode token. See Ajax\ScanBeacon.
        Route::register(
            '/cookie-manager/scan/beacon.js',
            '\CookieManagerKit\Ajax\ScanBeacon::script',
            'cookie_manager_kit.scan_beacon',
            [],
            [],
            '',
            [],
            ['GET', 'HEAD'],
        );
    }

    public function registerEvents(): void
    {
        app()->bind(ConsentRecorder::class, static fn() => new PersistingConsentRecorder(new DoctrineConsentLogStore(app(EntityManagerInterface::class))));

        Events::addListener('on_before_render', function () {
            ConsentManager::onBeforeRender();
        });

        // Registered unconditionally: the SEO tracking snippets are made inert (or removed) even if the banner fails.
        Events::addListener('on_page_output', function ($event) {
            ConsentManager::onPageOutput($event);
        });
    }

}
