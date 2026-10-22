<?php

declare(strict_types=1);

/**
 * Runs one eval case in-process: creates a throwaway user/cart/session,
 * sends each turn through ChatbotService (internal tools, no LLM) and checks
 * the expectations. All rows it creates are deleted afterwards.
 */
final class AgentEvalCaseRunner
{
    public function __construct(private PDO $pdo, private FreeShippingPolicy $policy)
    {
    }

    /** @return array{id:string,group:string,failures:list<string>,turns:list<array<string,mixed>>} */
    public function run(array $case): array
    {
        $userId = !empty($case['user']) ? $this->createUser() : null;
        $sessionId = $this->createSession($userId);
        $failures = [];
        $turns = [];
        try {
            $subtotal = 0;
            foreach (($case['cart'] ?? []) as $row) {
                $this->pdo->prepare('INSERT INTO cart (user_id, product_id, quantity, size) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, (int)$row['product_id'], (int)$row['quantity'], (string)($row['size'] ?? 'S')]);
                $subtotal += $this->price((int)$row['product_id']) * (int)$row['quantity'];
            }
            foreach ($case['turns'] as $i => $turn) {
                $result = (new ChatbotService($this->pdo, $sessionId, $userId, null))->respond((string)$turn['message']);
                foreach ($this->check($result, (array)($turn['expect'] ?? []), $subtotal) as $failure) {
                    $failures[] = 'turn ' . ($i + 1) . ': ' . $failure;
                }
                $turns[] = [
                    'message' => $turn['message'],
                    'intent' => $result['primary_intent'] ?? null,
                    'response_type' => $result['response_type'] ?? null,
                    'tools' => $result['latency']['routing']['selected_tools'] ?? [],
                    'product_ids' => array_map(fn($card) => (int)$card['id'], $result['products'] ?? []),
                    'outfits' => $result['outfits'] ?? null,
                    'freeship_status' => $result['freeship_status'] ?? null,
                    'freeship_nudge' => $result['freeship_nudge'] ?? null,
                    'answer' => $result['message'] ?? '',
                ];
            }
        } catch (Throwable $error) {
            $failures[] = 'exception: ' . $error->getMessage();
        } finally {
            $this->cleanup($userId, $sessionId);
        }
        return ['id' => (string)$case['id'], 'group' => (string)($case['group'] ?? ''), 'failures' => $failures, 'turns' => $turns];
    }

    /** @return list<string> */
    private function check(array $result, array $expect, int $subtotal): array
    {
        $failures = [];
        $intent = (string)($result['primary_intent'] ?? '');
        $cards = array_values(array_filter($result['products'] ?? [], 'is_array'));
        $ids = array_map(fn(array $card) => (int)$card['id'], $cards);
        $tools = (array)($result['latency']['routing']['selected_tools'] ?? []);
        $outfits = is_array($result['outfits'] ?? null) ? $result['outfits'] : [];

        if (isset($expect['intent']) && $intent !== $expect['intent']) $failures[] = "intent $intent, expected {$expect['intent']}";
        if (isset($expect['response_type']) && !in_array($result['response_type'] ?? '', (array)$expect['response_type'], true)) {
            $failures[] = 'response_type ' . ($result['response_type'] ?? 'null');
        }
        if (isset($expect['tool']) && !in_array($expect['tool'], $tools, true)) $failures[] = "tool {$expect['tool']} not used";
        if (!empty($expect['no_tools']) && $tools !== []) $failures[] = 'tools used: ' . implode(',', $tools);
        if (isset($expect['products']) && count($ids) !== (int)$expect['products']) $failures[] = 'product count ' . count($ids);
        if (isset($expect['min_products']) && count($ids) < (int)$expect['min_products']) $failures[] = 'product count ' . count($ids);

        // Grounding: every card is a real catalog product; recommendations are in stock.
        foreach ($ids as $id) {
            $stock = $this->stock($id);
            if ($stock === null) $failures[] = "hallucinated product $id";
            elseif ($stock <= 0 && in_array($intent, ['build_outfit', 'freeship_gap'], true)) $failures[] = "out-of-stock product $id";
        }

        if (isset($expect['max_total'])) {
            foreach ($outfits as $outfit) {
                if ((int)$outfit['total_price'] > (int)$expect['max_total']) {
                    $failures[] = "outfit {$outfit['outfit_index']} total {$outfit['total_price']} over budget";
                }
            }
        }
        if (isset($expect['first_set'])) {
            $first = array_map('intval', (array)($outfits[0]['product_ids'] ?? []));
            if ($first !== array_map('intval', $expect['first_set'])) $failures[] = 'first set ' . json_encode($first);
        }
        if (isset($expect['all_sets_contain'])) {
            foreach ($outfits as $outfit) {
                if (!in_array((int)$expect['all_sets_contain'], array_map('intval', $outfit['product_ids']), true)) {
                    $failures[] = "outfit {$outfit['outfit_index']} lacks anchor {$expect['all_sets_contain']}";
                }
            }
        }
        if (isset($expect['size'])) {
            foreach ($cards as $card) {
                if (in_array((int)($card['category_id'] ?? 0), [1, 2, 3], true)
                    && !in_array($expect['size'], (array)($card['available_sizes'] ?? []), true)) {
                    $failures[] = "product {$card['id']} lacks size {$expect['size']}";
                }
            }
        }
        if (isset($expect['freeship']) && ($result['freeship_status'] ?? null) !== $expect['freeship']) {
            $failures[] = 'freeship_status ' . json_encode($result['freeship_status'] ?? null);
        }
        if (isset($expect['first_product']) && ($ids[0] ?? null) !== (int)$expect['first_product']) {
            $failures[] = 'first product ' . json_encode($ids[0] ?? null);
        }
        if (!empty($expect['reaches_threshold'])) {
            foreach ($cards as $card) {
                if ($subtotal + (int)round((float)$card['price']) < $this->policy->threshold) {
                    $failures[] = "product {$card['id']} does not reach the free-shipping threshold";
                }
            }
        }
        if (isset($expect['nudge']) && (bool)($result['freeship_nudge'] ?? false) !== (bool)$expect['nudge']) {
            $failures[] = 'freeship_nudge ' . json_encode($result['freeship_nudge'] ?? null);
        }
        if (isset($expect['message_contains']) && mb_stripos((string)($result['message'] ?? ''), (string)$expect['message_contains']) === false) {
            $failures[] = 'answer lacks "' . $expect['message_contains'] . '"';
        }
        return $failures;
    }

    private function stock(int $productId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    private function price(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT price FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int)round((float)$stmt->fetchColumn());
    }

    private function createUser(): int
    {
        $tag = bin2hex(random_bytes(6));
        $this->pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')")
            ->execute(["eval_$tag", "eval_$tag@example.test", password_hash($tag, PASSWORD_DEFAULT)]);
        return (int)$this->pdo->lastInsertId();
    }

    private function createSession(?int $userId): int
    {
        $this->pdo->prepare("INSERT INTO chat_sessions (user_id, session_token, status) VALUES (?, ?, 'active')")
            ->execute([$userId, bin2hex(random_bytes(16))]);
        return (int)$this->pdo->lastInsertId();
    }

    private function cleanup(?int $userId, int $sessionId): void
    {
        $statements = [
            ['DELETE FROM chat_messages WHERE session_id = ?', $sessionId],
            ['DELETE FROM tool_executions WHERE session_id = ?', $sessionId],
            ['DELETE FROM chat_session_memory WHERE session_id = ?', $sessionId],
            ['DELETE FROM chat_sessions WHERE id = ?', $sessionId],
        ];
        if ($userId !== null) {
            $statements[] = ['DELETE FROM cart WHERE user_id = ?', $userId];
            $statements[] = ['DELETE FROM user_long_term_memory WHERE user_id = ?', $userId];
            $statements[] = ['DELETE FROM users WHERE id = ?', $userId];
        }
        foreach ($statements as [$sql, $id]) {
            try {
                $this->pdo->prepare($sql)->execute([$id]);
            } catch (PDOException) {
                // Optional tables may be absent on minimal schemas.
            }
        }
    }
}
