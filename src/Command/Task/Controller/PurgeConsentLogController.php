<?php

namespace CookieManagerKit\Command\Task\Controller;

use Concrete\Core\Command\Task\TaskInterface;
use Concrete\Core\Command\Task\Input\InputInterface;
use CookieManagerKit\Command\Task\PurgeConsentLogCommand;
use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Runner\{ProcessTaskRunner, TaskRunnerInterface};

class PurgeConsentLogController extends AbstractController
{
    public function getName(): string
    {
        return t('Purge old consent records');
    }

    public function getDescription(): string
    {
        return t('Deletes the consent history of browsers that have not made a choice within the retention period set on the Banner page. Purged records cannot be recovered.');
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new ProcessTaskRunner(
            $task,
            new PurgeConsentLogCommand(),
            $input,
            t('Purging old consent records...'),
        );
    }
}
