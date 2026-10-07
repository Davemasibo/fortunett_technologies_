let wanRouterId=0;
const wanCsrf=document.querySelector('meta[name="wan-csrf"]').content;
function wanFields(prefix) {
    const mode=document.getElementById(prefix+'Mode').value;
    document.getElementById(prefix+'Static').hidden=mode!=='static';
    document.getElementById(prefix+'Pppoe').hidden=mode!=='pppoe';
}
function wanPayload(prefix) {
    const data=new FormData(); data.append('csrf',wanCsrf);
    for (const [field,suffix] of Object.entries({wan_interface:'Interface',wan_mode:'Mode',vlan_id:'Vlan',wan_address:'Address',wan_gateway:'Gateway',wan_dns:'Dns',wan_username:'Username',wan_password:'Password',lan_bridge:'Lan'})) data.append(field,document.getElementById(prefix+suffix).value);
    if (prefix==='editWan') data.append('router_id',wanRouterId);
    else data.append('identity',document.getElementById('mikrotikName').value.trim());
    return data;
}
function downloadRouterScript(script,filename) {
    const url=URL.createObjectURL(new Blob([script],{type:'text/plain'}));
    const link=document.createElement('a');link.href=url;link.download=filename;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
async function prepareWan(prefix,button) {
    const output=document.getElementById(prefix+'Output');
    let prepared=false;
    button.disabled=true; output.textContent='Preparing installer script...';
    try {
        const response=await fetch('api/routers/wan_setup.php',{method:'POST',body:wanPayload(prefix)});
        const data=await response.json();
        if (!response.ok || data.status!=='success') throw new Error(data.message || 'WAN setup failed.');
        document.getElementById(prefix+'Password').value='';
        output.replaceChildren();
        const wanDownload=document.createElement('button');wanDownload.type='button';wanDownload.textContent='Download WAN recovery .rsc';
        wanDownload.onclick=()=>downloadRouterScript(data.script,'fortunett-wan.rsc');
        output.append(wanDownload);prepared=true;
        // Download management configuration through the installer's browser, so the
        // resulting file does not require the router to fetch its initial script.
        const managementUrl=new URL(document.getElementById('wanProvisionEndpoint').value,location.href);
        managementUrl.searchParams.set('identity',data.identity);
        managementUrl.searchParams.set('format','rsc');
        const management=await fetch(managementUrl,{cache:'no-store'});
        const managementScript=await management.text();
        if (!management.ok) throw new Error('WAN settings saved, but management script is unavailable. '+managementScript);
        const script=data.script+'\n'+managementScript;
        output.replaceChildren();
        const status=document.createElement('p');status.textContent='Script prepared; setup is pending verification. Transfer it to the router in WinBox Files and run /import fortunett-setup.rsc through a customer LAN port.';
        const pre=document.createElement('pre');pre.textContent=script;pre.style.cssText='white-space:pre-wrap;max-height:180px;overflow:auto;';
        const download=document.createElement('button');download.type='button';download.textContent='Download installer .rsc';download.onclick=()=>downloadRouterScript(script,'fortunett-setup.rsc');
        output.append(status,download,pre);
        if (prefix==='wizardWan') {
            document.getElementById('wizardBridgeName').value=data.config.lan;
            document.getElementById('provisionCommand').textContent='/import fortunett-setup.rsc';
            startPolling();
        }
    } catch(e) {
        if (prepared) {
            const error=document.createElement('p');error.textContent=e.message+' You can still import the WAN recovery script locally with /import fortunett-wan.rsc.';output.append(error);
        } else output.textContent=e.message;
    } finally {button.disabled=false;}
}
async function openWanSetup(routerId) {
    wanRouterId=routerId;
    const modal=document.getElementById('wanSetupModal');modal.style.display='flex';
    const output=document.getElementById('editWanOutput');output.textContent='Loading saved WAN settings...';
    // Reset all fields so settings from another tenant router cannot carry over.
    const defaults={Interface:'ether1',Mode:'dhcp',Vlan:'',Address:'',Gateway:'',Dns:'',Username:'',Password:'',Lan:''};
    for (const [suffix,value] of Object.entries(defaults)) document.getElementById('editWan'+suffix).value=value;
    wanFields('editWan');
    try {
        const response=await fetch('api/routers/wan_setup.php?router_id='+encodeURIComponent(routerId));const data=await response.json();
        if (!response.ok) throw new Error(data.message);
        if (data.config) {
            for (const [key,suffix] of Object.entries({base:'Interface',mode:'Mode',vlan:'Vlan',address:'Address',gateway:'Gateway',dns:'Dns',username:'Username',lan:'Lan'})) document.getElementById('editWan'+suffix).value=data.config[key] ?? '';
            wanFields('editWan');
        }
        output.textContent='Prepare a script for onsite installation, then verify after the router reconnects.';
    } catch(e) {output.textContent=e.message;}
}
async function verifyWan(button) {
    const output=document.getElementById('editWanOutput');button.disabled=true;output.textContent='Checking connectivity from the router...';
    const body=new FormData();body.append('csrf',wanCsrf);body.append('router_id',wanRouterId);body.append('action','verify');
    try {
        const response=await fetch('api/routers/wan_setup.php',{method:'POST',body});const data=await response.json();
        if (!response.ok) throw new Error(data.message);
        output.textContent=(data.all_ok?'WAN verified. Customer services can now be configured.':'WAN verification failed.')+'\n'+data.checks.map(c=>(c.ok?'PASS: ':'FAIL: ')+c.label+' — '+c.detail).join('\n');
    } catch(e) {output.textContent=e.message;} finally {button.disabled=false;}
}
