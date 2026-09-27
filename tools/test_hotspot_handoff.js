const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync(require('node:path').join(__dirname, '../hotspot/login.html'), 'utf8');
const source = html.slice(html.indexOf('function autoConnect('), html.indexOf('function showResult('));
for (const connected of [true, false]) {
    let submissions = 0, destination = '', result;
    const elements = new Map();
    const ctx = {TID:1, sessionStorage:{removeItem(){}}, escHtml:s=>s,
        document:{getElementById(id){
            if(!elements.has(id))elements.set(id,{value:id==='auto-dst'?'https://portal.test/landing':'',style:{},submit(){submissions++;}});
            return elements.get(id);
        }}, window:{location:{assign(url){destination=url;}}},
        showResult(...args){result=args;},setTimeout(fn){fn();}};
    vm.createContext(ctx);vm.runInContext(source,ctx);
    ctx.autoConnect('254712345678','0123','result','Account connected',connected);
    assert.equal(result[1],null);
    assert.equal(result[2],'Connecting...');
    assert.equal(submissions,connected?0:1);
    assert.equal(destination,connected?'https://portal.test/landing':'');
}
console.log('PASS: verified active sessions skip a second RouterOS login; pending sessions submit once without claiming success');
