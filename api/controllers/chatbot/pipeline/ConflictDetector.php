<?php

class ConflictDetector {
    public function detect(array $partial): array {
        $conflicts = is_array($partial['conflicts'] ?? null) ? $partial['conflicts'] : [];
        $candidates = is_array($partial['parser_metadata']['field_candidates'] ?? null)
            ? $partial['parser_metadata']['field_candidates']
            : [];

        foreach ($candidates as $field => $values) {
            if (!is_array($values) || count($values) < 2) {
                continue;
            }

            $distinct = [];
            foreach ($values as $value) {
                if (!is_array($value)) continue;
                $distinct[(string)($value['value'] ?? '')] = true;
            }
            if (count($distinct) < 2) {
                continue;
            }

            $conflicts[] = [
                'field' => (string)$field,
                'values' => array_values(array_map(fn($value) => [
                    'value' => $value['value'] ?? null,
                    'position' => (int)($value['position'] ?? 0),
                    'text' => (string)($value['text'] ?? ''),
                ], $values)),
            ];
        }

        return $this->dedupe($conflicts);
    }

    private function dedupe(array $conflicts): array {
        $seen = [];
        $out = [];
        foreach ($conflicts as $conflict) {
            $key = sha1(json_encode($conflict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $conflict;
        }
        return $out;
    }
}
