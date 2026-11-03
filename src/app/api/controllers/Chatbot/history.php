<?php
/**
 * Chatbot History API
 * GET /api/chatbot/history
 * Bearer token required. Returns full chat history for logged-in user.
 */

authenticate();

global $pdo;

jsonResponse((new ChatHistoryService($pdo))->forRequest(null, getBearerToken()));
