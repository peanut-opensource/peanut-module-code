import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const require = createRequire(new URL('../../package.json', import.meta.url));
const { createSSRApp, compile, h } = require('vue');
const { renderToString } = require('vue/server-renderer');
const { parse } = require('vue/compiler-sfc');
const root = fileURLToPath(new URL('../../', import.meta.url));
const source = readFileSync(root+'src/views/system/menu/index.vue','utf8');
const { descriptor, errors } = parse(source, { filename:'src/views/system/menu/index.vue' });
assert.deepEqual(errors, []);
function descend(node,fn,out=[]) {
  if(node.type===1&&fn(node))out.push(node);
  for(const child of node.children??[])descend(child,fn,out);
  return out;
}
function expr(node,name,arg) {
  return node.props.find(p=>p.type===7&&p.name===name&&(arg===undefined||p.arg?.content===arg))?.exp?.content;
}
const col=descend(descriptor.template.ast,n=>
  n.tag==='el-table-column'&&expr(n,'bind','label')?.includes('systemMenu.columns.status'))[0];
assert.ok(col);
test('real Admin Menu status cell shows read-only active, disabled and retired without modification',async()=>{
  const tsEvent='(v: string | number | boolean) => handleStatus(row, v as boolean)';
  assert.ok(col.loc.source.includes(tsEvent));
  const render=compile('<div>'+col.loc.source.replace(tsEvent,'(v) => handleStatus(row,v)')+'</div>');
  const dictionary={
    'systemMenu.columns.status':'状态',
    'systemMenu.status.enabled':'启用',
    'systemMenu.status.disabled':'停用',
    'systemMenu.status.retired':'已退役'
  };
  async function check(permission,status,is_disable) {
    const calls=[];
    const app=createSSRApp({setup:()=>({
      canManageMenus:()=>{calls.push('core.menu.manage');return permission;},
      handleStatus:()=>{throw Error('read-only render cannot write')}
    }),render});
    app.config.globalProperties.$t=(key)=>dictionary[key]??key;
    app.component('el-table-column',{setup(_,slots){return()=>h('div',{class:'menu-status'},slots.slots.default?.({row:{status,is_disable}}))}});
    app.component('el-switch',{props:['modelValue','disabled'],setup(p){return()=>h('button',{'data-test':'menu-toggle',disabled:p.disabled},p.modelValue?'on':'off')}});
    app.component('el-tag',{setup(_,ctx){return()=>h('span',{'data-test':'menu-status-text'},ctx.slots.default?.())}});
    return {html:await renderToString(app),calls};
  }
  for(const [status,disabled,label] of [['active',0,'启用'],['active',1,'停用'],['retired',0,'已退役']]){
    const out=await check(false,status,disabled);
    assert.match(out.html,/data-test="menu-status-text"/);
    assert.ok(out.html.includes(label),out.html);
    assert.doesNotMatch(out.html,/data-test="menu-toggle"/);
    assert.deepEqual(out.calls,['core.menu.manage']);
  }
  const allowed=await check(true,'active',0);
  assert.match(allowed.html,/data-test="menu-toggle"/);
  assert.doesNotMatch(allowed.html,/data-test="menu-status-text"/);
  assert.deepEqual(allowed.calls,['core.menu.manage']);
});
test('real Menu page keeps native read/write permission and status API separate',()=>{
  const router=readFileSync(root+'src/router/routes/modules/system.ts','utf8');
  const routes=readFileSync(root+'../server/route/admin.php','utf8');
  assert.match(router,/name: 'SystemMenu'/);
  assert.match(router,/requiredPermissions: \['core.menu.read'\]/);
  assert.match(routes,/Route::get\('menu.lists',[^\n]*'peanut_permission' => 'core.menu.read'/);
  assert.match(routes,/Route::post\('menu.status',[^\n]*'peanut_permission' => 'core.menu.manage'/);
  assert.match(source,/hasPermission\('core.menu.manage', route.path\)/);
  assert.match(source,/<el-table[\s\S]*:data="renderData"/);
  for(const locale of ['zh-CN','en-US']){
    const loc=readFileSync(root+'src/views/system/menu/locale/'+locale+'.ts','utf8');
    for(const key of ['enabled','disabled','retired'])assert.ok(loc.includes('systemMenu.status.'+key));
  }
});
