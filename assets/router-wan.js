let wanRouterId=0,wanViewVersion=0,wanReturnFocus=null,wanPreviousOverflow='';
const wanCsrf=document.querySelector('meta[name="wan-csrf"]').content;
const wanElement=id=>document.getElementById(id);
function wanProgress(step) {
    document.querySelectorAll('[data-wan-step]').forEach(node=>{
        const active=Number(node.dataset.wanStep)===step;node.classList.toggle('wan-progress-active',active);
        if(active)node.setAttribute('aria-current','step');else node.removeAttribute('aria-current');
    });
}
function wanFields(prefix) {
    const mode=wanElement(prefix+'Mode').value;
    wanElement(prefix+'Static').hidden=mode!=='static';
    wanElement(prefix+'Pppoe').hidden=mode!=='pppoe';
    document.querySelectorAll(`input[name="${prefix}Connection"]`).forEach(radio=>radio.checked=radio.value===mode);
    for (const suffix of ['Address','Gateway']) wanElement(prefix+suffix).required=mode==='static';
    for (const suffix of ['Username','Password']) wanElement(prefix+suffix).required=mode==='pppoe';
    wanElement(prefix+'Dns').required=mode==='static';
    wanElement(prefix+'DnsRequired').textContent=mode==='static'?'Required':'Optional';
    wanElement(prefix+'Dns').placeholder=mode==='static'?'For example, 1.1.1.1,8.8.8.8':'Automatic from your provider';
    if (mode==='static') wanElement(prefix+'Advanced').open=true;
    const port=wanElement(prefix+'Interface').value.trim() || 'ether1';
    wanElement(prefix+'ModeHint').textContent=mode==='dhcp'
        ? (port==='ether1' ? "Connect your provider's cable to ether1. Automatic also works when their equipment supplies a bridged Ethernet connection." : `Provider connection: ${port}. For a WAN bridge, use the physical port your installer assigned to that bridge.`)
        : mode==='static' ? 'Enter the IP settings your provider supplied. Open Advanced connection settings below to enter their DNS servers.'
        : 'Use the login details from your Internet provider. These are separate from your billing account.';
}
function wanPayload(prefix) {
    const data=new FormData();data.append('csrf',wanCsrf);
    for (const [field,suffix] of Object.entries({wan_interface:'Interface',wan_mode:'Mode',vlan_id:'Vlan',wan_address:'Address',wan_gateway:'Gateway',wan_dns:'Dns',wan_username:'Username',wan_password:'Password',lan_bridge:'Lan'})) data.append(field,wanElement(prefix+suffix).value);
    if (prefix==='editWan') data.append('router_id',wanRouterId);
    else data.append('identity',wanElement('mikrotikName').value.trim());
    return data;
}
function downloadRouterScript(script,filename) {
    const url=URL.createObjectURL(new Blob([script],{type:'text/plain'}));
    const link=document.createElement('a');link.href=url;link.download=filename;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
function wanNode(tag,className,text) {
    const node=document.createElement(tag);if(className)node.className=className;if(text)node.textContent=text;return node;
}
function wanStatus(output,title,message,type='') {
    const box=wanNode('div','wan-status'+(type?' wan-status-'+type:''));
    box.append(wanNode('h4','',title),wanNode('p','',message));output.replaceChildren(box);return box;
}
function wanRevealOutput(output) {output.scrollIntoView({block:'nearest',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}
function wanError(output,error,title='Could not prepare your setup file') {
    let message=error.message || 'Please try again.';
    if (/storage unavailable|database migration/i.test(message) || error.code==='storage_unavailable') message='Internet setup needs a server update. Ask your administrator to finish the update, then reopen this window. Your device has not been changed.';
    else if (/cannot connect|unreachable|no route|connection refused/i.test(message)) message='Your device is not reachable yet. Apply the downloaded setup file onsite, wait for the device to reconnect, then check again.';
    else if (message==='Failed to fetch') message='We could not reach the billing server. Check your connection and try again.';
    wanStatus(output,title,message,'error');wanRevealOutput(output);
}
function wanValidate(prefix) {
    const fields=document.querySelectorAll(`[data-prefix="${prefix}"] input:not([type="hidden"]):not([type="radio"])`);
    for (const input of fields) input.removeAttribute('aria-invalid');
    for (const input of fields) {
        if (!input.checkValidity() || (input.required && !input.value.trim())) {
            input.setAttribute('aria-invalid','true');
            if (wanElement(prefix+'Advanced').contains(input)) wanElement(prefix+'Advanced').open=true;
            input.focus();input.reportValidity();
            const label=document.querySelector(`label[for="${input.id}"]`);
            throw new Error('Please complete '+(label?.childNodes[0]?.textContent.trim() || 'the highlighted field')+'.');
        }
    }
    if (wanElement(prefix+'Lan').value.trim()===wanElement(prefix+'Interface').value.trim()) {
        wanElement(prefix+'Lan').focus();throw new Error('Choose a customer network separate from your provider connection.');
    }
}
function wanBusy(prefix,busy,button) {
    button.disabled=busy;
    if (prefix==='editWan') for (const id of ['editWanPrepare','editWanVerify']) wanElement(id).disabled=busy;
}
function wanInstallResult(output,script,recovery=false) {
    const filename=recovery?'fortunett-wan.rsc':'fortunett-setup.rsc';
    const box=wanStatus(output,recovery?'Internet recovery file ready':'Your setup file is ready',recovery?'The full setup file is unavailable. This file can restore the Internet connection; management setup will still need to be completed.':'Follow these steps at the device. Preparing the file has not changed its settings.',recovery?'':'success');
    const download=wanNode('button','wan-button wan-button-primary',recovery?'Download Internet recovery file':'Download setup file');
    download.type='button';download.onclick=()=>downloadRouterScript(script,filename);box.append(download);
    const steps=wanNode('ol','');
    steps.append(wanNode('li','','Download the file to the computer you will use onsite.'),wanNode('li','','Connect that computer through a customer LAN port. Open WinBox, connect to the device and drag the file into Files.'));
    const last=wanNode('li','','Open New Terminal and paste this command: ');last.append(wanNode('code','',`/import ${filename}`));steps.append(last);box.append(steps);
    box.append(wanNode('p','','Wait for the import to finish. Return here and choose Check connection. Keep the file private: it includes device setup credentials.'));
    const details=wanNode('details','wan-script');details.append(wanNode('summary','','View script (advanced)'),wanNode('pre','',script));box.append(details);
    return box;
}
async function prepareWan(prefix,button) {
    const output=wanElement(prefix+'Output'),version=wanViewVersion;
    const active=()=>prefix!=='editWan' || version===wanViewVersion;
    let recoveryScript='';
    try {
        wanFields(prefix);wanValidate(prefix);wanBusy(prefix,true,button);
        if(prefix==='editWan')wanElement('editWanCheckOutput').replaceChildren();
        wanStatus(output,'Preparing your setup file','Saving connection details and building an installer you can use onsite.');
        const response=await fetch('api/routers/wan_setup.php',{method:'POST',body:wanPayload(prefix)});
        const data=await response.json();if(!active())return;
        if (!response.ok || data.status!=='success') throw Object.assign(new Error(data.message || 'Please try again.'),{code:data.code});
        recoveryScript=data.script;wanElement(prefix+'Password').value='';
        const managementUrl=new URL(wanElement('wanProvisionEndpoint').value,location.href);
        managementUrl.searchParams.set('identity',data.identity);managementUrl.searchParams.set('format','rsc');
        const management=await fetch(managementUrl,{cache:'no-store'});
        const managementScript=await management.text();if(!active())return;
        if (!management.ok) throw new Error('The Internet recovery file is available, but the full installer could not be created. Ask your administrator to check device management setup.');
        wanInstallResult(output,data.script+'\n'+managementScript);wanRevealOutput(output);
        if(prefix==='editWan')wanProgress(2);
        if (prefix==='wizardWan') {
            wanElement('wizardBridgeName').value=data.config.lan;
            wanElement('provisionCommand').textContent='/import fortunett-setup.rsc';startPolling();
        }
    } catch(error) {
        if (!active())return;
        if (recoveryScript) {const box=wanInstallResult(output,recoveryScript,true);box.append(wanNode('p','',error.message));wanRevealOutput(output);}
        else wanError(output,error);
    } finally {if(active())wanBusy(prefix,false,button);}
}
function closeWanSetup() {
    wanViewVersion++;wanElement('wanSetupModal').style.display='none';
    wanElement('editWanPassword').value='';document.body.style.overflow=wanPreviousOverflow;
    wanReturnFocus?.focus();
}
async function wanLoadBridges(routerId,version) {
    const hint=wanElement('editWanBridgeHint');
    try {
        const response=await fetch('api/routers/bridges.php?router_id='+encodeURIComponent(routerId));
        const data=await response.json();if(version!==wanViewVersion)return;
        if(!response.ok)throw new Error('Device offline');
        const list=wanElement('editWanBridges');list.replaceChildren();
        for (const name of data.bridges || []) {const option=wanNode('option','');option.value=name;list.append(option);}
        hint.textContent=data.bridges?.length?'Choose an existing customer bridge from the suggestions, or enter its exact name.':'No customer bridge found. Create a separate customer bridge in WinBox before preparing setup.';
    } catch(error) {
        if(version===wanViewVersion)hint.textContent='Device unavailable? You can still prepare a file. Copy your customer bridge name from WinBox → Bridge when onsite.';
    }
}
async function openWanSetup(routerId) {
    wanRouterId=routerId;const version=++wanViewVersion;
    const modal=wanElement('wanSetupModal'),output=wanElement('editWanOutput');
    if(modal.style.display!=='flex') {wanReturnFocus=document.activeElement;wanPreviousOverflow=document.body.style.overflow;}
    modal.style.display='flex';document.body.style.overflow='hidden';
    wanElement('wanDeviceName').textContent='';
    wanProgress(1);
    wanElement('editWanCheckOutput').replaceChildren();
    wanStatus(output,'Loading your connection','Reading saved settings for this device.');
    const defaults={Interface:'ether1',Mode:'dhcp',Vlan:'',Address:'',Gateway:'',Dns:'',Username:'',Password:'',Lan:''};
    for (const [suffix,value] of Object.entries(defaults)) {wanElement('editWan'+suffix).value=value;wanElement('editWan'+suffix).removeAttribute('aria-invalid');}
    wanElement('editWanAdvanced').open=false;wanElement('editWanBridges').replaceChildren();wanFields('editWan');
    wanBusy('editWan',true,wanElement('editWanPrepare'));
    modal.querySelector('input[type="radio"]')?.focus();
    try {
        const response=await fetch('api/routers/wan_setup.php?router_id='+encodeURIComponent(routerId));const data=await response.json();
        if(version!==wanViewVersion)return;
        if (!response.ok) throw Object.assign(new Error(data.message),{code:data.code});
        wanElement('wanDeviceName').textContent=data.router_name || '';
        if (data.config) {
            for (const [key,suffix] of Object.entries({base:'Interface',mode:'Mode',vlan:'Vlan',address:'Address',gateway:'Gateway',dns:'Dns',username:'Username',lan:'Lan'})) wanElement('editWan'+suffix).value=data.config[key] ?? '';
            if(data.config.base!=='ether1' || data.config.vlan || data.config.dns)wanElement('editWanAdvanced').open=true;
            wanFields('editWan');
        }
        output.replaceChildren();wanBusy('editWan',false,wanElement('editWanPrepare'));
        wanLoadBridges(routerId,version);
    } catch(error) {
        if(version!==wanViewVersion)return;
        wanError(output,error,'Setup is temporarily unavailable');
        const retry=wanNode('button','wan-button','Try again');retry.type='button';retry.onclick=()=>openWanSetup(routerId);output.firstElementChild.append(retry);
    }
}
async function verifyWan(button) {
    const output=wanElement('editWanCheckOutput'),version=wanViewVersion;wanBusy('editWan',true,button);
    wanProgress(3);
    wanStatus(output,'Checking your device','Checking its Internet address, route, name lookup and connection to the billing server.');
    const body=new FormData();body.append('csrf',wanCsrf);body.append('router_id',wanRouterId);body.append('action','verify');
    try {
        const response=await fetch('api/routers/wan_setup.php',{method:'POST',body});const data=await response.json();
        if(version!==wanViewVersion)return;
        if (!response.ok) throw Object.assign(new Error(data.message),{code:data.code});
        const box=wanStatus(output,data.all_ok?'Your device is connected':'A few things still need attention',data.all_ok?'Internet checks passed. You can now configure customer services.':'Apply the setup file onsite, then check again. If a check keeps failing, confirm the details with your Internet provider.',data.all_ok?'success':'error');
        const list=wanNode('ul','wan-check-list');
        const labels=['Internet address and customer network','Internet route','Website name lookup (DNS)','Billing server connection'];
        const help=['Confirm your provider settings and keep the customer network separate.','Confirm the provider gateway or login details.','Confirm the DNS settings with your provider.','Check Internet access and the date and time on your device.'];
        for (const [index,check] of data.checks.entries()) {
            const item=wanNode('li',check.ok?'':'wan-check-failed');
            const icon=wanNode('i',check.ok?'fas fa-circle-check':'fas fa-circle-exclamation');icon.setAttribute('aria-hidden','true');
            const info=wanNode('div','');info.append(wanNode('strong','',labels[index] || check.label),wanNode('small','',check.ok?'Passed':'Needs attention. '+(help[index] || 'Ask your installer to check this setting.')));item.append(icon,info);list.append(item);
        }
        box.append(list);
        if(data.all_ok && typeof openProvision==='function') {
            const next=wanNode('button','wan-button wan-button-primary','Configure customer services');next.type='button';
            const routerId=wanRouterId;
            next.onclick=()=>{closeWanSetup();openProvision(routerId,data.router_name || 'Your device');wanElement('provBridgeName').value=data.lan_bridge || '';};box.append(next);
        }
        wanRevealOutput(output);
    } catch(error) {if(version===wanViewVersion)wanError(output,error,'Could not check the connection');}
    finally {if(version===wanViewVersion)wanBusy('editWan',false,button);}
}
document.addEventListener('keydown',event=>{
    const modal=wanElement('wanSetupModal');if(modal.style.display!=='flex')return;
    if(event.key==='Escape') {event.preventDefault();closeWanSetup();}
    if(event.key==='Tab') {
        const nodes=Array.from(modal.querySelectorAll('button:not(:disabled),input:not([type="hidden"]):not(:disabled),summary,a[href]')).filter(node=>node.getClientRects().length>0);
        const first=nodes[0],last=nodes[nodes.length-1];
        if(event.shiftKey && document.activeElement===first){event.preventDefault();last?.focus();}
        else if(!event.shiftKey && document.activeElement===last){event.preventDefault();first?.focus();}
    }
});
for(const prefix of ['editWan','wizardWan'])wanElement(prefix+'Interface')?.addEventListener('input',()=>wanFields(prefix));
