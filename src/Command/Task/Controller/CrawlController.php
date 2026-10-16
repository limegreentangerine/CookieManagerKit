<?php

namespace CookieManagerKit\Command\Task\Controller;

use Concrete\Core\Command\Task\TaskInterface;
use CookieManagerKit\Command\Task\CrawlCommand;
use Concrete\Core\Command\Task\Input\InputInterface;
use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Runner\{ProcessTaskRunner, TaskRunnerInterface};

class CrawlController extends AbstractController
{
    public function getName(): string
    {
        return t('Scan the site for cookies and scripts');
    }

    public function getDescription(): string
    {
        return t('Fetches the published pages, records the third-party scripts, frames, images and cookies they use, and emails an alert when something new appears. Does nothing unless scheduled scanning is switched on in the Scanner settings.');
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new ProcessTaskRunner(
            $task,
            new CrawlCommand(),
            $input,
            t('Scanning the site...'),
        );
    }
}
