#!/usr/bin/env python3
"""
Mzigo MCP microservice (privacy-enforcing).

A dependency-free microservice that applies the privacy policy configured by
the platform admin in Admin -> MCP. It serves MCP-style JSON-RPC over HTTP and
audits every call to a local SQLite database.

The admin save action writes `microservice/config.json`; this process reloads
it on each request so policy changes apply immediately without a restart.
"""
import hashlib
import hmac
import json
import os
import re
import sqlite3
import sys
import threading
import time
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(ROOT, "config.json")
DATA_DIR = os.path.join(ROOT, "data")
DB_PATH = os.path.join(DATA_DIR, "audit.db")
PORT = int(os.environ.get("MCP_PORT", "8484"))

# Synthetic sandbox data. No real customers, phones or payments.
SYNTHETIC_PARCELS = {
    "TRK-UAT-0001": {
        "tracking": "TRK-UAT-0001",
        "status": "AT_ORIGIN_POINT",
        "carrier": "Metro Trans",
        "route": "Nairobi -> Mombasa",
        "sender_name": "Synthetic Sender",
        "sender_phone": "0700000001",
        "receiver_name": "Synthetic Receiver",
        "receiver_phone": "0700000002",
        "receiver_email": "receiver@sandbox.example",
        "scans": [
            {"status": "CREATED", "label": "Registered", "at": "2026-10-29T09:00:00Z"},
            {"status": "AT_ORIGIN_POINT", "label": "Dropped off at origin", "at": "2026-10-29T09:30:00Z"},
        ],
    },
    "TRK-UAT-0002": {
        "tracking": "TRK-UAT-0002",
        "status": "READY_FOR_COLLECTION",
        "carrier": "Chania",
        "route": "Nairobi -> Nakuru",
        "sender_name": "Synthetic Sender",
        "sender_phone": "0700000003",
        "receiver_name": "Synthetic Receiver",
        "receiver_phone": "0700000004",
        "receiver_email": "receiver2@sandbox.example",
        "scans": [
            {"status": "CREATED", "label": "Registered", "at": "2026-10-28T08:00:00Z"},
            {"status": "READY_FOR_COLLECTION", "label": "Ready for collection", "at": "2026-10-29T07:15:00Z"},
        ],
    },
}

SYNTHETIC_ORDERS = [
    {"id": 101, "tracking": "TRK-UAT-0001", "receiver": "Synthetic Receiver", "phone": "0700000002", "email": "receiver@sandbox.example", "amount_kes": 450, "status": "in_transit"},
    {"id": 102, "tracking": "TRK-UAT-0002", "receiver": "Synthetic Receiver", "phone": "0700000004", "email": "receiver2@sandbox.example", "amount_kes": 520, "status": "ready"},
]

TOOLS = {
    "parcel.track": {"scope": "parcel.track", "read_only": True},
    "orders.list": {"scope": "orders.list", "read_only": True},
    "workspace.analytics_summary": {"scope": "workspace.analytics_summary", "read_only": True},
    "privacy.status": {"scope": None, "read_only": True},
}


def load_config():
    if not os.path.isfile(CONFIG_PATH):
        return {"enabled": False}
    try:
        with open(CONFIG_PATH, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except Exception:
        return {"enabled": False}


def init_db():
    os.makedirs(DATA_DIR, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.execute(
        "CREATE TABLE IF NOT EXISTS audit ("
        "id INTEGER PRIMARY KEY AUTOINCREMENT,"
        "ts TEXT NOT NULL, token_hash TEXT NOT NULL, tenant_id TEXT,"
        "method TEXT NOT NULL, scope TEXT, ok INTEGER NOT NULL,"
        "error TEXT, client_ip TEXT)"
    )
    conn.execute(
        "CREATE TABLE IF NOT EXISTS rate ("
        "bucket TEXT PRIMARY KEY, window_start INTEGER NOT NULL, count INTEGER NOT NULL)"
    )
    conn.commit()
    conn.close()


def mask(value, mask_fields):
    text = str(value)
    if "email" in mask_fields and "@" in text:
        local, _, domain = text.partition("@")
        text = (local[0] + "***@" + domain) if local else text
    if "phone" in mask_fields and re.fullmatch(r"07\d{8}", text):
        text = text[:4] + "****" + text[-2:]
    return text


def apply_privacy(obj, mask_fields):
    if isinstance(obj, dict):
        return {k: apply_privacy(v, mask_fields) for k, v in obj.items()}
    if isinstance(obj, list):
        return [apply_privacy(v, mask_fields) for v in obj]
    if isinstance(obj, str):
        return mask(obj, mask_fields)
    return obj


def audit(cfg, token, tenant_id, method, scope, ok, error, client_ip):
    try:
        conn = sqlite3.connect(DB_PATH)
        conn.execute(
            "INSERT INTO audit (ts, token_hash, tenant_id, method, scope, ok, error, client_ip) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            (
                datetime.now(timezone.utc).isoformat(),
                hashlib.sha256(token.encode("utf-8")).hexdigest(),
                str(tenant_id),
                method,
                scope or "",
                1 if ok else 0,
                error or "",
                client_ip or "",
            ),
        )
        conn.commit()
        # Retention: drop audit rows older than the configured window.
        retention = int(cfg.get("retention_days", 30))
        cutoff = (datetime.now(timezone.utc) - timedelta(days=retention)).isoformat()
        conn.execute("DELETE FROM audit WHERE ts < ?", (cutoff,))
        conn.commit()
        conn.close()
    except Exception:
        pass


def rate_ok(cfg, token, tenant_id):
    rpm = int(cfg.get("rate_limit_per_min", 60))
    bucket = "{}:{}".format(hashlib.sha256((token + "|" + str(tenant_id)).encode()).hexdigest(), int(time.time() // 60))
    try:
        conn = sqlite3.connect(DB_PATH)
        row = conn.execute("SELECT count FROM rate WHERE bucket = ? AND window_start = ?", (bucket, int(time.time() // 60))).fetchone()
        if row:
            if row[0] >= rpm:
                conn.close()
                return False
            conn.execute("UPDATE rate SET count = count + 1 WHERE bucket = ?", (bucket,))
        else:
            conn.execute("INSERT INTO rate (bucket, window_start, count) VALUES (?, ?, 1)", (bucket, int(time.time() // 60)))
        conn.commit()
        conn.close()
        return True
    except Exception:
        return True


def tool_result(name, args, cfg, tenant_id):
    if name == "privacy.status":
        return {
            "mode": cfg.get("mode", "sandbox"),
            "enabled": bool(cfg.get("enabled", False)),
            "allowed_scopes": cfg.get("allowed_scopes", []),
            "mask_fields": cfg.get("mask_fields", []),
            "max_rows": cfg.get("max_rows", 50),
            "tenant_isolation": bool(cfg.get("tenant_isolation", True)),
            "read_only": bool(cfg.get("read_only", True)),
            "retention_days": cfg.get("retention_days", 30),
        }
    if name == "parcel.track":
        tracking = str(args.get("tracking", "")).strip().upper()
        parcel = SYNTHETIC_PARCELS.get(tracking)
        if not parcel:
            return {"error": "No parcel found for that tracking number."}
        return apply_privacy(parcel, cfg.get("mask_fields", []))
    if name == "orders.list":
        limit = min(int(cfg.get("max_rows", 50)), int(args.get("limit", 25) or 25))
        rows = SYNTHETIC_ORDERS[:limit]
        return {"orders": apply_privacy(rows, cfg.get("mask_fields", []))}
    if name == "workspace.analytics_summary":
        return apply_privacy(
            {"tenant_id": tenant_id, "parcels_30d": 128, "collected_30d": 96, "ready_30d": 11, "open_claims": 2},
            cfg.get("mask_fields", []),
        )
    return {"error": "Unknown tool."}


class Handler(BaseHTTPRequestHandler):
    server_version = "MzigoMCP/1.0"

    def _json(self, code, obj):
        body = json.dumps(obj).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path.rstrip("/") == "/health":
            cfg = load_config()
            self._json(200, {"ok": True, "enabled": bool(cfg.get("enabled", False)), "mode": cfg.get("mode", "sandbox")})
            return
        self._json(404, {"ok": False, "error": "Not found."})

    def do_POST(self):
        cfg = load_config()
        client_ip = self.client_address[0]
        if self.path.rstrip("/") != "/mcp":
            self._json(404, {"ok": False, "error": "Not found."})
            return
        if not cfg.get("enabled", False):
            self._json(403, {"ok": False, "error": "MCP access is disabled."})
            return
        token = ""
        auth = self.headers.get("Authorization", "")
        if auth.startswith("Bearer "):
            token = auth[7:].strip()
        if not token or not hmac.compare_digest(token, str(cfg.get("token", ""))):
            self._json(401, {"ok": False, "error": "Invalid MCP token."})
            return
        length = int(self.headers.get("Content-Length", "0") or 0)
        try:
            payload = json.loads(self.rfile.read(length) or b"{}")
        except Exception:
            payload = {}
        method = payload.get("method", "")
        rid = payload.get("id", None)
        params = payload.get("params") or {}
        tenant_id = str(self.headers.get("X-Tenant-Id") or params.get("tenant_id") or "")
        if cfg.get("tenant_isolation", True):
            if not tenant_id.isdigit():
                self._json(400, {"jsonrpc": "2.0", "id": rid, "error": {"code": -32602, "message": "A tenant_id is required."}})
                return
        elif tenant_id == "":
            tenant_id = "1"

        def respond(result=None, error=None):
            if error:
                self._json(200, {"jsonrpc": "2.0", "id": rid, "error": error})
            else:
                self._json(200, {"jsonrpc": "2.0", "id": rid, "result": result})

        if method == "tools/list":
            result = {"tools": [{"name": n, "scope": m["scope"], "read_only": m["read_only"]} for n, m in TOOLS.items()]}
            audit(cfg, token, tenant_id, "tools/list", None, True, None, client_ip)
            respond(result=result)
            return
        if method != "tools/call":
            respond(error={"code": -32601, "message": "Method not found."})
            return

        tool_name = str((params.get("name") or "") if isinstance(params, dict) else "")
        arguments = params.get("arguments") or {} if isinstance(params, dict) else {}
        tool = TOOLS.get(tool_name)
        if not tool:
            respond(error={"code": -32602, "message": "Unknown tool."})
            return
        scope = tool["scope"]
        allowed = [s.strip() for s in cfg.get("allowed_scopes", [])]
        if scope and scope not in allowed:
            audit(cfg, token, tenant_id, tool_name, scope, False, "scope denied", client_ip)
            respond(error={"code": -32001, "message": "Scope not allowed: " + scope})
            return
        if cfg.get("read_only", True) and not tool["read_only"]:
            audit(cfg, token, tenant_id, tool_name, scope, False, "write denied", client_ip)
            respond(error={"code": -32002, "message": "Read-only policy is enabled."})
            return
        if not rate_ok(cfg, token, tenant_id):
            audit(cfg, token, tenant_id, tool_name, scope, False, "rate limited", client_ip)
            respond(error={"code": -32003, "message": "Rate limit exceeded."})
            return
        result = tool_result(tool_name, arguments, cfg, tenant_id)
        audit(cfg, token, tenant_id, tool_name, scope, True, None, client_ip)
        respond(result={"content": [{"type": "text", "text": json.dumps(result, ensure_ascii=False)}]})


def main():
    init_db()
    server = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    print("Mzigo MCP microservice listening on http://127.0.0.1:%d" % PORT, flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
