const opt = Object.prototype.toString;

export function isArray(obj: unknown): obj is unknown[] {
  return Array.isArray(obj);
}

export function isObject(obj: unknown): obj is Record<string, unknown> {
  return (
    typeof obj === 'object' &&
    obj !== null &&
    opt.call(obj) === '[object Object]'
  );
}

export function isString(obj: unknown): obj is string {
  return typeof obj === 'string';
}

export function isNumber(obj: unknown): obj is number {
  return typeof obj === 'number' && !Number.isNaN(obj);
}

export function isRegExp(obj: unknown): boolean {
  return opt.call(obj) === '[object RegExp]';
}

export function isFile(obj: unknown): obj is File {
  return opt.call(obj) === '[object File]';
}

export function isBlob(obj: unknown): obj is Blob {
  return opt.call(obj) === '[object Blob]';
}

export function isUndefined(obj: unknown): obj is undefined {
  return obj === undefined;
}

export function isNull(obj: unknown): obj is null {
  return obj === null;
}

export function isFunction(obj: unknown): obj is (...args: never[]) => unknown {
  return typeof obj === 'function';
}

export function isEmptyObject(obj: unknown): boolean {
  return isObject(obj) && Object.keys(obj).length === 0;
}

export function isExist(obj: unknown): boolean {
  return Boolean(obj) || obj === 0;
}

export function isWindow(el: unknown): el is Window {
  return typeof window !== 'undefined' && el === window;
}
