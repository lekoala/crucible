<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Runner\ProcessSetup;
use LucianoPereira\Crucible\Test\TestId;

use function fclose;
use function fread;
use function fwrite;
use function getenv;
use function is_int;
use function is_resource;
use function min;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function str_ends_with;
use function stream_select;
use function stream_set_blocking;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function usleep;

use const PHP_BINARY;

/**
 * One spawned worker: `php crucible --worker`, manifest written to stdin
 * (then closed — the worker reads to EOF), NDJSON events read from
 * stderr. The child's stdout is the platform's null device: echoes are
 * discarded by policy, and discarding them at the descriptor means no
 * pipe can fill and stall a worker nobody is draining.
 *
 * Events and user stderr share the stderr pipe, but every event frame is
 * prefixed with {@see self::EVENT_MARKER} (CB-23). That is what makes a
 * raw `fwrite(STDERR, 'x')` without a newline — or any bootstrap,
 * discovery or test output — harmless: the reader splits on the marker,
 * never on a newline the user may not have written. stdout being the
 * null device, nothing a test writes there can touch a frame either.
 *
 * Keeping both channels on one pipe preserves the D-124 ordering: text
 * read before a frame belongs to no test (forwarded at `test:start`),
 * text read between two frames belongs to the test that produced them.
 * {@see self::nextLine()} returns one frame at a time and leaves
 * everything after it in the buffer, so the supervisor attributes the
 * exact text that preceded each event even when it read ahead.
 *
 * stderr reads are gated on stream_select readiness: the Supervisor
 * multiplexes many workers over stream_select, and asking the pipe
 * before reading it is what keeps that contract on Windows, where a
 * proc_open() pipe cannot be made non-blocking.
 */
final class WorkerProcess
{
    /**
     * The per-frame sentinel. It starts a write that also carries the
     * JSON and its newline, so the first bytes of a frame are never
     * split; a reader that sees a prefix holds it until the rest arrives.
     *
     * @var non-empty-string
     */
    public const string EVENT_MARKER = "\0\x1Ecrucible-worker-event\x1E\0";

    private string $stderrBuffer = '';

    private string $stderrPending = '';

    private string $stderrTail = '';

    private bool $finished = false;

    private bool $exited = false;

    /**
     * The first terminal status the process reported. proc_get_status()
     * answers a signal only to the call that first observes the exit: a
     * second read reports -1, which made `close()` lose the signal that
     * `atEof()` had already seen (CB-23, WSL).
     *
     * @var ?array{exitcode: int, signaled: bool, termsig: int}
     */
    private ?array $terminal = null;

    private ?int $signal = null;

    /**
     * @param resource     $process
     * @param resource     $stderr
     * @param list<TestId> $expected the test ids this worker must finish
     */
    private function __construct(
        private $process,
        private $stderr,
        public readonly array $expected,
    ) {}

    /**
     * @param non-empty-string        $crucibleBinary path of the crucible entry script
     * @param list<TestId>            $expected
     * @param list<non-empty-string>  $phpArguments extra php CLI arguments (e.g. the -d flags that
     *                                              load the coverage driver — children do not inherit
     *                                              the parent's -d flags)
     * @param array<string, string>   $env          extra environment for the child, merged over the
     *                                              inherited environment (the mutation worker injects
     *                                              its mutant this way); empty = plain inheritance
     */
    public static function spawn(
        string $crucibleBinary,
        WorkingDirectory $workingDirectory,
        WorkerManifest $manifest,
        array $expected,
        array $phpArguments = [],
        array $env = [],
    ): ?self {
        $process = proc_open(
            [PHP_BINARY, ...$phpArguments, $crucibleBinary, '--worker'],
            [0 => ['pipe', 'r'], 1 => ['file', NullDevice::path(), 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory->path,
            self::environment($env),
        );

        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $manifest->toJson());
        fclose($pipes[0]);

        // The child's stdout is the null device (see the class doc):
        // there is no pipe to drain and no way for an echo to stall the
        // run. stderr is the one channel the supervisor reads.
        stream_set_blocking($pipes[2], false);

        return new self($process, $pipes[2], $expected);
    }

    /**
     * @param array<string, string> $env
     *
     * @return ?array<string, string>
     */
    private static function environment(array $env): ?array
    {
        $inherited = ProcessSetup::inheritedEnvironment();

        if ($env === [] && $inherited === null) {
            return null;
        }

        return [...$inherited ?? getenv(), ...$env];
    }

    /**
     * The worker's stderr: framed events and the user's own stderr,
     * interleaved in write order.
     *
     * @return resource
     */
    public function stderr()
    {
        return $this->stderr;
    }

    /**
     * The next complete event frame, without blocking. User text read
     * before it is kept for {@see takeStderr()}; a partial frame stays
     * buffered until its newline arrives.
     */
    public function nextLine(): ?string
    {
        $marker = self::EVENT_MARKER;

        while (true) {
            $at = strpos($this->stderrBuffer, $marker);

            if ($at === false) {
                $this->flushText();

                // A marker split across two reads keeps its prefix
                // buffered; wait for the rest rather than print protocol
                // bytes as user output.
                if ($this->stderrBuffer !== '') {
                    if ($this->readStderr() === 0) {
                        return null;
                    }

                    continue;
                }

                if ($this->readStderr() === 0) {
                    return null;
                }

                continue;
            }

            if ($at > 0) {
                $this->appendPending(substr($this->stderrBuffer, 0, $at));
                $this->stderrBuffer = substr($this->stderrBuffer, $at);
            }

            $newline = strpos($this->stderrBuffer, "\n");

            if ($newline === false) {
                if ($this->readStderr() === 0) {
                    return null;
                }

                continue;
            }

            $line               = substr($this->stderrBuffer, strlen($marker), $newline - strlen($marker));
            $this->stderrBuffer = substr($this->stderrBuffer, $newline + 1);

            return $line;
        }
    }

    /**
     * Every complete frame currently available. The supervisor uses
     * {@see nextLine()} instead, because attribution needs one event at a
     * time; this is for callers that only collect the stream.
     *
     * @return list<string>
     */
    public function readLines(): array
    {
        $lines = [];

        while (($line = $this->nextLine()) !== null) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Everything the user wrote to stderr before the last frame read.
     * Reading is what the stream has delivered so far, never what a
     * test wrote after the event: {@see nextLine()} stops at the frame.
     */
    public function takeStderr(): string
    {
        $pending             = $this->stderrPending;
        $this->stderrPending = '';

        return $pending;
    }

    public function stderrTail(): string
    {
        return trim($this->stderrTail);
    }

    /**
     * The worker emitted its run:finish — the completion handshake.
     */
    public function markFinished(): void
    {
        $this->finished = true;
    }

    public function hasFinished(): bool
    {
        return $this->finished;
    }

    /**
     * Whether the worker is done and its channel fully read. The first
     * call after the process exits answers false so the caller reads the
     * bytes it wrote before exiting; the next one answers true.
     */
    public function atEof(): bool
    {
        if ($this->exited) {
            return true;
        }

        if ($this->terminalStatus() === null) {
            return false;
        }

        $this->exited = true;

        return false;
    }

    /**
     * Hard-kill the worker (signal 9 by number, so no pcntl constant is
     * needed — the cold mutation path runs where pcntl is absent). Used to
     * enforce a mutant's timeout; close() still reaps it afterwards.
     */
    public function terminate(): void
    {
        proc_terminate($this->process, 9);
    }

    /**
     * The signal that killed the worker, once close() reaped it; null
     * for a normal exit.
     */
    public function signal(): ?int
    {
        return $this->signal;
    }

    /**
     * Reaps the worker. The status is read before proc_close(), which
     * folds a signal into the exit code; a signal is returned as the
     * code, as proc_close() alone returns it.
     */
    public function close(): int
    {
        $this->readStderr();
        $this->flushAllText();

        fclose($this->stderr);

        do {
            $status = $this->terminalStatus();

            if ($status === null) {
                usleep(1_000);
            }
        } while ($status === null);

        $exit = proc_close($this->process);

        if ($status['signaled']) {
            $this->signal = $status['termsig'];

            return $status['termsig'];
        }

        return $exit;
    }

    /**
     * The status of a process that is no longer running, cached: the
     * first call is the only one that carries the signal.
     *
     * @return ?array{exitcode: int, signaled: bool, termsig: int}
     */
    private function terminalStatus(): ?array
    {
        if ($this->terminal !== null) {
            return $this->terminal;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            return null;
        }

        return $this->terminal = [
            'exitcode' => $status['exitcode'],
            'signaled' => $status['signaled'],
            'termsig'  => $status['termsig'],
        ];
    }

    /** Reads what the stderr pipe holds now; the byte count, for callers deciding whether to wait. */
    private function readStderr(): int
    {
        $read = 0;

        // Gated: readStderr() is called on the way out of nextLine()
        // and close(), where the frame the caller wanted may not have
        // arrived yet, and a bare read would wait for output the worker
        // may only produce once this process drains its pipe.
        while ($this->readable($this->stderr)) {
            $chunk = fread($this->stderr, 65_536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $this->stderrBuffer .= $chunk;
            $read += strlen($chunk);
        }

        return $read;
    }

    /**
     * Whether a pipe holds something to read right now, EOF included.
     *
     * PHP cannot make these pipes non-blocking on Windows:
     * stream_set_blocking() returns false for a proc_open() pipe there
     * (measured on 8.3.6), and a bare fread() on the stdout of a worker
     * that writes nothing to it waits forever — while the worker waits
     * for the stderr the supervisor has not drained. A worker running
     * a handful of tests emits more than the pipe's buffer, so the two
     * sides deadlock. stream_select() reports readiness without
     * reading, on every platform the fork runs on.
     *
     * @param resource|mixed $stream
     */
    private function readable(mixed $stream): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        $read   = [$stream];
        $write  = null;
        $except = null;

        $ready = @stream_select($read, $write, $except, 0, 0);

        return is_int($ready) && $ready > 0;
    }

    /**
     * Moves the buffer's unambiguous user text to the pending text, and
     * keeps a trailing prefix of the marker buffered — it may be the
     * first half of a frame.
     */
    private function flushText(): void
    {
        $length = strlen($this->stderrBuffer);

        if ($length === 0) {
            return;
        }

        $marker = self::EVENT_MARKER;
        $keep   = 0;

        for ($size = min($length, strlen($marker) - 1); $size > 0; --$size) {
            if (str_ends_with($this->stderrBuffer, substr($marker, 0, $size))) {
                $keep = $size;

                break;
            }
        }

        $this->appendPending(substr($this->stderrBuffer, 0, $length - $keep));
        $this->stderrBuffer = substr($this->stderrBuffer, $length - $keep);
    }

    /** At EOF a held partial marker is user text, not protocol. */
    private function flushAllText(): void
    {
        $this->appendPending($this->stderrBuffer);
        $this->stderrBuffer = '';
    }

    private function appendPending(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->stderrPending .= $text;
        $this->stderrTail = substr($this->stderrTail . $text, -2048);
    }
}
