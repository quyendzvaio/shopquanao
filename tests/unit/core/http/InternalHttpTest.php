<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InternalHttpTest extends TestCase
{
    public function testInternalCallsResolveIpv4Only(): void
    {
        $this->assertSame(CURL_IPRESOLVE_V4, InternalHttp::CURL_OPTIONS[CURLOPT_IPRESOLVE] ?? null);
    }

    /** Every curl handle in src/ talks to a sibling Compose service, so each one starts from InternalHttp::CURL_OPTIONS. */
    public function testEveryCurlHandleInSrcUsesTheInternalOptions(): void
    {
        $src = dirname(__DIR__, 4) . '/src';
        $checked = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $handles = substr_count($code, 'curl_init(');
            if ($handles === 0) {
                continue;
            }
            $checked += $handles;
            $this->assertGreaterThanOrEqual(
                $handles,
                substr_count($code, 'InternalHttp::CURL_OPTIONS'),
                $file->getPathname() . ': a curl handle without InternalHttp::CURL_OPTIONS (slow AAAA lookups)'
            );
        }
        $this->assertGreaterThan(0, $checked);
    }
}
