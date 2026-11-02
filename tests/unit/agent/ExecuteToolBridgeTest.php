<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EnvSandbox.php';

/**
 * AgentFactory::executeTool is what POST /api/internal/mcp calls. It must run only
 * the tools in ToolDefinitionCatalog, so the cart and checkout operations that
 * ToolApplicationService still implements are unreachable from the chatbot over MCP. A refusal is an InvalidArgumentException,
 * which Internal/mcp.php answers with 400, exactly like any unknown tool.
 */
final class ExecuteToolBridgeTest extends TestCase
{
    use EnvSandbox;

    protected function setUp(): void
    {
        $this->cleanEnv();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    /** @return array<string,array{0:string,1:array<string,mixed>}> */
    public static function uncataloguedTools(): array
    {
        return [
            'add_to_cart' => ['add_to_cart', ['product_id' => 1, 'quantity' => 1, 'size' => 'M']],
            'update_cart' => ['update_cart', ['cart_id' => 1, 'quantity' => 2]],
            'remove_from_cart' => ['remove_from_cart', ['cart_id' => 1]],
            'list_cart' => ['list_cart', []],
            'create_order' => ['create_order', []],
            'list_orders' => ['list_orders', []],
            'get_order_detail' => ['get_order_detail', ['order_id' => 1]],
            'made_up' => ['drop_database', []],
        ];
    }

    /** @param array<string,mixed> $arguments */
    #[DataProvider('uncataloguedTools')]
    public function testToolOutsideTheCatalogIsRefusedAsUnknownEvenForALoggedInUser(string $tool, array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool: ' . $tool);

        AgentFactory::executeTool(SqliteShop::pdo(), 9, $tool, $arguments);
    }

    public function testRefusedToolNeverReachesTheCartOrOrders(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 1, 1, 'Áo Thun', 200000, 5, 'phongvan');
        $pdo->exec("INSERT INTO product_sizes (product_id, size_name) VALUES (1, 'M')");
        try {
            AgentFactory::executeTool($pdo, 9, 'add_to_cart', ['product_id' => 1, 'quantity' => 1, 'size' => 'M']);
            $this->fail('add_to_cart must be refused');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
    }

    public function testCataloguedToolStillRuns(): void
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec('ALTER TABLE products ADD COLUMN subcategory_id INTEGER');
        $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, canonical_key TEXT)');
        $pdo->exec('CREATE TABLE product_subcategories (id INTEGER PRIMARY KEY, canonical_key TEXT, display_name TEXT)');
        $pdo->exec("INSERT INTO categories (id, name, canonical_key) VALUES (1, 'Áo', 'ao')");
        SqliteShop::product($pdo, 1, 1, 'Áo Thun', 200000, 5, 'phongvan');

        $result = AgentFactory::executeTool($pdo, null, 'get_product_detail', ['product_id' => 1]);

        $this->assertNotSame([], $result);
    }

    public function testCatalogueNamesAreTheOnlyAllowedOnes(): void
    {
        $names = array_map(
            static fn (array $definition): string => (string) $definition['function']['name'],
            ToolDefinitionCatalog::chatbotDefinitions()
        );

        $this->assertNotContains('add_to_cart', $names);
        $this->assertNotContains('create_order', $names);
        $this->assertContains('search_products', $names);
    }

    public function testDraftSupportTicketReturnsADraftAndWritesNothing(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::supportTables($pdo);
        $pdo->exec("INSERT INTO orders (id, user_id, total_price, status) VALUES (12, 1, 100000, 'Chờ xử lý')");

        $result = AgentFactory::executeTool($pdo, 1, 'draft_support_ticket', ['type' => 'doi_tra', 'order_id' => 12, 'summary' => 'Đổi size M']);

        $this->assertSame('draft', $result['status']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn());
    }

    public function testDraftSupportTicketAsGuestRequiresLogin(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::supportTables($pdo);

        $result = AgentFactory::executeTool($pdo, null, 'draft_support_ticket', ['type' => 'khac', 'summary' => 'x']);

        $this->assertSame('requires_login', $result['status']);
    }

    public function testGetSupportTicketsListsTheUsersTickets(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::supportTables($pdo);
        $pdo->exec("INSERT INTO support_tickets (user_id, order_id, type, summary, status, created_at, updated_at)
            VALUES (1, NULL, 'khac', 'hỏi thêm', 'moi', '2026-11-01 10:00:00', '2026-11-01 10:00:00')");

        $result = AgentFactory::executeTool($pdo, 1, 'get_support_tickets', []);

        $this->assertCount(1, $result['tickets']);
        $this->assertTrue(AgentFactory::executeTool($pdo, null, 'get_support_tickets', [])['requires_login']);
    }

    public function testSupportToolsAreCatalogued(): void
    {
        $names = array_map(
            static fn (array $definition): string => (string) $definition['function']['name'],
            ToolDefinitionCatalog::chatbotDefinitions()
        );

        $this->assertContains('draft_support_ticket', $names);
        $this->assertContains('get_support_tickets', $names);
        $this->assertCount(11, $names);
    }
}
