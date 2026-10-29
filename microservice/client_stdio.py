#!/usr/bin/env python3
"""
Stdio MCP bridge for Claude / Claude Code.

Claude launches this process and talks MCP over stdin/stdout. This bridge
forwards `tools/list` and `tools/call` to the HTTP microservice, which applies
the admin-configured privacy policy. `initialize` is answered locally.

Environment:
    MZIGO_MCP_URL     http://127.0.0.1:8484/mcp
    MZIGO_MCP_TOKEN   <token from Admin -> MCP>
    MZIGO_TENANT_ID   <workspace id>
"""
import json
import os
import sys
import urllib.request

URL = os.environ.get("MZIGO_MCP_URL", "http://127.0.0.1:8090/microservice/mcp")
TOKEN = os.environ.get("MZIGO_MCP_TOKEN", "")
TENANT_ID = os.environ.get("MZIGO_TENANT_ID", "1")


def http_rpc(payload):
    req = urllib.request.Request(
        URL,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": "Bearer " + TOKEN,
            "X-Tenant-Id": TENANT_ID,
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode("utf-8"))


def respond(rid, result=None, error=None):
    msg = {"jsonrpc": "2.0", "id": rid}
    if error is not None:
        msg["error"] = error
    else:
        msg["result"] = result
    sys.stdout.write(json.dumps(msg) + "\n")
    sys.stdout.flush()


def main():
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            msg = json.loads(line)
        except Exception:
            continue
        method = msg.get("method", "")
        rid = msg.get("id")
        params = msg.get("params") or {}

        if method == "initialize":
            respond(rid, result={
                "protocolVersion": "2024-11-05",
                "capabilities": {"tools": {}},
                "serverInfo": {"name": "mzigo", "version": "1.0.0"},
            })
        elif method == "notifications/initialized":
            # No response for notifications.
            pass
        elif method in ("tools/list", "tools/call"):
            try:
                result = http_rpc({"jsonrpc": "2.0", "id": rid, "method": method, "params": params})
                body = result.get("result")
                err = result.get("error")
                respond(rid, result=body, error=err)
            except Exception as err:
                respond(rid, error={"code": -32603, "message": str(err)})
        else:
            respond(rid, error={"code": -32601, "message": "Method not found."})


if __name__ == "__main__":
    main()
