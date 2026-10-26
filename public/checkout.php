<?php
require_once __DIR__ . '/../src/bootstrap.php';
include __DIR__ . '/../templates/partials/header.php';
$result = (new CheckoutPage(new CartService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../templates/pages/checkout.php';
include __DIR__ . '/../templates/partials/footer.php';