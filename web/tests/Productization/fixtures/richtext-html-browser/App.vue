<template>
  <main>
    <h1>RichText 当前安装包 · 浏览器验证</h1>
    <p class="muted">Module dev 固定包 1.0.19-rc.1；直接使用实际 RichTextEditor.vue、html-import.ts 与22个原始测试输入。任务私有本地页面，不登录、不写数据库、不改产品路由。</p>
    <section>
      <div class="row"><button id="run-all" @click="runAll">重跑原始22组用例</button><span id="summary" class="stat">{{ summary }}</span><small>Chrome 真DOM vs happy-dom 14/22的差异</small></div>
      <div class="row"><label for="case">当前样本</label><select id="case" v-model.number="selected" @change="setCase"><option v-for="(t,i) in cases" :key="t.id" :value="i">{{ i+1 }}. {{ t.title }} ({{ t.expected }})</option></select><button id="run-selected" @click="runSelected">测试当前输入</button></div>
      <textarea id="html-source" aria-label="可审查的HTML源代码" v-model="htmlSource"></textarea>
      <div class="row"><button id="run-custom" @click="runCustom">检查文本框的自定义 HTML</button><small>所有未知元素、脚本、style、事件属性应被拒绝，而不是静默删除</small></div>
      <p class="stat" :class="lastResult?.ok ? 'good':'bad'">结果：{{ lastResult ? (lastResult.ok ? '允许预览' : '拒绝预览') : '尚未运行' }}</p>
      <pre id="result">{{ resultText }}</pre>
    </section>
    <section>
      <h2>当前包实际富文本编辑组件</h2>
      <RichTextEditor v-model="doc" context-key="task-richtext-html-20261126" @error="onEditorError" @ready="editorReady = true"/>
      <p>Editor ready: {{ editorReady }}；组件错误：{{ editorError || '无' }}</p>
      <p class="muted">只有可接受的 HTML 才会进入该真实组件；非法 HTML 不更新文档。</p>
    </section>
    <section>
      <h2>逐样本检查</h2>
      <p v-for="r in checks" :key="r.id" class="tag" :class="r.pass ? 'good':'bad'">{{r.pass ? 'PASS':'FAIL'}} {{r.id}} ({{r.reason}})</p>
      <pre id="checks">{{ JSON.stringify(checks, null, 2) }}</pre>
    </section>
  </main>
</template>
<script setup lang="ts">
import { ref, shallowRef, onMounted } from '../../../../node_modules/vue/dist/vue.runtime.esm-bundler.js';
import RichTextEditor from '../../../../src/modules/peanut-rich-text/components/RichTextEditor.vue';
import { emptyDocument, parseRichTextDocument } from '../../../../src/modules/peanut-rich-text/src/document.ts';
import { previewRichTextHtml } from '../../../../src/modules/peanut-rich-text/src/html-import.ts';
import { cases } from './cases';
type Result = ReturnType<typeof previewRichTextHtml>;
type Check = {id:string, pass:boolean, expected:string, actual:string, reason:string, exception?:string};
const selected=ref(0);
const htmlSource=ref<string>(cases[0].html);
const doc=shallowRef(emptyDocument());
const editorReady=ref(false);
const editorError=ref('');
const checks=ref<Check[]>([]);
const summary=ref('准备运行');
const lastResult=ref<Result|null>(null);
const resultText=ref('');
const onEditorError=(e:string)=>{editorError.value=e};
function checkDetail(id:string, res:Result): string{
  if(!res.ok) return res.limitations.length>0 && !('document' in res) ? 'REJECTED_WITH_REASON':'REJECTION_CONTRACT_INVALID';
  const body=res.document.content.content ?? [];
  if(JSON.stringify(parseRichTextDocument(res.document))!==JSON.stringify(res.document)) return 'DOCUMENT_PROJECTION_INVALID';
  if(id==='safe-structure'){
    const seq=body.map((b:any)=>b.type);
    return JSON.stringify(seq)===JSON.stringify(['heading','paragraph','blockquote','bulletList','codeBlock','horizontalRule']) && JSON.stringify(body[1]?.content?.[1]?.marks)===JSON.stringify([{type:'bold'}])?'MATCH':'BLOCKS_OR_MARKS_DIFFER';
  }
  if(id==='safe-link-list'){
    const list=body[0];
    return list?.attrs?.start===3 &&
      list?.content?.[0]?.content?.[0]?.content?.[0]?.marks?.[0]?.attrs?.href==='https://example.com/a?x=1&y=2'?'MATCH':'LINK_OR_LIST_DIFFER';
  }
  if(id==='safe-whitespace')
    return body[0]?.content?.[0]?.text===' a  b & <x> ' ? 'MATCH':'TEXT_DIFFER';
  if(id==='safe-merged-strike')
    return body[0]?.content?.length===1 &&
      body[0].content?.[0]?.text==='SD' &&
      body[0].content?.[0]?.marks?.[0]?.type==='strike' && JSON.stringify(body[0].content?.[0]?.marks)===JSON.stringify([{type:'strike'}])?'MATCH':'STRIKE_DIFFER';
  if(id==='safe-paragraph-links'){
    const ps=body.map((x:any)=>x.type);
    return body.length===2 && ps.every((x:any)=>x==='paragraph') &&
      body[1]?.content?.map((x:any)=>x.text).join('')==='BCD' && JSON.stringify(body[1]?.content?.slice(1).map((x:any)=>x.marks?.[0]?.attrs?.href))===JSON.stringify(['https://one.invalid','https://two.invalid'])?'MATCH':'PARA_OR_LINK_DIFFER';
  }
  return 'MATCH';
}
function runOne(input:string, expected:string, id:string, apply=true):Check {
  try{
    const r=previewRichTextHtml(input);
    const actual=r.ok?'allow':'reject';
    const detail=checkDetail(id,r);
    const pass=actual===expected && (expected==='reject' ? detail==='REJECTED_WITH_REASON' : detail==='MATCH');
    if(apply){
      lastResult.value=r;
      resultText.value=JSON.stringify(r,null,2);
      if(r.ok) doc.value=r.document;
    }
    return {id, pass, expected, actual, reason:detail};
  }catch(e){
    if(apply)resultText.value=String(e);
    return {id,pass:false,expected,actual:'exception',reason:'EXCEPTION',exception:String(e)};
  }
}
function setCase(){htmlSource.value=cases[selected.value].html}
function runSelected(){
  const t=cases[selected.value];
  const result=runOne(htmlSource.value,t.expected,t.id,true);
  checks.value=[result];
  summary.value=result.pass?'当前样本PASS':'当前样本FAIL';
}
function runCustom(){
  const result=runOne(htmlSource.value,'allow','custom',true);
  checks.value=[result];
  summary.value='自定义输入：'+result.actual+' / '+result.reason;
}
function runAll(){
  const values=cases.map(t=>runOne(t.html,t.expected,t.id,false));
  checks.value=values;
  const pass=values.filter(x=>x.pass).length;
  summary.value=pass+'/'+values.length+' PASS';
  setCase();
  // Exercise current RichTextEditor rendering with safe structured content.
  const allowed=runOne(cases[0].html,cases[0].expected,cases[0].id,true);
  return {total:values.length,passed:pass,failed:values.filter(x=>!x.pass),editorResult:allowed};
}
declare global {interface Window{__qaRichText?:{runAll:typeof runAll,runSelected:typeof runSelected,setCase:(n:number)=>void,results:()=>Check[],source:()=>string}}}
onMounted(()=>{
  window.__qaRichText={runAll,runSelected,setCase:(n:number)=>{selected.value=n;setCase()},results:()=>checks.value,source:()=>htmlSource.value};
  runAll();
});
</script>
