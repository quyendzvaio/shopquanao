<?php

final class StructuredLogFashionMetrics implements FashionPipelineMetrics
{
    private const ALLOWED = [
        'fashion_extraction_calls_total',
        'fashion_extraction_success_total',
        'fashion_extraction_failure_total',
        'fashion_extraction_invalid_schema_total',
        'fashion_extraction_repair_attempts_total',
        'fashion_extraction_repair_success_total',
        'fashion_extraction_fast_path_total',
        'fashion_extraction_llm_path_total',
        'fashion_normalization_success_total',
        'fashion_normalization_unknown_category_total',
        'fashion_search_relaxation_total',
    ];

    public function increment(string $metric): void
    {
        if (!in_array($metric, self::ALLOWED, true)) return;
        error_log(json_encode(['metric' => $metric, 'increment' => 1], JSON_UNESCAPED_SLASHES));
    }
}
