/**
 * Cloudflare Worker (free plan) that serves mzigo.online/microservice/*
 * and forwards to the origin server by IP — no second subdomain and no loop.
 *
 * The origin web server must reverse-proxy /mcp-internal/mcp and
 * /mcp-internal/health to 127.0.0.1:8484 on the Python service.
 *
 * Variables:
 *   MCP_ORIGIN_IP = 162.0.209.206
 *   MCP_HOST      = mzigo.online
 */
export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const originIp = (env.MCP_ORIGIN_IP || "162.0.209.206").replace(/\/+$/, "");
    const host = env.MCP_HOST || "mzigo.online";
    const path = "/mcp-internal" + url.pathname.replace(/^\/microservice/, "");
    const target = new URL("http://" + originIp + path + url.search);

    const headers = new Headers(request.headers);
    headers.set("Host", host);

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
