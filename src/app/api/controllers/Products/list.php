<?php
/**
 * Products list API — tối ưu FULLTEXT + LIKE hybrid + composite indexes.
 * GET /api/products?search=&category=&min_price=&max_price=&sort=&in_stock=
 */

global $pdo;

jsonResponse((new ProductService($pdo, null, ServiceConfig::fromEnvironment()->rerankerUrl))->listPage($_GET));
