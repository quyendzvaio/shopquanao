<?php

/**
 * Harness middleware, in run order: beforeTurn stops at the first blocker,
 * index 0 is the outermost model-call wrapper, afterTurn runs top to bottom.
 * Each class is built by AgentFactory::makeMiddleware().
 */
return [
    'middleware' => [],
];
