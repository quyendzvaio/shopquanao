<?php

/**
 * Transport settings for PHP's curl calls to sibling Compose services (rag-ml, qdrant, reranker, chat-graph).
 *
 * The Compose network is IPv4. Resolving a service name for both families makes Docker's embedded DNS forward the
 * AAAA query to the host's upstream resolver, which measured 25-480 ms per lookup; IPv4-only resolution takes < 1 ms.
 * Every such curl handle starts from these options.
 */
final class InternalHttp
{
    public const CURL_OPTIONS = [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
}
