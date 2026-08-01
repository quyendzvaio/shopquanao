# Báo Cáo Chatbot Multi-Step RAGAS + LangSmith

Ngày chạy: 2026-08-01 13:24:36 +0700
Target: `http://localhost`
Case file: `eval/chatbot_eval_cases.jsonl`

## Tóm Tắt

| Metric | Value |
| --- | ---: |
| Scenarios | `9` |
| Turns | `9` |
| Deterministic passed | `9` |
| Deterministic failed | `0` |
| Latency min | `19 ms` |
| Latency avg | `25.78 ms` |
| Latency p50 | `21 ms` |
| Latency p95 | `38 ms` |
| Latency max | `38 ms` |

## RAGAS

| Metric | Value |
| --- | ---: |
| Faithfulness | `0.7166666666666667` |
| Answer relevancy | `0.5098129497095285` |
| Context precision | `0.9861111110546297` |
| Context recall | `0.8333333333333334` |

Notes:
- DeepSeek n=1 fan-out is enabled for evaluator model deepseek-v4-flash.
- RAGAS embeddings use rag-ml /embed with model bkai-foundation-models/vietnamese-bi-encoder.
- Evaluator embeddings use the same normalized vector model as Qdrant ingestion and query retrieval.
- answer_relevancy question generation is constrained to Vietnamese.
- answer_relevancy evaluated all 9 turns.
- grounding metrics evaluated 6 turns with evidence contexts.
- RAGAS contexts include RAG documents plus serialized product/order evidence when those tools are used.

## LangSmith

- Tracing bật qua biến môi trường `LANGSMITH_API_KEY`.
- Project: `fashion-shop-chatbot-eval-ragml-newkey-20260801`.
- API key không được ghi vào report hoặc source.

## Chi Tiết Turn

| Scenario | Turn | Type | Latency | Products | Knowledge | Contexts | Result | Failures |
| --- | ---: | --- | ---: | ---: | ---: | ---: | --- | --- |
| `policy_return_basic` | `1` | `rag` | `19 ms` | `0` | `3` | `4` | `PASS` | - |
| `policy_sale_return` | `1` | `rag` | `19 ms` | `0` | `3` | `4` | `PASS` | - |
| `policy_fault_shipping_multistep` | `1` | `rag` | `28 ms` | `0` | `3` | `4` | `PASS` | - |
| `shipping_fee_threshold` | `1` | `rag` | `20 ms` | `0` | `4` | `4` | `PASS` | - |
| `product_search_basic` | `1` | `product` | `21 ms` | `2` | `0` | `2` | `PASS` | - |
| `product_policy_multistep` | `1` | `mixed` | `33 ms` | `1` | `3` | `5` | `PASS` | - |
| `size_advice` | `1` | `size` | `20 ms` | `0` | `0` | `0` | `PASS` | - |
| `removed_checkout_behavior` | `1` | `guardrail` | `38 ms` | `0` | `0` | `0` | `PASS` | - |
| `removed_outfit_behavior` | `1` | `guardrail` | `34 ms` | `0` | `0` | `0` | `PASS` | - |

## Bảo Mật

- Không ghi `LANGSMITH_API_KEY`, `LLM_API_KEY`, `OPENAI_API_KEY` hoặc HuggingFace token vào file.
- HuggingFace embedding model chạy local public model; không dùng HuggingFace Inference API.
