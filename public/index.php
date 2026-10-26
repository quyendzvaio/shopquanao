<?php
require_once __DIR__ . '/../src/bootstrap.php';
$session = [];
$result = (new HomePage(new ProductService($pdo), new CategoryService($pdo)))->handle(WebRequest::fromGlobals(), $session);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../templates/partials/header.php';
include __DIR__ . '/../templates/pages/home.php';
include __DIR__ . '/../templates/partials/footer.php';
