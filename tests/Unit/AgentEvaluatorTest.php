<?php

class AgentEvaluatorTest extends \PHPUnit\Framework\TestCase
{
    private AgentEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AgentEvaluator(null);
    }

    public function testProductSearchPassesForMatchingCategoryAndPrice(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'product_search',
            'user_query' => 'tìm áo khoác dưới 600k',
            'tool_name' => 'search_products',
            'tool_arguments' => ['search' => 'áo khoác', 'category_id' => 1, 'max_price' => 600000],
            'tool_result' => [
                'products' => [
                    ['id' => 52, 'category_id' => 1, 'name' => 'Áo Khoác Bomber Kaki Đen', 'price' => 550000, 'stock' => 12],
                ],
            ],
            'draft_answer' => 'Mình tìm thấy 1 sản phẩm phù hợp. Bạn bấm vào thẻ sản phẩm bên dưới để xem chi tiết.',
            'runtime_context' => ['authenticated' => false],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertTrue($result->passed);
        $this->assertSame('return', $result->nextAction);
        $this->assertGreaterThanOrEqual(7.5, $result->weightedScore);
    }

    public function testProductSearchFailsWhenCategoryIsWrong(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'product_search',
            'user_query' => 'tìm áo dưới 600k',
            'tool_name' => 'search_products',
            'tool_arguments' => ['search' => 'áo', 'category_id' => 1, 'max_price' => 600000],
            'tool_result' => [
                'products' => [
                    ['id' => 65, 'category_id' => 2, 'name' => 'Quần Jeans Slimfit Xanh', 'price' => 590000, 'stock' => 5],
                ],
            ],
            'draft_answer' => 'Mình tìm thấy 1 sản phẩm phù hợp. Bạn bấm vào thẻ sản phẩm bên dưới để xem chi tiết.',
            'runtime_context' => ['authenticated' => false],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertFalse($result->passed);
        $this->assertContains('category_mismatch', $result->hardConstraintFailures);
        $this->assertSame('fallback', $result->nextAction);
    }

    public function testProductSearchFailsWhenPriceExceedsMax(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'product_search',
            'user_query' => 'tìm áo khoác dưới 500k',
            'tool_name' => 'search_products',
            'tool_arguments' => ['search' => 'áo khoác', 'category_id' => 1, 'max_price' => 500000],
            'tool_result' => [
                'products' => [
                    ['id' => 52, 'category_id' => 1, 'name' => 'Áo Khoác Bomber Kaki Đen', 'price' => 550000, 'stock' => 12],
                ],
            ],
            'draft_answer' => 'Mình tìm thấy 1 sản phẩm phù hợp. Bạn bấm vào thẻ sản phẩm bên dưới để xem chi tiết.',
            'runtime_context' => ['authenticated' => false],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertFalse($result->passed);
        $this->assertContains('price_above_max', $result->hardConstraintFailures);
        $this->assertSame('fallback', $result->nextAction);
    }

    public function testProductSearchRequestsRevisionWhenDraftIsTooThin(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'product_search',
            'user_query' => 'tìm áo thun',
            'tool_name' => 'search_products',
            'tool_arguments' => ['search' => 'áo thun', 'category_id' => 1],
            'tool_result' => [
                'products' => [
                    ['id' => 50, 'category_id' => 1, 'name' => 'Áo Thun Cotton Basic Trắng', 'price' => 180000, 'stock' => 10],
                ],
            ],
            'draft_answer' => 'Có ạ.',
            'runtime_context' => ['authenticated' => false],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertFalse($result->passed);
        $this->assertSame('revise_answer', $result->nextAction);
        $this->assertSame('incomplete_answer', $result->failureType);
    }

    public function testProductDetailFailsWrongProductIdAndMissingFields(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'product_detail',
            'user_query' => 'chi tiết sản phẩm 52',
            'tool_name' => 'get_product_detail',
            'tool_arguments' => ['product_id' => 52],
            'tool_result' => [
                'product' => [
                    'id' => 51,
                    'name' => 'Áo Sơ Mi Linen Xanh',
                    'price' => 320000,
                    'stock' => 5,
                    'sizes' => [['size_name' => 'M']],
                ],
            ],
            'draft_answer' => 'Áo Sơ Mi Linen Xanh giá 320000đ, còn hàng.',
            'runtime_context' => ['authenticated' => false],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertFalse($result->passed);
        $this->assertContains('wrong_product_id', $result->hardConstraintFailures);
        $this->assertContains('missing_image', $result->hardConstraintFailures);
    }

    public function testProductDetailFailsWhenAnswerMentionsWrongPrice(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'product_detail',
            'user_query' => 'chi tiết sản phẩm 52',
            'tool_name' => 'get_product_detail',
            'tool_arguments' => ['product_id' => 52],
            'tool_result' => [
                'product' => [
                    'id' => 52,
                    'name' => 'Áo Khoác Bomber Kaki Đen',
                    'price' => 550000,
                    'stock' => 12,
                    'image' => 'ak_01.jpg',
                    'sizes' => [['size_name' => 'M']],
                ],
            ],
            'draft_answer' => 'Áo Khoác Bomber Kaki Đen có giá 450000đ và còn size M.',
            'runtime_context' => ['authenticated' => false],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertFalse($result->passed);
        $this->assertContains('price_mismatch_in_answer', $result->hardConstraintFailures);
    }

    public function testSizeAdviceAsksUserWhenHeightMissing(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'size_advice',
            'user_query' => 'tôi nặng 65kg mặc size gì',
            'tool_name' => 'suggest_size',
            'tool_arguments' => ['weight' => 65],
            'tool_result' => [],
            'draft_answer' => 'Bạn mặc size M.',
            'runtime_context' => ['authenticated' => false],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertFalse($result->passed);
        $this->assertSame('ask_user', $result->nextAction);
        $this->assertSame('missing_user_input', $result->failureType);
    }

    public function testSizeAdviceFailsOverconfidentAnswer(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'size_advice',
            'user_query' => 'cao 170 nặng 65 mặc size gì',
            'tool_name' => 'suggest_size',
            'tool_arguments' => ['height' => 170, 'weight' => 65, 'category_id' => 1],
            'tool_result' => [
                'recommended' => ['size_name' => 'M'],
                'sizes' => [
                    ['size_name' => 'M', 'height_from' => 160, 'height_to' => 170, 'weight_from' => 55, 'weight_to' => 65],
                ],
            ],
            'draft_answer' => 'Bạn chắc chắn 100% mặc size M, đảm bảo vừa.',
            'runtime_context' => ['authenticated' => false],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertFalse($result->passed);
        $this->assertContains('overconfident_size_claim', $result->hardConstraintFailures);
        $this->assertSame('revise_answer', $result->nextAction);
    }

    public function testOrderStatusPassesForAuthenticatedOwnedOrder(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'order_status',
            'user_query' => 'đơn của tôi đến đâu rồi',
            'tool_name' => 'get_order_status',
            'tool_arguments' => [],
            'tool_result' => ['orders' => [['id' => 1, 'status' => 'Đang giao', 'created_at' => '2026-07-01']]],
            'draft_answer' => 'Đơn hàng #1 của bạn hiện đang ở trạng thái Đang giao.',
            'runtime_context' => ['authenticated' => true, 'user_id' => 123],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertTrue($result->passed);
        $this->assertSame('return', $result->nextAction);
    }

    public function testOrderStatusDeniesWhenUserIsUnauthenticated(): void
    {
        $result = $this->evaluator->evaluate([
            'task_type' => 'order_status',
            'user_query' => 'đơn của tôi đến đâu rồi',
            'tool_name' => 'get_order_status',
            'tool_arguments' => [],
            'tool_result' => ['requires_login' => true],
            'draft_answer' => 'Đơn của bạn đang giao.',
            'runtime_context' => ['authenticated' => false],
            'retry_state' => $this->retryState(),
        ]);

        $this->assertFalse($result->passed);
        $this->assertSame('deny', $result->nextAction);
        $this->assertSame('authentication_failure', $result->failureType);
    }

    public function testRetryBudgetAndNoProgressDetector(): void
    {
        $budget = new RetryBudgetManager();
        $this->assertTrue($budget->can('retry_tool', ['total_steps' => 1, 'tool_retries' => 1]));
        $this->assertFalse($budget->can('retry_tool', ['total_steps' => 2, 'tool_retries' => 2]));
        $this->assertFalse($budget->can('revise_answer', ['total_steps' => 1, 'answer_revisions' => 1]));
        $this->assertFalse($budget->can('rewrite_query', ['total_steps' => 1, 'query_rewrites' => 1]));

        $fingerprint = $budget->fingerprint([
            'tool_name' => 'search_products',
            'tool_arguments' => ['search' => 'áo'],
            'tool_result' => ['products' => []],
            'failure_type' => 'empty_tool_result',
        ]);
        $this->assertFalse($budget->hasProgress([$fingerprint, $fingerprint], 6.0, 6.0));
    }

    private function retryState(): array
    {
        return [
            'total_steps' => 0,
            'tool_retries' => 0,
            'answer_revisions' => 0,
            'query_rewrites' => 0,
        ];
    }
}
