<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminAddProductPageTest extends TestCase
{
    private string $imagesDir;

    protected function setUp(): void
    {
        $this->imagesDir = sys_get_temp_dir() . '/add-product-test-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->imagesDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->imagesDir . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->imagesDir);
    }

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, name TEXT, price REAL,
            stock INTEGER, description TEXT, image TEXT, style_tags TEXT NOT NULL DEFAULT '')");
        return $pdo;
    }

    /** Posts with the session's CSRF token by default; pass $token = null for none, or another value for a wrong one. */
    private function visit(PDO $pdo, array $post, array $files, array $session, ?string $token = 'tok'): PageResult
    {
        $session['csrf_token'] = 'tok';
        if ($token !== null) {
            $post['csrf_token'] = $token;
        }
        $page = new AdminAddProductPage(new ProductAdminService($pdo), $this->imagesDir);

        return $page->handle(new WebRequest('POST', [], $post, $files), $session);
    }

    private function productCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    public function testGuestIsRedirectedBeforeAnyUploadOrInsert(): void
    {
        $pdo = $this->db();
        $upload = $this->imagesDir . 'incoming.jpg';
        file_put_contents($upload, 'x');

        $result = $this->visit(
            $pdo,
            ['add_product' => '1', 'name' => 'Ao', 'price' => '100'],
            ['image' => ['name' => 'a.jpg', 'tmp_name' => $upload, 'error' => UPLOAD_ERR_OK]],
            []
        );

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('login.php', $result->payload());
        $this->assertSame(0, $this->productCount($pdo));
        $this->assertSame([$upload], glob($this->imagesDir . '*'), 'nothing was moved into the images directory');
    }

    public function testCustomerRoleIsRedirectedToo(): void
    {
        $pdo = $this->db();

        $result = $this->visit($pdo, ['add_product' => '1', 'name' => 'Ao', 'price' => '1'], [], ['role' => 'user']);

        $this->assertSame('login.php', $result->payload());
        $this->assertSame(0, $this->productCount($pdo));
    }

    public function testAdminWithoutAnUploadSeesTheUploadErrorCode(): void
    {
        $pdo = $this->db();

        $result = $this->visit($pdo, ['add_product' => '1', 'name' => 'Ao', 'price' => '1'], [], ['role' => 'admin']);

        $this->assertTrue($result->isView());
        $this->assertSame('Lỗi khi tải file lên: ' . UPLOAD_ERR_NO_FILE, $result->data()['msg_error']);
        $this->assertSame(0, $this->productCount($pdo));
    }

    public function testExtensionOutsideTheWhitelistIsRejectedWithoutMovingTheFile(): void
    {
        $pdo = $this->db();
        $upload = $this->imagesDir . 'incoming.php';
        file_put_contents($upload, 'x');

        $result = $this->visit(
            $pdo,
            ['add_product' => '1', 'name' => 'Ao', 'price' => '1'],
            ['image' => ['name' => 'shell.php', 'tmp_name' => $upload, 'error' => UPLOAD_ERR_OK]],
            ['role' => 'admin']
        );

        $this->assertSame(
            'Định dạng file này không được hỗ trợ (Chỉ nhận jpg, png, webp, gif).',
            $result->data()['msg_error']
        );
        $this->assertSame(0, $this->productCount($pdo));
        $this->assertSame([$upload], glob($this->imagesDir . '*'));
    }

    public function testExtensionCheckIsStrictSoATruthyLookalikeIsRejected(): void
    {
        $pdo = $this->db();

        $result = $this->visit(
            $pdo,
            ['add_product' => '1', 'name' => 'Ao', 'price' => '1'],
            ['image' => ['name' => 'noext', 'tmp_name' => '/nope', 'error' => UPLOAD_ERR_OK]],
            ['role' => 'admin']
        );

        $this->assertStringStartsWith('Định dạng file này không được hỗ trợ', $result->data()['msg_error']);
    }

    public function testAnAdminWhoOnlyOpensTheFormGetsNoError(): void
    {
        $page = new AdminAddProductPage(new ProductAdminService($this->db()), $this->imagesDir);

        $result = $page->handle(new WebRequest('GET', [], [], []), ['role' => 'admin']);

        $this->assertTrue($result->isView());
        $this->assertSame('', $result->data()['msg_error']);
    }

    public function testAMoveThatFailsReportsTheMoveErrorAndInsertsNothing(): void
    {
        $pdo = $this->db();

        // A tmp_name that is not an uploaded file makes move_uploaded_file() return false.
        $result = $this->visit(
            $pdo,
            ['add_product' => '1', 'name' => 'Ao', 'price' => '1'],
            ['image' => ['name' => 'a.PNG', 'tmp_name' => '/nope', 'error' => UPLOAD_ERR_OK]],
            ['role' => 'admin']
        );

        $this->assertSame('Không thể di chuyển file vào thư mục images.', $result->data()['msg_error']);
        $this->assertSame(0, $this->productCount($pdo));
    }

    public function testMissingOrWrongTokenSkipsTheUploadAndInsertsNothing(): void
    {
        $pdo = $this->db();
        $upload = $this->imagesDir . 'incoming.jpg';
        file_put_contents($upload, 'x');
        $files = ['image' => ['name' => 'a.jpg', 'tmp_name' => $upload, 'error' => UPLOAD_ERR_OK]];

        foreach ([null, 'forged', ''] as $token) {
            $result = $this->visit($pdo, ['add_product' => '1', 'name' => 'Ao', 'price' => '1'], $files, ['role' => 'admin'], $token);

            $this->assertTrue($result->isView());
            $this->assertSame('', $result->data()['msg_error'], 'the upload was not even looked at');
        }
        $this->assertSame(0, $this->productCount($pdo));
        $this->assertSame([$upload], glob($this->imagesDir . '*'));
    }

    public function testAValidTokenLetsTheUploadRun(): void
    {
        $result = $this->visit($this->db(), ['add_product' => '1', 'name' => 'Ao', 'price' => '1'], [], ['role' => 'admin']);

        $this->assertSame('Lỗi khi tải file lên: ' . UPLOAD_ERR_NO_FILE, $result->data()['msg_error']);
    }

    public function testAGetNeverRunsTheUploadEvenWithAValidToken(): void
    {
        $pdo = $this->db();
        $page = new AdminAddProductPage(new ProductAdminService($pdo), $this->imagesDir);
        $post = ['add_product' => '1', 'name' => 'Ao', 'price' => '1', 'csrf_token' => 'tok'];

        $result = $page->handle(new WebRequest('GET', [], $post, []), ['role' => 'admin', 'csrf_token' => 'tok']);

        $this->assertSame('', $result->data()['msg_error']);
        $this->assertSame(0, $this->productCount($pdo));
    }

    public function testASessionWithoutATokenNeverRunsTheUpload(): void
    {
        $page = new AdminAddProductPage(new ProductAdminService($this->db()), $this->imagesDir);
        $post = ['add_product' => '1', 'name' => 'Ao', 'price' => '1', 'csrf_token' => ''];

        $result = $page->handle(new WebRequest('POST', [], $post, []), ['role' => 'admin']);

        $this->assertSame('', $result->data()['msg_error']);
    }
}
