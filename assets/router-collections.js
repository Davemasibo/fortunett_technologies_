(()=>{
 const picker=document.getElementById('collection-router'),status=document.getElementById('routerCollectionsStatus');
 let report,chart,request=0;
 const colors=['#60a5fa','#34d399','#c084fc','#fbbf24','#fb7185'];
 function draw(){if(!report)return;const groups=picker.value==='all'?report.groups:report.groups.filter(g=>g.id===picker.value);
 const data=picker.value==='all'?report.labels.map((_,i)=>groups.reduce((n,g)=>n+Number(g.data[i]),0)):(groups[0]?.data||[]);
 const datasets=picker.value==='all'?[{label:'Combined collections',data,backgroundColor:colors[0]}]:groups.map((g,i)=>({label:g.name,data:g.data,backgroundColor:colors[i%colors.length]}));
 if(chart)chart.destroy();chart=new Chart(document.getElementById('routerCollectionsChart'),{type:'bar',data:{labels:report.labels,datasets},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true}},plugins:{legend:{display:false}}}});
 status.textContent='KES '+groups.reduce((n,g)=>n+g.total,0).toLocaleString(undefined,{minimumFractionDigits:2})+' ? '+groups.reduce((n,g)=>n+g.sales,0)+' completed payments. '+(picker.value==='all'?'Includes unattributed collections.':'Only payments recorded for this selection.');
 }
 async function load(){const generation=++request;try{const range=document.getElementById('analyticsRange')?.value||document.querySelector('[data-analytics-range]')?.value||'7d';const response=await fetch('api/dashboard/router_collections.php?range='+encodeURIComponent(range),{cache:'no-store'});const data=await response.json();if(generation!==request)return;if(!data.success)throw Error(data.message||'Collections unavailable');report=data;const selected=picker.value;picker.replaceChildren(new Option('All routers combined','all'));for(const g of data.groups)picker.add(new Option(g.name,g.id));picker.value=[...picker.options].some(o=>o.value===selected)?selected:'all';draw();}catch(e){status.textContent=e.message;}}
 picker.addEventListener('change',draw);document.addEventListener('change',e=>{if(e.target.matches('#analyticsRange,[data-analytics-range]'))load();});load();setInterval(load,60000);
})();
