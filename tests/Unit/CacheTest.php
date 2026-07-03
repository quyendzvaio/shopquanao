<?php
/**
 */
class CacheTest extends \PHPUnit\Framework\TestCase
{
    private string $testKey;
    private array $testData;

    protected function setUp(): void
    {
        Cache::flush();
        $this->testKey = 'test_' . uniqid();
        $this->testData = ['id' => 1, 'name' => 'Áo Thun Test', 'price' => 200000];
    }

    public function testSetAndGet(): void
    {
        Cache::set($this->testKey, $this->testData, 60);
        $result = Cache::get($this->testKey);
        $this->assertNotNull($result);
        $this->assertEquals($this->testData, $result);
    }

    public function testGetExpiredReturnsNull(): void
    {
        Cache::set($this->testKey, $this->testData, 0); // TTL 0 = expired immediately
        usleep(1000); // ensure time passes
        $result = Cache::get($this->testKey);
        $this->assertNull($result);
    }

    public function testGetNonExistentReturnsNull(): void
    {
        $result = Cache::get('non_existent_key_' . uniqid());
        $this->assertNull($result);
    }

    public function testDelete(): void
    {
        Cache::set($this->testKey, $this->testData, 60);
        Cache::delete($this->testKey);
        $result = Cache::get($this->testKey);
        $this->assertNull($result);
    }

    public function testFlush(): void
    {
        Cache::set('k1', 'data1', 60);
        Cache::set('k2', 'data2', 60);
        Cache::flush();
        $this->assertNull(Cache::get('k1'));
        $this->assertNull(Cache::get('k2'));
    }

    public function testBuildKeyConsistent(): void
    {
        $k1 = Cache::buildKey('sp', ['search' => 'áo', 'max_price' => 500000]);
        $k2 = Cache::buildKey('sp', ['max_price' => 500000, 'search' => 'áo']);
        $this->assertEquals($k1, $k2, 'Keys should be identical regardless of param order');
    }

    public function testSearchResultCache(): void
    {
        $params = ['search' => 'áo thun', 'sort' => 'price_asc'];
        $data = ['products' => [['id' => 1, 'name' => 'Test']]];

        // Set cache
        Cache::setSearchResult($params, $data);
        $result = Cache::getSearchResult($params);
        $this->assertEquals($data, $result);
    }

    public function testFaqCache(): void
    {
        $params = ['category' => 'shipping'];
        $data = ['faqs' => [['question' => 'Test?', 'answer' => 'OK']]];

        Cache::setFaqResult($params, $data);
        $result = Cache::getFaqResult($params);
        $this->assertEquals($data, $result);
    }

    public function testCategoriesCache(): void
    {
        $data = ['categories' => [['id' => 1, 'name' => 'Áo']]];
        Cache::setCategories($data);
        $result = Cache::getCategories();
        $this->assertEquals($data, $result);
    }

    public function testProductDetailCache(): void
    {
        $data = ['product' => ['id' => 50, 'name' => 'Test Product']];
        Cache::setProductDetail(50, $data);
        $result = Cache::getProductDetail(50);
        $this->assertEquals($data, $result);
    }

    public function testSetWithDifferentTtl(): void
    {
        // Short TTL
        Cache::set('short_ttl', 'value', 1);
        // Long TTL
        Cache::set('long_ttl', 'value', 3600);

        $this->assertNotNull(Cache::get('short_ttl'));
        $this->assertNotNull(Cache::get('long_ttl'));
    }

    public function testOverwriteExisting(): void
    {
        Cache::set($this->testKey, 'old_value', 60);
        Cache::set($this->testKey, 'new_value', 60);
        $result = Cache::get($this->testKey);
        $this->assertEquals('new_value', $result);
    }

    public function testSizeGuideCache(): void
    {
        $params = ['height' => 170, 'weight' => 65, 'category_id' => 1];
        $data = ['size' => 'M', 'description' => 'Fit M'];
        Cache::setSizeGuide($params, $data);
        $result = Cache::getSizeGuide($params);
        $this->assertEquals($data, $result);
    }

    public function testOutfitCache(): void
    {
        $params = ['product_id' => 50];
        $data = ['outfits' => [['paired_product_id' => 65]]];
        Cache::setOutfit($params, $data);
        $result = Cache::getOutfit($params);
        $this->assertEquals($data, $result);
    }

    protected function tearDown(): void
    {
        Cache::delete($this->testKey);
    }
}
