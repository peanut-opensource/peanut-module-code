import{T as e,l as t,p as n,w as r}from"./BNYOWs_P.js";import{B as i,Bt as a,G as o}from"./CsjZh4Mc.js";var s={prefix:Math.floor(Math.random()*1e4),current:0},c=Symbol(`elIdInjection`),l=()=>i()?o(c,s):s,u=i=>{let o=l();!e&&o===s&&n(`IdInjection`,`Looks like you are using server rendering, you must provide a id provider to ensure the hydration process to be succeed
usage: app.provide(ID_INJECTION_KEY, {
  prefix: number,
  current: number,
})`);let c=t();return r(()=>a(i)||`${c.value}-id-${o.prefix}-${o.current++}`)};export{l as n,u as t};