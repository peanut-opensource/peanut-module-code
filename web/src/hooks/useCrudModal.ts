import { useAsyncAction } from '@peanut-admin/vue';
import type { FormInstance } from 'element-plus';
import { computed, ref, shallowRef, watch, type Ref } from 'vue';
import useCrudTab from './useCrudTab';

export type CrudModalMode = 'create' | 'edit' | 'view';

interface CrudModalOptions<TForm, TRecord, TOptions> {
  canOpen: (mode: CrudModalMode) => boolean;
  createForm: () => TForm;
  editForm: (record: TRecord, mode: 'edit' | 'view') => TForm;
  canLoadOptions?: (mode: CrudModalMode) => boolean;
  loadOptions?: (signal: AbortSignal) => Promise<TOptions>;
  save: (
    form: TForm,
    mode: 'create' | 'edit',
    signal: AbortSignal
  ) => Promise<unknown>;
  onSaved?: () => void | Promise<void>;
  errorMessage?: (error: unknown) => string;
}

export default function useCrudModal<
  TForm extends object,
  TRecord,
  TOptions = never
>(config: CrudModalOptions<TForm, TRecord, TOptions>) {
  const shown = ref(false);
  const mode = ref<CrudModalMode>('create');
  const form = ref(config.createForm()) as Ref<TForm>;
  const formRef = shallowRef<FormInstance>();
  const options = shallowRef<TOptions>();
  const optionsLoading = ref(false);
  const optionsError = ref<string>();
  const errorMessage =
    config.errorMessage ??
    ((error: unknown) =>
      error instanceof Error ? error.message : String(error));
  const action = useAsyncAction(errorMessage);
  let opening = 0;
  let optionRequest:
    | {
        record: TRecord | undefined;
        controller: AbortController;
        promise: Promise<TOptions>;
      }
    | undefined;

  const discardOptions = () => {
    optionRequest?.controller.abort();
    optionRequest = undefined;
    options.value = undefined;
    optionsLoading.value = false;
    optionsError.value = undefined;
  };
  const close = () => {
    opening += 1;
    shown.value = false;
    discardOptions();
    action.cancel();
  };
  const visible = computed({
    get: () => shown.value,
    set: (value) => {
      if (!value) close();
    },
  });
  const current = useCrudTab(close);
  const mayLoadOptions = () => config.canLoadOptions?.(mode.value) ?? true;

  watch(
    mayLoadOptions,
    (allowed) => {
      if (!allowed) discardOptions();
    },
    { flush: 'sync' }
  );
  watch(
    () => config.canOpen(mode.value),
    (allowed) => {
      if (!allowed) close();
    },
    { flush: 'sync' }
  );

  const isOpen = (key: number) =>
    current.value &&
    shown.value &&
    key === opening &&
    config.canOpen(mode.value);

  async function open(nextMode: 'create'): Promise<boolean>;
  async function open(
    nextMode: 'edit' | 'view',
    record: TRecord
  ): Promise<boolean>;
  async function open(...args: ['create'] | ['edit' | 'view', TRecord]) {
    const [nextMode, record] = args;
    if (!current.value || action.loading.value || !config.canOpen(nextMode))
      return false;
    action.cancel();
    opening += 1;
    const key = opening;
    if (
      mode.value !== nextMode ||
      (optionRequest && !Object.is(optionRequest.record, record))
    )
      discardOptions();
    mode.value = nextMode;
    form.value =
      nextMode === 'create'
        ? config.createForm()
        : config.editForm(record, nextMode);
    formRef.value?.clearValidate();
    optionsError.value = undefined;
    shown.value = true;

    const load = config.loadOptions;
    if (!load || !mayLoadOptions()) return true;
    optionsLoading.value = true;
    let request = optionRequest;
    try {
      if (!request) {
        const controller = new AbortController();
        request = { record, controller, promise: load(controller.signal) };
        optionRequest = request;
      }
      const result = await request.promise;
      if (!isOpen(key) || optionRequest !== request || !mayLoadOptions())
        return false;
      options.value = result;
      return true;
    } catch (error) {
      const rejectedCurrentRequest = optionRequest === request;
      if (rejectedCurrentRequest) optionRequest = undefined;
      if (!isOpen(key) || !rejectedCurrentRequest || !mayLoadOptions())
        return false;
      optionsError.value = errorMessage(error);
      return false;
    } finally {
      if (isOpen(key)) optionsLoading.value = false;
    }
  }

  const submit = async () => {
    const key = opening;
    const activeMode = mode.value;
    if (
      !isOpen(key) ||
      activeMode === 'view' ||
      optionsLoading.value ||
      optionsError.value !== undefined
    )
      return false;
    const result = await action.run(async ({ signal }) => {
      const valid = await formRef.value?.validate().catch(() => false);
      if (!valid || !isOpen(key) || signal.aborted) return false;
      await config.save(form.value, activeMode, signal);
      return true;
    });
    if (result.status !== 'completed' || !result.data || !isOpen(key))
      return false;
    close();
    await config.onSaved?.();
    return true;
  };

  return {
    create: () => open('create'),
    edit: (record: TRecord) => open('edit', record),
    view: (record: TRecord) => open('view', record),
    open,
    submit,
    form,
    formRef,
    mode,
    visible,
    options,
    optionsLoading,
    optionsError,
    submitError: action.error,
    submitLoading: action.loading,
  };
}
