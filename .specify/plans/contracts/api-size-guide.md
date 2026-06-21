# API Contract: GET /api/size-guide

Tư vấn size theo chiều cao, cân nặng, danh mục.

## Request

```
GET /api/size-guide?height=170&weight=65&category_id=1
```

| Param | Type | Required | Description |
|---|---|---|---|
| height | int | yes | Chiều cao (cm) |
| weight | int | yes | Cân nặng (kg) |
| category_id | int | no | Lọc theo danh mục (1=Áo, 2=Quần, 3=Váy) |

## Response 200

```json
{
  "recommended": {
    "size_name": "M",
    "description": "Áo size M: Cao 1m60-1m70, Nặng 55-65kg",
    "height_from": 160,
    "height_to": 170,
    "weight_from": 55,
    "weight_to": 65
  },
  "all_sizes": [
    {"size_name": "S", "height_from": 155, "height_to": 165, "weight_from": 45, "weight_to": 55, "description": "..."},
    {"size_name": "M", ...},
    {"size_name": "L", ...},
    {"size_name": "XL", ...}
  ],
  "category_id": 1
}
```

## Response 400

```json
{"error": true, "message": "Height and weight are required"}
```

## Logic

1. Query `size_guides` WHERE category_id = ? (hoặc tất cả nếu không có category_id)
2. Match size WHERE height BETWEEN height_from AND height_to AND weight BETWEEN weight_from AND weight_to
3. Trả về recommended + all_sizes
