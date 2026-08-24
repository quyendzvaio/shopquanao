import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { config, requireConfiguration } from './config.js';
import { createMcpServer } from './tools.js';

requireConfiguration();

const rawUserId = process.env.MCP_PRINCIPAL_USER_ID ?? '';
const userId = /^\d+$/.test(rawUserId) && Number(rawUserId) > 0 ? Number(rawUserId) : null;
const server = createMcpServer({
  userId,
  mode: 'service',
  scopes: ['shop.read', 'shop.write'],
});

const transport = new StdioServerTransport();
await server.connect(transport);

// stdout is reserved for MCP JSON-RPC frames. Diagnostics must use stderr.
process.stderr.write(`Fashion Shop MCP stdio ready (user=${userId ?? 'guest'}, timeout=${config.requestTimeoutMs}ms)\n`);
