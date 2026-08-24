export const config = {
  shopInternalUrl: (process.env.SHOP_INTERNAL_URL ?? 'http://127.0.0.1/api/internal/mcp').replace(/\/$/, ''),
  serviceToken: process.env.MCP_SERVICE_TOKEN ?? '',
  requestTimeoutMs: Number(process.env.MCP_REQUEST_TIMEOUT_MS ?? 30_000),
};

export function requireConfiguration(): void {
  if (!config.serviceToken || config.serviceToken === 'change-me') {
    throw new Error('MCP_SERVICE_TOKEN must be configured with a non-default value');
  }
}
