(() => {
    let rendered=false;
    window.renderFortunettGoogle=() => {
        const target=document.getElementById('google-button');
        if (rendered || !target || !window.google?.accounts?.id) return;
        rendered=true;
        const feedback=document.getElementById('google-feedback');
        google.accounts.id.initialize({client_id:target.dataset.clientId, nonce:target.dataset.nonce, auto_select:false,
            callback:async response => {
                feedback.textContent='Verifying your Google account…';
                const data=new FormData(); data.set('credential',response.credential);data.set('csrf',target.dataset.csrf);
                try {
                    const result=await fetch('api/auth/google.php',{method:'POST',body:data,credentials:'same-origin'}).then(r=>r.json());
                    if (result.success && result.workspace_url) {
                        const destination = new URL(result.workspace_url);
                        if (destination.protocol !== 'https:' || !/^[a-z0-9-]+\.fortunetttech\.site$/.test(destination.hostname) || destination.pathname !== '/google_return.php' || !/^[a-f0-9]{64}$/.test(destination.searchParams.get('ticket') || '')) throw new Error('Unexpected workspace destination.');
                        window.location.assign(destination.href);return;
                    }
                    if (!result.success) {
                        if (result.workspace_url && /^https:\/\/[a-z0-9-]+\.fortunetttech\.site\/google_start\.php$/.test(result.workspace_url)) {
                            feedback.textContent=result.message + ' ';
                            const link=document.createElement('a');link.href=result.workspace_url;link.textContent='Open your workspace';feedback.appendChild(link);return;
                        }
                        throw new Error(result.message);
                    }
                    // Only known local pages are accepted as sign-in destinations.
                    if (!['dashboard.php','super_admin/index.php','login.php?signin=1','signup.php?google=1'].includes(result.redirect)) throw new Error('Unexpected sign-in destination.');
                    window.location.assign(result.redirect);
                } catch(e) {feedback.textContent=e.message || 'Google sign-in failed. Reload and try again.';}
            }});
        google.accounts.id.renderButton(target,{theme:'outline',size:'large',text:'continue_with',shape:'rectangular',width:Math.min(target.clientWidth,400)});
        feedback.textContent='';
    };
    window.renderFortunettGoogle();
    window.addEventListener('load',window.renderFortunettGoogle);
    setTimeout(()=>{if(!rendered){const el=document.getElementById('google-feedback');if(el)el.textContent='Google could not load. Continue with email or reload to try again.';}},10000);
})();
