<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A form that arrives without the `image` file part must not raise PHP warnings. */
final class AdminUploadWithoutFilePartTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, subcategory_id INTEGER,
            name TEXT, price REAL, stock INTEGER, description TEXT, image TEXT, style_tags TEXT NOT NULL DEFAULT '')");
        $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE product_subcategories (id INTEGER PRIMARY KEY, category_id INTEGER, display_name TEXT)');
        $pdo->exec("INSERT INTO products (id, name, price, stock, description, image) VALUES (1, 'Ao', 100, 2, 'd', 'old.jpg')");
        return $pdo;
    }

    private function strict(callable $run): mixed
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new ErrorException($msg, 0, $no);
        });
        try {
            return $run();
        } finally {
            restore_error_handler();
        }
    }

    public function testEditWithoutAFilePartKeepsTheOldImage(): void
    {
        $pdo = $this->db();
        $page = new AdminEditProductPage(new ProductAdminService($pdo), new CategoryService($pdo), '/nonexistent/');

        $result = $this->strict(fn () => $page->handle(
            new WebRequest('POST', ['id' => '1'], ['update_product' => '1', 'name' => 'Ao moi', 'price' => '150',
                'stock' => '3', 'description' => 'x'], []),
            ['role' => 'admin']
        ));

        $this->assertSame('manage_products.php?msg=updated', $result->payload());
        $row = $pdo->query('SELECT name, image FROM products WHERE id = 1')->fetch();
        $this->assertSame('Ao moi', $row['name']);
        $this->assertSame('old.jpg', $row['image']);
    }

    public function testAddWithoutAFilePartReportsAnUploadErrorAndInsertsNothing(): void
    {
        $pdo = $this->db();
        $page = new AdminProductsPage(new ProductAdminService($pdo), new CategoryService($pdo), '/nonexistent/');

        $result = $this->strict(fn () => $page->handle(
            new WebRequest('POST', [], ['add_product' => '1', 'name' => 'Moi', 'price' => '1', 'description' => 'x'], []),
            ['role' => 'admin']
        ));

        $this->assertTrue($result->isView());
        $this->assertStringStartsWith('Lỗi khi tải file lên:', $result->data()['msg_error']);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }
}
