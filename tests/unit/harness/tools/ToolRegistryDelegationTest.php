<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** ToolRegistry must be a thin gateway: same contracts, no own data access. */
final class ToolRegistryDelegationTest extends TestCase
{
    public function testRegistryHasNoPrivateToolImplementations(): void
    {
        $methods = array_map(
            fn(ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(ToolRegistry::class))->getMethods(ReflectionMethod::IS_PRIVATE)
        );
        $this->assertSame([], array_values(array_filter($methods, fn($n) => str_starts_with($n, 'execute'))));
    }

    public function testSizeSuggestionRunsWithoutRegistry(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE size_guides (id INTEGER PRIMARY KEY, product_id INTEGER, category_id INTEGER,
            size_name TEXT, height_from INTEGER, height_to INTEGER, weight_from INTEGER, weight_to INTEGER, description TEXT)');
        $pdo->exec("INSERT INTO size_guides VALUES (1,NULL,1,'M',160,170,55,65,'M'),(2,NULL,1,'L',165,175,60,75,'L')");

        $result = (new SizeService($pdo))->suggest(['height' => 168, 'weight' => 60, 'category_id' => 1]);

        $this->assertSame('M', $result['recommended']['size_name']);
    }

    public function testMutationToolsAreNotExecutableInProcess(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool: add_to_cart');

        (new ToolRegistry($pdo, 1))->execute('add_to_cart', ['product_id' => 1, 'quantity' => 1]);
    }
}
