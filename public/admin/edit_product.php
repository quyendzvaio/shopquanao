<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../src/bootstrap.php';

$result = (new AdminEditProductPage(new ProductAdminService($pdo), new CategoryService($pdo), __DIR__ . '/../assets/images/'))
    ->handle(WebRequest::fromGlobals(), $_SESSION);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../../templates/pages/admin/edit_product.php';
