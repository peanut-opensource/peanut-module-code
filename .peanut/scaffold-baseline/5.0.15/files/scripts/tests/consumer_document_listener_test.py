#!/usr/bin/env python3
"""Offline listener admission checks; uses only an ephemeral local test port."""

from pathlib import Path
import json
import runpy
import socket
import threading
import time
import tempfile
from unittest import TestCase, main, mock


SOURCE = Path(__file__).resolve().parents[1] / "consumer-document-article"


class ListenerAdmissionTest(TestCase):
    def setUp(self):
        self.consumer = runpy.run_path(str(SOURCE))

    def listener(self):
        listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        listener.bind(("127.0.0.1", 0))
        self.addCleanup(listener.close)
        return listener, f"http://127.0.0.1:{listener.getsockname()[1]}"

    def test_already_ready_listener(self):
        listener, endpoint = self.listener()
        listener.listen()
        self.consumer["wait_for_listener"](endpoint, seconds=0.2)

    def test_delayed_listener(self):
        listener, endpoint = self.listener()
        ready = threading.Event()

        def start():
            time.sleep(0.1)
            listener.listen()
            ready.set()

        worker = threading.Thread(target=start)
        worker.start()
        self.addCleanup(worker.join)
        self.consumer["wait_for_listener"](endpoint, seconds=1)
        self.assertTrue(ready.is_set())

    def test_refused_listener_times_out_without_journal(self):
        _, endpoint = self.listener()
        with self.assertRaisesRegex(self.consumer["Rejected"], "ENDPOINT_LISTENER_NOT_READY"):
            self.consumer["wait_for_listener"](endpoint, seconds=0.1)
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "not-started.jsonl"
            with mock.patch.dict(self.consumer["execute"].__globals__,
                                 verify_grant=lambda *_: None,
                                 wait_for_listener=lambda *_: (_ for _ in ()).throw(self.consumer["Rejected"]("ENDPOINT_LISTENER_NOT_READY"))):
                with self.assertRaisesRegex(self.consumer["Rejected"], "ENDPOINT_LISTENER_NOT_READY"):
                    self.consumer["execute"]({"endpoint": endpoint, "output": str(output)}, "unused")
            self.assertFalse(output.exists())

    def test_invalid_port_and_slow_dns_fail_within_deadline(self):
        with self.assertRaisesRegex(self.consumer["Rejected"], "ENDPOINT_PORT"):
            self.consumer["wait_for_listener"]("http://127.0.0.1:0", seconds=0.1)
        def slow_resolution(*_args, **_kwargs):
            time.sleep(0.15)
            return []
        start = time.monotonic()
        with mock.patch.object(self.consumer["socket"], "getaddrinfo", side_effect=slow_resolution):
            with self.assertRaisesRegex(self.consumer["Rejected"], "ENDPOINT_LISTENER_NOT_READY"):
                self.consumer["wait_for_listener"]("https://example.invalid", seconds=0.03)
        self.assertLess(time.monotonic() - start, 0.12)

    def test_later_wait_failure_records_only_requests_already_started(self):
        for fail_at, expected in ((2, 0), (3, 1)):
            with self.subTest(fail_at=fail_at), tempfile.TemporaryDirectory() as temporary:
                output = Path(temporary) / "journal.jsonl"
                c = {"endpoint": "http://127.0.0.1:1", "output": str(output),
                     "runtime_root": temporary, "source_commit": "a" * 40,
                     "budget": {"max_started": 9}, "request_prefix": "fixture",
                     "tenant_id": 1, "article_id": 2, "document_id": 3,
                     "expected_state_seq": 4, "host": "tenant.example",
                     "history": {"revision_id": 5}}
                calls = 0
                sent = []
                def readiness(*_args):
                    nonlocal calls
                    calls += 1
                    if calls == fail_at:
                        raise self.consumer["Rejected"]("ENDPOINT_LISTENER_NOT_READY")
                class Response:
                    status = 200
                    def read(self, _limit):
                        return json.dumps({"code": 20000, "data": {"document_id": 3, "article_id": 2,
                            "status": "active", "collaboration_enabled": False,
                            "owner": {"module_key": "official.article", "object_type": "article",
                                      "object_id": "2", "slot": "body"}, "state_seq": 4,
                            "article_metadata": {"is_show": 0}}}).encode()
                class Connection:
                    def __init__(self, *_args, **_kwargs): pass
                    def request(self, *_args, **_kwargs): sent.append(1)
                    def getresponse(self): return Response()
                    def close(self): pass
                with mock.patch.dict(self.consumer["execute"].__globals__,
                                     verify_grant=lambda *_: None, wait_for_listener=readiness,
                                     git=lambda _root, command, *_rest: "a" * 40 if command == "rev-parse" else ""), \
                     mock.patch.object(self.consumer["http"].client, "HTTPConnection", Connection):
                    with self.assertRaisesRegex(self.consumer["Rejected"], "ENDPOINT_LISTENER_NOT_READY"):
                        self.consumer["execute"](c, "unused")
                events = [json.loads(line) for line in output.read_text().splitlines()]
                self.assertEqual(sum(event.get("state") == "started" for event in events), expected)
                self.assertEqual(events[-1]["requests"], expected)
                self.assertEqual(len(sent), expected)


if __name__ == "__main__":
    main()
