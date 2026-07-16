<?php

class CapabilityRegistry {
    public static function fromToolDefinitions(array $toolDefinitions): array {
        $capabilities = [];
        foreach ($toolDefinitions as $definition) {
            $function = is_array($definition['function'] ?? null) ? $definition['function'] : [];
            $name = (string)($function['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $parameters = is_array($function['parameters'] ?? null) ? $function['parameters'] : ['type' => 'object', 'properties' => []];
            $properties = is_array($parameters['properties'] ?? null) ? $parameters['properties'] : [];
            $required = is_array($parameters['required'] ?? null) ? $parameters['required'] : [];

            $capabilities[$name] = array_merge([
                'name' => $name,
                'description' => (string)($function['description'] ?? ''),
                'use_when' => [],
                'do_not_use_when' => [],
                'required_arguments' => array_values($required),
                'optional_arguments' => array_values(array_diff(array_keys($properties), $required)),
                'input_schema' => $parameters,
                'output_schema' => ['type' => 'object', 'properties' => []],
            ], self::knownMetadata($name, $parameters));
        }

        return $capabilities;
    }

    public static function relevantForPartial(array $partial, array $capabilities): array {
        $intent = (string)($partial['resolved_fields']['intent']['value'] ?? '');
        $names = match ($intent) {
            'product_search' => ['search_products'],
            'product_detail' => ['get_product_detail'],
            'size_advice' => ['suggest_size'],
            'order_status' => ['get_order_status'],
            'return_exchange', 'shipping', 'policy' => ['retrieve_knowledge'],
            'mixed_product_policy' => ['get_product_detail', 'search_products', 'retrieve_knowledge'],
            default => array_keys($capabilities),
        };

        return array_values(array_filter(
            $capabilities,
            fn($capability) => in_array((string)$capability['name'], $names, true)
        ));
    }

    private static function knownMetadata(string $name, array $inputSchema): array {
        return match ($name) {
            'search_products' => self::productSearch($inputSchema),
            'get_product_detail' => [
                'use_when' => [
                    'Người dùng hỏi chi tiết một product_id cụ thể.',
                    'Query có mã sản phẩm rõ ràng hoặc slot memory xác nhận sản phẩm đang được nhắc tới.',
                ],
                'do_not_use_when' => [
                    'Người dùng muốn tìm nhiều sản phẩm.',
                    'Người dùng hỏi chính sách hoặc trạng thái đơn hàng.',
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product' => ['type' => 'object'],
                        'cache' => ['type' => 'object'],
                    ],
                ],
            ],
            'suggest_size' => [
                'use_when' => [
                    'Người dùng hỏi mặc size gì hoặc chọn kích cỡ.',
                    'Có đủ chiều cao và cân nặng, hoặc cần hỏi thêm nếu thiếu.',
                ],
                'do_not_use_when' => [
                    'Người dùng hỏi tồn kho size của một sản phẩm cụ thể.',
                    'Người dùng chỉ hỏi chính sách đổi trả size.',
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'recommended' => ['type' => 'object'],
                        'sizes' => ['type' => 'array'],
                    ],
                ],
            ],
            'retrieve_knowledge' => [
                'use_when' => [
                    'Người dùng hỏi đổi trả, hoàn tiền, phí ship, giao hàng, bảo hành, thanh toán, bán sỉ hoặc thông tin shop.',
                    'Câu hỏi mixed intent vừa có sản phẩm vừa có chính sách.',
                ],
                'do_not_use_when' => [
                    'Người dùng chỉ muốn tìm sản phẩm theo giá/danh mục.',
                    'Người dùng hỏi chi tiết product_id nhưng không hỏi chính sách.',
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'results' => ['type' => 'array'],
                        'retrieval_mode' => ['type' => 'string'],
                        'latency' => ['type' => 'object'],
                    ],
                ],
            ],
            'get_order_status' => [
                'use_when' => [
                    'Người dùng hỏi trạng thái đơn hàng cá nhân.',
                    'Người dùng cung cấp order_id hoặc hỏi các đơn gần nhất.',
                ],
                'do_not_use_when' => [
                    'Người dùng hỏi chính sách giao hàng chung.',
                    'Người dùng chưa có ngữ cảnh đơn hàng và chỉ hỏi sản phẩm.',
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'orders' => ['type' => 'array'],
                        'requires_login' => ['type' => 'boolean'],
                    ],
                ],
            ],
            'get_categories' => [
                'use_when' => ['Cần danh sách danh mục sản phẩm để hỗ trợ lọc nội bộ.'],
                'do_not_use_when' => ['Không dùng như câu trả lời chính cho khách nếu đã biết danh mục từ query.'],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => ['categories' => ['type' => 'array']],
                ],
            ],
            default => [],
        };
    }

    private static function productSearch(array $inputSchema): array {
        $schema = $inputSchema;
        $schema['properties'] = array_merge($schema['properties'] ?? [], [
            'color' => ['type' => 'string'],
            'size' => ['type' => 'string'],
            'in_stock' => ['type' => 'boolean'],
            'occasion' => ['type' => 'string'],
            'style' => ['type' => ['string', 'array']],
            'avoid' => ['type' => ['string', 'array']],
            'semantic_query' => ['type' => 'string'],
        ]);

        return [
            'use_when' => [
                'Người dùng muốn tìm hoặc được đề xuất nhiều sản phẩm.',
                'Query có category, màu, giá, size, occasion, style hoặc semantic constraint.',
            ],
            'do_not_use_when' => [
                'Người dùng hỏi chi tiết một product_id cụ thể.',
                'Người dùng hỏi trạng thái đơn hàng hoặc chính sách mà không cần sản phẩm.',
            ],
            'optional_arguments' => array_values(array_unique(array_merge(
                array_diff(array_keys($schema['properties']), $schema['required'] ?? []),
                ['color', 'size', 'in_stock', 'occasion', 'style', 'avoid', 'semantic_query']
            ))),
            'input_schema' => $schema,
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'products' => ['type' => 'array'],
                    'pagination' => ['type' => 'object'],
                    'cache' => ['type' => 'object'],
                ],
            ],
        ];
    }
}
