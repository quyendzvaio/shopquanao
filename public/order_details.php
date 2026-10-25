<?php
session_start();
require_once __DIR__ . '/../src/bootstrap.php';
$result = (new OrderDetailsPage(new OrderService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../templates/pages/order_details.php';