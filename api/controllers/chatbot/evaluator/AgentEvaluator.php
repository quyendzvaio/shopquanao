<?php
/**
 * Internal evaluator for ReAct chatbot answers.
 *
 * The evaluator never trusts the LLM for hard facts such as product IDs,
 * prices, stock, auth, ownership, or order status. Those checks are
 * deterministic; the optional LLM judge only scores soft criteria.
 */

class AgentEvaluationResult {
    public string $taskType;
    public bool $passed;
    public float $weightedScore;
    public array $criteriaScores;
    public array $hardConstraintFailures;
    public string $failureType;
    public array $issues;
    public string $nextAction;
    public ?string $retryInstruction;
    public ?string $revisionInstruction;
    public ?string $questionForUser;
    public ?string $safeFallbackMessage;

    public function __construct(
        string $taskType,
        bool $passed,
        float $weightedScore,
        array $criteriaScores,
        array $hardConstraintFailures = [],
        string $failureType = 'none',
        array $issues = [],
        string $nextAction = 'return',
        ?string $retryInstruction = null,
        ?string $revisionInstruction = null,
        ?string $questionForUser = null,
        ?string $safeFallbackMessage = null
    ) {
        $this->taskType = $taskType;
        $this->passed = $passed;
        $this->weightedScore = $weightedScore;
        $this->criteriaScores = $criteriaScores;
        $this->hardConstraintFailures = $hardConstraintFailures;
        $this->failureType = $failureType;
        $this->issues = $issues;
        $this->nextAction = $nextAction;
        $this->retryInstruction = $retryInstruction;
        $this->revisionInstruction = $revisionInstruction;
        $this->questionForUser = $questionForUser;
        $this->safeFallbackMessage = $safeFallbackMessage;
    }

    public function toArray(): array {
        return [
            'task_type' => $this->taskType,
            'passed' => $this->passed,
            'weighted_score' => $this->weightedScore,
            'criteria_scores' => $this->criteriaScores,
            'hard_constraint_failures' => $this->hardConstraintFailures,
            'failure_type' => $this->failureType,
            'issues' => $this->issues,
            'next_action' => $this->nextAction,
            'retry_instruction' => $this->retryInstruction,
            'revision_instruction' => $this->revisionInstruction,
            'question_for_user' => $this->questionForUser,
            'safe_fallback_message' => $this->safeFallbackMessage,
        ];
    }
}

class TaskRubricRegistry {
    private const RUBRICS = [
        'product_search' => [
            'threshold' => 7.5,
            'weights' => [
                'category_match' => 0.20,
                'keyword_relevance' => 0.20,
                'price_constraint' => 0.20,
                'availability' => 0.15,
                'groundedness' => 0.15,
                'helpfulness' => 0.10,
            ],
            'semantic' => ['keyword_relevance', 'helpfulness'],
        ],
        'product_detail' => [
            'threshold' => 8.5,
            'weights' => [
                'product_id_correctness' => 0.25,
                'db_accuracy' => 0.25,
                'required_fields' => 0.20,
                'card_schema' => 0.20,
                'clarity' => 0.10,
            ],
            'semantic' => ['clarity'],
        ],
        'size_advice' => [
            'threshold' => 8.0,
            'weights' => [
                'input_sufficiency' => 0.15,
                'size_chart_usage' => 0.25,
                'recommendation_consistency' => 0.25,
                'boundary_handling' => 0.15,
                'uncertainty_expression' => 0.10,
                'helpfulness' => 0.10,
            ],
            'semantic' => ['boundary_handling', 'uncertainty_expression', 'helpfulness'],
        ],
        'order_status' => [
            'threshold' => 9.0,
            'weights' => [
                'authenticated' => 0.20,
                'ownership_verified' => 0.25,
                'status_correct' => 0.25,
                'shipping_info_accurate' => 0.15,
                'privacy_safe' => 0.10,
                'clarity' => 0.05,
            ],
            'semantic' => ['clarity'],
        ],
    ];

    public function get(string $taskType): array {
        return self::RUBRICS[$taskType] ?? [
            'threshold' => 8.0,
            'weights' => ['groundedness' => 0.70, 'helpfulness' => 0.30],
            'semantic' => ['helpfulness'],
        ];
    }
}

class WeightedScoreCalculator {
    public const VALID_SCORES = [0, 2, 4, 6, 8, 10];

    public function calculate(array $rubric, array $criteriaScores): float {
        $total = 0.0;
        foreach ($rubric['weights'] as $criterion => $weight) {
            $score = $criteriaScores[$criterion] ?? 0;
            $total += ((float)$score) * (float)$weight;
        }
        return round($total, 2);
    }

    public function normalizeScore($score): int {
        $score = is_numeric($score) ? (int)$score : 0;
        if (in_array($score, self::VALID_SCORES, true)) {
            return $score;
        }

        $closest = 0;
        $distance = PHP_INT_MAX;
        foreach (self::VALID_SCORES as $valid) {
            $currentDistance = abs($valid - $score);
            if ($currentDistance < $distance) {
                $distance = $currentDistance;
                $closest = $valid;
            }
        }
        return $closest;
    }
}

class RetryBudgetManager {
    public const MAX_TOTAL_STEPS = 4;
    public const MAX_TOOL_RETRIES = 2;
    public const MAX_ANSWER_REVISIONS = 1;
    public const MAX_QUERY_REWRITES = 1;

    public function can(string $action, array $retryState): bool {
        if (($retryState['total_steps'] ?? 0) >= self::MAX_TOTAL_STEPS) {
            return false;
        }
        if ($action === 'retry_tool') {
            return ($retryState['tool_retries'] ?? 0) < self::MAX_TOOL_RETRIES;
        }
        if ($action === 'revise_answer') {
            return ($retryState['answer_revisions'] ?? 0) < self::MAX_ANSWER_REVISIONS;
        }
        if ($action === 'rewrite_query') {
            return ($retryState['query_rewrites'] ?? 0) < self::MAX_QUERY_REWRITES;
        }
        return true;
    }

    public function fingerprint(array $attempt): string {
        return sha1(json_encode([
            'tool_name' => $attempt['tool_name'] ?? '',
            'tool_arguments' => $attempt['tool_arguments'] ?? [],
            'result_status' => $this->resultStatus($attempt['tool_result'] ?? []),
            'failure_type' => $attempt['failure_type'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function hasProgress(array $fingerprints, ?float $previousScore, float $currentScore): bool {
        $count = count($fingerprints);
        if ($count >= 2 && $fingerprints[$count - 1] === $fingerprints[$count - 2]) {
            return false;
        }
        if ($previousScore !== null && $currentScore <= $previousScore) {
            return false;
        }
        return true;
    }

    private function resultStatus($result): string {
        if (!is_array($result)) {
            return 'non_array';
        }
        if (isset($result['error'])) {
            return 'error:' . (string)$result['error'];
        }
        if (isset($result['requires_login']) && $result['requires_login']) {
            return 'requires_login';
        }
        if (isset($result['products']) && empty($result['products'])) {
            return 'empty_products';
        }
        if (isset($result['orders']) && empty($result['orders'])) {
            return 'empty_orders';
        }
        return 'ok';
    }
}

class DeterministicValidator {
    private const TASK_MESSAGES = [
        'product_search' => 'Mình chưa tìm thấy sản phẩm đáp ứng đầy đủ các điều kiện hiện tại. Bạn có thể mở rộng khoảng giá hoặc điều chỉnh một tiêu chí tìm kiếm.',
        'product_detail' => 'Mình chưa lấy được đầy đủ thông tin chính xác của sản phẩm này. Vui lòng chọn lại sản phẩm hoặc thử lại sau.',
        'size_advice' => 'Mình chưa đủ dữ liệu để tư vấn size phù hợp. Vui lòng cung cấp chiều cao, cân nặng và sản phẩm bạn đang quan tâm.',
        'order_status' => 'Hiện mình chưa thể xác minh trạng thái đơn hàng. Bạn có thể kiểm tra trong mục Đơn hàng của tôi hoặc thử lại sau.',
        'deny' => 'Mình không thể cung cấp thông tin của đơn hàng này vì chưa xác minh được quyền truy cập.',
    ];

    public function validate(array $input, array $rubric): array {
        $taskType = (string)($input['task_type'] ?? '');
        return match ($taskType) {
            'product_search' => $this->validateProductSearch($input),
            'product_detail' => $this->validateProductDetail($input),
            'size_advice' => $this->validateSizeAdvice($input),
            'order_status' => $this->validateOrderStatus($input),
            default => $this->base([], [], 'none'),
        };
    }

    public function safeMessage(string $taskType, string $action = 'fallback'): string {
        if ($action === 'deny') {
            return self::TASK_MESSAGES['deny'];
        }
        return self::TASK_MESSAGES[$taskType] ?? 'Mình chưa đủ thông tin để trả lời chính xác. Bạn vui lòng thử lại sau.';
    }

    private function validateProductSearch(array $input): array {
        $args = $input['tool_arguments'] ?? [];
        $result = $input['tool_result'] ?? [];
        $answer = (string)($input['draft_answer'] ?? '');
        $scores = [
            'category_match' => 10,
            'keyword_relevance' => 10,
            'price_constraint' => 10,
            'availability' => 10,
            'groundedness' => 10,
            'helpfulness' => 8,
        ];
        $hard = [];
        $issues = [];

        if (isset($result['error'])) {
            return $this->base($scores, ['tool_error'], 'temporary_tool_error', ['Tool search_products lỗi: ' . $result['error']], 'retry_tool');
        }

        $products = is_array($result['products'] ?? null) ? $result['products'] : [];
        if (empty($products)) {
            $scores['helpfulness'] = 6;
            $issues[] = 'Không có sản phẩm phù hợp trong tool result.';
            return $this->base($scores, [], 'empty_tool_result', $issues, 'fallback');
        }

        foreach ($products as $product) {
            if (empty($product['id'])) {
                $hard[] = 'product_id_missing';
                $scores['groundedness'] = 0;
            }
            if (isset($args['category_id']) && (int)($product['category_id'] ?? 0) !== (int)$args['category_id']) {
                $hard[] = 'category_mismatch';
                $scores['category_match'] = 0;
            }
            if (isset($args['min_price']) && (float)($product['price'] ?? 0) < (float)$args['min_price']) {
                $hard[] = 'price_below_min';
                $scores['price_constraint'] = 0;
            }
            if (isset($args['max_price']) && (float)($product['price'] ?? 0) > (float)$args['max_price']) {
                $hard[] = 'price_above_max';
                $scores['price_constraint'] = 0;
            }
        }

        if ($this->containsHallucinatedColor($answer)) {
            $hard[] = 'hallucinated_color';
            $scores['groundedness'] = 0;
            $issues[] = 'Câu trả lời nhắc màu sắc như thuộc tính lọc dù DB/product tool không có trường màu riêng.';
        }

        if ($this->answerLooksTooThin($answer)) {
            $scores['helpfulness'] = 4;
            $issues[] = 'Draft answer quá ngắn hoặc thiếu hướng dẫn xem thẻ sản phẩm.';
            return $this->base($scores, $hard, empty($hard) ? 'incomplete_answer' : 'constraint_violation', $issues, 'revise_answer');
        }

        return $this->base($scores, $hard, empty($hard) ? 'none' : 'constraint_violation', $issues);
    }

    private function validateProductDetail(array $input): array {
        $args = $input['tool_arguments'] ?? [];
        $result = $input['tool_result'] ?? [];
        $answer = (string)($input['draft_answer'] ?? '');
        $scores = [
            'product_id_correctness' => 10,
            'db_accuracy' => 10,
            'required_fields' => 10,
            'card_schema' => 10,
            'clarity' => 8,
        ];
        $hard = [];
        $issues = [];

        if (isset($result['error'])) {
            return $this->base($scores, ['tool_error'], 'temporary_tool_error', ['Tool get_product_detail lỗi: ' . $result['error']], 'retry_tool');
        }

        $product = is_array($result['product'] ?? null) ? $result['product'] : [];
        if (empty($product)) {
            return $this->base($scores, ['product_missing'], 'empty_tool_result', ['Tool không trả product.'], 'fallback');
        }

        if (isset($args['product_id']) && (int)($product['id'] ?? 0) !== (int)$args['product_id']) {
            $hard[] = 'wrong_product_id';
            $scores['product_id_correctness'] = 0;
        }

        foreach (['id', 'name', 'price', 'stock', 'image'] as $field) {
            if (!array_key_exists($field, $product)) {
                $hard[] = 'missing_' . $field;
                $scores['required_fields'] = 0;
            }
        }

        $sizes = $this->extractSizes($product);
        if (empty($sizes)) {
            $scores['card_schema'] = 8;
            $issues[] = 'Sản phẩm chưa có available_sizes; frontend vẫn tương thích nhưng evaluator đánh dấu thiếu nhẹ.';
        }

        if ($this->answerMentionsWrongPrice($answer, (float)($product['price'] ?? 0))) {
            $hard[] = 'price_mismatch_in_answer';
            $scores['db_accuracy'] = 0;
        }

        $mentionedSizes = $this->mentionedSizes($answer);
        foreach ($mentionedSizes as $size) {
            if (!empty($sizes) && !in_array($size, $sizes, true)) {
                $hard[] = 'size_not_available:' . $size;
                $scores['db_accuracy'] = 0;
            }
        }

        if ($this->answerLooksTooThin($answer)) {
            $scores['clarity'] = 4;
            $issues[] = 'Draft answer chi tiết sản phẩm chưa đủ rõ.';
            return $this->base($scores, $hard, empty($hard) ? 'incomplete_answer' : 'constraint_violation', $issues, 'revise_answer');
        }

        return $this->base($scores, $hard, empty($hard) ? 'none' : 'constraint_violation', $issues);
    }

    private function validateSizeAdvice(array $input): array {
        $args = $input['tool_arguments'] ?? [];
        $result = $input['tool_result'] ?? [];
        $answer = mb_strtolower((string)($input['draft_answer'] ?? ''));
        $scores = [
            'input_sufficiency' => 10,
            'size_chart_usage' => 10,
            'recommendation_consistency' => 10,
            'boundary_handling' => 8,
            'uncertainty_expression' => 8,
            'helpfulness' => 8,
        ];
        $hard = [];
        $issues = [];

        if (empty($args['height']) || empty($args['weight'])) {
            $missing = empty($args['height']) ? 'chiều cao' : 'cân nặng';
            $scores['input_sufficiency'] = 0;
            return $this->base($scores, ['missing_' . ($missing === 'chiều cao' ? 'height' : 'weight')], 'missing_user_input', ["Thiếu $missing."], 'ask_user');
        }

        if (isset($result['error'])) {
            return $this->base($scores, ['tool_error'], 'temporary_tool_error', ['Tool suggest_size lỗi: ' . $result['error']], 'retry_tool');
        }

        $recommended = is_array($result['recommended'] ?? null) ? $result['recommended'] : [];
        $sizes = is_array($result['sizes'] ?? null) ? $result['sizes'] : [];
        if (empty($sizes)) {
            $scores['size_chart_usage'] = 0;
            return $this->base($scores, ['size_chart_missing'], 'empty_tool_result', ['Không có bảng size.'], 'fallback');
        }
        if (empty($recommended)) {
            $scores['recommendation_consistency'] = 4;
            return $this->base($scores, [], 'incomplete_answer', ['Không tìm được size khớp chiều cao/cân nặng.'], 'revise_answer');
        }

        $recommendedSize = mb_strtolower((string)($recommended['size_name'] ?? ''));
        if ($recommendedSize !== '' && mb_strpos($answer, $recommendedSize) === false) {
            $scores['recommendation_consistency'] = 0;
            $hard[] = 'recommended_size_missing_in_answer';
            $issues[] = 'Draft answer không nhắc size được tool đề xuất.';
        }

        if (str_contains($answer, '100%') || str_contains($answer, 'chắc chắn tuyệt đối') || str_contains($answer, 'đảm bảo vừa')) {
            $hard[] = 'overconfident_size_claim';
            $scores['uncertainty_expression'] = 0;
            $issues[] = 'Tư vấn size không được khẳng định chắc chắn tuyệt đối.';
        }

        return $this->base($scores, $hard, empty($hard) ? 'none' : 'constraint_violation', $issues, empty($hard) ? 'return' : 'revise_answer');
    }

    private function validateOrderStatus(array $input): array {
        $result = $input['tool_result'] ?? [];
        $answer = (string)($input['draft_answer'] ?? '');
        $runtime = $input['runtime_context'] ?? [];
        $scores = [
            'authenticated' => 10,
            'ownership_verified' => 10,
            'status_correct' => 10,
            'shipping_info_accurate' => 10,
            'privacy_safe' => 10,
            'clarity' => 8,
        ];
        $hard = [];
        $issues = [];

        if (empty($runtime['authenticated']) || !empty($result['requires_login'])) {
            $scores['authenticated'] = 0;
            return $this->base($scores, ['unauthenticated'], 'authentication_failure', ['User chưa đăng nhập.'], 'deny');
        }

        if (isset($result['error'])) {
            return $this->base($scores, ['tool_error'], 'temporary_tool_error', ['Tool get_order_status lỗi: ' . $result['error']], 'retry_tool');
        }

        $orders = is_array($result['orders'] ?? null) ? $result['orders'] : [];
        if (empty($orders)) {
            $scores['ownership_verified'] = 0;
            return $this->base($scores, ['order_not_found_or_not_owned'], 'ownership_failure', ['Không xác minh được đơn thuộc user.'], 'deny');
        }

        foreach ($orders as $order) {
            $status = trim((string)($order['status'] ?? ''));
            if ($status !== '' && mb_stripos($answer, $status) === false) {
                $hard[] = 'order_status_missing_or_mismatch';
                $scores['status_correct'] = 0;
            }
        }

        if (preg_match('/0\d{8,10}|\b\d{1,4}\s+[^\n,]+(đường|phố|quận|huyện|tp\.?|thành phố)/iu', $answer)) {
            $hard[] = 'privacy_risk';
            $scores['privacy_safe'] = 0;
        }
        if (preg_match('/(ngày mai|chắc chắn giao|dự kiến giao ngày \d{1,2})/iu', $answer)) {
            $hard[] = 'guessed_delivery_date';
            $scores['shipping_info_accurate'] = 0;
        }

        return $this->base($scores, $hard, empty($hard) ? 'none' : 'constraint_violation', $issues, empty($hard) ? 'return' : 'revise_answer');
    }

    private function base(
        array $scores,
        array $hard = [],
        string $failureType = 'none',
        array $issues = [],
        string $nextAction = 'return'
    ): array {
        return [
            'criteria_scores' => $scores,
            'hard_constraint_failures' => array_values(array_unique($hard)),
            'failure_type' => $failureType,
            'issues' => $issues,
            'next_action' => $nextAction,
        ];
    }

    private function answerLooksTooThin(string $answer): bool {
        return mb_strlen(trim($answer)) < 20;
    }

    private function containsHallucinatedColor(string $answer): bool {
        return preg_match('/\b(màu|color)\s+(đỏ|xanh|trắng|đen|vàng|hồng|nâu|be|kem)\b/iu', $answer) === 1;
    }

    private function answerMentionsWrongPrice(string $answer, float $expectedPrice): bool {
        if (!preg_match_all('/(\d+(?:[.,]\d{3})*)\s*(k|đ|vnd)?/iu', $answer, $matches, PREG_SET_ORDER)) {
            return false;
        }
        foreach ($matches as $match) {
            $raw = (string)$match[0];
            if (!preg_match('/(giá|price|vnđ|vnd|đ|k)/iu', $raw . ' ' . $answer)) {
                continue;
            }
            $number = (float)str_replace(['.', ','], '', $match[1]);
            if (isset($match[2]) && mb_strtolower($match[2]) === 'k') {
                $number *= 1000;
            }
            if ($number >= 10000 && abs($number - $expectedPrice) > 1000) {
                return true;
            }
        }
        return false;
    }

    private function extractSizes(array $product): array {
        $sizes = [];
        foreach ($product['sizes'] ?? [] as $size) {
            if (is_array($size) && isset($size['size_name'])) {
                $sizes[] = mb_strtoupper((string)$size['size_name']);
            } elseif (is_string($size)) {
                $sizes[] = mb_strtoupper($size);
            }
        }
        return array_values(array_unique($sizes));
    }

    private function mentionedSizes(string $answer): array {
        if (!preg_match_all('/\b(S|M|L|XL|XXL)\b/u', mb_strtoupper($answer), $matches)) {
            return [];
        }
        return array_values(array_unique($matches[1]));
    }
}

class SemanticEvaluator {
    private ?LLMProvider $llm;
    private WeightedScoreCalculator $calculator;

    public function __construct(?LLMProvider $llm = null) {
        $this->llm = $llm;
        $this->calculator = new WeightedScoreCalculator();
    }

    public function evaluate(array $input, array $rubric): array {
        if ($this->llm === null || empty($rubric['semantic'])) {
            return [];
        }

        $criteria = array_values($rubric['semantic']);
        $prompt = [
            [
                'role' => 'system',
                'content' => 'Bạn là evaluator nội bộ. Chỉ trả JSON object hợp lệ. Không giải thích. Mỗi score chỉ được là 0,2,4,6,8,10.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'task_type' => $input['task_type'] ?? '',
                    'criteria' => $criteria,
                    'user_query' => $input['user_query'] ?? '',
                    'tool_name' => $input['tool_name'] ?? '',
                    'tool_arguments' => $input['tool_arguments'] ?? [],
                    'tool_result_summary' => $this->summarizeToolResult($input['tool_result'] ?? []),
                    'draft_answer' => $input['draft_answer'] ?? '',
                    'output_schema' => [
                        'criteria_scores' => array_fill_keys($criteria, 0),
                        'issues' => [],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        try {
            $response = $this->llm->chat($prompt, [], 'none');
            $raw = trim($response->content);
            $raw = preg_replace('/^```json\s*|\s*```$/u', '', $raw) ?? $raw;
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return [];
            }
            $scores = [];
            foreach ($criteria as $criterion) {
                if (array_key_exists($criterion, $data['criteria_scores'] ?? [])) {
                    $scores[$criterion] = $this->calculator->normalizeScore($data['criteria_scores'][$criterion]);
                }
            }
            return [
                'criteria_scores' => $scores,
                'issues' => is_array($data['issues'] ?? null) ? array_slice($data['issues'], 0, 5) : [],
            ];
        } catch (Throwable $e) {
            error_log('Semantic evaluator skipped: ' . $e->getMessage());
            return [];
        }
    }

    private function summarizeToolResult(array $result): array {
        if (isset($result['products']) && is_array($result['products'])) {
            return [
                'products_count' => count($result['products']),
                'products' => array_map(fn($p) => [
                    'id' => $p['id'] ?? null,
                    'name' => $p['name'] ?? null,
                    'price' => $p['price'] ?? null,
                    'stock' => $p['stock'] ?? null,
                    'category_id' => $p['category_id'] ?? null,
                ], array_slice($result['products'], 0, 5)),
            ];
        }
        if (isset($result['product']) && is_array($result['product'])) {
            $p = $result['product'];
            return [
                'product' => [
                    'id' => $p['id'] ?? null,
                    'name' => $p['name'] ?? null,
                    'price' => $p['price'] ?? null,
                    'stock' => $p['stock'] ?? null,
                    'sizes' => $p['sizes'] ?? [],
                ],
            ];
        }
        if (isset($result['orders']) && is_array($result['orders'])) {
            return [
                'orders' => array_map(fn($o) => [
                    'id' => $o['id'] ?? null,
                    'status' => $o['status'] ?? null,
                    'created_at' => $o['created_at'] ?? null,
                ], array_slice($result['orders'], 0, 5)),
            ];
        }
        return $result;
    }
}

class DecisionRouter {
    private RetryBudgetManager $budget;
    private DeterministicValidator $validator;

    public function __construct(?RetryBudgetManager $budget = null, ?DeterministicValidator $validator = null) {
        $this->budget = $budget ?? new RetryBudgetManager();
        $this->validator = $validator ?? new DeterministicValidator();
    }

    public function decide(string $taskType, array $rubric, array $state, float $weightedScore): array {
        $hard = $state['hard_constraint_failures'] ?? [];
        $failureType = (string)($state['failure_type'] ?? 'none');
        $retryState = $state['retry_state'] ?? [];
        $threshold = (float)($rubric['threshold'] ?? 8.0);

        if ($failureType === 'authentication_failure' || $failureType === 'ownership_failure') {
            return $this->withMessage($state, 'deny', $taskType);
        }
        if ($failureType === 'missing_user_input') {
            return $this->withMessage($state, 'ask_user', $taskType);
        }
        if ($failureType === 'temporary_tool_error' && $this->budget->can('retry_tool', $retryState)) {
            return $this->withMessage($state, 'retry_tool', $taskType);
        }
        if (!empty($hard)) {
            if ($this->budget->can('revise_answer', $retryState)
                && !$this->hasNonRepairableHardFailure($hard)) {
                return $this->withMessage($state, 'revise_answer', $taskType);
            }
            return $this->withMessage($state, 'fallback', $taskType);
        }

        $suggested = (string)($state['next_action'] ?? 'fallback');
        if (in_array($failureType, ['incomplete_answer', 'ungrounded_answer'], true)
            && $suggested === 'revise_answer'
            && $this->budget->can('revise_answer', $retryState)) {
            return $this->withMessage($state, 'revise_answer', $taskType);
        }
        if ($weightedScore >= $threshold) {
            return $this->withMessage($state, 'return', $taskType);
        }

        if (in_array($suggested, ['retry_tool', 'rewrite_query', 'revise_answer'], true)
            && $this->budget->can($suggested, $retryState)) {
            return $this->withMessage($state, $suggested, $taskType);
        }

        return $this->withMessage($state, 'fallback', $taskType);
    }

    private function withMessage(array $state, string $action, string $taskType): array {
        $state['next_action'] = $action;
        if ($action === 'ask_user') {
            $state['question_for_user'] = $this->validator->safeMessage($taskType, 'fallback');
        }
        if ($action === 'deny' || $action === 'fallback') {
            $state['safe_fallback_message'] = $this->validator->safeMessage($taskType, $action);
        }
        if ($action === 'revise_answer') {
            $state['revision_instruction'] = 'Sửa câu trả lời để chỉ dựa trên tool result, nhắc đúng dữ liệu, bỏ mọi thuộc tính không có căn cứ.';
        }
        if ($action === 'retry_tool') {
            $state['retry_instruction'] = 'Retry tool vì lỗi tạm thời; không thay đổi dữ liệu người dùng.';
        }
        return $state;
    }

    private function hasNonRepairableHardFailure(array $hard): bool {
        foreach ($hard as $failure) {
            if (str_contains((string)$failure, 'wrong_product_id')
                || str_contains((string)$failure, 'price_above')
                || str_contains((string)$failure, 'price_below')
                || str_contains((string)$failure, 'category_mismatch')
                || str_contains((string)$failure, 'product_missing')
                || str_contains((string)$failure, 'tool_error')) {
                return true;
            }
        }
        return false;
    }
}

class AgentEvaluator {
    private TaskRubricRegistry $rubrics;
    private DeterministicValidator $deterministic;
    private SemanticEvaluator $semantic;
    private WeightedScoreCalculator $calculator;
    private DecisionRouter $router;

    public function __construct(?LLMProvider $llm = null) {
        $this->rubrics = new TaskRubricRegistry();
        $this->deterministic = new DeterministicValidator();
        $this->semantic = new SemanticEvaluator($llm);
        $this->calculator = new WeightedScoreCalculator();
        $this->router = new DecisionRouter();
    }

    public function evaluate(array $input): AgentEvaluationResult {
        $taskType = (string)($input['task_type'] ?? 'unknown');
        $rubric = $this->rubrics->get($taskType);
        $deterministic = $this->deterministic->validate($input, $rubric);
        $semantic = $this->semantic->evaluate($input, $rubric);

        $criteriaScores = $deterministic['criteria_scores'] ?? [];
        foreach ($semantic['criteria_scores'] ?? [] as $criterion => $score) {
            if (isset($rubric['weights'][$criterion])) {
                $criteriaScores[$criterion] = $this->calculator->normalizeScore($score);
            }
        }
        foreach ($rubric['weights'] as $criterion => $_weight) {
            $criteriaScores[$criterion] = $this->calculator->normalizeScore($criteriaScores[$criterion] ?? 0);
        }

        $issues = array_values(array_unique(array_merge(
            $deterministic['issues'] ?? [],
            $semantic['issues'] ?? []
        )));
        $state = [
            'criteria_scores' => $criteriaScores,
            'hard_constraint_failures' => $deterministic['hard_constraint_failures'] ?? [],
            'failure_type' => $deterministic['failure_type'] ?? 'none',
            'issues' => $issues,
            'next_action' => $deterministic['next_action'] ?? 'return',
            'retry_state' => $input['retry_state'] ?? [],
        ];

        $weightedScore = $this->calculator->calculate($rubric, $criteriaScores);
        $state = $this->router->decide($taskType, $rubric, $state, $weightedScore);
        $passed = empty($state['hard_constraint_failures'])
            && $weightedScore >= (float)($rubric['threshold'] ?? 8.0)
            && $state['next_action'] === 'return';

        return new AgentEvaluationResult(
            taskType: $taskType,
            passed: $passed,
            weightedScore: $weightedScore,
            criteriaScores: $criteriaScores,
            hardConstraintFailures: $state['hard_constraint_failures'] ?? [],
            failureType: $state['failure_type'] ?? 'none',
            issues: $state['issues'] ?? [],
            nextAction: $state['next_action'] ?? 'return',
            retryInstruction: $state['retry_instruction'] ?? null,
            revisionInstruction: $state['revision_instruction'] ?? null,
            questionForUser: $state['question_for_user'] ?? null,
            safeFallbackMessage: $state['safe_fallback_message'] ?? null
        );
    }

    public static function taskTypeForTool(string $toolName): ?string {
        return match ($toolName) {
            'search_products' => 'product_search',
            'get_product_detail' => 'product_detail',
            'suggest_size' => 'size_advice',
            'get_order_status' => 'order_status',
            default => null,
        };
    }
}
