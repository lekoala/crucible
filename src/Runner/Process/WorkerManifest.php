<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Configuration\Overrides;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * What the supervisor hands a worker on stdin: the configuration to
 * load, the work to run, and the ordering to apply — everything a
 * worker needs to reproduce its slice of the run deterministically,
 * as plain JSON. The manifest is the entire input protocol; the
 * NDJSON event stream on stdout is the entire output protocol.
 */
final readonly class WorkerManifest
{
    /**
     * @param non-empty-string  $configuration   absolute path of the configuration file
     * @param list<WorkUnit>    $units
     * @param ?positive-int     $testToken       ParaTest-compatible worker-slot number (TEST_TOKEN)
     * @param ?non-empty-string $uniqueTestToken run-unique per-slot token (UNIQUE_TEST_TOKEN)
     * @param ?int<0, max>      $retries         the run's resolved retry budget (G4); null = configuration decides
     * @param ?non-empty-string $coverageArtifact where this worker writes its CoverageData JSON (D-041); null = no coverage
     * @param bool              $coverageBranch   collect branch coverage in the window (D-062, xdebug only)
     * @param bool              $pathCoverage     also collect path analysis (--path-coverage, xdebug only)
     * @param ?string           $globalState      the parent's exported constants and globals, for #[PreserveGlobalState(true)]; null = boot clean
     * @param ?bool             $strictCoverage   CLI --strict-coverage override (D-063); null = configuration decides
     * @param ?non-empty-string $browser          CLI --browser engine override (D-064); null = configuration decides
     * @param bool              $debug            CLI --debug headed override (D-064)
     * @param Overrides         $overrides        configuration values the CLI overrode; a worker loads the file from disk and would otherwise disagree with its sequential twin
     * @param list<non-empty-string> $includePaths the spec's --include-path, applied before the worker's bootstrap
     * @param array<string, string>  $dependencyValues    name => base64 of a serialized #[Depends] return value produced by an earlier unit
     * @param list<string>           $provideValues       names whose return value a later unit depends on, so this worker records them
     * @param ?non-empty-string      $dependencyArtifact  where this worker writes the values it was asked to provide; null = none wanted
     * @param list<non-empty-string> $paths               the run's test paths (D-152), which discovery runs instead of the configured suites
     * @param bool              $noExtensions           CLI --no-extensions (D-172): the worker registers no configured extension — the around-test role included — so a parallel run stays its sequential twin
     * @param bool                   $fullDiscovery replay discovery in the original order for a coupled suite
     * @param ?list<non-empty-string> $onlySuites
     * @param list<non-empty-string>  $exceptSuites
     * @param list<non-empty-string>  $suffixes
     */
    public function __construct(
        public string $configuration,
        public array $units,
        public ExecutionOrder $order = ExecutionOrder::Declared,
        public int $seed = 0,
        public ?int $testToken = null,
        public ?string $uniqueTestToken = null,
        public ?int $retries = null,
        public ?string $coverageArtifact = null,
        public bool $updateSnapshots = false,
        public bool $coverageBranch = false,
        public ?bool $strictCoverage = null,
        public ?string $browser = null,
        public bool $debug = false,
        public Overrides $overrides = new Overrides(),
        public array $includePaths = [],
        public array $dependencyValues = [],
        public array $provideValues = [],
        public ?string $dependencyArtifact = null,
        public bool $pathCoverage = false,
        public ?string $globalState = null,
        public array $paths = [],
        public bool $noExtensions = false,
        public bool $fullDiscovery = false,
        public ?array $onlySuites = null,
        public array $exceptSuites = [],
        public array $suffixes = [],
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'configuration' => $this->configuration,
            'units'         => array_map(
                static fn(WorkUnit $unit): array => ['file' => $unit->file, 'tests' => $unit->tests],
                $this->units,
            ),
            'order'              => $this->order->value,
            'seed'               => $this->seed,
            'testToken'          => $this->testToken,
            'uniqueTestToken'    => $this->uniqueTestToken,
            'retries'            => $this->retries,
            'overrides'          => $this->overrides->toArray(),
            'includePaths'       => $this->includePaths,
            'coverageArtifact'   => $this->coverageArtifact,
            'updateSnapshots'    => $this->updateSnapshots,
            'coverageBranch'     => $this->coverageBranch,
            'strictCoverage'     => $this->strictCoverage,
            'browser'            => $this->browser,
            'debug'              => $this->debug,
            'dependencyValues'   => $this->dependencyValues,
            'provideValues'      => $this->provideValues,
            'dependencyArtifact' => $this->dependencyArtifact,
            'pathCoverage'       => $this->pathCoverage,
            'globalState'        => $this->globalState,
            'paths'              => $this->paths,
            'noExtensions'       => $this->noExtensions,
            'fullDiscovery'      => $this->fullDiscovery,
            'onlySuites'         => $this->onlySuites,
            'exceptSuites'       => $this->exceptSuites,
            'suffixes'           => $this->suffixes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Narrows discovered groups to this manifest's work, in the
     * manifest's OWN order: the units decide which file comes first (a
     * file may appear twice, for two suites or two repetitions), and a
     * unit's test list is the exact sequence its worker runs — full id
     * strings keep their dataset row and their duplicates.
     *
     * Rebuilding rather than filtering is what makes a batch the
     * parent's plan rather than the discovery order: the parent has
     * already applied the ordering, the repetitions and the selection,
     * and none of that survives a discovery-order walk.
     *
     * @param list<TestGroup> $groups
     *
     * @return list<TestGroup>
     */
    public function filter(array $groups): array
    {
        /** @var array<non-empty-string, list<TestGroup>> $byFile */
        $byFile = [];

        foreach ($groups as $group) {
            if ($group->tests !== []) {
                $byFile[$group->tests[0]->id->file][] = $group;
            }
        }

        $filtered = [];

        foreach ($this->units as $unit) {
            $source = $byFile[$unit->file] ?? [];

            if ($source === []) {
                continue;
            }

            if ($unit->tests === null) {
                foreach ($source as $group) {
                    $filtered[] = $group;
                }

                continue;
            }

            /** @var array<int, array{non-empty-string, TestDefinition}> $byId */
            $byId = [];

            /** @var array<non-empty-string, list<array{int, TestDefinition}>> $byName */
            $byName = [];

            foreach ($source as $index => $group) {
                foreach ($group->tests as $definition) {
                    // First match wins for an exact id; a name can be
                    // carried by several dataset rows, which is the
                    // documented behaviour of a name-only entry.
                    $byId[$definition->id->toString()] ??= [$index, $definition];
                    $byName[$definition->id->name][] = [$index, $definition];
                }
            }

            /** @var array<int, list<TestDefinition>> $wanted */
            $wanted = [];

            foreach ($unit->tests as $entry) {
                $matches = isset($byId[$entry])
                    ? [$byId[$entry]]
                    : $byName[$entry] ?? [];

                foreach ($matches as [$index, $definition]) {
                    $wanted[$index][] = $definition;
                }
            }

            foreach ($source as $index => $group) {
                if (!isset($wanted[$index])) {
                    continue;
                }

                $filtered[] = new TestGroup($group->name, $wanted[$index], $group->beforeAll, $group->afterAll);
            }
        }

        return $filtered;
    }

    /**
     * Whether a unit names only a file, leaving discovery and ordering
     * to the worker (a hand-built manifest, or cold mutation naming a
     * whole file). Supervisor-planned units always carry their ids, so
     * a parallel run never takes this path.
     */
    public function needsScheduling(): bool
    {
        foreach ($this->units as $unit) {
            if ($unit->tests === null) {
                return true;
            }
        }

        return false;
    }

    public static function fromJson(string $json): ?self
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return null;
        }

        $configuration = $decoded['configuration'] ?? null;
        $order         = ExecutionOrder::tryFrom(is_string($decoded['order'] ?? null) ? $decoded['order'] : '');
        $seed          = $decoded['seed'] ?? 0;

        if (!is_string($configuration) || $configuration === '' || !$order instanceof ExecutionOrder || !is_int($seed)) {
            return null;
        }

        $units = [];

        foreach (is_array($decoded['units'] ?? null) ? $decoded['units'] : [] as $unit) {
            if (!is_array($unit) || !is_string($unit['file'] ?? null) || $unit['file'] === '') {
                return null;
            }

            $tests = null;

            if (is_array($unit['tests'] ?? null)) {
                $tests = [];

                foreach ($unit['tests'] as $test) {
                    if (!is_string($test) || $test === '') {
                        return null;
                    }

                    $tests[] = $test;
                }
            }

            $units[] = new WorkUnit($unit['file'], $tests);
        }

        $testToken       = $decoded['testToken'] ?? null;
        $uniqueTestToken = $decoded['uniqueTestToken'] ?? null;
        $retries         = $decoded['retries'] ?? null;
        $includePaths    = [];

        foreach (is_array($decoded['includePaths'] ?? null) ? $decoded['includePaths'] : [] as $path) {
            if (is_string($path) && $path !== '') {
                $includePaths[] = $path;
            }
        }

        $paths = [];

        foreach (is_array($decoded['paths'] ?? null) ? $decoded['paths'] : [] as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        $coverageArtifact = $decoded['coverageArtifact'] ?? null;
        $provideValues    = [];

        foreach (is_array($decoded['provideValues'] ?? null) ? $decoded['provideValues'] : [] as $name) {
            if (is_string($name) && $name !== '') {
                $provideValues[] = $name;
            }
        }

        return new self(
            $configuration,
            $units,
            $order,
            $seed,
            is_int($testToken) && $testToken >= 1 ? $testToken : null,
            is_string($uniqueTestToken) && $uniqueTestToken !== '' ? $uniqueTestToken : null,
            is_int($retries) && $retries >= 0 ? $retries : null,
            is_string($coverageArtifact) && $coverageArtifact !== '' ? $coverageArtifact : null,
            ($decoded['updateSnapshots'] ?? false) === true,
            ($decoded['coverageBranch'] ?? false) === true,
            is_bool($decoded['strictCoverage'] ?? null) ? $decoded['strictCoverage'] : null,
            is_string($decoded['browser'] ?? null) && $decoded['browser'] !== '' ? $decoded['browser'] : null,
            ($decoded['debug'] ?? false) === true,
            Overrides::fromArray(is_array($decoded['overrides'] ?? null) ? $decoded['overrides'] : []),
            $includePaths,
            DependencyValues::fromArray(is_array($decoded['dependencyValues'] ?? null) ? $decoded['dependencyValues'] : []),
            $provideValues,
            is_string($decoded['dependencyArtifact'] ?? null) && $decoded['dependencyArtifact'] !== ''
                ? $decoded['dependencyArtifact']
                : null,
            ($decoded['pathCoverage'] ?? false) === true,
            is_string($decoded['globalState'] ?? null) && $decoded['globalState'] !== ''
                ? $decoded['globalState']
                : null,
            $paths,
            ($decoded['noExtensions'] ?? false) === true,
            ($decoded['fullDiscovery'] ?? false) === true,
            is_array($decoded['onlySuites'] ?? null) ? self::strings($decoded['onlySuites']) : null,
            self::strings($decoded['exceptSuites'] ?? null),
            self::strings($decoded['suffixes'] ?? null),
        );
    }

    /** @return list<non-empty-string> */
    private static function strings(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, static fn(mixed $item): bool => is_string($item) && $item !== ''))
            : [];
    }
}
