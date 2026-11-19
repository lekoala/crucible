<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Test\TestId;

/**
 * The work one worker process is given, plus what the supervisor
 * expects back from it: the ids (dataset rows included) that must
 * appear as test:finish events on the worker's stream. The expectation
 * is what makes worker crashes accountable — anything expected and
 * unfinished is reported errored.
 *
 * Usually one unit per process. `--worker-batch` lets a process carry
 * several (D-183): the same expectation, the same accounting, one
 * process startup and one bootstrap for the whole batch.
 */
final readonly class PlannedUnit
{
    /**
     * @param list<WorkUnit> $units
     * @param list<TestId>   $expected
     * @param list<string>   $dependsOn names this worker's tests depend on that no test of its own provides
     * @param list<string>   $provides  names of its own tests that some other unit depends on
     * @param bool           $preserveGlobalState a test here asked for the parent's globals and constants
     * @param bool           $isolated            the unit exists because its tests asked for their own process;
     *                                            false for a --parallel unit, which is only a share of the work
     */
    public function __construct(
        public array $units,
        public array $expected,
        public array $dependsOn = [],
        public array $provides = [],
        public bool $preserveGlobalState = false,
        public bool $isolated = false,
    ) {}

    /**
     * What the batch is expected to cost, for LPT ordering: the sum of
     * its members' cached durations (0.0 each when unknown).
     */
    public function cost(): float
    {
        $cost = 0.0;

        foreach ($this->units as $unit) {
            $cost += $unit->estimatedCost;
        }

        return $cost;
    }

    /**
     * @param list<string> $provides
     */
    public function providing(array $provides): self
    {
        return new self($this->units, $this->expected, $this->dependsOn, $provides, $this->preserveGlobalState, $this->isolated);
    }
}
