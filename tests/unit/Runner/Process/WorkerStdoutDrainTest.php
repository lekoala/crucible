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
use function fclose;
use function file_put_contents;
use function fread;
use function getenv;
use function is_dir;
use function is_file;
use function is_resource;
use function microtime;
use function mkdir;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function realpath;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function stream_select;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * A worker that writes nothing to stdout must not stall the supervisor.
 *
 * `stream_set_blocking()` returns false for a proc_open() pipe on
 * Windows (measured on PHP 8.3.6), so the pipes stay blocking. The
 * supervisor used to drain stdout with a bare fread() as soon as any
 * pipe woke it up: while the worker was alive and silent on stdout,
 * the parent waited there. The worker, in turn, filled its stderr
 * pipe with events and waited for that to be drained. A handful of
 * tests per worker is enough to fill the OS pipe buffer, so a plain
 * `--parallel 2` run of three ordinary files never finished.
 *
 * The fixture is deliberately boring — trivial assertions, no output —
 * because that is the shape that deadlocks: nothing writes to stdout,
 * and the work takes long enough for the stderr buffer to fill.
 */
final class WorkerStdoutDrainTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-worker-drain-' . uniqid();

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

        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $methods = '';

            for ($case = 0; $case < 30; $case++) {
                $methods .= "    public function testCase{$case}(): void { self::assertTrue(true); }\n";
            }

            file_put_contents($this->project . '/tests/' . $name . 'Test.php', <<<PHP
                <?php

                declare(strict_types=1);

                use LucianoPereira\\Crucible\\Framework\\TestCase;

                final class {$name}Test extends TestCase
                {
                {$methods}}
                PHP);
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testAWorkerSilentOnStdoutStillFinishesTheRun(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/crucible', '--parallel', '2'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb'],
        );

        if (!is_resource($process)) {
            self::fail('crucible could not be started.');
        }

        $stdout   = '';
        $stderr   = '';
        $deadline = microtime(true) + 15.0;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            // The test's own pipes are blocking on Windows too: ask
            // before reading, exactly as the supervisor now does.
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;

            if (@stream_select($read, $write, $except, 0, 100_000) > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65_536);

                    if ($chunk === false) {
                        continue;
                    }

                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            if (!$status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                $timedOut = true;

                break;
            }
        }

        if ($timedOut) {
            // A deadlocked run leaves a worker waiting on its stderr;
            // stop the parent, then read whatever the pipes still hold
            // without waiting for a writer that may never finish.
            proc_terminate($process, 9);
        } else {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        self::assertFalse($timedOut, 'The run deadlocked: the supervisor waited on a worker that was waiting for its stderr to be drained.');
        self::assertSame(0, $exit, $stdout . $stderr);
        self::assertStringContainsString('Tests: 90', $stdout, 'Every test the plan named ran.');
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
