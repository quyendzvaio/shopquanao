<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../src/bootstrap.php';
$result = (new RegisterPage(new UserService($pdo)))->handle(WebRequest::fromGlobals());
$result->sendIfFinal();
include __DIR__ . '/../templates/partials/header.php';
extract($result->data());
include __DIR__ . '/../templates/pages/register.php';
include __DIR__ . '/../templates/partials/footer.php';