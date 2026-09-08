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

            // SRS FR-011: only same-scope candidates can conflict. A product
            // budget and a shipping threshold are different facts even when
            // both parse as max_price.
            foreach ($this->groupByScope($values) as $scope => $scoped) {
                if (count($scoped) < 2) {
                    continue;
                }
                $distinct = [];
                foreach ($scoped as $value) {
                    if (!is_array($value)) continue;
                    $distinct[(string)($value['value'] ?? '')] = true;
                }
                if (count($distinct) < 2) {
                    continue;
                }

                $conflicts[] = [
                    'field' => (string)$field,
                    'scope' => $scope,
                    'values' => array_values(array_map(fn($value) => [
                        'value' => $value['value'] ?? null,
                        'position' => (int)($value['position'] ?? 0),
                        'text' => (string)($value['text'] ?? ''),
                    ], $scoped)),
                ];
            }
        }

        return $this->dedupe($conflicts);
    }

    /** @return array<string, array> */
    private function groupByScope(array $values): array {
        $groups = [];
        foreach ($values as $value) {
            $scope = is_array($value) ? (string)($value['scope'] ?? 'product') : 'product';
            $groups[$scope][] = $value;
        }
        return $groups;
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
