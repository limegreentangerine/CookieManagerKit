<?php

namespace CookieManagerKit\Command\Task\Controller;

use Concrete\Core\Command\Task\TaskInterface;
use Concrete\Core\Command\Task\Input\InputInterface;
use Concrete\Core\Command\Task\Controller\AbstractController;
use CookieManagerKit\Command\Task\RefreshClassificationCommand;
use Concrete\Core\Command\Task\Runner\{ProcessTaskRunner, TaskRunnerInterface};

class RefreshClassificationController extends AbstractController
{
    public function getName(): string
    {
        return t('Update cookie classification data');
    }

    public function getDescription(): string
    {
        return t('Downloads the public Open Cookie Database and uses it to suggest categories for scan findings. Does nothing unless downloading is switched on in the Scanner settings; your own rules are never replaced.');
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new ProcessTaskRunner(
            $task,
            new RefreshClassificationCommand(),
            $input,
            t('Updating classification data...'),
        );
    }
}
