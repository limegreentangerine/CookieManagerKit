<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The task handles in tasks.xml, the map in the package controller and the classes on disk must all agree, or the
 * task shows up in Automation without a working controller (or the other way round).
 */
final class TasksTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testEveryXmlTaskHasAControllerEntryAndClassFiles(): void
    {
        $xml = simplexml_load_file(self::ROOT . '/tasks.xml');
        $controller = (string) file_get_contents(self::ROOT . '/controller.php');
        preg_match_all("/'([a-z_]+)'\\s*=>\\s*\\\\CookieManagerKit\\\\Command\\\\Task\\\\Controller\\\\([A-Za-z]+)::class/", $controller, $mapped, PREG_SET_ORDER);
        $map = [];

        foreach ($mapped as $row) {
            $map[$row[1]] = $row[2];
        }

        $this->assertNotEmpty($map);

        foreach ($xml->tasks->task as $task) {
            $handle = (string) $task['handle'];

            $this->assertSame('cookie_manager_kit', (string) $task['package'], $handle);
            $this->assertArrayHasKey($handle, $map, $handle . ' is missing from $tasks in controller.php');
            $this->assertFileExists(self::ROOT . '/src/Command/Task/Controller/' . $map[$handle] . '.php');
        }

        $this->assertSame(array_keys($map), array_map(static fn($task): string => (string) $task['handle'], iterator_to_array($xml->tasks->task, false)));
    }

    public function testEveryCommandHasAHandlerBesideIt(): void
    {
        foreach (glob(self::ROOT . '/src/Command/Task/*Command.php') ?: [] as $command) {
            $this->assertFileExists(preg_replace('/\.php\z/', 'Handler.php', $command), basename($command));
        }
    }

    public function testTheTasksetListsEveryTask(): void
    {
        $xml = simplexml_load_file(self::ROOT . '/tasks.xml');
        $tasks = array_map(static fn($task): string => (string) $task['handle'], iterator_to_array($xml->tasks->task, false));
        $inSet = array_map(static fn($task): string => (string) $task['handle'], iterator_to_array($xml->tasksets->taskset->task, false));

        $this->assertSame($tasks, $inSet);
    }

    public function testTasksAreInstalledBeforeThePagesOnEveryInstallOrUpgrade(): void
    {
        // The importer looks tasks and sets up by handle before adding them, so running it on each upgrade is safe.
        $controller = (string) file_get_contents(self::ROOT . '/controller.php');

        $this->assertMatchesRegularExpression("/function installOrUpgrade\\(.*?\\{\\s*\\\$this->installContentFile\\('tasks\\.xml'\\);/s", $controller);
    }

    public function testCachesAreFlushedAfterTheSeedOnEveryInstallOrUpgrade(): void
    {
        $controller = (string) file_get_contents(self::ROOT . '/controller.php');

        $this->assertMatchesRegularExpression(
            '/syncBundled\(\);.*?executeCommand\(new ClearCacheCommand\(\)\);\s*Catalog::flush\(\);/s',
            $controller,
        );
    }
}
