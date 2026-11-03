<?php

/**
 * HTTP client for the internal chat-graph service (POST {url}/turn).
 *
 * stream=true reads NDJSON (progress/delta/replace/cards/complete) and calls $onEvent
 * for each event as it arrives; stream=false reads one JSON TurnResult. Both
 * return the same TurnResult array. Anything but a complete 200 answer is a
 * ChatGraphUnavailable, which ChatbotService turns into the busy answer.
 */
class ChatGraphClient
{
    public const EVENT_TYPES = ['progress', 'delta', 'replace', 'cards', 'complete'];

    /** @var Closure(string,list<string>,string,int,callable):int */
    private Closure $transport;

    /** @param Closure(string,list<string>,string,int,callable):int|null $transport (url, headers, body, timeout, onChunk) => HTTP status; curl by default */
    public function __construct(
        private string $url,
        private string $token,
        private int $timeoutSeconds,
        ?Closure $transport = null
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /**
     * @param array<string,mixed> $request a TurnRequest
     * @param (callable(array<string,mixed>):void)|null $onEvent stream events as they arrive
     * @return array<string,mixed> the TurnResult
     * @throws ChatGraphUnavailable
     */
    public function turn(array $request, ?callable $onEvent = null): array
    {
        $stream = ($request['stream'] ?? false) === true;
        $body = $this->encode($request);
        $headers = [
            'Content-Type: application/json',
            'Accept: ' . ($stream ? 'application/x-ndjson' : 'application/json'),
            'X-CHAT-GRAPH-TOKEN: ' . $this->token,
        ];
        $reader = new class ($stream, $onEvent) {
            public string $raw = '';
            public string $message = '';
            /** @var list<array<string,mixed>> */
            public array $products = [];
            /** @var array<string,mixed>|null */
            public ?array $complete = null;
            public bool $malformed = false;
            public ?Throwable $callbackFailure = null;

            public function __construct(private bool $stream, private mixed $onEvent)
            {
            }

            public function feed(string $chunk): void
            {
                $this->raw .= $chunk;
                if (!$this->stream) {
                    return;
                }
                while (($newline = strpos($this->raw, "\n")) !== false) {
                    $line = substr($this->raw, 0, $newline);
                    $this->raw = substr($this->raw, $newline + 1);
                    $this->line($line);
                }
            }

            public function finish(): void
            {
                if ($this->stream && trim($this->raw) !== '') {
                    $this->line($this->raw);
                    $this->raw = '';
                }
            }

            private function line(string $line): void
            {
                $line = trim($line);
                if ($line === '' || $this->malformed) {
                    return;
                }
                $event = json_decode($line, true);
                if (!is_array($event) || !is_string($event['type'] ?? null)) {
                    $this->malformed = true;
                    return;
                }
                if ($event['type'] === 'delta' && is_string($event['text'] ?? null)) {
                    $this->message .= $event['text'];
                } elseif ($event['type'] === 'replace' && is_string($event['text'] ?? null)) {
                    $this->message = $event['text'];
                } elseif ($event['type'] === 'cards' && is_array($event['products'] ?? null)) {
                    $this->products = array_values(array_filter($event['products'], 'is_array'));
                } elseif ($event['type'] === 'complete') {
                    $this->complete = $event;
                }
                if ($this->onEvent !== null && in_array($event['type'], ChatGraphClient::EVENT_TYPES, true)) {
                    try {
                        ($this->onEvent)($event);
                    } catch (Throwable $e) {
                        $this->callbackFailure = $e;
                        throw $e;
                    }
                }
            }
        };

        try {
            $status = ($this->transport)(
                rtrim($this->url, '/') . '/turn',
                $headers,
                $body,
                $this->timeoutSeconds,
                static function (string $chunk) use ($reader): void {
                    $reader->feed($chunk);
                }
            );
        } catch (ChatGraphUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($reader->callbackFailure !== null && $e === $reader->callbackFailure) {
                throw $e;
            }
            throw new ChatGraphUnavailable('transport', 'chat-graph request failed: ' . $e->getMessage(), $e);
        }
        if ($status !== 200) {
            throw new ChatGraphUnavailable('http_' . $status, 'chat-graph answered HTTP ' . $status);
        }
        $reader->finish();

        return $stream ? $this->streamResult($reader) : $this->jsonResult($reader->raw);
    }

    /** @return array<string,mixed> */
    private function streamResult(object $reader): array
    {
        if ($reader->malformed) {
            throw new ChatGraphUnavailable('malformed_stream', 'chat-graph sent a line that is not an NDJSON event');
        }
        if ($reader->complete === null) {
            throw new ChatGraphUnavailable('incomplete_stream', 'chat-graph stream ended without a complete event');
        }

        return $this->normalize(['message' => $reader->message, 'products' => $reader->products] + $reader->complete);
    }

    /** @return array<string,mixed> */
    private function jsonResult(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_string($decoded['message'] ?? null) || !is_string($decoded['response_type'] ?? null)) {
            throw new ChatGraphUnavailable('malformed_json', 'chat-graph body is not a TurnResult');
        }

        return $this->normalize($decoded);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function normalize(array $result): array
    {
        if (!is_string($result['response_type'] ?? null) || $result['response_type'] === '') {
            throw new ChatGraphUnavailable('malformed_result', 'chat-graph result has no response_type');
        }

        return [
            'message' => (string) $result['message'],
            'products' => is_array($result['products'] ?? null) ? array_values($result['products']) : [],
            'response_type' => $result['response_type'],
            'primary_intent' => is_string($result['primary_intent'] ?? null) ? $result['primary_intent'] : 'unknown',
            'tools_used' => is_array($result['tools_used'] ?? null) ? array_values($result['tools_used']) : [],
            'outfits' => is_array($result['outfits'] ?? null) ? array_values($result['outfits']) : [],
            'offered' => is_array($result['offered'] ?? null) ? array_values($result['offered']) : [],
            'freeship_nudge' => ($result['freeship_nudge'] ?? false) === true,
            'pending_ticket' => self::pendingTicket($result['pending_ticket'] ?? null),
            'freeship_fingerprint' => is_string($result['freeship_fingerprint'] ?? null) ? $result['freeship_fingerprint'] : null,
            'trace_id' => is_string($result['trace_id'] ?? null) ? $result['trace_id'] : null,
            'latency' => is_array($result['latency'] ?? null) ? $result['latency'] : [],
        ];
    }

    /**
     * A drafted support ticket is kept only in the shape SupportTicketService accepts: anything else is dropped.
     *
     * @return array{type:string,order_id:?int,summary:string}|null
     */
    private static function pendingTicket(mixed $pending): ?array
    {
        if (!is_array($pending)) {
            return null;
        }
        $type = $pending['type'] ?? null;
        $summary = $pending['summary'] ?? null;
        $orderId = $pending['order_id'] ?? null;
        if (!is_string($type) || !isset(SupportTicketService::TYPES[$type])) {
            return null;
        }
        if (!is_string($summary) || trim($summary) === '') {
            return null;
        }
        if ($orderId !== null && (!is_int($orderId) || $orderId <= 0)) {
            return null;
        }

        return ['type' => $type, 'order_id' => $orderId, 'summary' => $summary];
    }

    /** JSON body; node's schema wants memory.slots and memory.profile as objects, so an empty PHP array must not become []. */
    private function encode(array $request): string
    {
        foreach (['slots', 'profile'] as $key) {
            if (isset($request['memory'][$key]) && $request['memory'][$key] === []) {
                $request['memory'][$key] = new stdClass();
            }
        }

        return json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $headers
     * @param callable(string):void $onChunk
     */
    private static function curlTransport(string $url, array $headers, string $body, int $timeout, callable $onChunk): int
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ChatGraphUnavailable('transport', 'curl_init failed');
        }
        $failure = null;
        curl_setopt_array($ch, InternalHttp::CURL_OPTIONS + [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($onChunk, &$failure): int {
                // An error body (non-200) is not an event stream: count it, do not feed it.
                if (curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                    return strlen($chunk);
                }
                try {
                    $onChunk($chunk);
                } catch (Throwable $e) {
                    $failure = $e;
                    return 0;
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($failure !== null) {
            throw $failure;
        }
        if ($ok === false) {
            throw new ChatGraphUnavailable($errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport', 'chat-graph request failed: ' . $error);
        }

        return $status;
    }
}
