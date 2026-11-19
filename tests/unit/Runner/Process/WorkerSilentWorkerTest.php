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

use function array_search;
use function chmod;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function microtime;
use function mkdir;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function realpath;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

use const PHP_BINARY;

/**
 * A silent worker must not delay the frames of a busy one.
 *
 * This is the decisive shape of the Windows drain bug: the supervisor
 * reads the workers' stderr in spawn order, and the first worker emits
 * its `test:start` and then sleeps. A blocking read waits there for the
 * first worker's next frame, so the second worker's result — already
 * written — only reaches the stream once the silent one finishes. The
 * assertion is on the SEQUENCE of events, not on sorted ids: the fast
 * worker must finish before the slow one does, because nothing should
 * have been waiting for the slow one.
 *
 * The opposite order (slow worker's finish first) is what the old pipe
 * read produced, and it is also what makes the mutation executor check
 * its timeout late.
 */
final class WorkerSilentWorkerTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-worker-silence-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->testSuite('unit', 'tests')
                ->build();
            PHP);

        // Dispatched first (name order), silent for 850 ms after its
        // start event.
        file_put_contents($this->project . '/tests/AlphaSlowTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class AlphaSlowTest extends TestCase
            {
                public function testSleepsQuietly(): void
                {
                    usleep(850_000);

                    self::assertTrue(true);
                }
            }
            PHP);

        file_put_contents($this->project . '/tests/BetaFastTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class BetaFastTest extends TestCase
            {
                public function testFinishesImmediately(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testABusyWorkersFramesDoNotWaitForASilentOne(): void
    {
        $events     = $this->project . '/events.ndjson';
        $stdoutFile = $this->project . '/run.stdout';
        $stderrFile = $this->project . '/run.stderr';

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/crucible', '--parallel', '2', '--log-events-json', $events],
            [
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ],
            $pipes,
            $this->project,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb'],
        );

        if (!is_resource($process)) {
            self::fail('crucible could not be started.');
        }

        $deadline = microtime(true) + 20.0;
        $timedOut = false;

        while (proc_get_status($process)['running']) {
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);

                break;
            }

            usleep(10_000);
        }

        $exit   = proc_close($process);
        $stdout = is_file($stdoutFile) ? (string) file_get_contents($stdoutFile) : '';
        $stderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';

        self::assertFalse($timedOut, 'The run never finished.');
        self::assertSame(0, $exit, $stdout . $stderr);
        self::assertTrue(is_file($events), 'No event stream was written. ' . $stdout . $stderr);

        /** @var list<string> $order */
        $order = [];

        foreach (explode("\n", (string) file_get_contents($events)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['id'] ?? null)) {
                $order[] = $decoded['id'];
            }
        }

        $fast = array_search('tests/BetaFastTest.php::testFinishesImmediately', $order, true);
        $slow = array_search('tests/AlphaSlowTest.php::testSleepsQuietly', $order, true);

        self::assertIsInt($fast, 'The fast worker produced no result.');
        self::assertIsInt($slow, 'The slow worker produced no result.');
        self::assertLessThan(
            $slow,
            $fast,
            'The fast worker finished at position ' . $fast . ' and the silent one at ' . $slow . ': the stream waited for the silent worker.',
        );
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
