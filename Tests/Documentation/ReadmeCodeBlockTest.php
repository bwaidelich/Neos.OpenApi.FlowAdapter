<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Documentation;

use Neos\Utility\Arrays;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Executes every PHP example in the README, and checks every YAML one, so the documentation cannot drift away from
 * the code.
 *
 * The conventions an example follows – those of neos/openapi's README, plus the ones for the Settings:
 *
 * - Every ` ```php ` block is executed. Mark one ` ```php (no test) ` to exclude it.
 * - A block whose first line is `// ...` *continues* the previous one: it is evaluated in the same namespace and
 *   the same variable scope, and the `use` statements of the preceding blocks are re-applied — so one example can
 *   be told in several steps without repeating its setup.
 * - An example is evaluated in a namespace of its own, unless its first block declares one (`namespace Some\Package;`)
 *   – which is how the class names in the Settings refer to the classes of the example.
 * - `$settings` holds the Settings of the package, merged with those of every ` ```yaml ` block above – the way Flow
 *   merges the Settings of all packages. {@see FakeFlow} serves the APIs they configure.
 * - `assert(...)` is rewritten into a PHPUnit assertion before evaluation. Plain `assert()` would be compiled
 *   away under `zend.assertions=-1` (what php.ini-production ships, and with it most CI setups), which would make
 *   these tests silently vacuous.
 * - Every example must assert at least once: a block of code nobody checks is exactly the documentation that
 *   rots. What only the test needs to see goes into a block within an HTML comment (`<!-- … -->`), which the
 *   rendered README hides.
 * - Every ` ```yaml ` block has to parse, and may only use the options an API actually has.
 */
#[CoversNothing]
final class ReadmeCodeBlockTest extends TestCase
{
    /**
     * The options of an API in `Neos.OpenApi.FlowAdapter.apis`, as {@see \Neos\OpenApi\FlowAdapter\CompiledApis} reads them
     */
    private const API_OPTIONS = ['uriPrefix', 'info', 'classes', 'specPath', 'docsPath', 'servers', 'authContextProvider'];
    private const INFO_OPTIONS = ['title', 'version'];
    private const SERVER_OPTIONS = ['url', 'description'];

    /**
     * @return iterable<string, array{string|null, list<array{line: int, code: string, settings: array<mixed>}>}>
     */
    public static function examples(): iterable
    {
        /** @var list<array{heading: string, line: int, code: string, continuation: bool, settings: array<mixed>}> $blocks */
        $blocks = [];
        $settings = self::packageSettings();
        foreach (self::codeBlocks() as $block) {
            if ($block['language'] === 'yaml') {
                $settings = Arrays::arrayMergeRecursiveOverrule($settings, self::parseYaml($block['code'], $block['line']));
                continue;
            }
            if ($block['language'] !== 'php') {
                continue;
            }
            $blocks[] = [
                'heading' => $block['heading'],
                'line' => $block['line'],
                'code' => $block['code'],
                'continuation' => str_starts_with(ltrim($block['code']), '// ...'),
                'settings' => $settings,
            ];
        }
        self::assertNotSame([], $blocks, 'The README contains no executable PHP examples');

        // group each block with the `// ...` continuations that follow it
        /** @var list<array{heading: string, line: int, blocks: list<array{line: int, code: string, settings: array<mixed>}>}> $chains */
        $chains = [];
        /** @var array{heading: string, line: int, blocks: list<array{line: int, code: string, settings: array<mixed>}>}|null $chain */
        $chain = null;
        foreach ($blocks as $block) {
            if (!$block['continuation'] || $chain === null) {
                if ($chain !== null) {
                    $chains[] = $chain;
                }
                $chain = ['heading' => $block['heading'], 'line' => $block['line'], 'blocks' => []];
            }
            $chain['blocks'][] = ['line' => $block['line'], 'code' => $block['code'], 'settings' => $block['settings']];
        }
        if ($chain !== null) {
            $chains[] = $chain;
        }

        foreach ($chains as $chain) {
            yield sprintf('README.md line %d: %s', $chain['line'], $chain['heading']) => [__NAMESPACE__ . '\\Readme\\Line_' . $chain['line'], $chain['blocks']];
        }
    }

    /**
     * @param string $defaultNamespace a namespace of its own per example, so identically named classes don't clash
     * @param list<array{line: int, code: string, settings: array<mixed>}> $blocks the example's blocks, in order
     */
    #[DataProvider('examples')]
    public function testExample(string $defaultNamespace, array $blocks): void
    {
        $namespace = null;
        $imports = [];
        $assertions = 0;
        foreach ($blocks as $block) {
            // a line taken out is left empty, so that a line of the evaluated code is the same line of the block
            $statements = [];
            foreach (explode("\n", $block['code']) as $line) {
                $trimmed = trim($line);
                if ($trimmed === '<?php' || str_starts_with($trimmed, 'declare(strict_types')) {
                    $statements[] = '';
                    continue;
                }
                if (preg_match('/^namespace\s+([^;\s]+);$/', $trimmed, $matches) === 1) {
                    self::assertNull($namespace, sprintf('Only the first block of an example may declare a namespace (in the README block starting on line %d)', $block['line']));
                    $namespace = $matches[1];
                    $statements[] = '';
                    continue;
                }
                if (preg_match('/^use\s+[^;]+;$/', $trimmed) === 1) {
                    $imports[$trimmed] = true;
                    $statements[] = '';
                    continue;
                }
                $statements[] = $line;
            }
            $namespace ??= $defaultNamespace;
            $body = implode("\n", $statements);
            $assertions += preg_match_all('/(?<![\w$>:\\\\])assert\s*\(/', $body);
            $body = preg_replace('/(?<![\w$>:\\\\])assert\s*\(/', '\\PHPUnit\\Framework\\Assert::assertTrue(', $body);
            self::assertIsString($body);

            // the eval'd blocks of one example share this scope, so a later block can use earlier variables
            $settings = $block['settings'];
            try {
                eval(sprintf("namespace %s { %s\n%s\n}", $namespace, implode(' ', array_keys($imports)), $body));
            } catch (ExpectationFailedException $exception) {
                // point at the line that drifted, which is rarely in the block the test is named after
                self::fail(sprintf('%s (on line %s of the README)', $exception->getMessage(), self::readmeLine($exception, $block['line'])));
            } catch (\Throwable $exception) {
                throw new \RuntimeException(sprintf('%s (on line %s of the README)', $exception->getMessage(), self::readmeLine($exception, $block['line'])), 1790000907, $exception);
            }
        }
        self::assertGreaterThan(0, $assertions, 'Every README example must verify itself with at least one assert(...)');
    }

    /**
     * The README line of the block's statement that failed
     *
     * The outermost frame of evaluated code is the block's own – any frame further in may be in a class an earlier
     * block declared, and there is no telling those apart. The first line of the evaluated code is the one of the
     * namespace and imports, so its second one is the first of the block.
     */
    private static function readmeLine(\Throwable $exception, int $blockLine): string
    {
        $line = null;
        foreach ([['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()] as $frame) {
            if (str_ends_with($frame['file'] ?? '', "eval()'d code") && isset($frame['line'])) {
                $line = $blockLine + $frame['line'] - 1;
            }
        }
        return $line !== null ? (string)$line : sprintf('? (in the block starting on line %d)', $blockLine);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function settingsExamples(): iterable
    {
        foreach (self::codeBlocks() as $block) {
            if ($block['language'] === 'yaml') {
                yield sprintf('README.md line %d: %s', $block['line'], $block['heading']) => [$block['code'], $block['line']];
            }
        }
    }

    #[DataProvider('settingsExamples')]
    public function testSettingsExampleUsesOnlyExistingOptions(string $yaml, int $line): void
    {
        $apis = self::parseYaml($yaml, $line)['Neos']['OpenApi']['FlowAdapter']['apis'] ?? [];
        self::assertIsArray($apis);
        foreach ($apis as $apiName => $api) {
            self::assertIsArray($api);
            $where = sprintf('the API "%s" in the README block starting on line %d', $apiName, $line);
            self::assertSame([], array_diff(array_keys($api), self::API_OPTIONS), 'Unknown options of ' . $where);
            self::assertSame([], array_diff(array_keys($api['info'] ?? []), self::INFO_OPTIONS), 'Unknown info options of ' . $where);
            foreach ($api['servers'] ?? [] as $server) {
                self::assertSame([], array_diff(array_keys($server ?? []), self::SERVER_OPTIONS), 'Unknown server options of ' . $where);
            }
            foreach ($api['classes'] ?? [] as $className => $enabled) {
                self::assertIsBool($enabled, sprintf('The class "%s" of %s must be enabled or disabled', $className, $where));
            }
        }
    }

    /**
     * Every fenced code block of the README, with the heading it is found under
     *
     * @return list<array{language: string, heading: string, line: int, code: string}>
     */
    private static function codeBlocks(): array
    {
        $path = realpath(__DIR__ . '/../../README.md');
        self::assertIsString($path, 'README.md not found');
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'README.md could not be read');

        $blocks = [];
        $heading = '';
        $open = null;
        foreach (explode("\n", $contents) as $index => $line) {
            if ($open === null) {
                if (str_starts_with($line, '#')) {
                    $heading = trim($line, "# \t\r");
                } elseif (preg_match('/^```(\w+)(.*)$/', $line, $matches) === 1) {
                    $open = [
                        'language' => str_contains($matches[2], '(no test)') ? 'none' : $matches[1],
                        'heading' => $heading,
                        'line' => $index + 1,
                        'code' => [],
                    ];
                }
                continue;
            }
            if (rtrim($line) === '```') {
                $blocks[] = [...$open, 'code' => implode("\n", $open['code'])];
                $open = null;
                continue;
            }
            $open['code'][] = $line;
        }
        return $blocks;
    }

    /**
     * @return array<mixed>
     */
    private static function packageSettings(): array
    {
        return Yaml::parseFile(__DIR__ . '/../../Configuration/Settings.yaml');
    }

    /**
     * @return array<mixed>
     */
    private static function parseYaml(string $yaml, int $line): array
    {
        $parsed = Yaml::parse($yaml);
        self::assertIsArray($parsed, sprintf('The README block starting on line %d is no YAML map', $line));
        return $parsed;
    }
}
