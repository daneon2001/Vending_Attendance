const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
 const root=path.resolve(__dirname,'../..'), dir=path.join(root,'mobile/public/brand');
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try {
  const page=await browser.newPage();
  const manifestPath=path.join(root,'docs/branding/assets-manifest.json');
  const manifest=JSON.parse(fs.readFileSync(manifestPath,'utf8').replace(/^\uFEFF/,''));
  for(const entry of [...manifest].filter(e=>e.file.startsWith('dispenser-'))) {
   const input=fs.readFileSync(path.join(dir,entry.file));
   const data=await page.evaluate(async b64=>{const img=new Image();img.src='data:image/png;base64,'+b64;await img.decode();const canvas=document.createElement('canvas');canvas.width=img.width;canvas.height=img.height;canvas.getContext('2d').drawImage(img,0,0);return canvas.toDataURL('image/webp',.88).split(',')[1];},input.toString('base64'));
   const bytes=Buffer.from(data,'base64'), name=entry.file.replace('.png','.webp');
   fs.writeFileSync(path.join(dir,name),bytes);
   // Keep lossless crop outside the distributed bundle.
   fs.renameSync(path.join(dir,entry.file),path.join(root,'docs/branding/source',entry.file));
   manifest.push({...entry,file:name,bytes:bytes.length,sha256:require('node:crypto').createHash('sha256').update(bytes).digest('hex')});
   console.log(name,bytes.length);
  }
  fs.writeFileSync(manifestPath,JSON.stringify(manifest,null,2)+'\n');
  fs.copyFileSync(path.join(dir,'one-symbol.png'),path.join(root,'public/images/medical-life-one-mark.png'));
  fs.copyFileSync(path.join(dir,'dispenser-banner.webp'),path.join(root,'public/images/medical-life-dispenser-banner.webp'));
 } finally { await browser.close(); }
})();
