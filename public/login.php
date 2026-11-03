<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../src/bootstrap.php';
$result = (new LoginPage(new UserService($pdo), AppConfig::fromEnvironment()))->handle(WebRequest::fromGlobals(), $_SESSION);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../templates/partials/header.php';
include __DIR__ . '/../templates/pages/login.php';
include __DIR__ . '/../templates/partials/footer.php';