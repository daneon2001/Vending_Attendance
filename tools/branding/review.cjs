const {chromium}=require('playwright');const fs=require('node:fs');const path=require('node:path');
(async()=>{const dir=path.resolve(__dirname,'../../storage/app/private/branding-build10-review');fs.mkdirSync(dir,{recursive:true});const browser=await chromium.launch({channel:'chrome',headless:true});const results=[];
try{const context=await browser.newContext({viewport:{width:412,height:892},deviceScaleFactor:1});
await context.route('**/*',route=>route.request().url().startsWith('http://127.0.0.1:5189/')||route.request().url().startsWith('data:')?route.continue():route.abort());
const page=await context.newPage();let errors=[];page.on('pageerror',e=>{errors.push(e.message);console.log(e.stack);});
for(const view of ['login','home','device','activities','activity','menu','offline','error','about','empty','ownership']){
errors=[];console.log('Review',view);await page.goto('http://127.0.0.1:5189/tools/branding/review.html?view='+view);await page.waitForSelector('ion-content',{state:'attached'});await page.waitForTimeout(400);
const overflow=await page.evaluate(()=>[...document.querySelectorAll('main')].some(e=>e.scrollWidth>e.clientWidth+1));
await page.screenshot({path:path.join(dir,view+'.png')});
await page.evaluate(async()=>{const c=document.querySelector('ion-content');const s=await c.getScrollElement();s.scrollTop=s.scrollHeight;});await page.waitForTimeout(120);await page.screenshot({path:path.join(dir,view+'-bottom.png')});
results.push({view,overflow,errors:[...errors]});}
await page.setViewportSize({width:320,height:780});await page.goto('http://127.0.0.1:5189/tools/branding/review.html?view=home');await page.waitForTimeout(400);await page.screenshot({path:path.join(dir,'home-320.png')});
// Startup markup is the exact app shell, with application JS removed for a stable visual inspection.
let startup=fs.readFileSync(path.resolve(__dirname,'../../mobile/index.html'),'utf8').replace(/<script type="module"[\s\S]*?<\/script>/,'');
await page.setViewportSize({width:412,height:892});await page.route('**/startup-preview',r=>r.fulfill({contentType:'text/html',body:startup}));await page.goto('http://127.0.0.1:5189/startup-preview');await page.waitForTimeout(300);await page.screenshot({path:path.join(dir,'splash.png')});
fs.writeFileSync(path.join(dir,'results.json'),JSON.stringify(results,null,2));console.log(JSON.stringify(results));
}finally{await browser.close();}})();
