import { lstatSync, mkdirSync, readFileSync, readdirSync, realpathSync, rmdirSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

// Application link targets are independent of which Core checkout is selected.
// Same atomic directory/token/inode contract as the Core output writers.
export function acquireClientLinks(repository, clients, operation) {
  repository = realpathSync(repository);
  const names = [...new Set(clients)].sort();
  if (!names.length || names.some(name => !['php', 'web', 'platform', 'pc', 'uniapp'].includes(name))) throw new Error('Invalid client link writer set');
  let parent = repository;
  for (const name of ['.local', 'core-links']) {
    parent = join(parent, name);
    try { mkdirSync(parent); } catch (error) { if (error.code !== 'EEXIST') throw error; }
    const stat = lstatSync(parent);
    if (!stat.isDirectory() || stat.isSymbolicLink()) throw new Error(`Invalid link writer directory: ${parent}`);
  }
  const owned = [];
  function release() {
    for (const item of owned.splice(0).reverse()) {
      try {
        const stat = lstatSync(item.path);
        const files = readdirSync(item.path);
        const ownerPath = join(item.path, 'owner.json');
        const ownerStat = lstatSync(ownerPath);
        const owner = JSON.parse(readFileSync(ownerPath, 'utf8'));
        if (stat.dev !== item.stat.dev || stat.ino !== item.stat.ino || stat.isSymbolicLink()
            || files.length !== 1 || files[0] !== 'owner.json' || !ownerStat.isFile() || ownerStat.isSymbolicLink()
            || ownerStat.nlink !== 1 || owner.token !== item.token || owner.pid !== process.pid) throw new Error('ownership changed');
        unlinkSync(ownerPath);
        rmdirSync(item.path);
      } catch (error) { console.error(`Link writer retained; inspect ${item.path}: ${error.message}`); }
    }
  }
  try {
    for (const client of names) {
      const path = join(parent, client);
      try { mkdirSync(path); }
      catch (error) { throw new Error(`Client link writer unavailable: ${client}; inspect ${path}/owner.json (${error.code})`); }
      const token = randomUUID();
      writeFileSync(join(path, 'owner.json'), JSON.stringify({ repository, client, operation, token, pid: process.pid }) + '\n', { flag: 'wx', mode: 0o600 });
      owned.push({ path, token, stat: lstatSync(path) });
    }
  } catch (error) { release(); throw error; }
  return { release };
}

function assertPhpIdle(repository) {
  // Native PHP consumers expose the canonical router path in their command.
  const processes = spawnSync('ps', ['-axo', 'comm=,args='], { encoding: 'utf8' });
  if (processes.status !== 0 || !processes.stdout) throw new Error('Cannot inspect native PHP runtime consumers');
  for (const line of processes.stdout.split('\n')) {
    const match = line.match(/^\s*(\S+)\s+(.*)$/);
    if (match && /(?:^|\/)php(?:[0-9.]*)$/.test(match[1]) && match[2].includes(join(repository, 'server', 'public', 'router.php'))) {
      throw new Error('Native PHP runtime is active (including custom PID files); wait for its owner to stop it normally');
    }
  }
}

// PHP's native runtime is checked under this record before dependency/config
// writes. The selected command keeps the link writer until its children exit.
if (process.argv[1] && process.argv[1] !== '-' && import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href) {
  const [repository, names, operation, separator, command, ...args] = process.argv.slice(2);
  if (separator !== '--' || !command) throw new Error('Invalid client link writer command');
  const root = realpathSync(repository);
  const lease = acquireClientLinks(root, names.split(','), operation);
  let completed = false;
  process.on('exit', () => { if (completed) lease.release(); });
  try { if (names.split(',').includes('php') && operation !== 'php-read') assertPhpIdle(root); }
  catch (error) { completed = true; throw error; }
  const child = spawn(command, args, { stdio: 'inherit', detached: true });
  let closed = false;
  let status = 1;
  let stopping = false;
  let timer;
  const signal = name => {
    if (!child.pid) return;
    try { process.kill(-child.pid, name); } catch (error) { if (error.code !== 'ESRCH') throw error; }
  };
  for (const [name, code] of [['SIGINT', 130], ['SIGTERM', 143], ['SIGHUP', 129]]) process.on(name, () => {
    if (stopping) return;
    stopping = true;
    status = code;
    signal('SIGTERM');
    timer = setTimeout(() => signal('SIGKILL'), 5000);
  });
  child.on('error', error => { console.error(error.message); closed = true; });
  child.on('close', code => { closed = true; if (!stopping) status = code ?? 1; });
  await new Promise(resolve => {
    const poll = setInterval(() => {
      if (!closed) return;
      if (child.pid) {
        try { process.kill(-child.pid, 0); return; }
        catch (error) { if (error.code !== 'ESRCH') { console.error(error.message); return; } }
      }
      clearInterval(poll);
      clearTimeout(timer);
      resolve();
    }, 50);
  });
  process.exitCode = status;
  completed = true;
}
