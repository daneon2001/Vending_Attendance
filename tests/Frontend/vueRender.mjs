// Node-only component rendering. Not a browser or visual certification.
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { compileScript, parse } from '@vue/compiler-sfc';
import { readFile } from 'node:fs/promises';
import { dirname, resolve, extname } from 'node:path';
import { pathToFileURL } from 'node:url';

export async function renderVue(file, props = {}, permissions = {}, { modules = {} } = {}) {
 const page = { url:'/vending', props:{auth:{permissions, user:{name:'Piloto',email:'pilot@example.test'}, roles:[]}} };
 const cache = new Map();
 const container = { setup:(_, {slots}) => () => Vue.h('main', [slots.header?.(),slots.default?.()]) };
 const inertia = {
  usePage: () => page,
  Head: { render: () => null },
  Link: { props:['href'],setup:(p,{slots})=>()=>Vue.h('a',{href:p.href},slots.default?.()) },
  router: {},
  useForm: (values) => Vue.reactive({...values, errors:{}, processing:false, reset(){},post(){},patch(){},put(){}}),
 };
 async function load(fileName) {
  const absolute = resolve(fileName);
  if(cache.has(absolute)) return cache.get(absolute);
  const source = await readFile(absolute,'utf8');
  let {descriptor} = parse(source,{filename:absolute});
  if(!descriptor.script && !descriptor.scriptSetup) descriptor = parse('<script setup>const renderingOnly = true;</script>\n'+source,{filename:absolute}).descriptor;
  let code = compileScript(descriptor,{id:absolute,inlineTemplate:true}).content;
  const imports = [];
  const matches = [...code.matchAll(/import\s+([\s\S]*?)\s+from\s+['"]([^'"]+)['"];?/g)];
  for(const match of matches) {
   const [,binding,spec] = match;
   let module;
   if(spec === 'vue') module = Vue;
   else if(spec === '@inertiajs/vue3') module = inertia;
   else if(spec === 'axios') module = {default:{get(){throw new Error('Unexpected automatic request');}}};
   else if(spec.endsWith('Layouts/AuthenticatedLayout.vue')) module = {default:container};
   else if(spec.endsWith('Components/ApplicationLogo.vue')) module = {default:{render:()=>Vue.h('span','Medical Life')}};
   else if(spec.endsWith('Components/Modal.vue')) module = {default:{props:['show'],setup:(p,{slots})=>()=>p.show?Vue.h('dialog',slots.default?.()):null}};
   else if(spec.endsWith('utils/url')) module = {apiUrl:path=>path};
   else {
    let target = spec.startsWith('@/') ? resolve('resources/js',spec.slice(2)) : resolve(dirname(absolute),spec);
    if(!extname(target)) target += '.js';
    module = target.endsWith('.vue') ? {default:await load(target)}
     : target.endsWith('.json') ? {default:JSON.parse(await readFile(target,'utf8'))}
     : await import(pathToFileURL(target).href);
   }
   // Explicit test-only fixtures can initialize a workflow at an existing step.
   if (Object.prototype.hasOwnProperty.call(modules, spec)) module = { ...module, ...modules[spec] };
   const index = imports.push(module)-1;
   const declaration = binding.trim().startsWith('{') ? binding.replace(/\bas\b/g,':') : binding.trim();
   const expression = binding.trim().startsWith('{') ? '__imports['+index+']' : '__imports['+index+'].default';
   code = code.replace(match[0],'const '+declaration+' = '+expression+';');
  }
  code = code.replace('export default','return');
  const route = (name,params) => name ? '/'+name+(params?'?'+JSON.stringify(params):'') : {current:()=>false};
  const component = new Function('__imports','route',code)(imports,route);
  cache.set(absolute,component);
  return component;
 }
 const app = Vue.createSSRApp(await load(file),props);
 app.config.globalProperties.route = (name,params) => name ? '/'+name+(params?'?'+JSON.stringify(params):'') : {current:()=>false};
 app.config.globalProperties.$page = page;
 return renderToString(app);
}
