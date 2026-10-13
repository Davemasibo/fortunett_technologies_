const fs=require('fs'),vm=require('vm');
const source=fs.readFileSync('hotspot_diagnostics.php','utf8');
for(const block of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)) {
 const js=block[1].replace(/<\?=([\s\S]*?)\?>/g,'0').replace(/<\?php([\s\S]*?)\?>/g,'');
 new vm.Script(js);
}
console.log('Hotspot diagnostic JavaScript parses');
