<?php

namespace CookieManagerKit\Command\Task;

use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Consent\ConsentLogRetention;
use Concrete\Core\Command\Task\Output\{OutputAwareInterface, OutputAwareTrait};

/**
 * Output is a count or a fixed sentence; an exception's text (which can carry SQL) never reaches the task log.
 */
class PurgeConsentLogCommandHandler implements OutputAwareInterface
{
    use OutputAwareTrait;

    public const FAILED = 'The consent log could not be purged.';

    public function __invoke(PurgeConsentLogCommand $command)
    {
        try {
            $count = $this->removed();
        } catch (\Throwable $e) {
            ErrorLog::record($e);

            throw new \RuntimeException(t(self::FAILED));
        }

        $this->output->write(t('Removed the consent records of %s browsers that have not made a choice within the retention period.', $count));
    }

    protected function removed(): int
    {
        $months = Settings::get()->getConsentLogRetentionMonths();

        return (new ConsentLogRetention(app(EntityManagerInterface::class)->getConnection()))
            ->purge(ConsentLogRetention::cutoff($months, new \DateTimeImmutable()));
    }
}
