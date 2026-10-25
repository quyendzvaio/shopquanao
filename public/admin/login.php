<?php
/**
 * Admin Login — xác thực qua database hoặc env.
 * Không hardcode credentials.
 */
session_start();
require_once __DIR__ . '/../../src/bootstrap.php';

$result = (new AdminLoginPage(new UserService($pdo), AppConfig::fromEnvironment()))->handle(WebRequest::fromGlobals(), $_SESSION);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../../templates/pages/admin/login.php';
