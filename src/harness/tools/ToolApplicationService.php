<?php


final class ToolApplicationService
{
    private ProductService $products;
    private KnowledgeService $knowledge;
    private SizeService $sizes;
    private CartService $cart;
    private OrderService $orders;
    private PDO $pdo;

    public function __construct(PDO $pdo, private ?int $userId, ServiceConfig $config, private FreeShippingPolicy $freeShipping)
    {
        $this->pdo = $pdo;
        $this->products = new ProductService($pdo, $userId, $config->rerankerUrl);
        $this->knowledge = new KnowledgeService($pdo, $config);
        $this->sizes = new SizeService($pdo);
        $this->cart = new CartService($pdo);
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
            'list_cart' => $this->cart->list($this->requireUser()),
            'add_to_cart' => $this->cart->add($this->requireUser(), $arguments),
            'update_cart' => $this->cart->update($this->requireUser(), $arguments),
            'remove_from_cart' => $this->cart->remove($this->requireUser(), (int) ($arguments['cart_id'] ?? 0)),
            'list_orders' => $this->orders->list($this->requireUser(), isset($arguments['status']) ? (string) $arguments['status'] : null),
            'get_order_detail' => $this->orders->detail($this->requireUser(), (int) ($arguments['order_id'] ?? 0)),
            'create_order' => $this->orders->create($this->requireUser()),
            default => throw new InvalidArgumentException("Unknown tool: $tool"),
        };
    }

    private function requireUser(): int
    {
        if ($this->userId === null) {
            throw new RuntimeException('Authentication required');
        }
        return $this->userId;
    }
}
