import {createApp,h} from 'vue';
import * as Ionic from '@ionic/vue';
import '@ionic/vue/css/core.css';import '@ionic/vue/css/normalize.css';import '@ionic/vue/css/structure.css';import '@ionic/vue/css/typography.css';
import '/mobile/src/theme/variables.css';import '/mobile/src/theme/brand.css';
import AppStyle from '/mobile/src/App.vue';
const mode=new URLSearchParams(location.search).get('view')||'home';
const noop=()=>{};
const f={terminalActive:true,employees:[{}],canUseActivities:true,showAttendance:false,hasTerminal:true,filteredEmployees:[],search:'',connectivity:mode==='offline'?'OFFLINE':'ONLINE',state:{phase:'IDLE',summary:{pendingEvents:0,deviceStatus:'ACTIVE'}},formattedLastSync:'Hoy',sync:noop,deviceStatusLabel:()=> 'Activo',syncPhaseLabel:()=> 'Disponible',
unavailable:'',email:'',password:'',code:'',login:noop,verify:noop,backingLabel:'Hardware',
uuid:mode==='activity'?'visual-demo':null,offline:mode==='offline',pendingCount:0,localMessage:'',editing:false,refresh:noop,act:noop,emptyActivitiesMessage:()=> 'No tienes actividades asignadas.',activityNotificationLabel:()=> 'Actividad asignada',date:()=> '14/9/2026',activityStatus:()=> 'Completada',zoneLabel:()=> 'Dentro de la zona',note:'',saveNote:noop,completionPending:false,captures:[],pendingNotes:[],pendingItems:[],previews:{},operation:()=> ({}),delivery:()=> 'Pendiente',complete:noop,
busy:false,message:'Consulta finalizada.',rows:[{label:'Producto',value:'Vending Attendance · MEDICAL LIFE ONE'},{label:'Versión instalada',value:'1.0.1-beta.1'},{label:'Compilación',value:'10'},{label:'Entorno',value:'Beta interna · DEMO local'},{label:'Biometría',value:'No habilitada'}],retry:noop};
f.flow={step:mode==='login'?'login':mode==='ownership'?'blocked':'active',busy:false,error:mode==='ownership'?'No se puede continuar con este registro. Consulta el estado o solicita apoyo.':'',profile:mode==='login'?null:{employee:{name:'Técnico de demostración',number:'DEMO'},phone:'••••',devices:[]},signatureConfirmed:true,receipt:null,open:noop,logout:noop,
rows:mode==='empty'?[]:[{uuid:'visual-demo',title:'Mantenimiento DEMO con evidencias',machine:'VM-DEMO-001',type_label:'Mantenimiento',status:'COMPLETED'}],notifications:{unread_count:0,notifications:[]},page:1,hasMore:false,pending:null,activity:{uuid:'visual-demo',title:'Mantenimiento DEMO con evidencias',description:'Revisión visual de la actividad.',machine:'VM-DEMO-001',employee:'Técnico de demostración',type_label:'Mantenimiento',status:'COMPLETED',notes:[]}};
window.__visualFixture=f;
const file=['home','menu','offline'].includes(mode)?'views/HomePage.vue':['login','device','ownership'].includes(mode)?'fieldIdentity/FieldMobilePage.vue':['activities','activity','empty'].includes(mode)?'fieldSupport/FieldActivitiesPage.vue':mode==='error'?'views/StartupErrorPage.vue':'views/DiagnosticsPage.vue';
const pages=import.meta.glob('/mobile/src/{views,fieldIdentity,fieldSupport}/*Page.vue');
const page=await pages['/mobile/src/'+file]();
const app=createApp({render:()=>h(Ionic.IonApp,()=>h(page.default))});app.use(Ionic.IonicVue,{mode:'md'});
for(const [name,value] of Object.entries(Ionic))if(/^Ion[A-Z]/.test(name)&&name!=='IonicVue')app.component(name,value);
app.component('TerminalGeofence',{render:()=>null});app.mount('#app');
