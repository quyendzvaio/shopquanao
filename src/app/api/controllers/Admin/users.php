<?php

requireAdmin();

global $pdo;
$users = (new UserService($pdo))->adminList();

foreach ($users as &$u) {
    $u['id'] = (int)$u['id'];
    $u['status'] = (int)$u['status'];
}

jsonResponse(['users' => $users]);
