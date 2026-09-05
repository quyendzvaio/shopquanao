import { createServer } from 'node:http';
import { AgentOrchestrator } from './agent/orchestrator.js';
import { AgentTurnRequestSchema } from './agent/schemas.js';

const host = process.env.AGENT_ORCHESTRATOR_HOST || '0.0.0.0';
const port = Number(process.env.AGENT_ORCHESTRATOR_PORT || 8092);
const serviceToken = process.env.AGENT_SERVICE_TOKEN || process.env.MCP_SERVICE_TOKEN || '';
const orchestrator = await AgentOrchestrator.create();

const server = createServer(async (request, response) => {
  response.setHeader('content-type', 'application/json; charset=utf-8');
  if (request.method === 'GET' && request.url === '/healthz') {
    response.end(JSON.stringify({ status: 'ok', orchestrator: 'langgraph' }));
    return;
  }
  if (request.method !== 'POST' || request.url !== '/invoke') {
    response.statusCode = 404; response.end(JSON.stringify({ message: 'Not found' })); return;
  }
  if (!serviceToken || request.headers['x-agent-service-token'] !== serviceToken) {
    response.statusCode = 403; response.end(JSON.stringify({ message: 'Forbidden' })); return;
  }
  try {
    const chunks: Buffer[] = [];
    let bytes = 0;
    for await (const chunk of request) {
      bytes += chunk.length;
      if (bytes > 64 * 1024) throw new Error('Request body too large');
      chunks.push(chunk);
    }
    const input = AgentTurnRequestSchema.parse(JSON.parse(Buffer.concat(chunks).toString('utf8')));
    response.end(JSON.stringify(await orchestrator.invokeAgentTurn(input)));
  } catch (error) {
    const name = error instanceof Error ? error.name : 'UnknownError';
    const statusCode = name === 'ZodError' || error instanceof SyntaxError ? 400 : 502;
    console.error(JSON.stringify({
      operation: 'agent.invoke',
      success: false,
      error_name: name,
      error_message: error instanceof Error ? error.message : 'Agent invocation failed',
    }));
    response.statusCode = statusCode;
    response.end(JSON.stringify({
      code: statusCode === 400 ? 'BAD_AGENT_REQUEST' : 'AGENT_INVOCATION_FAILED',
      message: statusCode === 400 ? 'Invalid agent request' : 'Agent invocation failed',
    }));
  }
});

server.listen(port, host, () => process.stdout.write(`LangGraph agent listening on ${host}:${port}\n`));
