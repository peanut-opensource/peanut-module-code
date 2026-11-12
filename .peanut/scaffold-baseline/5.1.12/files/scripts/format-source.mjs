import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, lstatSync, realpathSync } from 'node:fs';
import { createRequire } from 'node:module';
import {
  basename,
  dirname,
  extname,
  isAbsolute,
  relative,
  resolve,
} from 'node:path';
import { fileURLToPath } from 'node:url';

const codeRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const sourceExtensions = new Set([
  '.js',
  '.jsx',
  '.mjs',
  '.cjs',
  '.ts',
  '.tsx',
  '.vue',
  '.css',
  '.scss',
  '.sass',
  '.less',
  '.html',
]);
const protectedParts = new Set([
  'node_modules',
  'vendor',
  '.local',
  '.git',
  'fixtures',
  '__snapshots__',
  'generated',
  'dist',
  '.nuxt',
  '.output',
]);

function isAuthoredJsonConfiguration(path) {
  const name = basename(path);
  return (
    name === 'package.json' ||
    name === 'composer.json' ||
    /^tsconfig(?:\.[a-zA-Z0-9-]+)*\.json$/.test(name) ||
    /^server\/app\/modules\/[^/]+\/[^/]+\/module\.json$/.test(path) ||
    /^\.vscode\/(?:settings|extensions|launch|tasks)\.json$/.test(path)
  );
}

export function isFormattingSource(path) {
  return (
    !path.startsWith('scaffold/') &&
    !path.startsWith('output/') &&
    !path.split('/').some((part) => protectedParts.has(part)) &&
    (sourceExtensions.has(extname(path)) || isAuthoredJsonConfiguration(path))
  );
}

export function formatSources(arguments_) {
  let root = codeRoot;
  let write = false;
  let mode;
  const requested = [];
  for (const argument of arguments_) {
    if (argument === '--check' || argument === '--write') {
      if (mode && mode !== argument) throw new Error('FORMAT_MODE_CONFLICT');
      mode = argument;
      write = argument === '--write';
      continue;
    }
    if (argument.startsWith('--root=')) root = realpathSync(argument.slice(7));
    else if (argument.startsWith('-'))
      throw new Error('Unknown formatter option: ' + argument);
    else requested.push(argument);
  }
  root = realpathSync(root);
  const git = (...args) =>
    execFileSync('git', ['-C', root, ...args], { encoding: 'utf8' });
  if (realpathSync(git('rev-parse', '--show-toplevel').trim()) !== root) {
    throw new Error('FORMAT_EXACT_GIT_ROOT_REQUIRED');
  }
  const config = resolve(root, '.prettierrc.cjs');
  const ignore =
    root === codeRoot
      ? resolve(root, 'tools/quality/prettier-ignore')
      : resolve(root, '.prettierignore');
  if (!existsSync(config) || !existsSync(ignore))
    throw new Error('FORMAT_CONFIG_REQUIRED');
  const require = createRequire(
    resolve(codeRoot, 'tools/quality/package.json')
  );
  const prettier = require('prettier');
  if (prettier.version !== '2.8.8')
    throw new Error('FORMAT_LOCKED_PRETTIER_REQUIRED');
  const tracked = new Set(
    git('ls-files', '-z', ...(requested.length ? ['--', ...requested] : []))
      .split('\0')
      .filter(Boolean)
  );
  const candidates = requested.length === 0 ? [...tracked] : requested;
  const files = [];
  for (const path of candidates) {
    if (
      isAbsolute(path) ||
      path.split('/').includes('..') ||
      !tracked.has(path)
    ) {
      throw new Error('FORMAT_TRACKED_RELATIVE_FILE_REQUIRED: ' + path);
    }
    if (!isFormattingSource(path)) {
      if (requested.length)
        throw new Error('FORMAT_SOURCE_OUTSIDE_POLICY: ' + path);
      continue;
    }
    const absolute = resolve(root, path);
    if (
      !lstatSync(absolute).isFile() ||
      lstatSync(absolute).isSymbolicLink() ||
      relative(root, realpathSync(absolute)).startsWith('..')
    ) {
      throw new Error('FORMAT_SOURCE_OUTSIDE_ROOT: ' + path);
    }
    if (!prettier.getFileInfo.sync(absolute, { ignorePath: ignore }).ignored)
      files.push(path);
  }
  if (files.length === 0) throw new Error('FORMAT_EMPTY_SOURCE_SCOPE');
  files.sort();
  const cli = require.resolve('prettier/bin-prettier.js');
  for (let offset = 0; offset < files.length; offset += 100) {
    const result = spawnSync(
      process.execPath,
      [
        cli,
        write ? '--write' : '--check',
        '--config',
        config,
        '--ignore-path',
        ignore,
        ...files.slice(offset, offset + 100),
      ],
      { cwd: root, stdio: 'inherit' }
    );
    if (result.error) throw result.error;
    if (result.signal)
      throw new Error('FORMAT_PROCESS_INTERRUPTED: ' + result.signal);
    if (result.status !== 0) return result.status ?? 2;
  }
  console.log(
    `FORMAT-SOURCE-001 ${write ? 'formatted' : 'passed'}: ${
      files.length
    } files; Prettier ${prettier.version}`
  );
  return 0;
}

if (
  process.argv[1] &&
  resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
  try {
    process.exitCode = formatSources(process.argv.slice(2));
  } catch (error) {
    console.error(error.message);
    process.exitCode = 2;
  }
}
