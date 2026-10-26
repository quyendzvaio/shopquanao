<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PageResultTest extends TestCase
{
    public function testViewCarriesTemplateData(): void
    {
        $result = PageResult::view(['product' => ['id' => 7]]);

        $this->assertTrue($result->isView());
        $this->assertSame('view', $result->kind());
        $this->assertSame(['product' => ['id' => 7]], $result->data());
        $this->assertSame('', $result->payload());
    }

    public function testViewDefaultsToNoData(): void
    {
        $this->assertSame([], PageResult::view()->data());
    }

    public function testFinalResultsExposeKindAndPayload(): void
    {
        $redirect = PageResult::redirect('cart.php');
        $output = PageResult::output("<script>alert('x');</script>");
        $halt = PageResult::halt('Lỗi');

        $this->assertSame(['redirect', 'cart.php'], [$redirect->kind(), $redirect->payload()]);
        $this->assertSame(['output', "<script>alert('x');</script>"], [$output->kind(), $output->payload()]);
        $this->assertSame(['halt', 'Lỗi'], [$halt->kind(), $halt->payload()]);
        foreach ([$redirect, $output, $halt] as $final) {
            $this->assertFalse($final->isView());
            $this->assertSame([], $final->data());
        }
    }

    public function testSendIfFinalDoesNothingForAView(): void
    {
        $this->expectOutputString('');

        PageResult::view(['a' => 1])->sendIfFinal();
    }

    public function testWebRequestReadsTheGlobals(): void
    {
        $saved = [$_GET, $_POST, $_FILES, $_SERVER['REQUEST_METHOD'] ?? null];
        $_GET = ['id' => '5'];
        $_POST = ['name' => 'x'];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $request = WebRequest::fromGlobals();
        } finally {
            [$_GET, $_POST, $_FILES] = $saved;
            if ($saved[3] === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $saved[3];
            }
        }

        $this->assertSame('POST', $request->method);
        $this->assertSame(['id' => '5'], $request->query);
        $this->assertSame(['name' => 'x'], $request->post);
        $this->assertSame([], $request->files);
    }
}
