const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync('assets/router-wan.js','utf8');
class Element {
 constructor(value=''){this.value=value;this.children=[];this.style={};this.disabled=false;this.hidden=false;this.textContent='';}
 append(...children){this.children.push(...children);}
 replaceChildren(...children){this.children=children;}
}
async function exercise(managementOk) {
 const elements={};
 const values={Mode:'pppoe',Interface:'ether1',Vlan:'100',Address:'',Gateway:'',Dns:'',Username:'isp',Password:'private',Lan:'bridge-lan'};
 for(const [suffix,value] of Object.entries(values)) elements['wizardWan'+suffix]=new Element(value);
 for(const id of ['wizardWanOutput','wizardBridgeName','provisionCommand']) elements[id]=new Element();
 elements.mikrotikName=new Element('HomeLink');elements.wanProvisionEndpoint=new Element('/api/routers/provision.php?token=test');
 const calls=[];let polls=0;
 const config={lan:'bridge-lan'};
 const context={FormData,URL,Blob,location:{href:'https://example.test/mikrotik.php'},setTimeout,
  document:{querySelector:()=>({content:'csrf-test'}),getElementById:id=>elements[id],createElement:()=>new Element()},
  startPolling(){polls++;},fetch:async(url,options)=>{
   calls.push([String(url),options]);
   return calls.length===1 ? {ok:true,json:async()=>({status:'success',identity:'HomeLink',config,script:'# wan setup'})} : {ok:managementOk,text:async()=>managementOk?'# management setup':'Unavailable'};
  }};
 vm.createContext(context);vm.runInContext(source,context);
 const button=new Element();await context.prepareWan('wizardWan',button);
 assert.equal(button.disabled,false);assert.equal(elements.wizardWanPassword.value,'');
 assert.equal(calls[0][1].body.get('csrf'),'csrf-test');
 assert.equal(calls[0][1].body.get('wan_password'),'private');
 assert.ok(calls[1][0].includes('identity=HomeLink') && calls[1][0].includes('format=rsc'));
 if(managementOk){
  assert.equal(polls,1);assert.equal(elements.wizardBridgeName.value,'bridge-lan');
  assert.ok(elements.wizardWanOutput.children.some(node=>node.textContent.includes('# management setup')));
 }else{
  assert.equal(polls,0);assert.ok(elements.wizardWanOutput.children.some(node=>node.textContent==='Download WAN recovery .rsc'));
 }
}
(async()=>{await exercise(true);await exercise(false);console.log('PASS: browser prepares offline installer, clears PPPoE password, polls after preparation and retains WAN recovery download if management is unavailable.');})().catch(e=>{console.error(e);process.exit(1);});
