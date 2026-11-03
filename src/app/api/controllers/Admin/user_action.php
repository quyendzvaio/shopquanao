<?php

$admin = requireAdmin();
$adminId = (int)$admin['id'];

global $pdo, $route_params;
$users = new UserService($pdo);
$userId = (int)($route_params['id'] ?? 0);
if (!$userId) errorResponse('User ID required', 400);

$data = getJsonInput();

$action = $data['action'] ?? ''; // lock, unlock, toggle_role
$newRole = $data['role'] ?? '';

if ($action === 'lock' && $userId !== $adminId) {
    if ($users->setStatus($userId, 0) === 0) errorResponse('User not found', 404);
    jsonResponse(['message' => 'User locked']);

} elseif ($action === 'unlock' && $userId !== $adminId) {
    if ($users->setStatus($userId, 1) === 0) errorResponse('User not found', 404);
    jsonResponse(['message' => 'User unlocked']);

} elseif ($action === 'toggle_role' && $userId !== $adminId) {
    if (!in_array($newRole, ['admin', 'user'])) {
        errorResponse('Invalid role. Must be admin or user', 400);
    }
    if ($users->setRole($userId, $newRole) === 0) errorResponse('User not found', 404);
    jsonResponse(['message' => "User role changed to $newRole"]);

} else {
    errorResponse('Invalid action or cannot modify yourself', 400);
}
