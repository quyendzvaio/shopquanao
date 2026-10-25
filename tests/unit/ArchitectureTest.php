<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Enforces spec §3.1 and the §4.1 component table on src/harness.
 * Class names are mapped to components through the Composer classmap;
 * comments and string literals are stripped before scanning.
 */
final class ArchitectureTest extends TestCase
{
    private const MAX_FUNCTION_LINES = 80;

    /** Spec §3.1: harness may use service, model and core (never repository directly). */
    private const DOMAIN = ['service', 'model', 'core'];

    /** Spec §4.1 "Được dùng" column, per harness component (domain layers are added for all). */
    private const ALLOWED = [
        'llm' => ['middleware'],
        'turn' => ['llm', 'intent', 'planning', 'loop', 'evidence', 'tools', 'response', 'memory', 'middleware'],
        'intent' => ['llm', 'planning'],
        'planning' => [],
        'loop' => ['planning', 'tools', 'evidence'],
        'evidence' => [],
        'tools' => [],
        'response' => ['llm'],
        'memory' => ['repository'],
        'middleware' => ['turn', 'llm', 'intent', 'response', 'evidence', 'repository'],
    ];

    /** Edges the table limits to named classes: [from][to] => classes. */
    private const LIMITED = [
        'middleware' => [
            'turn' => ['TurnContext', 'TurnResult'],
            'intent' => ['CheckoutDetector'],
            'repository' => ['ChatMemoryRepository'],
        ],
        'llm' => [
            'middleware' => ['Middleware', 'MiddlewareStack'],
        ],
    ];

    private const OUTSIDE = ['app', 'agent', 'llm_providers'];

    /**
     * Spec §3.1 layer table: layer => layers it may use. Every harness component is the layer `harness`
     * (its inner rules live in ALLOWED/LIMITED); files directly under src/ are the composition root, layer `app`.
     */
    private const LAYERS = [
        'app' => ['agent', 'service', 'model', 'core'],
        'agent' => ['harness', 'llm_providers', 'service', 'repository', 'model', 'core'],
        'service' => ['repository', 'model', 'core'],
        'repository' => ['model', 'core'],
        'model' => [],
        'core' => [],
        'llm_providers' => ['harness', 'core'],
    ];

    /** Layer edges limited to one harness component: [from][to] => component. */
    private const LAYER_LIMITED = [
        'llm_providers' => ['harness' => 'llm'],
    ];

    /** Layers whose files must never read $_SERVER (getenv/$_ENV are allowed only in agent and core). */
    private const NO_SERVER_LAYERS = ['harness', 'service', 'repository', 'model'];

    private const ENV_LAYERS = ['agent', 'core'];

    /** Permanent: infrastructure that owns its tables' SQL outside src/repository (spec §3.1). */
    private const SQL_EXCEPTIONS = [
        'src/core/database/MigrationRunner.php',
        'src/core/observability/LangfuseOtlpTracePublisher.php',
        'src/core/observability/LangfuseTraceOutbox.php',
    ];

    /**
     * Ratchet: files that still run SQL outside the repository layer. Later tasks only remove entries.
     *
     * @var list<string>
     */
    private const LEGACY_SQL = [
        'public/add_to_cart.php',
        'public/admin/add_product.php',
        'public/admin/edit_product.php',
        'public/admin/index.php',
        'public/admin/login.php',
        'public/admin/manage_orders.php',
        'public/admin/manage_products.php',
        'public/admin/manage_users.php',
        'public/login.php',
        'public/process_checkout.php',
        'public/profile.php',
        'public/register.php',
        'public/submit_review.php',
    ];

    /**
     * Ratchet: files that still read the environment where they must not. Later tasks only remove entries.
     *
     * @var list<string>
     */
    private const LEGACY_ENV = [
        'public/admin/login.php',
        'public/login.php',
    ];

    /**
     * Ratchet: layer-table violations still present, path => referenced classes. Later tasks only remove entries.
     *
     * @var array<string,list<string>>
     */
    private const LEGACY_EDGES = [];

    /** @var array<string,string>|null class => component */
    private static ?array $components = null;

    /** Guards the other tests against passing vacuously (empty classmap, wrong component mapping, broken scan). */
    public function testComponentMapResolvesHarnessAndOutsideClasses(): void
    {
        $components = $this->components();

        $this->assertSame('turn', $components['TurnRunner'] ?? null);
        $this->assertSame('llm', $components['MiddlewareModel'] ?? null);
        $this->assertSame('agent', $components['AgentFactory'] ?? null);
        $this->assertContains($components['AgentFactory'], self::OUTSIDE);
        $this->assertSame('middleware', $this->referencedClasses(ROOT_DIR . '/src/harness/turn/TurnRunner.php')['MiddlewareStack'] ?? null);

        // One known class per layer, so the layer, SQL and env tests cannot pass vacuously.
        $this->assertSame('service', $this->layerOfComponent($components['CartService'] ?? ''));
        $this->assertSame('repository', $this->layerOfComponent($components['OutfitCatalogRepository'] ?? ''));
        $this->assertSame('model', $this->layerOfComponent($components['StyleTag'] ?? ''));
        $this->assertSame('harness', $this->layerOfComponent($components['TurnRunner'] ?? ''));
    }

    public function testLayerDependenciesFollowTable(): void
    {
        $violations = [];
        $scanned = array_fill_keys(array_keys(self::LAYERS), 0);
        foreach ($this->projectFiles(['src']) as $file) {
            $layer = $this->layerOfPath($this->relative($file));
            if ($layer === 'harness') {
                continue; // the harness keeps its component test
            }
            $this->assertArrayHasKey($layer, self::LAYERS, $this->relative($file) . " is in unknown layer {$layer}");
            $scanned[$layer]++;
            $legacy = self::LEGACY_EDGES[$this->relative($file)] ?? [];
            foreach ($this->layerViolations($file) as $class => $to) {
                if (!in_array($class, $legacy, true)) {
                    $violations[] = $this->relative($file) . " ({$layer}) -> {$class} ({$to})";
                }
            }
        }

        foreach ($scanned as $layer => $count) {
            $this->assertGreaterThan(0, $count, "no file scanned in layer {$layer}");
        }
        $this->assertSame([], $violations, "dependency not allowed by spec §3.1:\n" . implode("\n", $violations));
    }

    public function testSqlOnlyInRepositories(): void
    {
        $violations = [];
        foreach ($this->projectFiles(['src', 'public', 'templates']) as $file) {
            $path = $this->relative($file);
            if ($this->runsSqlOutsideRepository($file) && !in_array($path, self::LEGACY_SQL, true)) {
                $violations[] = $path;
            }
        }

        $this->assertSame([], $violations, "->prepare/query/exec outside src/repository:\n" . implode("\n", $violations));
    }

    public function testEnvReadOnlyInAgentAndCore(): void
    {
        $violations = [];
        foreach ($this->projectFiles(['src', 'public', 'templates']) as $file) {
            $path = $this->relative($file);
            $reads = $this->forbiddenEnvReads($file);
            if ($reads !== [] && !in_array($path, self::LEGACY_ENV, true)) {
                $violations[] = $path . ': ' . implode(', ', $reads);
            }
        }

        $this->assertSame([], $violations, "environment read outside agent/core:\n" . implode("\n", $violations));
    }

    /** The ratchet only shrinks: a fixed file must be removed from its LEGACY_* list. */
    public function testLegacyAllowlistsStillApply(): void
    {
        $stale = [];
        foreach (['LEGACY_SQL' => self::LEGACY_SQL, 'LEGACY_ENV' => self::LEGACY_ENV] as $name => $paths) {
            $sorted = $paths;
            sort($sorted);
            $this->assertSame($sorted, $paths, "{$name} must be sorted");
            $this->assertSame(array_values(array_unique($paths)), $paths, "{$name} has duplicates");
        }
        foreach (self::LEGACY_SQL as $path) {
            if (!is_file(ROOT_DIR . '/' . $path) || !$this->runsSqlOutsideRepository(ROOT_DIR . '/' . $path)) {
                $stale[] = "LEGACY_SQL: {$path}";
            }
        }
        foreach (self::LEGACY_ENV as $path) {
            if (!is_file(ROOT_DIR . '/' . $path) || $this->forbiddenEnvReads(ROOT_DIR . '/' . $path) === []) {
                $stale[] = "LEGACY_ENV: {$path}";
            }
        }
        $paths = array_keys(self::LEGACY_EDGES);
        $sortedPaths = $paths;
        sort($sortedPaths);
        $this->assertSame($sortedPaths, $paths, 'LEGACY_EDGES must be sorted by path');
        foreach (self::LEGACY_EDGES as $path => $classes) {
            $sorted = $classes;
            sort($sorted);
            $this->assertSame($sorted, $classes, "LEGACY_EDGES {$path} must be sorted");
            $current = is_file(ROOT_DIR . '/' . $path) ? $this->layerViolations(ROOT_DIR . '/' . $path) : [];
            foreach ($classes as $class) {
                if (!isset($current[$class])) {
                    $stale[] = "LEGACY_EDGES: {$path} -> {$class}";
                }
            }
        }

        $this->assertSame([], $stale, "legacy entries that no longer violate (remove them):\n" . implode("\n", $stale));
    }

    public function testClassReferenceExtractionSeesGlobalNamesOnly(): void
    {
        $sample = 'new \\AgentFactory(); \\TurnRunner::class; Foo\\TurnRunner; $x->TurnRunner; X::TurnRunner; new TurnRunner();';

        // Seen: `\AgentFactory`, `\TurnRunner`, `Foo`, `X`, `TurnRunner`; not seen: after `Foo\`, `->` or `::`.
        $this->assertSame(['AgentFactory', 'TurnRunner', 'Foo', 'X', 'TurnRunner'], self::classNamesIn($sample));
    }

    public function testHarnessNeverReferencesOutside(): void
    {
        $violations = [];
        foreach ($this->harnessFiles() as $file) {
            foreach ($this->referencedClasses($file) as $class => $component) {
                if (in_array($component, self::OUTSIDE, true)) {
                    $violations[] = $this->relative($file) . " -> {$class} ({$component})";
                }
            }
        }

        $this->assertSame([], $violations, "harness must not use app/agent/llm_providers:\n" . implode("\n", $violations));
    }

    public function testHarnessReadsNoEnvironment(): void
    {
        $violations = [];
        foreach ($this->harnessFiles() as $file) {
            $code = $this->strippedCode($file);
            foreach (['/\bgetenv\s*\(/', '/\$_ENV\b/', '/\$_SERVER\b/'] as $pattern) {
                if (preg_match($pattern, $code, $match) === 1) {
                    $violations[] = $this->relative($file) . ': ' . $match[0];
                }
            }
        }

        $this->assertSame([], $violations, "harness must not read the environment:\n" . implode("\n", $violations));
    }

    public function testComponentDependenciesFollowTable(): void
    {
        $violations = [];
        foreach ($this->harnessFiles() as $file) {
            $from = $this->componentOfFile($file);
            foreach ($this->referencedClasses($file) as $class => $to) {
                if ($to === $from || in_array($to, self::DOMAIN, true)) {
                    continue;
                }
                if (!$this->componentEdgeAllowed($from, $class, $to)) {
                    $violations[] = $this->relative($file) . " ({$from}) -> {$class} ({$to})";
                }
            }
        }

        $this->assertSame([], $violations, "dependency not allowed by spec §4.1:\n" . implode("\n", $violations));
    }

    /** Decision function behind the table, fed synthetic edges so D3 cannot be dead config. */
    public function testComponentEdgeDecisionHonoursAllowedAndLimited(): void
    {
        $this->assertTrue($this->componentEdgeAllowed('middleware', 'ChatMemoryRepository', 'repository'));
        $this->assertFalse($this->componentEdgeAllowed('middleware', 'OutfitCatalogRepository', 'repository'));
        $this->assertFalse($this->componentEdgeAllowed('middleware', 'SomeOtherRepository', 'repository'));
        $this->assertTrue($this->componentEdgeAllowed('memory', 'AnyRepository', 'repository'));
        $this->assertFalse($this->componentEdgeAllowed('turn', 'ChatMemoryRepository', 'repository'));
        $this->assertTrue($this->componentEdgeAllowed('middleware', 'TurnContext', 'turn'));
        $this->assertFalse($this->componentEdgeAllowed('middleware', 'TurnRunner', 'turn'));
    }

    public function testNoHarnessFunctionOver80Lines(): void
    {
        $violations = [];
        foreach ($this->harnessFiles() as $file) {
            foreach ($this->functionLengths($file) as $name => $lines) {
                if ($lines > self::MAX_FUNCTION_LINES) {
                    $violations[] = $this->relative($file) . "::{$name} = {$lines} lines";
                }
            }
        }

        $this->assertSame([], $violations, "harness functions over 80 lines:\n" . implode("\n", $violations));
    }

    /**
     * @param list<string> $dirs top-level directories under the project root
     * @return list<string> absolute paths of .php files, sorted
     */
    private function projectFiles(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_DIR . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }
        sort($files);
        $this->assertNotEmpty($files);

        return $files;
    }

    /** Layer of a component name from components(): every harness component is `harness`. */
    private function layerOfComponent(string $component): string
    {
        if (isset(self::ALLOWED[$component])) {
            return 'harness';
        }

        return str_ends_with($component, '.php') ? 'app' : $component;
    }

    /** Layer of a repo-relative path under src/, public/ or templates/ (the last two are their own names). */
    private function layerOfPath(string $path): string
    {
        $parts = explode('/', $path);
        if ($parts[0] !== 'src') {
            return $parts[0];
        }

        return count($parts) === 2 ? 'app' : $parts[1];
    }

    /** @return array<string,string> referenced project class => its layer, for edges the layer table forbids */
    private function layerViolations(string $file): array
    {
        $from = $this->layerOfPath($this->relative($file));
        $violations = [];
        foreach ($this->referencedClasses($file) as $class => $component) {
            $to = $this->layerOfComponent($component);
            if ($to === $from) {
                continue;
            }
            $allowed = in_array($to, self::LAYERS[$from] ?? [], true);
            $only = self::LAYER_LIMITED[$from][$to] ?? null;
            if (!$allowed || ($only !== null && $component !== $only)) {
                $violations[$class] = $to;
            }
        }

        return $violations;
    }

    /** True when the file calls ->prepare( / ->query( / ->exec( and is not in the repository layer or SQL_EXCEPTIONS. */
    private function runsSqlOutsideRepository(string $file): bool
    {
        $path = $this->relative($file);
        if ($this->layerOfPath($path) === 'repository' || in_array($path, self::SQL_EXCEPTIONS, true)) {
            return false;
        }
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $count = count($tokens);
        for ($i = 0; $i + 2 < $count; $i++) {
            $operator = $tokens[$i];
            $name = $tokens[$i + 1];
            if (is_array($operator) && in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && is_array($name) && $name[0] === T_STRING && in_array($name[1], ['prepare', 'query', 'exec'], true)
                && $tokens[$i + 2] === '(') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> forbidden environment reads in the file (empty = clean) */
    private function forbiddenEnvReads(string $file): array
    {
        $layer = $this->layerOfPath($this->relative($file));
        $code = $this->strippedCode($file);
        $patterns = [];
        if (!in_array($layer, self::ENV_LAYERS, true)) {
            $patterns[] = '/\bgetenv\s*\(/';
            $patterns[] = '/\$_ENV\b/';
        }
        if (in_array($layer, self::NO_SERVER_LAYERS, true)) {
            $patterns[] = '/\$_SERVER\b/';
        }
        $reads = [];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code, $match) === 1) {
                $reads[] = $match[0];
            }
        }

        return $reads;
    }

    /** Spec §4.1: `to` must be in ALLOWED[from]; when LIMITED names classes for that edge, only those classes pass. */
    private function componentEdgeAllowed(string $from, string $class, string $to): bool
    {
        if (!in_array($to, self::ALLOWED[$from] ?? [], true)) {
            return false;
        }
        $limit = self::LIMITED[$from][$to] ?? null;

        return $limit === null || in_array($class, $limit, true);
    }

    /** @return list<string> */
    private function harnessFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_DIR . '/src/harness', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);
        $this->assertNotEmpty($files);

        return $files;
    }

    /** @return array<string,string> referenced project class => its component (built-ins and self excluded) */
    private function referencedClasses(string $file): array
    {
        $components = $this->components();
        $self = basename($file, '.php');
        $classes = [];
        foreach (array_unique(self::classNamesIn($this->strippedCode($file))) as $name) {
            if ($name !== $self && isset($components[$name])) {
                $classes[$name] = $components[$name];
            }
        }
        ksort($classes);

        return $classes;
    }

    /**
     * Capitalised names in $code that can be class references, in order.
     * A leading `\` (global name, e.g. `\AgentFactory`) still counts; a segment
     * after `Foo\`, `->` or `::` does not.
     *
     * @return list<string>
     */
    private static function classNamesIn(string $code): array
    {
        preg_match_all('/(?<![\w$]|\w\\\\|->|::)[A-Z]\w*\b/', $code, $matches);

        return $matches[0];
    }

    /** @return array<string,string> */
    private function components(): array
    {
        if (self::$components !== null) {
            return self::$components;
        }
        $map = require ROOT_DIR . '/vendor/composer/autoload_classmap.php';
        $src = realpath(ROOT_DIR . '/src') . '/';
        $components = [];
        foreach ($map as $class => $path) {
            $real = realpath($path);
            if ($real !== false && str_starts_with($real, $src)) {
                $components[$class] = $this->componentOfFile($real);
            }
        }

        return self::$components = $components;
    }

    private function componentOfFile(string $file): string
    {
        $parts = explode('/', substr((string) realpath($file), strlen((string) realpath(ROOT_DIR . '/src')) + 1));

        return $parts[0] === 'harness' ? $parts[1] : $parts[0];
    }

    private function strippedCode(string $file): string
    {
        $stripped = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], $stripped, true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return array<string,int> function name => lines from the `function` keyword to its closing brace */
    private function functionLengths(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $lengths = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $name = $this->functionName($tokens, $i);
            $body = $this->bodyEndLine($tokens, $i);
            if ($name !== null && $body !== null) {
                $lengths[$name] = $body - $tokens[$i][2] + 1;
            }
        }

        return $lengths;
    }

    /** @param array<int,mixed> $tokens */
    private function functionName(array $tokens, int $i): ?string
    {
        $skippable = [T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG];
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            $token = $tokens[$j];
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
            if ($token !== '&' && !(is_array($token) && in_array($token[0], $skippable, true))) {
                return null; // closure
            }
        }

        return null;
    }

    /** @param array<int,mixed> $tokens */
    private function bodyEndLine(array $tokens, int $i): ?int
    {
        $depth = 0;
        $line = $tokens[$i][2];
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            $token = $tokens[$j];
            if (is_array($token)) {
                $line = $token[2] + substr_count($token[1], "\n");
                if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }
                continue;
            }
            if ($token === ';' && $depth === 0) {
                return null; // abstract or interface method
            }
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
                if ($depth === 0) {
                    return $line;
                }
            }
        }

        return null;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen(ROOT_DIR) + 1);
    }
}
