<?php


final class ToolApplicationService
{
    private ProductService $products;
    private KnowledgeService $knowledge;
    private SizeService $sizes;
    private OrderService $orders;
    private PDO $pdo;

    public function __construct(PDO $pdo, private ?int $userId, ServiceConfig $config, private FreeShippingPolicy $freeShipping)
    {
        $this->pdo = $pdo;
        $this->products = new ProductService($pdo, $userId, $config->rerankerUrl);
        $this->knowledge = new KnowledgeService($pdo, $config);
        $this->sizes = new SizeService($pdo);
        $this->orders = new OrderService($pdo);
    }

    public function execute(string $tool, array $arguments): array
    {
        return match ($tool) {
            'search_products' => $this->products->search($arguments),
            'get_product_detail' => $this->products->detail($arguments),
            'build_outfit' => (new OutfitService($this->pdo))->build($arguments),
            'suggest_freeship_items' => (new FreeShippingService($this->pdo, $this->freeShipping))->suggest($this->userId, $arguments),
            'get_categories' => $this->products->categories(),
            'suggest_size' => $this->sizes->suggest($arguments),
            'retrieve_knowledge' => $this->knowledge->retrieve($arguments),
            'get_order_status' => $this->orders->status($this->userId, $arguments),
            default => throw new InvalidArgumentException("Unknown tool: $tool"),
        };
    }
}
