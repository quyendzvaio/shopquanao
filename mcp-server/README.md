# Fashion Shop MCP server

TypeScript MCP server dùng `@modelcontextprotocol/sdk` và transport stdio. PHP chatbot khởi chạy process này trực tiếp; server không listen port mạng.

## Development

```bash
npm ci
npm test
npm run build
npm run dev
```

Required environment:

```env
SHOP_INTERNAL_URL=http://127.0.0.1/api/internal/mcp
MCP_SERVICE_TOKEN=replace-with-a-long-random-secret
MCP_REQUEST_TIMEOUT_MS=30000
MCP_PRINCIPAL_USER_ID=3
```

`MCP_SERVICE_TOKEN` phải giống cấu hình của PHP app. `MCP_PRINCIPAL_USER_ID` do PHP gateway tự truyền từ session đã xác thực; không nhận từ model/tool arguments.

Khi chạy qua Docker Compose, Node runtime và build output được đóng gói thẳng vào image PHP tại `/opt/mcp-server`; không có service MCP riêng và không có endpoint OAuth/public.
