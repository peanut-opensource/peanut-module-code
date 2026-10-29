import type {
  DecorationComponent,
  DecorationContent,
  DecorationItem,
  DecorationLink,
  DecorationPage,
} from './decoration';

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function invalid(): never {
  throw new Error('DECORATION_RESPONSE_INVALID');
}

function objectField(value: unknown): Record<string, unknown> {
  // PHP decodes JSON objects to associative arrays; empty object slots may be [].
  if (Array.isArray(value) && value.length === 0) return {};
  if (!record(value)) return invalid();
  return value;
}

function optionalStrings(value: Record<string, unknown>, keys: string[]): void {
  if (
    keys.some(
      (key) => value[key] !== undefined && typeof value[key] !== 'string'
    )
  )
    invalid();
}

function optionalNumbers(value: Record<string, unknown>, keys: string[]): void {
  if (
    keys.some(
      (key) =>
        value[key] !== undefined &&
        (typeof value[key] !== 'number' || !Number.isFinite(value[key]))
    )
  )
    invalid();
}

function readLink(value: unknown): DecorationLink {
  if (!record(value) || typeof value.target !== 'string') return invalid();
  const type = value.target_type;
  if (
    type !== 'shop' &&
    type !== 'article' &&
    type !== 'custom' &&
    type !== 'mini_program'
  )
    return invalid();
  if (value.query === undefined)
    return { ...value, target_type: type, target: value.target };
  const query = objectField(value.query);
  optionalStrings(query, ['app_id', 'web_url']);
  const env = query.env_version;
  if (
    env !== undefined &&
    env !== 'develop' &&
    env !== 'trial' &&
    env !== 'release'
  )
    return invalid();
  return {
    ...value,
    target_type: type,
    target: value.target,
    query: {
      ...query,
      app_id: typeof query.app_id === 'string' ? query.app_id : undefined,
      web_url: typeof query.web_url === 'string' ? query.web_url : undefined,
      env_version: env,
    },
  };
}

function readItem(value: unknown): DecorationItem {
  if (
    !record(value) ||
    typeof value.image !== 'string' ||
    typeof value.name !== 'string'
  )
    return invalid();
  if (value.is_show !== undefined && value.is_show !== 0 && value.is_show !== 1)
    return invalid();
  if (value.bg !== undefined && typeof value.bg !== 'string') return invalid();
  return {
    ...value,
    image: value.image,
    name: value.name,
    link: readLink(value.link),
    is_show: value.is_show,
    bg: value.bg,
  };
}

function readContent(value: unknown): DecorationContent {
  const data = objectField(value);
  optionalStrings(data, [
    'title',
    'title_img',
    'bg_color',
    'bg_image',
    'time',
    'mobile',
    'qrcode',
    'remark',
  ]);
  optionalNumbers(data, [
    'title_type',
    'bg_type',
    'text_color',
    'style',
    'bg_style',
    'per_line',
    'show_line',
    'enabled',
  ]);
  if (data.data !== undefined && !Array.isArray(data.data)) return invalid();
  // The precise properties are obtained by checks, not by retyping the entire record.
  return {
    ...data,
    title: typeof data.title === 'string' ? data.title : undefined,
    title_img: typeof data.title_img === 'string' ? data.title_img : undefined,
    bg_color: typeof data.bg_color === 'string' ? data.bg_color : undefined,
    bg_image: typeof data.bg_image === 'string' ? data.bg_image : undefined,
    time: typeof data.time === 'string' ? data.time : undefined,
    mobile: typeof data.mobile === 'string' ? data.mobile : undefined,
    qrcode: typeof data.qrcode === 'string' ? data.qrcode : undefined,
    remark: typeof data.remark === 'string' ? data.remark : undefined,
    title_type:
      typeof data.title_type === 'number' ? data.title_type : undefined,
    bg_type: typeof data.bg_type === 'number' ? data.bg_type : undefined,
    text_color:
      typeof data.text_color === 'number' ? data.text_color : undefined,
    style: typeof data.style === 'number' ? data.style : undefined,
    bg_style: typeof data.bg_style === 'number' ? data.bg_style : undefined,
    per_line: typeof data.per_line === 'number' ? data.per_line : undefined,
    show_line: typeof data.show_line === 'number' ? data.show_line : undefined,
    enabled: typeof data.enabled === 'number' ? data.enabled : undefined,
    ...(Array.isArray(data.data)
      ? { data: data.data.map(readItem) }
      : { data: undefined }),
  };
}

function readComponent(value: unknown): DecorationComponent {
  if (
    !record(value) ||
    typeof value.title !== 'string' ||
    typeof value.name !== 'string'
  )
    return invalid();
  if (
    value.disabled !== undefined &&
    value.disabled !== 0 &&
    value.disabled !== 1
  )
    return invalid();
  const styles: Record<string, string | number> = {};
  Object.entries(objectField(value.styles)).forEach(([key, item]) => {
    if (
      typeof item !== 'string' &&
      (typeof item !== 'number' || !Number.isFinite(item))
    )
      invalid();
    styles[key] = item;
  });
  return {
    ...value,
    title: value.title,
    name: value.name,
    disabled: value.disabled,
    content: readContent(value.content),
    styles,
  };
}

export default function readDecorationPage(value: unknown): DecorationPage {
  if (
    !record(value) ||
    typeof value.id !== 'number' ||
    !Number.isSafeInteger(value.id) ||
    typeof value.type !== 'number' ||
    !Number.isSafeInteger(value.type) ||
    typeof value.name !== 'string'
  )
    return invalid();
  const updateTime = value.update_time;
  if (
    updateTime !== undefined &&
    typeof updateTime !== 'string' &&
    (typeof updateTime !== 'number' || !Number.isFinite(updateTime))
  )
    return invalid();
  const data = Array.isArray(value.data)
    ? value.data.map(readComponent)
    : objectField(value.data);
  const meta = Array.isArray(value.meta)
    ? value.meta.map(readComponent)
    : objectField(value.meta);
  return {
    ...value,
    id: value.id,
    type: value.type,
    name: value.name,
    data,
    meta,
    update_time: updateTime,
  };
}
