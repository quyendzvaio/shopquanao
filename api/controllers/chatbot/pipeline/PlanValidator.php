<?php

class PlanValidator {
    private array $capabilities;

    public function __construct(array $capabilities) {
        $this->capabilities = $capabilities;
    }

    public function validate(array $plan, array $intent): array {
        $errors = [];
        $sanitized = $plan;
        $lockedFields = $this->lockedFields($intent);

        foreach (($sanitized['batches'] ?? []) as $batchIndex => &$batch) {
            foreach ($batch as $callIndex => &$call) {
                $tool = (string)($call['tool'] ?? '');
                if ($tool === '' || !isset($this->capabilities[$tool])) {
                    $errors[] = "unknown_tool:$tool";
                    continue;
                }

                $capability = $this->capabilities[$tool];
                $args = is_array($call['args'] ?? null) ? $call['args'] : [];
                $allowed = array_keys($capability['input_schema']['properties'] ?? []);
                foreach (array_keys($args) as $arg) {
                    if (!in_array($arg, $allowed, true)) {
                        $errors[] = "unknown_argument:$tool.$arg";
                        unset($args[$arg]);
                    }
                }

                foreach (($capability['required_arguments'] ?? []) as $required) {
                    if (!array_key_exists($required, $args) || $args[$required] === '' || $args[$required] === null) {
                        $errors[] = "missing_required:$tool.$required";
                    }
                }

                foreach ($this->toolFieldMap($tool) as $arg => $field) {
                    if (isset($args[$arg], $lockedFields[$field]) && (string)$args[$arg] !== (string)$lockedFields[$field]) {
                        $errors[] = "locked_field_changed:$field";
                    }
                }

                $call['args'] = $args;
                $call['capability'] = $tool;
                $call['validation'] = ['checked' => true];
            }
        }
        unset($batch, $call);

        return [
            'passed' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'sanitized_plan' => $sanitized,
        ];
    }

    private function lockedFields(array $intent): array {
        $locked = [];
        foreach (($intent['merged_fields'] ?? []) as $field => $metadata) {
            if (is_array($metadata) && ($metadata['locked'] ?? false)) {
                $locked[(string)$field] = $metadata['value'] ?? null;
            }
        }
        return $locked;
    }

    private function toolFieldMap(string $tool): array {
        return match ($tool) {
            'get_product_detail' => ['product_id' => 'product_id'],
            'search_products' => [
                'min_price' => 'min_price',
                'max_price' => 'max_price',
                'category_id' => 'category_id',
                'color' => 'color',
                'size' => 'size',
                'in_stock' => 'in_stock',
            ],
            'suggest_size' => ['height' => 'height_cm', 'weight' => 'weight_kg', 'category_id' => 'category_id'],
            'get_order_status' => ['order_id' => 'order_id'],
            default => [],
        };
    }
}
