# Báo Cáo Đo Lại RAGAS Và LangSmith

Ngày chạy: 2026-08-01 (Asia/Ho_Chi_Minh)

## Cấu Hình Đánh Giá

- LLM judge: `deepseek-v4-flash`.
- Embedding evaluator: `bkai-foundation-models/vietnamese-bi-encoder` qua dịch vụ `rag-ml /embed`.
- Vector embedding: 768 chiều, chuẩn hóa L2 bằng 1.0, cùng model với quá trình truy vấn Qdrant.
- API key DeepSeek mới chỉ được truyền vào môi trường tiến trình test, không được ghi vào `.env`, source hoặc report.
- Smoke test judge: thành công.

## Kết Quả Bộ Chatbot 9 Scenarios

Nguồn test: `eval/chatbot_eval_cases.jsonl`

| Chỉ số | Kết quả |
| --- | ---: |
| Scenarios | 9 |
| Deterministic PASS | 9 |
| Deterministic FAIL | 0 |
| Latency trung bình | 25.78 ms |
| Latency p50 | 21 ms |
| Latency p95/max | 38 ms |
| Answer relevancy | 0.5098 |
| Faithfulness | 0.7167 |
| Context precision | 0.9861 |
| Context recall | 0.8333 |

`answer_relevancy` được tính trên 9/9 lượt. Các metric grounding được tính trên 6 lượt có evidence. Metric `faithfulness` của câu “Shop đổi trả trong bao lâu và cần điều kiện gì?” timeout; điểm faithfulness tổng hợp được tính từ các lượt hợp lệ còn lại.

### Response Text

1. **Shop đổi trả trong bao lâu và cần điều kiện gì?**

   `- Đổi trả trong 7 ngày từ khi nhận hàng - Sản phẩm còn nguyên tem mác, chưa qua sử dụng - Không áp dụng cho sản phẩm sale > 50% - Khách cần cung cấp mã đơn hàng, hình ảnh sản phẩm và lý do đổi trả - Nếu đổi size/màu do nhu cầu cá nhân, khách thanh toán phí vận chuyển hai chiều - Nếu sản phẩm lỗi từ shop hoặc giao sai mẫu/size/màu, shop chịu phí vận chuyển đổi trả - Thời gian xử lý yêu cầu đổi trả: 1-3 ngày làm việc sau khi shop nhận đủ thông tin`

2. **Tôi mua hàng sale 60% thì có đổi trả được không?**

   Chatbot trả cùng khối chính sách trên, có câu “Không áp dụng cho sản phẩm sale > 50%”, nhưng chưa kết luận trực tiếp rằng hàng sale 60% không được đổi trả.

3. **Nếu áo bị lỗi đường may và tôi cần đổi size thì ai chịu phí ship, xử lý trong mấy ngày?**

   Chatbot trả chính sách đầy đủ: lỗi từ shop thì shop chịu phí, xử lý trong 1-3 ngày làm việc sau khi nhận đủ thông tin.

4. **Đơn 300k thì phí ship thế nào, còn đơn trên 500k thì sao?**

   `Miễn phí giao hàng cho đơn từ 500,000đ. Đơn dưới 500,000đ phí ship là 30,000đ nội thành và 50,000đ ngoại tỉnh.`

5. **Có áo khoác dưới 600k không?**

   `Mình tìm thấy 2 sản phẩm áo khoác dưới 600.000đ. Bạn có thể bấm vào thẻ sản phẩm bên dưới để xem chi tiết.`

6. **Áo bomber nếu mua về không vừa size thì đổi được không, và áo đó còn hàng không?**

   Chatbot tìm thấy 1 áo khoác bomber còn hàng, sau đó trả khối chính sách đổi trả.

7. **Tôi cao 1m70 nặng 65kg mua áo thì nên chọn size gì?**

   `Với chiều cao 170cm và cân nặng 65kg, size M phù hợp hơn. Nếu bạn thích mặc rộng hoặc đang sát ngưỡng trên của size này, bạn có thể cân nhắc tăng một size.`

8. **Tôi muốn mua áo thun trắng size M, thêm vào giỏ và thanh toán giúp tôi.**

   `Mình không thể tự thêm giỏ hàng hoặc thanh toán giúp bạn. Bạn vui lòng bấm vào thẻ sản phẩm hoặc vào trang chi tiết sản phẩm để tự thêm giỏ hàng và thanh toán.`

9. **Áo thun trắng phối với quần gì cho đẹp?**

   `Hiện mình không hỗ trợ tư vấn phối đồ. Mình có thể hỗ trợ bạn tìm sản phẩm, xem chi tiết sản phẩm, tư vấn size và chính sách shop.`

## Kết Quả Bộ RAG 8 Cases

Nguồn test: `tests/eval/rag_eval_cases.json`

| Chỉ số | Kết quả |
| --- | ---: |
| Cases | 8 |
| Retrieval errors | 0 |
| Chat errors | 0 |
| Unexpected redirects | 0 |
| Retrieval latency trung bình | 6.67 ms |
| Retrieval latency p50 | 5.41 ms |
| Retrieval latency p95/max | 13.35 ms |
| Chat latency trung bình | 385.83 ms |
| Chat latency p50 | 23.66 ms |
| Chat latency p95/max | 1088.52 ms |
| Context keyword coverage | 0.7500 |
| Answer keyword coverage | 0.9688 |
| Answer relevancy | 0.3688 (8/8 hợp lệ) |
| Faithfulness | 0.6286 (7/8 hợp lệ) |
| Context precision | 0.6146 (8/8 hợp lệ) |
| Context recall | 0.5000 (8/8 hợp lệ) |

### Điểm Và Response Theo Case

| # | Query | Faithfulness | Relevancy | Precision | Recall |
| ---: | --- | ---: | ---: | ---: | ---: |
| 1 | Shop đổi trả trong bao lâu? | 1.0000 | 0.5306 | 1.0000 | 1.0000 |
| 2 | Hàng sale 60% không vừa size có đổi được không? | 1.0000 | 0.2432 | 1.0000 | 1.0000 |
| 3 | Shop giao sai size thì ai chịu phí đổi trả? | 1.0000 | 0.4330 | 1.0000 | 1.0000 |
| 4 | Hoàn tiền mất mấy ngày? | 0.0000 | 0.4096 | 0.0000 | 0.0000 |
| 5 | Đơn 300k giao ngoại tỉnh phí ship bao nhiêu? | 1.0000 | 0.2473 | 0.9167 | 1.0000 |
| 6 | Có áo khoác dưới 600k không? | 0.0000 | 0.7207 | 0.0000 | 0.0000 |
| 7 | Áo bomber giao sai size thì đổi thế nào? | null | 0.3663 | 1.0000 | 0.0000 |
| 8 | Áo thun trắng phối với quần gì đẹp? | 0.4000 | 0.0000 | 0.0000 | 0.0000 |

1. Case đổi trả trả đầy đủ chính sách 7 ngày, điều kiện tem mác và các ngoại lệ.
2. Case sale 60% lấy đúng rule nhưng response chưa đưa kết luận trực tiếp “không được đổi”.
3. Case giao sai size chứa đúng thông tin shop chịu phí, nhưng trả thêm nhiều chính sách không cần thiết.
4. Case hoàn tiền trả đúng “3-7 ngày”, nhưng contexts được lưu cho RAGAS chỉ chứa tài liệu đổi trả, không chứa tài liệu hoàn tiền. Vì vậy grounding nhận 0 dù response text đúng với ground truth.
5. Case phí ship trả đúng 50.000đ ngoại tỉnh và ngưỡng miễn phí 500.000đ.
6. Case áo khoác trả đúng 2 sản phẩm, nhưng contexts của script RAG là knowledge documents không liên quan, không bao gồm product evidence/card nên grounding nhận 0.
7. Case bomber có policy context tốt nhưng thiếu product evidence so với ground truth; faithfulness bị timeout và recall bằng 0.
8. Case phối đồ bị guardrail từ chối theo phạm vi hiện tại, trong khi kho RAG vẫn chứa tài liệu “Set cơ bản”. Đây là bất nhất giữa product policy và knowledge corpus.

## LangSmith

### `fashion-shop-chatbot-eval-ragml-newkey-20260801`

- Tổng runs: 135.
- `call_chatbot`: 9/9 thành công.
- `call_knowledge`: 5/5 thành công.
- Application errors: 0.
- Một lỗi metric: `faithfulness` timeout tại câu hỏi đổi trả cơ bản.

### `fashion-shop-rag-eval-ragml-newkey-20260801`

- Tổng runs: 118.
- 8 evaluation rows đã được ghi nhận.
- Dataset: `fashion-shop-rag-eval-ragml-newkey-20260801-dataset`.
- Dataset examples: 8.
- Application errors: 0.
- Một lỗi metric: `faithfulness` timeout tại câu hỏi áo bomber giao sai size.

## Nhận Xét Chất Lượng

- Đường embedding/retrieval hoạt động ổn định; không còn lỗi load embedding hay DeepSeek 402.
- `context_precision` 0.9861 ở bộ chatbot chính cho thấy evidence dùng bởi pipeline nhìn chung rất sát truy vấn.
- `answer_relevancy` còn thấp vì chatbot thường trả toàn bộ khối policy thay vì kết luận ngắn gọn theo câu hỏi cụ thể.
- Bộ RAG độc lập đang đánh tụt các case product/guardrail vì script lấy contexts từ knowledge retrieval riêng, không phản ánh đầy đủ product evidence mà chatbot thật sử dụng.
- Cần ưu tiên: tổng hợp câu trả lời policy theo query, bổ sung tài liệu hoàn tiền đúng category, loại tài liệu phối đồ nếu tính năng đã tắt, và đưa product evidence vào dataset RAGAS.

## Artifacts

- Chatbot JSON: `reports/chatbot_ragas_latest.json`.
- Chatbot Markdown: `reports/chatbot_ragas_latest.md`.
- RAG JSON: `reports/rag_ragas_latest.json`.
- RAG CSV: `reports/rag_ragas_latest.csv`.
