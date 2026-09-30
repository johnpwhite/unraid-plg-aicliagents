#!/usr/bin/env python3
"""A tiny local stand-in for the Anthropic Messages API, for tools/repro-claude-enter.sh.

It lets a real Claude Code start and answer without a login, and it records which
Relay notices reached the model. It never talks to the internet.

- Every reply is the text "OK-REPLY".
- A user message "RUNSLEEP<n>" makes the model call the Bash tool with "sleep <n>",
  so Claude Code is busy for n seconds (add "RUNBG" to run it in the background).
- Each request is logged as one line with the notice tokens ("from <tok> is waiting")
  that it carries, so the repro can tell "submitted" from "not submitted".

Usage: repro-claude-enter-fakeapi.py <port> <log-file>
"""
import http.server
import json
import re
import sys
import time

PORT = int(sys.argv[1])
LOG = sys.argv[2]


def sse(handler, events):
    handler.send_response(200)
    handler.send_header('content-type', 'text/event-stream')
    handler.send_header('connection', 'close')
    handler.end_headers()
    for name, data in events:
        handler.wfile.write(f"event: {name}\ndata: {json.dumps(data)}\n\n".encode())
        handler.wfile.flush()
    handler.close_connection = True


def message(model, block, delta, stop):
    return [
        ('message_start', {"type": "message_start", "message": {"id": "msg_1", "type": "message", "role": "assistant",
                                                                 "model": model, "content": [], "stop_reason": None,
                                                                 "stop_sequence": None,
                                                                 "usage": {"input_tokens": 1, "output_tokens": 1}}}),
        ('content_block_start', {"type": "content_block_start", "index": 0, "content_block": block}),
        ('content_block_delta', {"type": "content_block_delta", "index": 0, "delta": delta}),
        ('content_block_stop', {"type": "content_block_stop", "index": 0}),
        ('message_delta', {"type": "message_delta", "delta": {"stop_reason": stop, "stop_sequence": None},
                           "usage": {"output_tokens": 2}}),
        ('message_stop', {"type": "message_stop"}),
    ]


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *args):
        pass

    def _json(self, obj):
        body = json.dumps(obj).encode()
        self.send_response(200)
        self.send_header('content-type', 'application/json')
        self.send_header('content-length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        self._json({})

    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('content-length', '0')))
        try:
            req = json.loads(body)
        except ValueError:
            req = {}
        msgs = req.get('messages', [])
        toks = sorted({t.decode() for t in re.findall(rb'from (\S+) is waiting', body)})
        with open(LOG, 'a') as f:
            f.write(f"{time.strftime('%H:%M:%S')} POST {self.path} toks={toks}\n")
        if 'count_tokens' in self.path:
            return self._json({"input_tokens": 10})
        model = req.get('model', 'x')
        if not req.get('stream'):
            return self._json({"id": "msg_1", "type": "message", "role": "assistant", "model": model,
                               "content": [{"type": "text", "text": "OK-REPLY"}], "stop_reason": "end_turn",
                               "stop_sequence": None, "usage": {"input_tokens": 1, "output_tokens": 1}})
        tools = [t.get('name') for t in (req.get('tools') or []) if isinstance(t, dict)]
        idx = max([i for i, m in enumerate(msgs) if m.get('role') == 'user' and 'RUNSLEEP' in json.dumps(m)] or [-1])
        answered = idx >= 0 and any(m.get('role') == 'assistant' for m in msgs[idx + 1:])
        if tools and idx >= 0 and not answered:
            raw = json.dumps(msgs[idx])
            secs = int(re.search(r'RUNSLEEP(\d+)', raw).group(1))
            name = 'Bash'
            inp = {"command": f"sleep {secs}", "description": "wait"}
            if 'RUNBG' in raw:
                inp["run_in_background"] = True
            if 'RUNAGENT' in raw:
                # A subagent whose own first message is "RUNSLEEP<n>": it runs the sleep.
                name = 'Agent' if 'Agent' in tools else 'Task'
                inp = {"description": "long job", "prompt": f"RUNSLEEP{secs}", "subagent_type": "general-purpose"}
            block = {"type": "tool_use", "id": f"toolu_{int(time.time() * 1000)}", "name": name, "input": {}}
            return sse(self, message(model, block, {"type": "input_json_delta", "partial_json": json.dumps(inp)},
                                     'tool_use'))
        return sse(self, message(model, {"type": "text", "text": ""}, {"type": "text_delta", "text": "OK-REPLY"},
                                 'end_turn'))


http.server.ThreadingHTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
