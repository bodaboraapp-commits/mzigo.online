/**
 * Cloudflare Worker (free plan) that proxies mzigo.online/microservice/*
 * to the Cloudflare Tunnel hostname that exposes the Python MCP service.
 *
 * Required secret/variable:
 *   MCP_ORIGIN = https://mcp-origin.mzigo.online
 */
export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const path = url.pathname.replace(/^\/microservice/, "");
    const origin = (env.MCP_ORIGIN || "https://mcp-origin.mzigo.online").replace(/\/+$/, "");
    const target = new URL(origin + path + url.search);

    const headers = new Headers(request.headers);
    headers.set("Host", target.host);

    const init = {
      method: request.method,
      headers,
      redirect: "follow",
    };

    if (request.method !== "GET" && request.method !== "HEAD") {
      init.body = request.body;
    }

    return fetch(target, init);
  },
};
