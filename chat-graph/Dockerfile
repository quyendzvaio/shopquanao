# chat-graph: LangGraph agent service (private, no published port).
# Build context is the repo root because chat-graph compiles the shared
# ../mcp-server/src tool contracts (tsconfig rootDir is "..").
ARG NODE_IMAGE=node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32

FROM ${NODE_IMAGE} AS build
WORKDIR /build
COPY mcp-server/package.json mcp-server/package-lock.json mcp-server/
RUN npm --prefix mcp-server ci --no-audit --no-fund
COPY chat-graph/package.json chat-graph/package-lock.json chat-graph/
RUN npm --prefix chat-graph ci --no-audit --no-fund
COPY mcp-server/tsconfig.json mcp-server/
COPY mcp-server/src/ mcp-server/src/
COPY chat-graph/tsconfig.json chat-graph/
COPY chat-graph/src/ chat-graph/src/
RUN npm --prefix chat-graph run build \
    && npm --prefix chat-graph prune --omit=dev --ignore-scripts \
    && npm cache clean --force

FROM ${NODE_IMAGE} AS runtime
ENV NODE_ENV=production
WORKDIR /app
# The compiled dist/mcp-server/src/*.js lives under this dist, so the MCP SDK
# and zod resolve from this single prod node_modules.
COPY --from=build /build/chat-graph/package.json /build/chat-graph/package-lock.json ./
COPY --from=build /build/chat-graph/node_modules ./node_modules
COPY --from=build /build/chat-graph/dist ./dist
USER node
EXPOSE 8090
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=5 \
  CMD wget -qO- http://127.0.0.1:8090/health >/dev/null 2>&1 || exit 1
CMD ["node", "dist/chat-graph/src/server.js"]
