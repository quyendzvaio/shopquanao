# Tool Definitions — LLM Function Calling

## Tool: `search_products`
```json
{
  "name": "search_products",
  "description": "Tìm kiếm sản phẩm theo từ khóa, giá, danh mục. Dùng khi người dùng hỏi về sản phẩm cụ thể, tìm đồ, hỏi giá.",
  "parameters": {
    "type": "object",
    "properties": {
      "search": {"type": "string", "description": "Từ khóa tìm kiếm (tên sản phẩm)"},
      "category_id": {"type": "integer", "description": "ID danh mục: 1=Áo, 2=Quần, 3=Váy & Đầm, 4=Phụ kiện"},
      "min_price": {"type": "number", "description": "Giá thấp nhất"},
      "max_price": {"type": "number", "description": "Giá cao nhất"},
      "sort": {"type": "string", "enum": ["newest", "price_asc", "price_desc", "name_asc"]},
      "limit": {"type": "integer", "description": "Số kết quả tối đa (mặc định 5)"}
    }
  }
}
```
→ `GET /api/products?search=...&category=...&min_price=...&max_price=...&sort=...&limit=...`

## Tool: `get_product_detail`
```json
{
  "name": "get_product_detail",
  "description": "Lấy thông tin chi tiết của một sản phẩm (mô tả, giá, size, đánh giá). Dùng khi người dùng hỏi về sản phẩm cụ thể theo ID hoặc tên.",
  "parameters": {
    "type": "object",
    "properties": {
      "product_id": {"type": "integer", "description": "ID sản phẩm"}
    },
    "required": ["product_id"]
  }
}
```
→ `GET /api/products/{id}`

## Tool: `suggest_size`
```json
{
  "name": "suggest_size",
  "description": "Tư vấn size phù hợp dựa trên chiều cao và cân nặng. Dùng khi người dùng hỏi 'mặc size gì', 'chọn size', cung cấp chiều cao/cân nặng.",
  "parameters": {
    "type": "object",
    "properties": {
      "height": {"type": "integer", "description": "Chiều cao (cm)"},
      "weight": {"type": "integer", "description": "Cân nặng (kg)"},
      "category_id": {"type": "integer", "description": "ID danh mục: 1=Áo, 2=Quần, 3=Váy"}
    },
    "required": ["height", "weight"]
  }
}
```
→ `GET /api/size-guide?height=...&weight=...&category_id=...`

## Tool: `get_faq`
```json
{
  "name": "get_faq",
  "description": "Tra cứu câu hỏi thường gặp về vận chuyển, đổi trả, thanh toán, bảo hành.",
  "parameters": {
    "type": "object",
    "properties": {
      "category": {"type": "string", "enum": ["shipping", "return", "payment", "warranty", "wholesale", "general"], "description": "Danh mục câu hỏi"},
      "search": {"type": "string", "description": "Từ khóa tìm kiếm"}
    }
  }
}
```
→ `GET /api/faq?category=...&search=...`

## Tool: `get_outfit`
```json
{
  "name": "get_outfit",
  "description": "Gợi ý phối đồ. Dùng khi người dùng hỏi 'mặc với gì', 'phối đồ', 'kết hợp'.",
  "parameters": {
    "type": "object",
    "properties": {
      "product_id": {"type": "integer", "description": "ID sản phẩm cần phối đồ"},
      "search": {"type": "string", "description": "Tên sản phẩm cần phối đồ"}
    }
  }
}
```
→ `GET /api/outfit?product_id=...&search=...`

## Tool: `get_order_status`
```json
{
  "name": "get_order_status",
  "description": "Lấy thông tin và trạng thái đơn hàng. YÊU CẦU ĐĂNG NHẬP. Dùng khi người dùng hỏi về đơn hàng cụ thể.",
  "parameters": {
    "type": "object",
    "properties": {
      "order_id": {"type": "integer", "description": "ID đơn hàng"}
    },
    "required": ["order_id"]
  }
}
```
→ `GET /api/orders/{id}` (cần Bearer token)

## Tool: `get_my_orders`
```json
{
  "name": "get_my_orders",
  "description": "Lấy danh sách đơn hàng của người dùng đang đăng nhập. YÊU CẦU ĐĂNG NHẬP. Dùng khi người dùng hỏi 'đơn hàng của tôi', 'lịch sử mua hàng'.",
  "parameters": {
    "type": "object",
    "properties": {
      "status": {"type": "string", "enum": ["all", "Chờ xử lý", "Đang giao", "Đã hoàn thành", "Đã hủy"], "description": "Lọc theo trạng thái (mặc định all)"}
    }
  }
}
```
→ `GET /api/orders?status=...` (cần Bearer token)

## Tool: `get_cart`
```json
{
  "name": "get_cart",
  "description": "Xem giỏ hàng hiện tại. YÊU CẦU ĐĂNG NHẬP. Dùng khi người dùng hỏi 'giỏ hàng', 'xem giỏ'.",
  "parameters": {
    "type": "object",
    "properties": {}
  }
}
```
→ `GET /api/cart` (cần Bearer token)

## Tool: `get_categories`
```json
{
  "name": "get_categories",
  "description": "Lấy danh sách danh mục sản phẩm.",
  "parameters": {
    "type": "object",
    "properties": {}
  }
}
```
→ `GET /api/categories`
