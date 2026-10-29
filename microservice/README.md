# Mzigo MCP microservice

Privacy-enforcing MCP microservice for Mzigo.online.

- `server.py` — dependency-free Python HTTP server that speaks MCP JSON-RPC.
- `config.json` — runtime policy, written automatically by **Admin → Settings → MCP microservice privacy**. Do not edit by hand and never commit (it is gitignored).
- `data/audit.db` — local SQLite audit log (also gitignored).

## Run

```powershell
python server.py
```

The Python process stays on `http://127.0.0.1:8484`, but it is proxied by the
main Apache so clients use the same host/port as the rest of the app:

- Local: `http://localhost:8090/microservice/mcp`
- Live: `https://mzigo.online/microservice/mcp`

On a shared cPanel host, the `/microservice/` reverse proxy must be configured at
the Apache vhost level (ProxyPass/ProxyPassReverse), not in `.htaccess`, because
cPanel disables `ProxyPass` in `.htaccess`. Ask the host to proxy
`/microservice/` to the Python service, or use a Cloudflare Worker for the
same effect.

## Configure

1. Sign in as platform admin.
2. Go to **Admin → Settings → MCP microservice privacy**.
3. Set mode, allowed scopes, masking, limits and token, then save.
4. `config.json` is updated instantly; the server reads it on every request.

## Health

```text
GET http://localhost:8090/microservice/health
```

## Free Cloudflare deployment (same domain)

Use the free plan only — no Spectrum, no paid port proxy:

1. **Tunnel** — expose the Python service on a public hostname:
   - `cloudflared tunnel create mzigo-mcp`
   - route DNS: `mcp-origin.mzigo.online`
   - ingress points that hostname to `http://127.0.0.1:8484`
   - see `cloudflare/cloudflared-config.yml.example`

2. **Worker** — map the main-domain path to the tunnel:
   - Deploy `cloudflare/worker.js`
   - Add route `mzigo.online/microservice/*`
   - Set `MCP_ORIGIN = https://mcp-origin.mzigo.online`

3. Clients then use `https://mzigo.online/microservice/mcp`.

## MCP client config example

```json
{
  "mcpServers": {
    "mzigo": {
      "command": "curl",
      "args": ["-s", "-X", "POST", "http://127.0.0.1:8484/mcp"],
      "env": {}
    }
  }
}
```

For a real MCP client that speaks HTTP JSON-RPC, point it at `http://localhost:8090/microservice/mcp` (or `https://mzigo.online/microservice/mcp` on live) with:

- `Authorization: Bearer <token>`
- `X-Tenant-Id: <workspace id>`

## Tools

| Tool | Scope | Notes |
|---|---|---|
| `parcel.track` | `parcel.track` | Masked PII |
| `orders.list` | `orders.list` | Capped by `max_rows` |
| `workspace.analytics_summary` | `workspace.analytics_summary` | Synthetic/aggregate |
| `privacy.status` | — | Returns the effective policy |

## Privacy enforcement

- Tenant isolation required when enabled
- Read-only by default
- PII masking (`phone`, `email`) applied before response
- Per-token rate limiting
- SQLite audit with configurable retention
