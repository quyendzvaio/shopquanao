<?php
session_start();
require_once __DIR__ . '/../src/bootstrap.php';

(new PlaceOrderAction(new OrderService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION)->sendIfFinal();
