<?php

interface ChatbotToolGateway
{
    public function getDefinitions(): array;

    public function execute(string $toolName, array $arguments): array;
}
