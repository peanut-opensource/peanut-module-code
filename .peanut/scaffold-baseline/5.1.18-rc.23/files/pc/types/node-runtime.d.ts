declare module 'node:fs' {
  interface FileStat {
    isFile(): boolean;
    isSymbolicLink(): boolean;
  }

  export function existsSync(path: string): boolean;
  export function lstatSync(path: string): FileStat;
  export function readFileSync(path: string, encoding: 'utf8'): string;
  export function realpathSync(path: string): string;
}

declare module 'node:path' {
  export function isAbsolute(path: string): boolean;
  export function resolve(...paths: string[]): string;
}

declare const process: {
  readonly env: Readonly<Record<string, string | undefined>>;
};

interface ImportMeta {
  readonly dirname: string;
}
