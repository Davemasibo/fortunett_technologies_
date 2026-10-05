// Real Chromium layout checks against synthetic previews. No external mail or HTTP.
const {spawn}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {pathToFileURL}=require('node:url');
const root=path.resolve(__dirname,'..');
const executable=process.env.EMAIL_BROWSER || ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(p=>fs.existsSync(p));
if (!executable) throw new Error('Set EMAIL_BROWSER to a Chromium executable.');
const profile=path.join(root,'tmp','email-layout-browser');fs.mkdirSync(profile,{recursive:true});
const browser=spawn(executable,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
const delay=ms=>new Promise(r=>setTimeout(r,ms));
let socket;
(async()=>{
 let port;
 for(let i=0;i<100;i++) {
  try {port=fs.readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0];await fetch(`http://127.0.0.1:${port}/json/version`);break;} catch(e){port=null;await delay(100);}
 }
 if(!port) throw new Error('Browser debug endpoint unavailable.');
 const target=await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`,{method:'PUT'})).json();
 socket=new WebSocket(target.webSocketDebuggerUrl);
 await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
 let sequence=0;const pending=new Map();const events=new Map();
 socket.onmessage=event=>{const msg=JSON.parse(event.data);if(msg.id){const entry=pending.get(msg.id);pending.delete(msg.id);if(msg.error)entry.reject(new Error(msg.error.message));else entry.resolve(msg.result);}else if(events.has(msg.method)){events.get(msg.method)();events.delete(msg.method);}};
 const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;pending.set(id,{resolve,reject});socket.send(JSON.stringify({id,method,params}));});
 await send('Page.enable');
 for(const width of [390,800]) {
  await send('Emulation.setDeviceMetricsOverride',{width,height:1200,deviceScaleFactor:1,mobile:width<600});
  for(const name of ['onboarding','customer']) {
   const loaded=new Promise(resolve=>events.set('Page.loadEventFired',resolve));
   await send('Page.navigate',{url:pathToFileURL(path.join(root,'artifacts','email-preview',name+'.html')).href});await loaded;
   const result=await send('Runtime.evaluate',{expression:`JSON.stringify({viewport:innerWidth,width:document.documentElement.scrollWidth,button:[...document.querySelectorAll('a')].find(a=>a.style.background==='rgb(37, 99, 235)')?.getBoundingClientRect().toJSON()})`,returnByValue:true});
   const metrics=JSON.parse(result.result.value);
   assert.equal(metrics.viewport,width,'viewport must match intended device');
   assert.ok(metrics.width<=width,`${name} overflows at ${width}px`);
   assert.ok(metrics.button && metrics.button.height>=44,'Action button must have a comfortable tap target');
   const layout=await send('Page.getLayoutMetrics');
   const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width,height:Math.ceil(layout.cssContentSize.height),scale:1}});
   fs.writeFileSync(path.join(root,'artifacts','email-preview',`${name}-${width<600?'mobile':'desktop'}.png`),Buffer.from(shot.data,'base64'));
   console.log(`PASS: ${name} at ${width}px fits without horizontal overflow; action target >=44px.`);
  }
 }
 await send('Browser.close');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(()=>{if(socket)socket.close();browser.kill();});
