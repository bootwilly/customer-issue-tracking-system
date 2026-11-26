"""Tiny client for the local iFastNET MCP server. Usage: python deploy/mcp_call.py <tool> '<json args>'"""
import json, sys, urllib.request


def call(name, args, timeout=600):
    body = json.dumps({"jsonrpc": "2.0", "id": 1, "method": "tools/call",
                       "params": {"name": name, "arguments": args}}).encode()
    req = urllib.request.Request("http://127.0.0.1:8765/mcp", body,
                                 {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"})
    raw = urllib.request.urlopen(req, timeout=timeout).read().decode("utf-8")
    texts = []
    for line in raw.splitlines():
        if line.startswith("data: "):
            res = json.loads(line[6:]).get("result", {})
            texts += [c.get("text", "") for c in res.get("content", [])]
    return "\n".join(texts)


if __name__ == "__main__":
    print(call(sys.argv[1], json.loads(sys.argv[2])))
