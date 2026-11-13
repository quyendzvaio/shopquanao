#!/usr/bin/env python3
"""Export the two RAG models to ONNX FP16 for ONNX Runtime (build-time only).

Produces (in the volume dir passed as argv[1], default /models/onnx):
  bi-encoder-fp16/model.onnx + tokenizer files   — vietnamese-bi-encoder
  phoranker-fp16/model.onnx  + tokenizer files   — PhoRanker cross-encoder
  quality_report.json                            — cosine / rank agreement vs fp32 PyTorch

Quality gate: embedding mean cosine >= 0.999 and reranker top-1 rank agreement == 1.0,
else exit 1 so the Docker build fails.

Why FP16 and not INT8 (measured on this pair, same sample set):
  embedding  int8 cos 0.925 | fp16 cos 0.999999
  reranker   int8 cos 0.575, top-1 agreement 0.08 (results reordered) | fp16 cos 1.000000, agreement 1.00
FP16 halves the fp32 weights, keeps ranking intact, and needs no calibration data.

Models are downloaded from the HF hub at build time and are NOT committed to git.
"""
import json
import math
import os
import sys
import time

import numpy as np
import onnx
import onnxruntime as ort
import torch
from onnxconverter_common import float16
from transformers import AutoModel, AutoModelForSequenceClassification, AutoTokenizer

OUT = sys.argv[1] if len(sys.argv) > 1 else "/models/onnx"
EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "bkai-foundation-models/vietnamese-bi-encoder")
RERANKER_MODEL = os.getenv("KNOWLEDGE_RERANKER_MODEL", "itdainb/PhoRanker")

EMB_GATE = 0.999
RANK_GATE = 1.0

SAMPLES = [
    "Shop đổi trả trong bao lâu và cần điều kiện gì?",
    "Áo polo đỏ dưới 400k có hàng không?",
    "Miễn phí vận chuyển cho đơn hàng từ 500.000đ",
    "Cao 1m65 nặng 55kg nên mặc size nào?",
    "Quần baggy ống rộng phong cách streetstyle",
    "Tôi muốn đổi size đơn hàng mã ORD-123 vì áo bị rộng",
    "Áo len cổ tròn xám giá bao nhiêu tiền",
    "Váy hai dây còn màu đen không?",
    "Phí ship về Hà Nội là bao nhiêu?",
    "Chính sách hoàn tiền khi hàng lỗi",
    "Có size XL cho quần kaki không?",
    "Thời gian giao hàng nội thành mấy ngày?",
]
QUERY = "đổi trả như thế nào"


def mean_cosine(a: np.ndarray, b: np.ndarray) -> float:
    a = a.reshape(len(a), -1).astype(np.float64)
    b = b.reshape(len(b), -1).astype(np.float64)
    num = (a * b).sum(axis=1)
    den = np.linalg.norm(a, axis=1) * np.linalg.norm(b, axis=1)
    return float(np.mean(num / np.where(den == 0.0, 1e-12, den)))


def rank_agreement(a: np.ndarray, b: np.ndarray) -> float:
    order_a, order_b = np.argsort(-a), np.argsort(-b)
    pos_b = {v: i for i, v in enumerate(order_b)}
    return sum(1 for i, v in enumerate(order_a) if pos_b[v] == i) / len(order_a)


def to_fp16(src: str, dst: str) -> None:
    model = onnx.load(src)
    model16 = float16.convert_float_to_float16(model, keep_io_types=True, disable_shape_infer=True)
    onnx.save(model16, dst)


def save_tokenizer(tokenizer, onnx_dir: str) -> None:
    tokenizer.save_pretrained(onnx_dir)


def export_encoder(name: str, out_dir: str) -> dict:
    tokenizer = AutoTokenizer.from_pretrained(name)
    model = AutoModel.from_pretrained(name).eval()

    onnx_dir = os.path.join(OUT, out_dir)
    os.makedirs(onnx_dir, exist_ok=True)
    fp32_path = os.path.join(onnx_dir, "model-fp32.onnx")
    model_path = os.path.join(onnx_dir, "model.onnx")

    dummy = tokenizer("kiểm tra xuất mô hình", return_tensors="pt", padding="max_length", max_length=128)
    torch.onnx.export(
        model,
        (dummy["input_ids"], dummy["attention_mask"]),
        fp32_path,
        input_names=["input_ids", "attention_mask"],
        output_names=["last_hidden_state"],
        dynamic_axes={
            "input_ids": {0: "batch", 1: "seq"},
            "attention_mask": {0: "batch", 1: "seq"},
            "last_hidden_state": {0: "batch", 1: "seq"},
        },
        opset_version=14,
        do_constant_folding=True,
    )
    onnx.checker.check_model(onnx.load(fp32_path))
    to_fp16(fp32_path, model_path)
    os.remove(fp32_path)
    save_tokenizer(tokenizer, onnx_dir)
    return {"tokenizer": tokenizer, "model_path": model_path, "fp32_model": model}


def export_cross_encoder(name: str, out_dir: str) -> dict:
    tokenizer = AutoTokenizer.from_pretrained(name)
    model = AutoModelForSequenceClassification.from_pretrained(name).eval()

    onnx_dir = os.path.join(OUT, out_dir)
    os.makedirs(onnx_dir, exist_ok=True)
    fp32_path = os.path.join(onnx_dir, "model-fp32.onnx")
    model_path = os.path.join(onnx_dir, "model.onnx")

    dummy = tokenizer("kiểm tra", "văn bản so sánh", return_tensors="pt", padding="max_length", max_length=192)
    torch.onnx.export(
        model,
        (dummy["input_ids"], dummy["attention_mask"], dummy["token_type_ids"]),
        fp32_path,
        input_names=["input_ids", "attention_mask", "token_type_ids"],
        output_names=["logits"],
        dynamic_axes={
            "input_ids": {0: "batch", 1: "seq"},
            "attention_mask": {0: "batch", 1: "seq"},
            "token_type_ids": {0: "batch", 1: "seq"},
            "logits": {0: "batch"},
        },
        opset_version=14,
        do_constant_folding=True,
    )
    onnx.checker.check_model(onnx.load(fp32_path))
    to_fp16(fp32_path, model_path)
    os.remove(fp32_path)
    save_tokenizer(tokenizer, onnx_dir)
    return {"tokenizer": tokenizer, "model_path": model_path, "fp32_model": model}


def mean_pool(hidden: np.ndarray, mask: np.ndarray) -> np.ndarray:
    m = mask[..., None].astype(np.float32)
    pooled = (hidden * m).sum(axis=1) / np.clip(m.sum(axis=1), 1e-9, None)
    return pooled / np.clip(np.linalg.norm(pooled, axis=1, keepdims=True), 1e-12, None)


def main() -> None:
    started = time.time()
    emb = export_encoder(EMBEDDING_MODEL, "bi-encoder-fp16")
    rr = export_cross_encoder(RERANKER_MODEL, "phoranker-fp16")
    export_seconds = round(time.time() - started, 1)

    so = ort.SessionOptions()
    so.intra_op_num_threads = max(1, (os.cpu_count() or 2) - 1)

    # Reference: the fp32 PyTorch outputs on the same samples.
    tok_e = emb["tokenizer"]
    e = tok_e(SAMPLES, return_tensors="pt", padding=True, truncation=True, max_length=128)
    with torch.no_grad():
        ref_vecs = mean_pool(emb["fp32_model"](**e).last_hidden_state.numpy(), e["attention_mask"].numpy())

    sess_e = ort.InferenceSession(emb["model_path"], so)
    out = sess_e.run(["last_hidden_state"], {
        "input_ids": e["input_ids"].numpy().astype(np.int64),
        "attention_mask": e["attention_mask"].numpy().astype(np.int64),
    })[0]
    emb_cos = mean_cosine(ref_vecs, mean_pool(out, e["attention_mask"].numpy()))

    tok_r = rr["tokenizer"]
    pairs = [(QUERY, s) for s in SAMPLES]
    e2 = tok_r([q for q, _ in pairs], [t for _, t in pairs], return_tensors="pt", padding=True, truncation=True, max_length=192)
    with torch.no_grad():
        ref_scores = rr["fp32_model"](**e2).logits.reshape(-1).numpy()

    sess_r = ort.InferenceSession(rr["model_path"], so)
    got = sess_r.run(["logits"], {
        "input_ids": e2["input_ids"].numpy().astype(np.int64),
        "attention_mask": e2["attention_mask"].numpy().astype(np.int64),
        "token_type_ids": e2["token_type_ids"].numpy().astype(np.int64),
    })[0].reshape(-1)
    rr_cos = mean_cosine(ref_scores.reshape(1, -1), got.reshape(1, -1))
    rr_rank = rank_agreement(ref_scores, got)

    sizes = {
        "bi_encoder_mb": round(os.path.getsize(emb["model_path"]) / 1e6, 1),
        "phoranker_mb": round(os.path.getsize(rr["model_path"]) / 1e6, 1),
    }
    passed = emb_cos >= EMB_GATE and rr_rank >= RANK_GATE
    report = {
        "precision": "fp16",
        "embedding_model": EMBEDDING_MODEL,
        "reranker_model": RERANKER_MODEL,
        "gates": {"embedding_mean_cosine": EMB_GATE, "reranker_top1_rank_agreement": RANK_GATE},
        "quality": {
            "embedding_mean_cosine": round(emb_cos, 6),
            "reranker_score_mean_cosine": round(rr_cos, 6),
            "reranker_top1_rank_agreement": round(rr_rank, 6),
        },
        "sizes": sizes,
        "export_seconds": export_seconds,
        "gate_passed": passed,
    }
    with open(os.path.join(OUT, "quality_report.json"), "w") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)
    print(json.dumps(report, indent=2, ensure_ascii=False))

    if not passed:
        print(
            f"QUALITY GATE FAILED: embedding cos {emb_cos:.6f} (>= {EMB_GATE}), "
            f"reranker rank agreement {rr_rank:.3f} (>= {RANK_GATE})",
            file=sys.stderr,
        )
        sys.exit(1)
    print("QUALITY GATE PASSED")


if __name__ == "__main__":
    main()
