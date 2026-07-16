<?php

class PartialParseResult {
    private array $data;

    public function __construct(string $originalQuery) {
        $this->data = [
            'original_query' => $originalQuery,
            'resolved_fields' => [],
            'unresolved_spans' => [],
            'conflicts' => [],
            'missing_fields' => [],
            'parser_metadata' => [
                'matched_rules' => [],
                'coverage' => 0.0,
                'field_candidates' => [],
            ],
        ];
    }

    public static function fromArray(array $data): self {
        $result = new self((string)($data['original_query'] ?? ''));
        $result->data = array_replace_recursive($result->data, $data);
        return $result;
    }

    public function addResolvedField(
        string $name,
        mixed $value,
        string $source = 'rule_parser',
        float $confidence = 1.0,
        bool $locked = true
    ): void {
        $this->data['resolved_fields'][$name] = [
            'value' => $value,
            'source' => $source,
            'confidence' => $confidence,
            'locked' => $locked,
        ];
    }

    public function addUnresolvedSpan(
        string $text,
        array $expectedFields = [],
        bool $affectsExecution = true,
        string $type = 'semantic'
    ): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->data['unresolved_spans'][] = [
            'text' => $text,
            'expected_fields' => array_values(array_unique($expectedFields)),
            'affects_execution' => $affectsExecution,
            'type' => $type,
        ];
    }

    public function addConflict(array $conflict): void {
        $this->data['conflicts'][] = $conflict;
    }

    public function addMissingField(string $field): void {
        if (!in_array($field, $this->data['missing_fields'], true)) {
            $this->data['missing_fields'][] = $field;
        }
    }

    public function addMatchedRule(string $rule): void {
        if (!in_array($rule, $this->data['parser_metadata']['matched_rules'], true)) {
            $this->data['parser_metadata']['matched_rules'][] = $rule;
        }
    }

    public function addFieldCandidate(string $field, mixed $value, int $position, string $text): void {
        if (!isset($this->data['parser_metadata']['field_candidates'][$field])) {
            $this->data['parser_metadata']['field_candidates'][$field] = [];
        }
        $this->data['parser_metadata']['field_candidates'][$field][] = [
            'value' => $value,
            'position' => $position,
            'text' => $text,
        ];
    }

    public function setCoverage(float $coverage): void {
        $this->data['parser_metadata']['coverage'] = max(0.0, min(1.0, $coverage));
    }

    public function toArray(): array {
        return $this->data;
    }
}
