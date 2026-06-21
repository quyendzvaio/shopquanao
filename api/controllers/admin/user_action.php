<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$admin = requireAdmin();
$adminId = (int)$admin['id'];

global $pdo, $route_params;
$userId = (int)($route_params['id'] ?? 0);
if (!$userId) errorResponse('User ID required', 400);

$data = getJsonInput();

$action = $data['action'] ?? ''; // lock, unlock, toggle_role
$newRole = $data['role'] ?? '';

if ($action === 'lock' && $userId !== $adminId) {
    $stmt = $pdo->prepare("UPDATE users SET status = 0 WHERE id = ?");
    $stmt->execute([$userId]);
    if ($stmt->rowCount() === 0) errorResponse('User not found', 404);
    jsonResponse(['message' => 'User locked']);

} elseif ($action === 'unlock' && $userId !== $adminId) {
    $stmt = $pdo->prepare("UPDATE users SET status = 1 WHERE id = ?");
    $stmt->execute([$userId]);
    if ($stmt->rowCount() === 0) errorResponse('User not found', 404);
    jsonResponse(['message' => 'User unlocked']);

} elseif ($action === 'toggle_role' && $userId !== $adminId) {
    if (!in_array($newRole, ['admin', 'user'])) {
        errorResponse('Invalid role. Must be admin or user', 400);
    }
    $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->execute([$newRole, $userId]);
    if ($stmt->rowCount() === 0) errorResponse('User not found', 404);
    jsonResponse(['message' => "User role changed to $newRole"]);

} else {
    errorResponse('Invalid action or cannot modify yourself', 400);
}
