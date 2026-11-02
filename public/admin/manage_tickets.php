<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../../src/bootstrap.php';

$result = (new AdminTicketsPage(new SupportTicketService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION);
$result->sendIfFinal();
extract($result->data());
include __DIR__ . '/../../templates/pages/admin/tickets.php';
