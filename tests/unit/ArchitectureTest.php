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
        'memory' => [],
        'middleware' => ['turn', 'llm', 'intent', 'response', 'evidence'],
    ];

    /** Edges the table limits to named classes: [from][to] => classes. */
    private const LIMITED = [
        'middleware' => [
            'turn' => ['TurnContext', 'TurnResult'],
            'intent' => ['CheckoutDetector'],
        ],
        'llm' => [
            'middleware' => ['Middleware', 'MiddlewareStack'],
        ],
    ];

    private const OUTSIDE = ['app', 'agent', 'llm_providers'];

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
                $allowed = in_array($to, self::ALLOWED[$from] ?? [], true);
                $limit = self::LIMITED[$from][$to] ?? null;
                if (!$allowed || ($limit !== null && !in_array($class, $limit, true))) {
                    $violations[] = $this->relative($file) . " ({$from}) -> {$class} ({$to})";
                }
            }
        }

        $this->assertSame([], $violations, "dependency not allowed by spec §4.1:\n" . implode("\n", $violations));
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
