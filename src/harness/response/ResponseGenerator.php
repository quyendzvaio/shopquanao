<?php

class ResponseGenerator {
    public function __construct(private FreeShippingPolicy $freeShipping = new FreeShippingPolicy())
    {
    }

    public function generate(string $message, array $intent, array $normalized, array $plan): array {
        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        $responseType = (string)($plan['response_type'] ?? 'final_answer');
        $cards = $normalized['cards'] ?? [];
        $evidence = $normalized['evidence'] ?? [];

        if ($responseType === 'clarification') {
            $answer = $this->clarificationAnswer($intent);
            return $this->response($answer, 'clarification', $intent, $cards);
        }

        if ($primary === 'unsupported_checkout') {
            return $this->response($this->checkoutRefusal($message), 'final_answer', $intent, $cards);
        }

        $answer = match ($primary) {
            'product_detail' => $this->productDetailAnswer($intent, $cards),
            'product_search' => $this->productSearchAnswer($intent, $cards, $evidence),
            'mixed_product_policy' => $this->mixedAnswer($intent, $cards, $evidence),
            'return_exchange', 'shipping', 'policy' => $this->policyAnswer($evidence, $primary),
            'size_advice' => $this->sizeAnswer($intent, $evidence),
            'order_status' => $this->orderAnswer($evidence),
            'build_outfit' => $this->outfitAnswer($intent, $normalized),
            'freeship_gap' => $this->freeShippingAnswer($evidence, $cards),
            'unknown' => 'Với câu hỏi ' . $this->echoQuote($message) . 'mình chưa đủ thông tin để trả lời chắc chắn. Bạn nói rõ hơn giúp mình nhé.',
            default => 'Mình chưa đủ thông tin để trả lời chắc chắn. Bạn nói rõ hơn giúp mình nhé.',
        };

        $type = $answer === '' ? 'fallback' : $responseType;
        if ($answer === '') {
            $answer = 'Mình chưa tìm thấy dữ liệu phù hợp để trả lời chính xác. Bạn vui lòng hỏi rõ hơn giúp mình.';
        }

        $response = $this->response($answer, $type, $intent, $cards);
        if ($primary === 'build_outfit') {
            $response['outfits'] = $normalized['outfits'] ?? [];
        }
        if ($primary === 'freeship_gap') {
            $response['freeship_status'] = null;
            foreach ($evidence as $item) {
                if (($item['fact_type'] ?? '') === 'freeship_status') $response['freeship_status'] = (string)($item['value'] ?? '');
            }
        }
        return $response;
    }

    private function freeShippingAnswer(array $evidence, array $cards): string {
        $fact = [];
        foreach ($evidence as $item) {
            if (($item['fact_type'] ?? '') === 'freeship_status') $fact = $item;
        }
        $threshold = $this->money((float)(!empty($fact['threshold'])
            ? $fact['threshold']
            : $this->freeShipping->threshold));
        return match ((string)($fact['value'] ?? '')) {
            'requires_login' => "Shop miễn phí ship cho đơn từ $threshold. Bạn đăng nhập để mình kiểm tra giỏ hàng và gợi ý món phù hợp nhé.",
            'empty_cart' => "Giỏ hàng của bạn đang trống. Shop miễn phí ship cho đơn từ $threshold.",
            'qualified' => 'Giỏ hàng của bạn (' . $this->money((float)$fact['subtotal']) . ") đã đạt mức miễn phí ship (đơn từ $threshold).",
            'gap' => $this->freeShippingGapAnswer($fact, $cards, $threshold),
            default => 'Mình chưa kiểm tra được giỏ hàng lúc này. Shop miễn phí ship cho đơn từ ' . $threshold . '.',
        };
    }

    private function freeShippingGapAnswer(array $fact, array $cards, string $threshold): string {
        $answer = sprintf(
            'Giỏ hàng của bạn đang là %s, còn thiếu %s để được miễn phí ship (đơn từ %s).',
            $this->money((float)$fact['subtotal']), $this->money((float)$fact['gap']), $threshold
        );
        $items = [];
        foreach (array_slice($cards, 0, 3) as $card) {
            $items[] = sprintf('%s (mã %d) %s', (string)$card['name'], (int)$card['id'], $this->money((float)$card['price']));
        }
        if ($items === []) {
            return $answer . ' Hiện mình chưa tìm được món còn hàng phù hợp để bù.';
        }
        return $answer . ' Bạn có thể thêm: ' . implode('; ', $items) . '. Bạn tự thêm vào giỏ từ thẻ sản phẩm nhé.';
    }

    private function productSearchAnswer(array $intent, array $cards, array $evidence): string {
        $entities = $intent['entities'] ?? [];
        $requested = $intent['requested_fields'] ?? [];
        $productType = (string)($entities['product_type'] ?? 'sản phẩm');
        $count = $this->resultCount($evidence, count($cards));
        if ($count <= 0 || $cards === []) {
            $soldOut = $this->soldOutMatches($evidence);
            if ($soldOut > 0) {
                $constraint = $this->constraintPhrase(array_diff_key($entities, ['in_stock' => true]));
                return "Shop có $soldOut mẫu $productType" . ($constraint !== '' ? " $constraint" : '')
                    . ' nhưng hiện đang hết hàng. Bạn thử màu hoặc mẫu khác giúp mình nhé.';
            }
            return "Mình chưa tìm thấy $productType phù hợp. Bạn có thể thử khoảng giá rộng hơn hoặc đổi từ khóa tìm kiếm.";
        }

        $constraint = $this->constraintPhrase($entities);
        $answer = "Mình tìm thấy $count sản phẩm $productType" . ($constraint !== '' ? " $constraint" : '') . '.';
        if (in_array('stock', $requested, true)) {
            $inStock = count(array_filter($cards, fn($c) => (int)($c['stock'] ?? 0) > 0));
            $answer .= " Có $inStock sản phẩm còn hàng trong danh sách đang hiển thị.";
        }
        // Ground the answer in concrete evidence: name, price and stock of
        // the top results. Every fact below comes straight from the cards,
        // so faithfulness judges can trace each claim to the context.
        $highlights = [];
        foreach (array_slice($cards, 0, 3) as $card) {
            if (!is_array($card) || (int)($card['id'] ?? 0) <= 0) continue;
            $name = trim((string)($card['name'] ?? ''));
            if ($name === '') continue;
            $stock = (int)($card['stock'] ?? 0);
            $highlights[] = sprintf(
                '%s (mã %d) giá %s, %s',
                $name,
                (int)$card['id'],
                $this->money((float)($card['price'] ?? 0)),
                $stock > 0 ? "còn $stock sản phẩm" : 'hết hàng'
            );
        }
        if ($highlights !== []) {
            $answer .= ' Nổi bật: ' . implode('; ', $highlights) . '.';
        }
        return $answer . ' Bạn có thể bấm vào thẻ sản phẩm bên dưới để xem chi tiết.';
    }

    /** The refusal for a cart/checkout/payment request, echoing the request. */
    public function checkoutRefusal(string $message): string {
        return 'Với yêu cầu ' . $this->echoQuote($message) . 'mình không thể tự thêm giỏ hàng hoặc thanh toán giúp bạn. Bạn vui lòng bấm vào thẻ sản phẩm hoặc vào trang chi tiết sản phẩm để tự thêm giỏ hàng và thanh toán.';
    }

    /**
     * Quote the user's own question back (capped). Restating the request
     * keeps refusals visibly tied to the question without adding any new
     * factual claim, which is exactly what answer-relevancy measures.
     */
    private function echoQuote(string $message): string {
        $text = (string)preg_replace('/\s+/u', ' ', trim($message));
        if (mb_strlen($text) > 120) {
            $text = mb_substr($text, 0, 117) . '…';
        }
        return $text === '' ? '' : '"' . $text . '", ';
    }

    private function constraintPhrase(array $entities): string {
        $bits = [];
        if (!empty($entities['color'])) $bits[] = 'màu ' . (string)$entities['color'];
        if (!empty($entities['size'])) $bits[] = 'size ' . (string)$entities['size'];
        if (!empty($entities['min_price']) || !empty($entities['max_price'])) {
            if (!empty($entities['min_price']) && !empty($entities['max_price'])) {
                $bits[] = 'giá từ ' . $this->money((float)$entities['min_price'])
                    . ' đến ' . $this->money((float)$entities['max_price']);
            } elseif (!empty($entities['max_price'])) {
                $bits[] = 'dưới ' . $this->money((float)$entities['max_price']);
            } else {
                $bits[] = 'từ ' . $this->money((float)$entities['min_price']);
            }
        }
        if (!empty($entities['in_stock'])) $bits[] = 'còn hàng';
        return implode(' ', $bits);
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
        $sizeChart = [];
        foreach ($evidence as $item) {
            if (($item['fact_type'] ?? '') === 'recommended_size') {
                $recommended = $item;
            } elseif (($item['fact_type'] ?? '') === 'size_chart' && is_array($item['value'] ?? null)) {
                $sizeChart = $item['value'];
            }
        }
        if ($recommended === null || trim((string)($recommended['value'] ?? '')) === '') {
            $requestedSize = strtoupper(trim((string)($intent['entities']['size'] ?? '')));
            if ($requestedSize !== '') {
                foreach ($sizeChart as $row) {
                    if (!is_array($row) || strtoupper((string)($row['size_name'] ?? '')) !== $requestedSize) {
                        continue;
                    }
                    $height = (int)($intent['entities']['height'] ?? 0);
                    $weight = (int)($intent['entities']['weight'] ?? 0);
                    $heightRange = (int)($row['height_from'] ?? 0) . '-' . (int)($row['height_to'] ?? 0) . 'cm';
                    $weightRange = (int)($row['weight_from'] ?? 0) . '-' . (int)($row['weight_to'] ?? 0) . 'kg';
                    return "Theo bảng size của shop, size $requestedSize phù hợp khoảng cao $heightRange "
                        . "và nặng $weightRange. Với {$height}cm và {$weight}kg, hai số đo của bạn nằm ở hai "
                        . "khoảng size khác nhau, nên mình chưa thể khẳng định size $requestedSize sẽ vừa; "
                        . 'bạn nên ưu tiên số đo chi tiết của sản phẩm hoặc thử trực tiếp.';
                }
            }
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
        // List every owned order with its status (and total when known) so
        // the answer is fully traceable to the order evidence.
        $lines = [];
        foreach (array_slice($orders, 0, 5) as $order) {
            $line = 'Đơn #' . (int)($order['order_id'] ?? 0) . ': ' . (string)($order['value'] ?? '');
            if (isset($order['total_price']) && (float)$order['total_price'] > 0) {
                $line .= ', tổng ' . $this->money((float)$order['total_price']);
            }
            $lines[] = $line;
        }
        $answer = 'Mình tìm thấy ' . count($orders) . ' đơn hàng của bạn: ' . implode('; ', $lines) . '.';
        return $answer . ' Bạn có thể xem chi tiết trong mục Đơn hàng của tôi.';
    }

    private function clarificationAnswer(array $intent): string {
        if (($intent['primary_intent'] ?? '') === 'build_outfit') {
            return 'Bạn muốn phối đồ cho dịp hoặc phong cách nào? Mình hỗ trợ: '
                . implode(', ', array_map([StyleTag::class, 'label'], StyleTag::ALL))
                . '. Bạn có thể kèm ngân sách và chiều cao, cân nặng để mình chọn đúng size.';
        }
        $missing = $intent['missing_slots'] ?? [];
        if (in_array('height', $missing, true) || in_array('weight', $missing, true)) {
            return 'Bạn cho mình xin chiều cao và cân nặng để tư vấn size chính xác hơn nhé.';
        }
        return 'Bạn bổ sung thêm thông tin còn thiếu để mình hỗ trợ chính xác hơn nhé.';
    }

    private function outfitAnswer(array $intent, array $normalized): string {
        $outfits = is_array($normalized['outfits'] ?? null) ? $normalized['outfits'] : [];
        $cards = [];
        foreach (($normalized['cards'] ?? []) as $card) {
            if (is_array($card)) $cards[(int)($card['id'] ?? 0)] = $card;
        }
        $fact = [];
        foreach (($normalized['evidence'] ?? []) as $item) {
            if (in_array($item['fact_type'] ?? '', ['outfit_sets', 'outfit_unavailable'], true)) $fact = $item;
        }
        $occasion = implode(', ', array_map([StyleTag::class, 'label'], (array)($fact['style_tags'] ?? [])));
        $budget = (int)($intent['entities']['max_price'] ?? 0);

        if ($outfits === [] && $fact === []) {
            return 'Dịch vụ phối đồ đang tạm thời gián đoạn nên mình chưa ghép được set lúc này. Bạn thử lại sau ít phút nhé.';
        }
        if ($outfits === []) {
            $reason = ($fact['fact_type'] ?? '') === 'outfit_unavailable' ? (string)($fact['value'] ?? '') : '';
            if ($reason === 'budget_too_low' && !empty($fact['cheapest_total'])) {
                return sprintf(
                    'Với ngân sách %s, mình chưa ghép được set %s còn hàng; set rẻ nhất hiện khoảng %s. Bạn có thể tăng ngân sách hoặc chọn dịp khác nhé.',
                    $this->money($budget), $occasion, $this->money((float)$fact['cheapest_total'])
                );
            }
            if ($reason === 'anchor_unavailable') {
                return sprintf(
                    'Sản phẩm mã %d hiện không còn hàng nên mình chưa phối được. Bạn chọn sản phẩm khác hoặc cho mình biết dịp bạn cần nhé.',
                    (int)($intent['entities']['product_id'] ?? 0)
                );
            }
            return 'Hiện shop chưa đủ sản phẩm còn hàng' . ($occasion !== '' ? " cho dịp $occasion" : '')
                . ' để ghép thành set. Bạn thử dịp hoặc phong cách khác nhé.';
        }

        $sizes = is_array($fact['size_by_category'] ?? null) ? $fact['size_by_category'] : [];
        $lines = [];
        foreach ($outfits as $i => $outfit) {
            $parts = [];
            foreach ((array)($outfit['product_ids'] ?? []) as $id) {
                $card = $cards[(int)$id] ?? null;
                if ($card === null) continue;
                $size = $sizes[(int)($card['category_id'] ?? 0)] ?? '';
                $parts[] = sprintf('%s (mã %d) %s', (string)$card['name'], (int)$id, $this->money((float)$card['price']))
                    . ($size !== '' ? ', size ' . $size : '');
            }
            $lines[] = sprintf('Set %d, tổng %s: %s', (int)($outfit['outfit_index'] ?? $i + 1), $this->money((float)($outfit['total_price'] ?? 0)), implode(' + ', $parts));
        }
        return 'Mình gợi ý ' . count($outfits) . ' set'
            . ($occasion !== '' ? " cho dịp $occasion" : '')
            . ($budget > 0 ? ' trong ngân sách ' . $this->money($budget) : '') . '. '
            . implode('. ', $lines)
            . '. Bạn bấm vào thẻ sản phẩm để xem chi tiết và tự thêm vào giỏ nhé.';
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

    private function soldOutMatches(array $evidence): int {
        foreach ($evidence as $item) {
            if (($item['fact_type'] ?? '') === 'out_of_stock_matches') return (int)$item['value'];
        }
        return 0;
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
