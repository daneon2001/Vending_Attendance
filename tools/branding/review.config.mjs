import { defineConfig } from '../../mobile/node_modules/vite/dist/node/index.js';
import vue from '../../mobile/node_modules/@vitejs/plugin-vue/dist/index.mjs';
import path from 'node:path';
const root=path.resolve(import.meta.dirname,'../..');
const variables={
 'HomePage.vue':'terminalActive,employees,canUseActivities,showAttendance,hasTerminal,filteredEmployees,search,connectivity,state,formattedLastSync,sync,deviceStatusLabel,syncPhaseLabel',
 'FieldMobilePage.vue':'flow,unavailable,email,password,code,login,verify,backingLabel',
 'FieldActivitiesPage.vue':'uuid,flow,unavailable,offline,pendingCount,localMessage,editing,refresh,act,emptyActivitiesMessage,activityNotificationLabel,date,activityStatus,zoneLabel,note,saveNote,completionPending,captures,pendingNotes,pendingItems,previews,operation,delivery,complete',
 'DiagnosticsPage.vue':'busy,message,rows,refresh',
 'StartupErrorPage.vue':'retry',
};
export default defineConfig({root,publicDir:path.join(root,'mobile/public'),server:{host:'127.0.0.1',port:5189,strictPort:true},
 resolve:{alias:{'@':path.join(root,'mobile/src'),'vue':path.join(root,'mobile/node_modules/vue/dist/vue.runtime.esm-bundler.js'),'@ionic/vue':path.join(root,'mobile/node_modules/@ionic/vue'),'vue-router':path.join(root,'mobile/node_modules/vue-router')}},
 plugins:[{name:'isolated-visual-fixtures',enforce:'pre',transform(code,id){
  const key=Object.keys(variables).find(name=>id.endsWith('/'+name));if(!key)return;
  const setup=`<script setup lang="ts">\nimport BrandIdentity from '@/components/BrandIdentity.vue';\nimport TerminalStatus from '@/components/TerminalStatus.vue';\nlet {${variables[key]}}=window.__visualFixture;\n</script>`;
  return code.replace(/<script setup[\s\S]*?<\/script>/,setup);
 }},vue()]
});
