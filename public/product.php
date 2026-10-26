<?php
session_start();
require_once __DIR__ . '/../src/bootstrap.php';
$result = (new ProductPage(new ProductService($pdo), new ReviewService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../templates/partials/header.php';
include __DIR__ . '/../templates/pages/product.php';
include __DIR__ . '/../templates/partials/footer.php';
