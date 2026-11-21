import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatRequestError as web } from '../../src/utils/request-trace.ts';
import { formatRequestError as platform } from '../../../platform/src/core/request-trace.ts';

for (const [name, format] of [['web', web], ['platform', platform]]) {
  test(`${name}: business errors show a validated trace independently of header case`, () => {
    assert.equal(format('禁止访问', { status: 403, headers: { 'X-Trace-ID': 'fixture-403' } }),
      '禁止访问（追踪 ID：fixture-403）');
  });
  test(`${name}: hides server details and displays only valid correlation metadata`, () => {
    assert.equal(format('SELECT private_data', { status: 502, headers: { 'x-trace-id': 'fixture-502' } }),
      '服务暂时不可用，请稍后重试（追踪 ID：fixture-502）');
    assert.equal(format('stack trace', { status: 500, headers: { 'x-trace-id': 'id\n' } }),
      '服务暂时不可用，请稍后重试');
  });
  test(`${name}: network errors without a response keep their local message`, () => {
    assert.equal(format('网络不可用'), '网络不可用');
  });
}
