<?php

$data = getJsonInput();
if (!is_array($data)) {
    $data = [];
}

global $pdo;

try {
    jsonResponse((new UserService($pdo))->registerViaApi($data), 201);
} catch (ApiError $error) {
    errorResponse($error->getMessage(), $error->getCode());
}
