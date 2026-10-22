<?php

$user = authenticate();
$userId = (int)$user['id'];

global $pdo;
jsonResponse((new CartService($pdo))->list($userId));
