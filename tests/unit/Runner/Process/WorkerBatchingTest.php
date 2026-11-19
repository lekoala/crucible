<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner\Process;

use LucianoPereira\Crucible\Framework\TestCase;

use function chmod;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function scandir;
use function sort;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * `--worker-batch N` lets one worker process carry N independent units.
 *
 * The measured claim is process startups, and the fixture makes them
 * visible the honest way: the bootstrap appends a line every time it
 * loads, so the parent plus its workers are counted in one file. The
 * plan and its verdicts must stay the sequential twin's — batching
 * divides the startup, never the answer.
 *
 * Opt-in: the batch size defaults to 1, and the cases that need their
 * own process keep it (isolation, dependencies, a stop-on run, the
 * parent's global state).
 */
final class WorkerBatchingTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-worker-batching-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/bootstrap.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/counters.log', "boot\n", FILE_APPEND);
            PHP);

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->bootstrap('bootstrap.php')
                ->testSuite('unit', 'tests')
                ->build();
            PHP);

        foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $name) {
            file_put_contents($this->project . '/tests/' . $name . 'Test.php', $this->testFile($name));
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    private function testFile(string $name): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use LucianoPereira\\Crucible\\Attributes\\DataProvider;
            use LucianoPereira\\Crucible\\Framework\\TestCase;

            final class {$name}Test extends TestCase
            {
                public static function rows(): array
                {
                    return ['one' => ['one'], 'two' => ['two']];
                }

                #[DataProvider('rows')]
                public function test{$name}Rows(string \$row): void
                {
                    self::assertNotSame('', \$row);
                }
            }
            PHP;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, list<string>}
     */
    private function crucible(array $arguments): array
    {
        $events = $this->project . '/events.ndjson';
        @unlink($events);
        @unlink($this->project . '/counters.log');

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/crucible', ...$arguments, '--log-events-json', $events],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb'],
        );

        if (!is_resource($process)) {
            self::fail('crucible could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit     = proc_close($process);
        $contents = is_file($events) ? (string) file_get_contents($events) : '';

        if ($contents === '') {
            self::fail('No event stream was written. stdout: ' . $stdout . ' stderr: ' . $stderr);
        }

        /** @var list<string> $lines */
        $lines = [];

        foreach (explode("\n", $contents) as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return [$exit, $stdout, $lines];
    }

    /**
     * @param list<string> $events
     *
     * @return list<string>
     */
    private function ranIds(array $events): array
    {
        $ids = [];

        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['id'] ?? null)) {
                $ids[] = $decoded['id'];
            }
        }

        sort($ids);

        return $ids;
    }

    private function boots(): int
    {
        return substr_count((string) file_get_contents($this->project . '/counters.log'), 'boot');
    }

    public function testABatchDividesStartupsAndKeepsThePlanExact(): void
    {
        // The sequential twin: one bootstrap in the parent, one load of
        // each file, eight rows answered.
        [$exit, , $events] = $this->crucible([]);

        self::assertSame(0, $exit);
        self::assertSame(1, $this->boots(), 'The sequential run bootstraps once.');
        $sequential = $this->ranIds($events);
        self::assertCount(8, $sequential);

        // One unit per process: the parent plus four workers.
        [$exit, $stdout, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(0, $exit, $stdout);
        self::assertSame(5, $this->boots(), 'Without batching every unit gets its own process.');
        self::assertSame($sequential, $this->ranIds($events));

        // Two units per process: the parent plus two workers for four
        // units. The rows of both files in a batch are accounted for as
        // one plan, so no id is lost or duplicated.
        [$exit, $stdout, $events] = $this->crucible(['--parallel', '2', '--worker-batch', '2']);

        self::assertSame(0, $exit, $stdout);
        self::assertSame(3, $this->boots(), 'A batch of two boots once, not twice.');
        self::assertSame($sequential, $this->ranIds($events), 'Batching divides the startup, never the plan.');
    }

    public function testProcessIsolationIsNeverBatched(): void
    {
        [$exit, $stdout, $events] = $this->crucible(['--parallel', '2', '--worker-batch', '2', '--process-isolation']);

        self::assertSame(0, $exit, $stdout);

        // Four test names, each asking for its own process: the parent
        // plus four workers, whatever the batch size says.
        self::assertSame(5, $this->boots(), 'A test that asked for its own process does not share one.');

        $ids = $this->ranIds($events);
        sort($ids);
        self::assertCount(8, $ids, 'The dataset rows still run exactly once.');
    }

    public function testACrashInsideABatchStillAccountsForEveryMissingResult(): void
    {
        file_put_contents($this->project . '/tests/BetaTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class BetaTest extends TestCase
            {
                public function testBetaDies(): void
                {
                    exit(42);
                }
            }
            PHP);

        [$exit, $stdout, $events] = $this->crucible(['--parallel', '2', '--worker-batch', '2']);

        self::assertNotSame(0, $exit, 'A worker that dies takes its batch down with it; the run must say so.');
        self::assertStringContainsString('exited unexpectedly', $stdout);

        $outcomes = [];

        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['id'] ?? null)) {
                $outcomes[$decoded['id']] = $decoded['outcome'] ?? '?';
            }
        }

        // Beta produced no result of its own: the shutdown killed it. The
        // supervisor names it errored rather than dropping it, and the
        // batch that ran in the other slot still finished.
        self::assertSame('error', $outcomes['tests/BetaTest.php::testBetaDies'] ?? 'missing');
        self::assertSame('pass', $outcomes['tests/GammaTest.php::testGammaRows#one'] ?? 'missing');
        self::assertSame('pass', $outcomes['tests/DeltaTest.php::testDeltaRows#two'] ?? 'missing');
    }

    /** Git leaves its objects read-only, which Windows will not unlink until told otherwise. */
    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                chmod($path, 0o666);
                unlink($path);
            }

            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
