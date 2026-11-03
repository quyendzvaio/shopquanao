# Fashion Shop MCP server

TypeScript MCP server dùng `@modelcontextprotocol/sdk`. Trên đường chạy chính, `chat-graph` biên dịch `src/` của package này và gọi `createMcpServer(principal)` trong cùng process, nối bằng `InMemoryTransport` (mỗi lượt chat một server, `principal.userId` lấy từ request PHP). Các tool gọi ngược về PHP qua `POST /api/internal/mcp` (header `X-MCP-SERVICE-TOKEN`). PHP không còn khởi chạy process này và image PHP không còn Node; server không listen port mạng.

## Development

`npm run dev` và `npm start` chạy entry stdio (`src/stdio.ts`) độc lập, chỉ dùng để debug tool bằng một MCP client; không nằm trên đường chạy của chatbot.

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

`MCP_SERVICE_TOKEN` phải giống cấu hình của PHP app. Trong `chat-graph`, principal (user id) do code truyền từ request PHP đã xác thực; `MCP_PRINCIPAL_USER_ID` chỉ dùng cho entry stdio khi debug. Không bao giờ nhận principal từ model/tool arguments.

Khi chạy qua Docker Compose, code này nằm trong image `chat-graph` (build context là root repo, xem `chat-graph/Dockerfile`); không có service MCP riêng và không có endpoint OAuth/public.
