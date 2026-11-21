// Real generated SFC + Element Plus + compiled Core; only transport and
// permission grants are synthetic. Launched by generated-crud-consumer.test.mjs.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { cloneVNode, defineComponent, h, KeepAlive, nextTick, ref } from 'vue';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import axios from 'axios';
import ElementPlus, {
  ElAlert,
  ElButton,
  ElMessage,
  ElPagination,
  ElPopconfirm,
  ElTable,
} from 'element-plus';
import Page from 'generated:crud-view';
import permission from '../../src/directive/permission/index';
import useCrudModal from '../../src/hooks/useCrudModal';
import useCrudTable from '../../src/hooks/useCrudTable';

const policy = vi.hoisted(() => ({ allow: true, observed: [] }));
vi.mock('@/hooks/permission', () => ({
  hasPermission(keys) {
    policy.observed.push([...keys]);
    return policy.allow;
  },
}));
// The directive uses the host router singleton. Tab behavior uses the actual
// memory router installed on the mounted page below.
vi.mock('@/router', () => ({
  default: { currentRoute: { value: { path: '/records' } } },
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
let defaultRouter;
const drain = async () => {
  await flushPromises();
  await nextTick();
  await flushPromises();
};
function open(component = Page, plugins = []) {
  const wrapper = mount(component, {
    attachTo: document.body,
    global: {
      plugins: [ElementPlus, ...(plugins.length ? plugins : [defaultRouter])],
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

beforeEach(async () => {
  defaultRouter = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/records', component: Page }],
  });
  await defaultRouter.push('/records');
  await defaultRouter.isReady();
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
    expect(pending[1].config.signal.aborted).toBe(true);
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
    expect(pending[0].config.signal.aborted).toBe(true);
    wrappers = [];
    // Vue Router resets its current route when its last mounted app unmounts.
    await defaultRouter.push('/records');
    await defaultRouter.isReady();
    const fresh = open();
    await drain();
    pending[1].resolve(result([row('fresh scope')]));
    await drain();
    pending[0].resolve(result([row('late old scope')]));
    await drain();
    expect(fresh.text()).toContain('fresh scope');
    expect(fresh.text()).not.toContain('late old scope');
  });

  it('aborts the previous KeepAlive tab and reloads its interrupted page on return', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/records', component: Page }],
    });
    const tabLayout = defineComponent(
      () => () =>
        h(RouterView, null, {
          default: ({ Component, route }) =>
            h(KeepAlive, null, {
              default: () =>
                Component && cloneVNode(Component, { key: route.fullPath }),
            }),
        })
    );
    await router.push('/records?tab=a');
    const wrapper = open(tabLayout, [router]);
    await drain();
    await router.push('/records?tab=b');
    await drain();
    expect(pending[0].config.signal.aborted).toBe(true);
    pending[1].resolve(result([row('second tab')]));
    pending[0].resolve(result([row('stale first tab')]));
    await drain();
    expect(wrapper.text()).toContain('second tab');
    expect(wrapper.text()).not.toContain('stale first tab');

    await router.push('/records?tab=a');
    await drain();
    expect(pending).toHaveLength(3);
    pending[2].resolve(result([row('first tab reloaded')]));
    await drain();
    expect(wrapper.text()).toContain('first tab reloaded');
  });

  if (recycle) {
    it('aborts the ordinary-list transport when opening the recycle bin', async () => {
      const wrapper = open();
      await drain();
      await button(wrapper, '回收站').trigger('click');
      await drain();
      expect(pending[0].config.signal.aborted).toBe(true);
      pending[1].resolve(result([row('recycled row')]));
      pending[0].resolve(result([row('old ordinary row')]));
      await drain();
      expect(wrapper.text()).toContain('recycled row');
      expect(wrapper.text()).not.toContain('old ordinary row');
    });

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

if (example === 'string-plain') {
  it('keeps search/reset at page one and submits the delete command once', async () => {
    let table;
    let completeDelete;
    const load = vi.fn(async ({ page, pageSize }) => ({
      items: [row()],
      total: 1,
      page,
      pageSize,
    }));
    const deleteRecord = vi.fn(
      () =>
        new Promise((resolve) => {
          completeDelete = resolve;
        })
    );
    open(
      defineComponent(() => {
        table = useCrudTable({
          initialFilters: { q: '' },
          initialPageSize: 15,
          load,
          deleteRecord,
          errorMessage: String,
        });
        return () => null;
      })
    );
    await table.fetchList(2);
    table.searchForm.value.q = 'new filter';
    await table.handleSearch();
    expect(load.mock.lastCall[0]).toMatchObject({
      page: 1,
      filters: { q: 'new filter' },
    });
    await table.handleReset();
    expect(load.mock.lastCall[0]).toMatchObject({
      page: 1,
      filters: { q: '' },
    });
    const selected = table.data.value[0];
    const deletion = table.handleDelete(selected);
    expect(await table.handleDelete(selected)).toBe(false);
    expect(deleteRecord).toHaveBeenCalledExactlyOnceWith(
      selected,
      expect.any(AbortSignal)
    );
    completeDelete();
    expect(await deletion).toBe(true);
    expect(load).toHaveBeenCalledTimes(4);
  });

  it('loads options only for an authorized open and aborts stale dialog requests', async () => {
    let modal;
    const authorized = ref(false);
    const optionsAuthorized = ref(true);
    const optionsPending = [];
    const loadOptions = vi.fn(
      (signal) =>
        new Promise((resolve) => {
          optionsPending.push({ signal, resolve });
        })
    );
    open(
      defineComponent(() => {
        modal = useCrudModal({
          canOpen: () => authorized.value,
          canLoadOptions: (mode) => mode !== 'view' && optionsAuthorized.value,
          createForm: () => ({ name: '' }),
          editForm: (record) => ({ name: record.title }),
          loadOptions,
          save: vi.fn(),
        });
        return () => null;
      })
    );
    expect(loadOptions).not.toHaveBeenCalled();
    expect(await modal.create()).toBe(false);
    expect(modal.visible.value).toBe(false);
    authorized.value = true;
    expect(await modal.view(row('reading'))).toBe(true);
    expect(await modal.submit()).toBe(false);
    expect(loadOptions).not.toHaveBeenCalled();

    const firstRow = row('first');
    const secondRow = { ...row('second'), [primary]: 'second-key' };
    const first = modal.edit(firstRow);
    const repeated = modal.edit(firstRow);
    expect(loadOptions).toHaveBeenCalledTimes(1);
    const second = modal.edit(secondRow);
    expect(loadOptions).toHaveBeenCalledTimes(2);
    expect(optionsPending[0].signal.aborted).toBe(true);
    optionsPending[0].resolve(['first row options']);
    expect(await first).toBe(false);
    expect(await repeated).toBe(false);
    expect(modal.options.value).toBeUndefined();
    expect(modal.form.value.name).toBe('second');
    optionsPending[1].resolve(['second row options']);
    expect(await second).toBe(true);
    expect(modal.options.value).toEqual(['second row options']);

    const closing = modal.edit(firstRow);
    modal.visible.value = false;
    expect(optionsPending[2].signal.aborted).toBe(true);
    const reopened = modal.edit(firstRow);
    optionsPending[2].resolve(['closed dialog options']);
    expect(await closing).toBe(false);
    optionsAuthorized.value = false;
    expect(optionsPending[3].signal.aborted).toBe(true);
    optionsPending[3].resolve(['withdrawn options']);
    expect(await reopened).toBe(false);
    expect(modal.options.value).toBeUndefined();
    expect(modal.optionsLoading.value).toBe(false);
    expect(modal.visible.value).toBe(true);
    authorized.value = false;
    expect(modal.visible.value).toBe(false);
  });

  it('blocks a second submit and a new modal intent while a save is running', async () => {
    let modal;
    let completeSave;
    const save = vi.fn(
      () =>
        new Promise((resolve) => {
          completeSave = resolve;
        })
    );
    const onSaved = vi.fn();
    open(
      defineComponent(() => {
        modal = useCrudModal({
          canOpen: () => true,
          createForm: () => ({ name: 'new' }),
          editForm: () => ({ name: 'changed' }),
          save,
          onSaved,
        });
        modal.formRef.value = {
          validate: async () => true,
          clearValidate() {},
        };
        return () => null;
      })
    );
    await modal.create();
    const first = modal.submit();
    expect(modal.submitLoading.value).toBe(true);
    expect(await modal.submit()).toBe(false);
    expect(await modal.edit(row())).toBe(false);
    expect(save).toHaveBeenCalledExactlyOnceWith(
      { name: 'new' },
      'create',
      expect.any(AbortSignal)
    );
    completeSave();
    expect(await first).toBe(true);
    expect(onSaved).toHaveBeenCalledOnce();
    expect(modal.visible.value).toBe(false);
  });
}
