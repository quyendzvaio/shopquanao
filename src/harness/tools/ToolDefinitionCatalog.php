<?php

/**
 * Canonical tool contracts shared by the MCP bridge and chat-graph.
 *
 * chatbotDefinitions() is the allow-list AgentFactory::executeTool enforces: the
 * chat agent sees only this read-oriented surface. Cart and order mutation tools
 * are not exposed anywhere.
 */
final class ToolDefinitionCatalog
{
    public static function chatbotDefinitions(): array
    {
        return array_merge(self::catalogDefinitions(), self::supportDefinitions());
    }

    /** @return list<array<string,mixed>> product, outfit and free-shipping tools */
    private static function catalogDefinitions(): array
    {
        return [
            self::definition('search_products', 'Tìm kiếm sản phẩm theo từ khóa, giá, danh mục, màu, size và tồn kho.', [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Cụm từ tên sản phẩm đầy đủ do người dùng cung cấp.'],
                    'category_id' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5]],
                    'category' => ['type' => 'string'],
                    'subcategory' => ['type' => 'string'],
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
            self::definition('find_similar_in_stock', 'Khi sản phẩm khách hỏi đã hết hàng, tìm sản phẩm còn hàng cùng danh mục và phong cách tương tự.', [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'minimum' => 1],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                ],
                'required' => ['product_id'],
            ]),
            self::definition('build_outfit', 'Ghép 1-2 set đồ còn hàng từ catalog shop theo tag dịp/phong cách, ngân sách và size.', [
                'type' => 'object',
                'properties' => [
                    'style_tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => StyleTag::ALL], 'maxItems' => 2],
                    'product_id' => ['type' => 'integer'],
                    'budget' => ['type' => 'integer'],
                    'size' => ['type' => 'string'],
                    'height' => ['type' => 'integer'],
                    'weight' => ['type' => 'integer'],
                ],
            ]),
            self::definition('suggest_freeship_items', 'Kiểm tra giỏ hàng còn thiếu bao nhiêu để được miễn phí ship và gợi ý món còn hàng để bù. Không thêm vào giỏ.', [
                'type' => 'object',
                'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5]],
            ]),
        ];
    }

    /** @return list<array<string,mixed>> size, policy, category, order and support-ticket tools */
    private static function supportDefinitions(): array
    {
        return [
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
            self::definition('get_order_status', 'Tra cứu đơn hàng của khách: không có order_id thì trả 5 đơn gần nhất (mã, trạng thái, tổng tiền), có order_id thì trả đơn đó kèm sản phẩm; khách chưa đăng nhập nhận requires_login.', [
                'type' => 'object',
                'properties' => ['order_id' => ['type' => 'integer', 'description' => 'Mã đơn, chỉ khi khách nêu; bỏ trống để xem các đơn gần nhất.']],
            ]),
            self::definition('draft_support_ticket', 'Soạn nháp yêu cầu hỗ trợ đơn hàng (đổi trả, hàng lỗi, huỷ đơn, đổi địa chỉ, khác) để hỏi khách xác nhận; không tạo yêu cầu.', [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => array_keys(SupportTicketService::TYPES)],
                    'order_id' => ['type' => 'integer', 'minimum' => 1],
                    'summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                ],
                'required' => ['type', 'summary'],
            ]),
            self::definition('get_support_tickets', 'Xem các yêu cầu hỗ trợ khách đã gửi và trạng thái xử lý.', [
                'type' => 'object',
                'properties' => ['ticket_id' => ['type' => 'integer', 'minimum' => 1]],
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
