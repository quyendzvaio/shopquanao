<?php
// Load DB config from parent
require_once __DIR__ . '/../config/db.php';

/**
 * Return the client-cache TTL for public, non-personalized GET endpoints.
 * Chatbot responses and account data deliberately remain no-store.
 */
function clientCacheTtl(): ?int {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return null;
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return null;

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $public = preg_match('#^/api/(products(?:/\\d+)?|categories|knowledge/search|size-guide|faq)$#', $path);
    if (!$public) return null;

    $ttl = (int)(getenv('CLIENT_CACHE_TTL') ?: ($_ENV['CLIENT_CACHE_TTL'] ?? 60));
    return max(0, $ttl);
}

function jsonResponse($data, $statusCode = 200) {
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        $body = json_encode(['error' => true, 'message' => 'Unable to encode response']);
        $statusCode = 500;
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    $ttl = ($statusCode >= 200 && $statusCode < 300) ? clientCacheTtl() : null;
    if ($ttl !== null) {
        $stale = (int)(getenv('CLIENT_CACHE_STALE_TTL') ?: ($_ENV['CLIENT_CACHE_STALE_TTL'] ?? 30));
        $etag = '"' . hash('sha256', $body) . '"';
        header('Cache-Control: public, max-age=' . $ttl . ', stale-while-revalidate=' . max(0, $stale));
        header('ETag: ' . $etag);
        header('Vary: Accept-Encoding');
        if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            header('Content-Length: 0');
            exit;
        }
    } else {
        header('Cache-Control: no-store');
    }

    echo $body;
    exit;
}

function errorResponse($message, $statusCode = 400) {
    jsonResponse(['error' => true, 'message' => $message], $statusCode);
}

function getJsonInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
        errorResponse('Invalid JSON body', 400);
    }
    return $input ?? [];
}

function getBearerToken() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/Bearer\s(\S+)/', $auth, $m)) {
        return $m[1];
    }
    return null;
}
