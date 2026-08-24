<?php

/**
 * Canonical tool contracts shared by the legacy in-process gateway and MCP.
 *
 * The chatbot planner intentionally sees only the original read-oriented tool
 * surface. Cart and order mutation tools are exposed by MCP for explicit calls,
 * but remain outside the deterministic chatbot planner guardrails.
 */
final class ToolDefinitionCatalog
{
    public static function chatbotDefinitions(): array
    {
        return [
            self::definition('search_products', 'Tìm kiếm sản phẩm theo từ khóa, giá, danh mục, màu, size và tồn kho.', [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Cụm từ tên sản phẩm đầy đủ do người dùng cung cấp.'],
                    'category_id' => ['type' => 'integer', 'enum' => [1, 2, 3, 4]],
                    'min_price' => ['type' => 'number'],
                    'max_price' => ['type' => 'number'],
                    'color' => ['type' => 'string'],
                    'size' => ['type' => 'string'],
                    'in_stock' => ['type' => 'boolean'],
                    'occasion' => ['type' => 'string'],
                    'style' => ['type' => ['string', 'array']],
                    'avoid' => ['type' => ['string', 'array']],
                    'semantic_query' => ['type' => 'string'],
                ],
                'required' => ['search'],
            ]),
            self::definition('get_product_detail', 'Lấy mô tả, giá, tồn kho, kích cỡ và đánh giá của một sản phẩm.', [
                'type' => 'object',
                'properties' => ['product_id' => ['type' => 'integer']],
                'required' => ['product_id'],
            ]),
            self::definition('suggest_size', 'Tư vấn size dựa trên chiều cao, cân nặng và danh mục.', [
                'type' => 'object',
                'properties' => [
                    'height' => ['type' => 'integer'],
                    'weight' => ['type' => 'integer'],
                    'category_id' => ['type' => 'integer'],
                ],
                'required' => ['height', 'weight'],
            ]),
            self::definition('retrieve_knowledge', 'Tra cứu chính sách và thông tin shop từ knowledge base/RAG.', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['shipping', 'return', 'payment', 'warranty', 'wholesale', 'general', 'order', 'size', 'shop_info', 'policy'],
                    ],
                    'limit' => ['type' => 'integer'],
                ],
                'required' => ['query'],
            ]),
            self::definition('get_categories', 'Lấy danh sách danh mục sản phẩm.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            self::definition('get_order_status', 'Tra cứu trạng thái đơn hàng của người dùng đã đăng nhập.', [
                'type' => 'object',
                'properties' => ['order_id' => ['type' => 'integer']],
            ]),
        ];
    }

    private static function definition(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }
}
