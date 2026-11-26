import assert from 'node:assert/strict';
import test from 'node:test';
import { createIntegrationSecurityFetchTransport } from '../../src/modules/peanut-integration/runtime/contracts.ts';

test('native machine rotate transport emits one JSON integer revision and exact route', async () => {
  const calls = [];
  const transport = createIntegrationSecurityFetchTransport({
    baseUrl: 'http://127.0.0.1:5020/',
    fetch: async (request) => {
      calls.push({
        method: request.method,
        path: new URL(request.url).pathname,
        contentType: request.headers.get('content-type'),
        body: await request.text(),
      });
      return new Response('{"data":{},"meta":{"request_id":"task-test-valid-request"}}', {
        status: 200,
        headers: {'content-type': 'application/json'},
      });
    },
  });
  const key = 'machine_' + 'a'.repeat(32);
  await transport.rotateMachine(key, 1, new AbortController().signal);
  assert.deepEqual(calls, [{
    method: 'POST',
    path: '/adminapi/api/v1/integration-security/machine-identities/' + key + '/rotate',
    contentType: 'application/json',
    body: '{"revision":1}',
  }]);
});

test('native machine revocation retains identical revision JSON contract', async () => {
  const calls = [];
  const transport = createIntegrationSecurityFetchTransport({
    baseUrl: 'http://127.0.0.1:5020/',
    fetch: async request => {
      calls.push([request.method, await request.text()]);
      return new Response('{"data":{}}', {status:200,headers:{'content-type':'application/json'}});
    }
  });
  await transport.revokeMachine('machine_' + 'b'.repeat(32), 1, new AbortController().signal);
  assert.deepEqual(calls,[['DELETE','{"revision":1}']]);
});
