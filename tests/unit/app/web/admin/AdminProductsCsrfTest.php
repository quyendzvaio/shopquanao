<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Product add (list page), edit and delete only act on a POST that carries the session's CSRF token. */
final class AdminProductsCsrfTest extends TestCase
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

    private function list(PDO $pdo): AdminProductsPage
    {
        return new AdminProductsPage(new ProductAdminService($pdo), new CategoryService($pdo), '/nonexistent/');
    }

    private function edit(PDO $pdo): AdminEditProductPage
    {
        return new AdminEditProductPage(new ProductAdminService($pdo), new CategoryService($pdo), '/nonexistent/');
    }

    private function productCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    private function nameOf(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT name FROM products WHERE id = 1')->fetchColumn();
    }

    private const SESSION = ['role' => 'admin', 'csrf_token' => 'tok'];

    private const EDIT = ['update_product' => '1', 'name' => 'Ao moi', 'price' => '150', 'stock' => '3', 'description' => 'x'];

    // ---- delete

    public function testDeleteWithAValidTokenRemovesTheProduct(): void
    {
        $pdo = $this->db();

        $result = $this->list($pdo)->handle(new WebRequest('POST', [], ['delete_id' => '1', 'csrf_token' => 'tok'], []), self::SESSION);

        $this->assertSame('manage_products.php?msg=deleted', $result->payload());
        $this->assertSame(0, $this->productCount($pdo));
    }

    public function testDeleteWithAMissingOrWrongTokenDeletesNothingAndShowsTheList(): void
    {
        $pdo = $this->db();

        foreach ([[], ['csrf_token' => 'forged'], ['csrf_token' => '']] as $token) {
            $result = $this->list($pdo)->handle(new WebRequest('POST', [], ['delete_id' => '1'] + $token, []), self::SESSION);

            $this->assertTrue($result->isView());
        }
        $this->assertSame(1, $this->productCount($pdo));
    }

    public function testTheOldGetDeleteUrlDeletesNothingEvenWithAValidTokenInTheQuery(): void
    {
        $pdo = $this->db();

        $viaQuery = $this->list($pdo)->handle(new WebRequest('GET', ['delete_id' => '1', 'csrf_token' => 'tok'], [], []), self::SESSION);
        $viaPostFields = $this->list($pdo)->handle(new WebRequest('GET', [], ['delete_id' => '1', 'csrf_token' => 'tok'], []), self::SESSION);

        $this->assertTrue($viaQuery->isView());
        $this->assertTrue($viaPostFields->isView());
        $this->assertSame(1, $this->productCount($pdo));
    }

    public function testDeleteFromASessionWithoutATokenDeletesNothing(): void
    {
        $pdo = $this->db();

        $this->list($pdo)->handle(new WebRequest('POST', [], ['delete_id' => '1', 'csrf_token' => ''], []), ['role' => 'admin']);

        $this->assertSame(1, $this->productCount($pdo));
    }

    // ---- add (list page): the upload only runs behind a valid token; a real move needs an uploaded file, so the
    // valid case is observed through the upload error it reaches.

    public function testAddWithAValidTokenReachesTheUploadStep(): void
    {
        $post = ['add_product' => '1', 'name' => 'Moi', 'price' => '1', 'description' => 'x', 'csrf_token' => 'tok'];

        $result = $this->list($this->db())->handle(new WebRequest('POST', [], $post, []), self::SESSION);

        $this->assertStringStartsWith('Lỗi khi tải file lên:', $result->data()['msg_error']);
    }

    public function testAddWithAMissingOrWrongTokenNeverTouchesTheUploadOrTheTable(): void
    {
        $pdo = $this->db();
        $upload = sys_get_temp_dir() . '/csrf-add-' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($upload, 'x');
        $files = ['image' => ['name' => 'a.jpg', 'tmp_name' => $upload, 'error' => UPLOAD_ERR_OK]];

        try {
            foreach ([[], ['csrf_token' => 'forged'], ['csrf_token' => '']] as $token) {
                $post = ['add_product' => '1', 'name' => 'Moi', 'price' => '1', 'description' => 'x'] + $token;
                $result = $this->list($pdo)->handle(new WebRequest('POST', [], $post, $files), self::SESSION);

                $this->assertTrue($result->isView());
                $this->assertArrayNotHasKey('msg_error', $result->data());
            }
            $this->assertSame(1, $this->productCount($pdo));
            $this->assertFileExists($upload);
        } finally {
            @unlink($upload);
        }
    }

    public function testAddOnAGetNeverRunsTheUploadEvenWithAValidToken(): void
    {
        $post = ['add_product' => '1', 'name' => 'Moi', 'price' => '1', 'description' => 'x', 'csrf_token' => 'tok'];

        $result = $this->list($this->db())->handle(new WebRequest('GET', [], $post, []), self::SESSION);

        $this->assertArrayNotHasKey('msg_error', $result->data());
    }

    // ---- edit

    public function testEditWithAValidTokenWritesTheProduct(): void
    {
        $pdo = $this->db();

        $result = $this->edit($pdo)->handle(new WebRequest('POST', ['id' => '1'], self::EDIT + ['csrf_token' => 'tok'], []), self::SESSION);

        $this->assertSame('manage_products.php?msg=updated', $result->payload());
        $this->assertSame('Ao moi', $this->nameOf($pdo));
    }

    public function testEditWithAMissingOrWrongTokenWritesNothingAndShowsTheForm(): void
    {
        $pdo = $this->db();

        foreach ([[], ['csrf_token' => 'forged'], ['csrf_token' => '']] as $token) {
            $result = $this->edit($pdo)->handle(new WebRequest('POST', ['id' => '1'], self::EDIT + $token, []), self::SESSION);

            $this->assertTrue($result->isView());
        }
        $this->assertSame('Ao', $this->nameOf($pdo));
    }

    public function testEditOnAGetWritesNothingEvenWithAValidToken(): void
    {
        $pdo = $this->db();
        $query = ['id' => '1'] + self::EDIT + ['csrf_token' => 'tok'];

        $viaQuery = $this->edit($pdo)->handle(new WebRequest('GET', $query, [], []), self::SESSION);
        $viaPostFields = $this->edit($pdo)->handle(new WebRequest('GET', ['id' => '1'], self::EDIT + ['csrf_token' => 'tok'], []), self::SESSION);

        $this->assertTrue($viaQuery->isView());
        $this->assertTrue($viaPostFields->isView());
        $this->assertSame('Ao', $this->nameOf($pdo));
    }

    public function testEditFromASessionWithoutATokenWritesNothing(): void
    {
        $pdo = $this->db();

        $this->edit($pdo)->handle(new WebRequest('POST', ['id' => '1'], self::EDIT + ['csrf_token' => ''], []), ['role' => 'admin']);

        $this->assertSame('Ao', $this->nameOf($pdo));
    }
}
