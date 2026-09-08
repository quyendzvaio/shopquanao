<?php

/**
 * Single source of truth for chatbot intent -> tool routing.
 *
 * Adding a new tool/use-case means adding ONE entry here. ToolPlanner,
 * PlanValidator and prompts render from this manifest instead of keeping
 * parallel switch statements in sync by hand.
 *
 * Shape per intent:
 *   tools          : ordered tool names the planner may select ([] = terminal)
 *   required_slots : entity fields that must be present before any tool runs
 *   empty_response : response_type when the plan has no tool calls
 *   needs_auth     : user must be authenticated (order/cart scope)
 *   mutation       : tool changes server state (requires confirmation slot)
 */
return [
    'intents' => [
        'product_search' => [
            'tools' => ['search_products'],
            'required_slots' => ['product_type'],
            'empty_response' => 'fallback',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'product_detail' => [
            'tools' => ['get_product_detail'],
            'required_slots' => ['product_id'],
            'empty_response' => 'fallback',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'size_advice' => [
            'tools' => ['suggest_size'],
            'required_slots' => [],
            'empty_response' => 'clarification',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'return_exchange' => [
            'tools' => ['retrieve_knowledge'],
            'required_slots' => [],
            'empty_response' => 'fallback',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'shipping' => [
            'tools' => ['retrieve_knowledge'],
            'required_slots' => [],
            'empty_response' => 'fallback',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'policy' => [
            'tools' => ['retrieve_knowledge'],
            'required_slots' => [],
            'empty_response' => 'fallback',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'mixed_product_policy' => [
            'tools' => ['get_product_detail', 'search_products', 'retrieve_knowledge'],
            'required_slots' => [],
            'empty_response' => 'fallback',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'order_status' => [
            'tools' => ['get_order_status'],
            'required_slots' => [],
            'empty_response' => 'final_answer',
            'needs_auth' => true,
            'mutation' => false,
        ],
        'suggest_complementary_products' => [
            'tools' => ['suggest_complementary_products'],
            'required_slots' => ['product_id'],
            'empty_response' => 'clarification',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'unknown' => [
            'tools' => [],
            'required_slots' => [],
            'empty_response' => 'fallback',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'unsupported_outfit' => [
            'tools' => [],
            'required_slots' => [],
            'empty_response' => 'final_answer',
            'needs_auth' => false,
            'mutation' => false,
        ],
        'unsupported_checkout' => [
            'tools' => [],
            'required_slots' => [],
            'empty_response' => 'final_answer',
            'needs_auth' => false,
            'mutation' => false,
        ],
    ],

    // Entity fields a required_slots entry may reference.
    'entity_fields' => [
        'product_id',
        'product_query',
        'product_type',
        'category_id',
        'color',
        'size',
        'height',
        'weight',
        'min_price',
        'max_price',
        'order_id',
        'confirmation',
    ],
];
