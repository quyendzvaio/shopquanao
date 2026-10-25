<?php
/**
 * API Login — xác thực qua database (password_verify).
 * Không hardcode credentials.
 */

$data = getJsonInput();
if (!is_array($data)) {
    $data = [];
}

global $pdo;

try {
    jsonResponse((new UserService($pdo))->loginViaApi($data));
} catch (ApiError $error) {
    errorResponse($error->getMessage(), $error->getCode());
}
