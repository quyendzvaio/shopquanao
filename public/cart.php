<?php
require_once __DIR__ . '/../src/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$result = (new CartPage(new CartService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../templates/partials/header.php';
include __DIR__ . '/../templates/pages/cart.php';
include __DIR__ . '/../templates/partials/footer.php';