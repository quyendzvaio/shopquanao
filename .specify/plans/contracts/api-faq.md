# API Contract: GET /api/faq

Lấy câu hỏi thường gặp.

## Request

```
GET /api/faq?category=shipping
```

| Param | Type | Required | Description |
|---|---|---|---|
| category | string | no | Lọc theo category (shipping, return, payment, warranty, wholesale, general) |
| search | string | no | Từ khóa tìm kiếm trong question/answer |

## Response 200

```json
{
  "faqs": [
    {
      "id": 1,
      "question": "Shop có giao hàng toàn quốc không?",
      "answer": "Có, chúng tôi giao hàng trên toàn quốc...",
      "category": "shipping",
      "priority": 1
    }
  ]
}
```

## Response 400

```json
{"error": true, "message": "Invalid category"}
```

Allowed categories: shipping, return, payment, warranty, wholesale, general
