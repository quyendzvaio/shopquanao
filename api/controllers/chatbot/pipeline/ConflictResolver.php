<?php

class ConflictResolver {
    public function resolve(array $partial): array {
        $query = mb_strtolower((string)($partial['original_query'] ?? ''));
        $conflicts = is_array($partial['conflicts'] ?? null) ? $partial['conflicts'] : [];
        $resolved = [];
        $unresolved = [];

        foreach ($conflicts as $conflict) {
            $field = (string)($conflict['field'] ?? '');
            $values = is_array($conflict['values'] ?? null) ? $conflict['values'] : [];
            usort($values, fn($a, $b) => (int)($a['position'] ?? 0) <=> (int)($b['position'] ?? 0));

            if ($field !== '' && $this->hasCorrectionSignal($query) && $values !== []) {
                $last = $values[count($values) - 1];
                $resolved[$field] = [
                    'value' => $last['value'] ?? null,
                    'source' => 'conflict_resolver',
                    'confidence' => 0.82,
                    'locked' => true,
                    'reason' => 'user_correction_signal',
                ];
                continue;
            }

            $unresolved[] = $conflict;
        }

        return [
            'resolved_fields' => $resolved,
            'unresolved_conflicts' => $unresolved,
            'clarification_message' => $this->clarificationMessage($unresolved),
        ];
    }

    private function hasCorrectionSignal(string $query): bool {
        return (bool)preg_match('/à không|a khong|ý là|y la|thay bằng|thay bang|không phải|khong phai|chốt|chot/ui', $query);
    }

    private function clarificationMessage(array $conflicts): string {
        foreach ($conflicts as $conflict) {
            if (($conflict['field'] ?? '') === 'max_price') {
                $values = array_values(array_unique(array_map(
                    fn($item) => $this->money((float)($item['value'] ?? 0)),
                    is_array($conflict['values'] ?? null) ? $conflict['values'] : []
                )));
                if (count($values) >= 2) {
                    return 'Bạn muốn giới hạn ngân sách ở ' . implode(' hay ', $values) . '?';
                }
            }
        }
        return 'Mình thấy có thông tin hơi mâu thuẫn. Bạn xác nhận lại giúp mình một tiêu chí chính nhé.';
    }

    private function money(float $value): string {
        return number_format($value, 0, ',', '.') . 'đ';
    }
}
