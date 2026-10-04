(()=>{
 const picker=document.getElementById('collection-router'),status=document.getElementById('routerCollectionsStatus');
 if(!picker || !status)return;
 let report,chart,request=0;
 const colors=['#60a5fa','#34d399','#c084fc','#fbbf24','#fb7185'];
 function draw(){
  if(!report)return;
  const comparison=picker.value==='all';
  const groups=comparison?report.groups.filter(g=>g.id!=='unattributed'||g.sales>0):report.groups.filter(g=>g.id===picker.value);
  const color=g=>g.id==='unattributed'?'#94a3b8':colors[report.groups.findIndex(r=>r.id===g.id)%colors.length];
  const labels=comparison?groups.map(g=>g.name):report.labels;
  const datasets=comparison?[{label:'Collections (KES)',data:groups.map(g=>g.total),backgroundColor:groups.map(color)}]:groups.map(g=>({label:g.name,data:g.data,backgroundColor:color(g)}));
  if(chart)chart.destroy();
  chart=new Chart(document.getElementById('routerCollectionsChart'),{type:'bar',data:{labels,datasets},options:{indexAxis:comparison?'y':'x',responsive:true,maintainAspectRatio:false,scales:comparison?{x:{beginAtZero:true,title:{display:true,text:'Collections (KES)'}}}:{y:{beginAtZero:true,title:{display:true,text:'Collections (KES)'}}},plugins:{legend:{display:false}}}});
  const unknown=report.groups.find(g=>g.id==='unattributed');
  status.textContent=comparison?"Compare each router's contribution. "+(unknown?.sales>0?'Unattributed: KES '+unknown.total.toLocaleString(undefined,{minimumFractionDigits:2})+'.':''):'KES '+groups.reduce((n,g)=>n+g.total,0).toLocaleString(undefined,{minimumFractionDigits:2})+' / '+groups.reduce((n,g)=>n+g.sales,0)+' completed payments for this selection.';
 }
 async function load(){const generation=++request;try{const range=document.getElementById('analyticsRange')?.value||document.querySelector('[data-analytics-range]')?.value||'7d';const response=await fetch('api/dashboard/router_collections.php?range='+encodeURIComponent(range),{cache:'no-store'});const data=await response.json();if(generation!==request)return;if(!data.success)throw Error(data.message||'Collections unavailable');report=data;const selected=picker.value;picker.replaceChildren(new Option('Compare routers','all'));for(const g of data.groups)picker.add(new Option(g.name,g.id));picker.value=[...picker.options].some(o=>o.value===selected)?selected:'all';draw();}catch(e){status.textContent=e.message;}}
 picker.addEventListener('change',draw);document.addEventListener('change',e=>{if(e.target.matches('#analyticsRange,[data-analytics-range]'))load();});load();setInterval(load,60000);
})();
