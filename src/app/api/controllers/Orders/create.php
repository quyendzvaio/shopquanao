<?php

$user = authenticate();
$userId = (int)$user['id'];

global $pdo;
try {
    jsonResponse((new OrderService($pdo))->create($userId), 201);
} catch (PDOException $error) {
    databaseErrorResponse($error);
} catch (RuntimeException $error) {
    errorResponse($error->getMessage(), $error instanceof EmptyCartException ? 400 : 409);
}
