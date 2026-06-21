# API Contract: GET /api/outfit

Gợi ý phối đồ.

## Request

```
GET /api/outfit?product_id=50
```

| Param | Type | Required | Description |
|---|---|---|---|
| product_id | int | no | Lọc theo sản phẩm (tìm outfit chứa SP này) |
| search | string | no | Tìm theo tên sản phẩm ("áo thun trắng") |

Nếu không có param nào, trả về tất cả outfit.

## Response 200

```json
{
  "outfits": [
    {
      "id": 1,
      "product_id": 50,
      "product_name": "Áo Thun Cotton Basic Trắng",
      "product_price": 180000,
      "paired_product_id": 65,
      "paired_name": "Quần Jeans Slimfit Xanh Đậm",
      "paired_price": 690000,
      "paired_image": "qj_slim_01.jpg",
      "note": "Áo thun trắng + Quần jeans slimfit - Set cơ bản không thể thiếu"
    }
  ]
}
```

## Response 404

```json
{"error": true, "message": "No outfit found"}
```
