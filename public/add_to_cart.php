<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/bootstrap.php';

(new AddToCartAction(new CartService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION)->sendIfFinal();
