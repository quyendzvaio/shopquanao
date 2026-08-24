<?php

class ResponseGenerator {
    public function generate(string $message, array $intent, array $normalized, array $plan): array {
        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        $responseType = (string)($plan['response_type'] ?? 'final_answer');
        $cards = $normalized['cards'] ?? [];
        $evidence = $normalized['evidence'] ?? [];
        $complementaryGroups = $normalized['complementary_groups'] ?? [];

        if ($responseType === 'clarification') {
            $answer = $this->clarificationAnswer($intent);
            return $this->response($answer, 'clarification', $intent, $cards);
        }

        if ($primary === 'unsupported_outfit') {
            return $this->response('Hiện mình không hỗ trợ tư vấn phối đồ. Mình có thể hỗ trợ bạn tìm sản phẩm, xem chi tiết sản phẩm, tư vấn size và chính sách shop.', 'final_answer', $intent, []);
        }

        if ($primary === 'unsupported_checkout') {
            return $this->response('Mình không thể tự thêm giỏ hàng hoặc thanh toán giúp bạn. Bạn vui lòng bấm vào thẻ sản phẩm hoặc vào trang chi tiết sản phẩm để tự thêm giỏ hàng và thanh toán.', 'final_answer', $intent, $cards);
        }

        $answer = match ($primary) {
            'product_detail' => $this->productDetailAnswer($intent, $cards),
            'product_search' => $this->productSearchAnswer($intent, $cards, $evidence),
            'mixed_product_policy' => $this->mixedAnswer($intent, $cards, $evidence),
            'return_exchange', 'shipping', 'policy' => $this->policyAnswer($evidence, $primary),
            'size_advice' => $this->sizeAnswer($intent, $evidence),
            'order_status' => $this->orderAnswer($evidence),
            'suggest_complementary_products' => $this->complementaryAnswer($complementaryGroups, $cards),
            default => 'Mình chưa đủ thông tin để trả lời chắc chắn. Bạn nói rõ hơn giúp mình nhé.',
        };

        $type = $answer === '' ? 'fallback' : $responseType;
        if ($answer === '') {
            $answer = 'Mình chưa tìm thấy dữ liệu phù hợp để trả lời chính xác. Bạn vui lòng hỏi rõ hơn giúp mình.';
        }

        return $this->response($answer, $type, $intent, $cards);
    }

    private function productSearchAnswer(array $intent, array $cards, array $evidence): string {
        $entities = $intent['entities'] ?? [];
        $requested = $intent['requested_fields'] ?? [];
        $productType = (string)($entities['product_type'] ?? 'sản phẩm');
        $count = $this->resultCount($evidence, count($cards));
        if ($count <= 0 || $cards === []) {
            return "Mình chưa tìm thấy $productType phù hợp. Bạn có thể thử khoảng giá rộng hơn hoặc đổi từ khóa tìm kiếm.";
        }

        $parts = ["Mình tìm thấy $count sản phẩm $productType"];
        if (isset($entities['max_price'])) {
            $parts[] = 'dưới ' . $this->money((float)$entities['max_price']);
        } elseif (isset($entities['min_price'])) {
            $parts[] = 'từ ' . $this->money((float)$entities['min_price']);
        }
        if (in_array('stock', $requested, true)) {
            $inStock = count(array_filter($cards, fn($c) => (int)($c['stock'] ?? 0) > 0));
            $parts[] = "có $inStock sản phẩm còn hàng trong danh sách đang hiển thị";
        }

        return trim(implode(' ', $parts)) . '. Bạn có thể bấm vào thẻ sản phẩm bên dưới để xem chi tiết.';
    }

    private function productDetailAnswer(array $intent, array $cards): string {
        $productId = (int)($intent['entities']['product_id'] ?? 0);
        $card = $cards[0] ?? null;
        if (!is_array($card)) {
            return "Mình chưa tìm thấy sản phẩm mã $productId.";
        }

        $stock = (int)($card['stock'] ?? 0);
        $stockText = $stock > 0 ? "còn $stock sản phẩm" : 'hết hàng';
        $sizes = $card['available_sizes'] ?? [];
        $sizeText = $sizes !== [] ? implode(', ', $sizes) : 'chưa cập nhật';

        return sprintf(
            "%s (mã %d) có giá %s, hiện %s. Size hiện có: %s. Bạn có thể bấm vào thẻ sản phẩm bên dưới để xem chi tiết.",
            (string)($card['name'] ?? "Sản phẩm mã $productId"),
            (int)($card['id'] ?? $productId),
            $this->money((float)($card['price'] ?? 0)),
            $stockText,
            $sizeText
        );
    }

    private function mixedAnswer(array $intent, array $cards, array $evidence): string {
        $productPart = '';
        if (!empty($intent['entities']['product_id'])) {
            $productPart = $this->productDetailAnswer($intent, $cards);
        } elseif ($cards !== []) {
            $productPart = $this->productSearchAnswer($intent, $cards, $evidence);
        }
        $policyPart = $this->policyAnswer($evidence, 'mixed_product_policy');
        return trim($productPart . ' ' . $policyPart);
    }

    private function policyAnswer(array $evidence, string $intent = ''): string {
        $policy = array_values(array_filter($evidence, fn($e) => ($e['source'] ?? '') === 'policy_rag' && trim((string)($e['value'] ?? '')) !== ''));
        if ($policy === []) {
            return 'Hiện mình chưa tìm thấy thông tin phù hợp trong dữ liệu chính sách của shop. Bạn vui lòng hỏi rõ hơn hoặc liên hệ CSKH để được kiểm tra.';
        }

        $text = trim((string)$policy[0]['value']);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        // Return/size questions often need a second policy fact (for example,
        // the personal-choice shipping rule) that is not present in the top
        // FAQ chunk. Add only missing, relevant sentences to avoid duplicating
        // near-identical RAG chunks in ordinary policy answers.
        if ($intent === 'return_exchange') {
            $extra = [];
            foreach ($policy as $item) {
                $value = preg_replace('/\s+/u', ' ', trim((string)$item['value'])) ?? trim((string)$item['value']);
                $sentences = preg_split('/(?<=[.!?])\s+|\s*[-•]\s*/u', $value) ?: [$value];
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence, " .\t\n\r\\-");
                    if ($sentence === '') continue;
                    if (!preg_match('/đổi\s+(?:size|màu)|size|phí vận chuyển|phí ship/ui', $sentence)) continue;
                    if (mb_stripos($text, $sentence) === false) $extra[] = $sentence;
                }
            }
            if ($extra !== []) $text .= ' ' . implode('. ', array_slice(array_unique($extra), 0, 2)) . '.';
        }

        // The first item is already cross-encoder reranked. Keeping up to three
        // sentences preserves compact facts such as the 24h inner-city SLA
        // without duplicating a second, near-identical FAQ chunk.
        return 'Theo chính sách của shop, ' . $this->firstSentences($text, 3);
    }

    private function sizeAnswer(array $intent, array $evidence): string {
        $recommended = null;
        foreach ($evidence as $item) {
            if (($item['fact_type'] ?? '') === 'recommended_size') {
                $recommended = $item;
                break;
            }
        }
        if ($recommended === null || trim((string)($recommended['value'] ?? '')) === '') {
            return 'Mình chưa có bảng size phù hợp để tư vấn chắc chắn. Bạn có thể gửi thêm sản phẩm hoặc danh mục bạn đang xem.';
        }

        $height = (int)($intent['entities']['height'] ?? 0);
        $weight = (int)($intent['entities']['weight'] ?? 0);
        $size = (string)$recommended['value'];
        return "Với chiều cao {$height}cm và cân nặng {$weight}kg, size $size phù hợp hơn. Nếu bạn thích mặc rộng hoặc đang sát ngưỡng trên của size này, bạn có thể cân nhắc tăng một size.";
    }

    private function orderAnswer(array $evidence): string {
        foreach ($evidence as $item) {
            if (($item['fact_type'] ?? '') === 'requires_login') {
                return 'Bạn vui lòng đăng nhập để mình kiểm tra trạng thái đơn hàng. Sau khi đăng nhập, bạn có thể xem trong mục Đơn hàng của tôi hoặc gửi mã đơn để mình hỗ trợ.';
            }
        }

        $orders = array_values(array_filter($evidence, fn($e) => ($e['fact_type'] ?? '') === 'order_status'));
        if ($orders === []) {
            return 'Mình chưa tìm thấy đơn hàng nào trong tài khoản của bạn.';
        }
        $first = $orders[0];
        return 'Đơn #' . (int)($first['order_id'] ?? 0) . ' hiện có trạng thái: ' . (string)($first['value'] ?? '') . '. Bạn có thể xem chi tiết trong mục Đơn hàng của tôi.';
    }

    private function complementaryAnswer(array $groups, array $cards): string {
        if ($cards === []) {
            return 'Mình chưa tìm thấy sản phẩm phối hợp phù hợp trong shop lúc này.';
        }
        $items = [];
        $seen = [];
        foreach ($cards as $card) {
            if (!is_array($card)) continue;
            $id = (int) ($card['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            $name = trim((string) ($card['name'] ?? ''));
            if ($name === '') continue;
            $items[] = sprintf('%s (mã %d)', $name, $id);
        }
        if ($items === []) {
            return 'Mình chưa tìm thấy sản phẩm phối hợp phù hợp trong shop lúc này.';
        }
        return 'Mình tìm thấy trong Product Search của shop: '
            . implode('; ', array_slice($items, 0, 5))
            . '. Bạn có thể xem các thẻ sản phẩm bên dưới.';
    }

    private function clarificationAnswer(array $intent): string {
        $missing = $intent['missing_slots'] ?? [];
        if (in_array('height', $missing, true) || in_array('weight', $missing, true)) {
            return 'Bạn cho mình xin chiều cao và cân nặng để tư vấn size chính xác hơn nhé.';
        }
        return 'Bạn bổ sung thêm thông tin còn thiếu để mình hỗ trợ chính xác hơn nhé.';
    }

    private function response(string $answer, string $type, array $intent, array $cards): array {
        return [
            'answer' => $answer,
            'message' => $answer,
            'response_type' => $type,
            'primary_intent' => (string)($intent['primary_intent'] ?? 'unknown'),
            'secondary_intents' => $intent['secondary_intents'] ?? [],
            'requested_fields' => $intent['requested_fields'] ?? [],
            'cards' => $cards,
            'products' => $cards,
            'missing_slots' => $intent['missing_slots'] ?? [],
        ];
    }

    private function resultCount(array $evidence, int $fallback): int {
        foreach ($evidence as $item) {
            if (($item['fact_type'] ?? '') === 'result_count') return (int)$item['value'];
        }
        return $fallback;
    }

    private function money(float $value): string {
        return number_format($value, 0, ',', '.') . 'đ';
    }

    private function firstSentences(string $text, int $max): string {
        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];
        return trim(implode(' ', array_slice($parts, 0, $max)));
    }
}
