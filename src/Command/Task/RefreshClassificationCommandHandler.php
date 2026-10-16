<?php

namespace CookieManagerKit\Command\Task;

use GuzzleHttp\Client;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Scan\{ClassificationStore, GithubSnapshot, Refresher};
use Concrete\Core\Command\Task\Output\{OutputAwareInterface, OutputAwareTrait};

/**
 * The only caller of Refresher, which has no Settings dependency, so the opt-in is checked here first. Output is a
 * count or a fixed sentence; Refresher already logs a fixed message before it rethrows.
 */
class RefreshClassificationCommandHandler implements OutputAwareInterface
{
    use OutputAwareTrait;

    public const FAILED = 'The classification data could not be updated. The previous data is still in use.';

    public function __invoke(RefreshClassificationCommand $command)
    {
        if (!$this->settings()->isClassificationRefreshEnabled()) {
            $this->output->write(t('Skipped: downloading classification data is switched off in the Scanner settings, so nothing was downloaded.'));

            return;
        }

        try {
            $count = $this->refresher()->refresh();
        } catch (\Throwable $e) {
            ErrorLog::record($e);

            throw new \RuntimeException(t(self::FAILED));
        }

        $this->output->write(t('Classification data updated: %s entries.', $count));
    }

    protected function settings(): Settings
    {
        return Settings::get();
    }

    protected function refresher(): Refresher
    {
        return new Refresher(new GithubSnapshot(new Client()), new ClassificationStore(app(EntityManagerInterface::class)));
    }
}
