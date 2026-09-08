<?php

declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));

require_once ROOT_DIR . '/api/controllers/chatbot/ProductAttributeNormalizer.php';
require_once ROOT_DIR . '/api/controllers/chatbot/llm/LLMProvider.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/PartialParseResult.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/DeterministicIntentParser.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ConflictDetector.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ConflictResolver.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/IntentResolver.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ResponseGenerator.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ChatbotToolManifest.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/TurnTierGate.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/CapabilityRegistry.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/ToolPlanner.php';
require_once ROOT_DIR . '/api/controllers/chatbot/pipeline/PlanValidator.php';
require_once ROOT_DIR . '/config/chatbot_tools.php';
