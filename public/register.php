<?php
require_once __DIR__ . '/../src/bootstrap.php';
include __DIR__ . '/../templates/partials/header.php';

$result = (new RegisterPage(new UserService($pdo)))->handle(WebRequest::fromGlobals());
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../templates/pages/register.php';
include __DIR__ . '/../templates/partials/footer.php';