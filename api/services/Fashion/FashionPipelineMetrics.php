<?php

interface FashionPipelineMetrics
{
    public function increment(string $metric): void;
}
