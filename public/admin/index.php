<?php
session_start();

require_once __DIR__ . '/../../src/bootstrap.php';

$result = (new AdminDashboardPage(new AdminDashboardService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../../templates/pages/admin/dashboard.php';
