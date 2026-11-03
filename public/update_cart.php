<?php
session_start();
require_once __DIR__ . '/../src/bootstrap.php';
(new UpdateCartAction(new CartService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION)->sendIfFinal();