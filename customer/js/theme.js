/* Customer preferences stay in this browser and never change tenant settings. */
(function () {
  var root=document.documentElement,key='fortunett-customer-theme:'+location.host;
  var choices=['tenant','dark','light','system'],selected='tenant';
  var media=window.matchMedia?window.matchMedia('(prefers-color-scheme: dark)'):null;
  try{var saved=localStorage.getItem(key);if(choices.indexOf(saved)>=0)selected=saved;}catch(_){}
  function apply(){var mode=selected==='system'?(media&&media.matches?'dark':'light'):selected;
    root.setAttribute('data-customer-theme',mode);
    document.querySelectorAll('[data-customer-theme-picker]').forEach(function(p){p.value=selected;});
  }
  apply();
  if(media&&media.addEventListener)media.addEventListener('change',apply);
  window.addEventListener('storage',function(e){if(e.key===key){selected=choices.indexOf(e.newValue)>=0?e.newValue:'tenant';apply();}});
  function picker(){var label=document.createElement('label');label.className='customer-theme-control';
    var text=document.createElement('span');text.textContent='Theme';label.appendChild(text);
    var select=document.createElement('select');select.setAttribute('data-customer-theme-picker','');select.setAttribute('aria-label','Choose appearance');
    [['tenant','Company'],['dark','Dark'],['light','Light'],['system','Device']].forEach(function(c){select.add(new Option(c[1],c[0]));});
    select.value=selected;select.addEventListener('change',function(){selected=select.value;try{localStorage.setItem(key,selected);}catch(_){}apply();});label.appendChild(select);return label;
  }
  document.addEventListener('DOMContentLoaded',function(){
    var mount=document.querySelector('[data-customer-theme-mount]');
    if(!mount){var bar=document.createElement('nav');bar.className='customer-public-navbar';bar.setAttribute('aria-label','Customer portal');
      var home=document.createElement('a');home.href='login.php';home.textContent='Customer portal';bar.appendChild(home);mount=document.createElement('div');bar.appendChild(mount);document.body.prepend(bar);}
    mount.appendChild(picker());apply();
    document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.customer-profile-menu[open]').forEach(function(menu){menu.open=false;menu.querySelector('summary').focus();});});
    document.addEventListener('click',function(e){document.querySelectorAll('.customer-profile-menu[open]').forEach(function(menu){if(!menu.contains(e.target))menu.open=false;});});
  });
  // Static legacy entrypoints have no server palette; load their tenant branding.
  if(!document.getElementById('tenant-customer-theme'))fetch('../api/tenant/branding.php',{cache:'no-cache'})
    .then(function(r){if(!r.ok)throw Error('Theme unavailable');return r.json();})
    .then(function(b){if(!b.theme)return;b.theme.vars.split(';').forEach(function(d){var n=d.indexOf(':');if(n<0)return;var name=d.slice(0,n).trim();if(/^--[a-z-]+$/.test(name))root.style.setProperty(name,d.slice(n+1).trim());});root.style.setProperty('--customer-background',b.theme.background);apply();})
    .catch(function(){});
}());
