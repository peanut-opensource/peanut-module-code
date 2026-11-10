// Real generated SFC + Element Plus + compiled Core; only transport and
// permission grants are synthetic. Launched by generated-crud-consumer.test.mjs.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import axios from 'axios';
import {
  ElAlert,
  ElButton,
  ElMessage,
  ElPagination,
  ElPopconfirm,
  ElTable,
} from 'element-plus';
import Page from 'generated:crud-view';
import permission from '../../src/directive/permission/index';

const policy = vi.hoisted(() => ({ allow: true, observed: [] }));
vi.mock('@/hooks/permission', () => ({
  hasPermission(keys) {
    policy.observed.push([...keys]);
    return policy.allow;
  },
}));
const example = process.env.PEANUT_CRUD_CASE;
if (
  !['string-plain', 'string-recycle', 'int-plain', 'int-recycle'].includes(
    example
  )
) {
  throw new Error('A fixed native generator case is required');
}
const recycle = example.endsWith('-recycle');
const primary = example.startsWith('string-') ? 'uuid' : 'id';
const key = primary === 'uuid' ? 'synthetic-record-key' : 7;
const prefix = `fixture.delivery-record.${example}-view-note`;
const row = (title = 'current row') => ({ [primary]: key, title });
const result = (rows = [row()], count = 45) => ({
  lists: rows,
  count,
  pageNo: 1,
  pageSize: 15,
});
let pending;
let wrappers;
let warnings;
let originalAdapter;
let success;
const drain = async () => {
  await flushPromises();
  await nextTick();
  await flushPromises();
};
function open() {
  const wrapper = mount(Page, {
    attachTo: document.body,
    global: {
      directives: { permission },
      config: { warnHandler: (message) => warnings.push(message) },
    },
  });
  wrappers.push(wrapper);
  return wrapper;
}
function button(wrapper, text) {
  const value = wrapper
    .findAllComponents(ElButton)
    .find((item) => item.text() === text);
  expect(value, `Missing actual Element Plus button: ${text}`).toBeDefined();
  return value;
}
async function loaded(rows = [row()]) {
  const wrapper = open();
  await drain();
  expect(pending).toHaveLength(1);
  pending[0].resolve(result(rows));
  await drain();
  return wrapper;
}
async function recycled() {
  const wrapper = await loaded();
  await button(wrapper, '回收站').trigger('click');
  await drain();
  expect(pending[1].config.url).toBe(`/adminapi/${prefix}.recycle.list`);
  expect(pending[1].config.params.page_no).toBe(1);
  pending[1].resolve(result());
  await drain();
  return wrapper;
}
const batch = (field, failed = []) => ({
  requested: [key],
  [field]: failed.length ? [] : [key],
  already_active: [],
  failed,
});

beforeEach(() => {
  pending = [];
  wrappers = [];
  warnings = [];
  policy.allow = true;
  policy.observed = [];
  originalAdapter = axios.defaults.adapter;
  axios.defaults.adapter = async (config) => {
    let resolve;
    let reject;
    const data = new Promise((yes, no) => {
      resolve = yes;
      reject = no;
    });
    pending.push({ config, resolve, reject });
    return {
      data: await data,
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
    };
  };
  success = vi.spyOn(ElMessage, 'success');
});
afterEach(async () => {
  for (const wrapper of wrappers) wrapper.unmount();
  for (const request of pending) request.resolve(result([], 0));
  await drain();
  axios.defaults.adapter = originalAdapter;
  ElMessage.closeAll();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
  expect(
    warnings.filter((message) =>
      /Failed to resolve|injection .* not found|Invalid prop/.test(message)
    )
  ).toEqual([]);
});

describe(`native generated ${example}`, () => {
  it('renders real table columns and paginates with unchanged URL and page size', async () => {
    const wrapper = await loaded();
    expect(wrapper.findComponent(ElTable).props('rowKey')).toBe(primary);
    expect(wrapper.text()).toContain('current row');
    expect(wrapper.text()).toContain('标题');
    expect(wrapper.text()).not.toContain('api_secret');
    expect(pending[0].config.url).toBe(`/adminapi/${prefix}.list`);
    expect(pending[0].config.params).toEqual({ page_no: 1, page_size: 15 });
    const pagination = wrapper.findComponent(ElPagination);
    expect(pagination.props('total')).toBe(45);
    await pagination.find('button.btn-next').trigger('click');
    await drain();
    expect(pending).toHaveLength(2);
    expect(pending[1].config.params).toEqual({ page_no: 2, page_size: 15 });
    pending[1].resolve(result([row('page two')]));
    await drain();
    expect(wrapper.text()).toContain('page two');
    expect(pagination.props('currentPage')).toBe(2);
  });

  it('renders loading, failure, retry and successful empty results distinctly', async () => {
    const wrapper = open();
    await drain();
    expect(wrapper.text()).toContain('正在加载');
    pending[0].reject(new Error('private upstream detail'));
    await drain();
    expect(wrapper.findComponent(ElAlert).props('title')).toBe(
      '列表加载失败，请重试'
    );
    expect(wrapper.text()).not.toContain('private upstream detail');
    await button(wrapper, '重新加载').trigger('click');
    await drain();
    pending[1].resolve(result([], 0));
    await drain();
    expect(wrapper.findComponent(ElAlert).exists()).toBe(false);
    expect(wrapper.text()).toContain('暂无数据');
    expect(wrapper.findComponent(ElPagination).props('total')).toBe(0);
  });

  it('late list failures cannot replace a newer page', async () => {
    const wrapper = await loaded();
    const pagination = wrapper.findComponent(ElPagination);
    pagination.vm.$emit('current-change', 2);
    await drain();
    pagination.vm.$emit('current-change', 3);
    await drain();
    pending[2].resolve(result([row('newest page')]));
    await drain();
    pending[1].reject(new Error('late failure'));
    await drain();
    expect(wrapper.text()).toContain('newest page');
    expect(wrapper.findComponent(ElAlert).exists()).toBe(false);
    expect(pagination.props('currentPage')).toBe(3);
  });

  it('unmounted list completions do not affect a new consumer scope', async () => {
    const old = open();
    await drain();
    old.unmount();
    wrappers = [];
    const fresh = open();
    await drain();
    pending[1].resolve(result([row('fresh scope')]));
    await drain();
    pending[0].resolve(result([row('late old scope')]));
    await drain();
    expect(fresh.text()).toContain('fresh scope');
    expect(fresh.text()).not.toContain('late old scope');
  });

  if (recycle) {
    it('uses native row slots and preserves recycle, restore and purge permission keys', async () => {
      const wrapper = await recycled();
      expect(button(wrapper, '恢复').exists()).toBe(true);
      expect(wrapper.findComponent(ElPopconfirm).props('title')).toContain(
        '不可恢复'
      );
      expect(policy.observed.flat()).toEqual(
        expect.arrayContaining([
          `${prefix}.recycle.list`,
          `${prefix}.restore`,
          `${prefix}.purge`,
        ])
      );
      await button(wrapper, '返回普通列表').trigger('click');
      await drain();
      expect(pending[2].config.url).toBe(`/adminapi/${prefix}.list`);
      expect(pending[2].config.params.page_no).toBe(1);
      expect(wrapper.findComponent(ElPopconfirm).exists()).toBe(false);
    });

    it('suppresses duplicate restore and reports failed batches without false success', async () => {
      const wrapper = await recycled();
      const restore = button(wrapper, '恢复');
      restore.vm.$emit('click', new MouseEvent('click'));
      restore.vm.$emit('click', new MouseEvent('click'));
      await drain();
      expect(pending).toHaveLength(3);
      expect(pending[2].config.url).toBe(`/adminapi/${prefix}.restore`);
      expect(JSON.parse(pending[2].config.data)).toEqual({ ids: [key] });
      expect(button(wrapper, '返回普通列表').props('disabled')).toBe(true);
      expect(wrapper.findComponent(ElPagination).props('disabled')).toBe(true);
      pending[2].resolve(
        batch('restored', [
          { [primary]: key, code: 'CONFLICT', message: 'conflict' },
        ])
      );
      await drain();
      expect(success).not.toHaveBeenCalled();
      expect(wrapper.findComponent(ElAlert).props('title')).toBe(
        '操作失败，请重试'
      );
      await button(wrapper, '恢复').trigger('click');
      await drain();
      pending[3].resolve(batch('restored'));
      await drain();
      expect(success).toHaveBeenCalledExactlyOnceWith('恢复成功');
      expect(pending[4].config.url).toBe(`/adminapi/${prefix}.recycle.list`);
    });

    it('the real popconfirm reference and confirm button invoke purge with the typed key', async () => {
      const wrapper = await recycled();
      await button(wrapper, '永久删除').trigger('click');
      await drain();
      // The real popover appears after its scheduled transition, not after
      // an assumed number of microtasks. Do not replace the confirm event.
      const confirm = await vi.waitFor(
        () => {
          const element = document.body.querySelector(
            '.el-popconfirm__action .el-button--primary'
          );
          expect(element).not.toBeNull();
          return element;
        },
        { timeout: 1000, interval: 10 }
      );
      confirm.click();
      await drain();
      expect(pending).toHaveLength(3);
      expect(pending[2].config.url).toBe(`/adminapi/${prefix}.purge`);
      expect(JSON.parse(pending[2].config.data)).toEqual({ ids: [key] });
      pending[2].resolve(
        batch('purged', [
          { [primary]: key, code: 'REJECTED', message: 'denied' },
        ])
      );
      await drain();
      expect(success).not.toHaveBeenCalled();
      wrapper
        .findComponent(ElPopconfirm)
        .vm.$emit('confirm', new MouseEvent('click'));
      await drain();
      pending[3].resolve(batch('purged'));
      await drain();
      expect(success).toHaveBeenCalledExactlyOnceWith('永久删除成功');
    });

    it('rejects wrong primary key types before any destructive request', async () => {
      const wrapper = await loaded();
      await button(wrapper, '回收站').trigger('click');
      await drain();
      pending[1].resolve(
        result([
          { [primary]: primary === 'uuid' ? 7 : '7', title: 'invalid primary' },
        ])
      );
      await drain();
      await button(wrapper, '恢复').trigger('click');
      await drain();
      expect(pending).toHaveLength(2);
      expect(success).not.toHaveBeenCalled();
      expect(wrapper.findComponent(ElAlert).props('title')).toBe(
        '操作失败，请重试'
      );
    });

    for (const operation of ['restore', 'purge']) {
      it(`${operation} rejects malformed batch failure collections and transport errors`, async () => {
        const wrapper = await recycled();
        const submit = async () => {
          if (operation === 'restore')
            button(wrapper, '恢复').vm.$emit('click', new MouseEvent('click'));
          else
            wrapper
              .findComponent(ElPopconfirm)
              .vm.$emit('confirm', new MouseEvent('click'));
          await drain();
        };
        await submit();
        pending[2].resolve({ requested: [key], failed: '' });
        await drain();
        expect(success).not.toHaveBeenCalled();
        expect(pending).toHaveLength(3);
        expect(wrapper.findComponent(ElAlert).props('title')).toBe(
          '操作失败，请重试'
        );
        await submit();
        pending[3].reject(new Error('private upstream failure'));
        await drain();
        expect(success).not.toHaveBeenCalled();
        expect(pending).toHaveLength(4);
        expect(wrapper.text()).not.toContain('private upstream failure');
      });
    }

    it('suppresses duplicate purge confirmation while a batch is in flight', async () => {
      const wrapper = await recycled();
      const confirm = wrapper.findComponent(ElPopconfirm);
      confirm.vm.$emit('confirm', new MouseEvent('click'));
      confirm.vm.$emit('confirm', new MouseEvent('click'));
      await drain();
      expect(pending).toHaveLength(3);
      expect(JSON.parse(pending[2].config.data)).toEqual({ ids: [key] });
      pending[2].resolve(batch('purged'));
      await drain();
      expect(success).toHaveBeenCalledExactlyOnceWith('永久删除成功');
    });

    it('unmounted action completion cannot announce success or refresh another scope', async () => {
      const wrapper = await recycled();
      await button(wrapper, '恢复').trigger('click');
      await drain();
      wrapper.unmount();
      wrappers = [];
      pending[2].resolve(batch('restored'));
      await drain();
      expect(success).not.toHaveBeenCalled();
      expect(pending).toHaveLength(3);
    });

    it('the actual permission directive removes unauthorized recycle entry', async () => {
      policy.allow = false;
      const wrapper = await loaded();
      expect(
        wrapper.findAll('button').some((item) => item.text() === '回收站')
      ).toBe(false);
      expect(pending).toHaveLength(1);
      expect(policy.observed.flat()).toContain(`${prefix}.recycle.list`);
    });
  }
});
