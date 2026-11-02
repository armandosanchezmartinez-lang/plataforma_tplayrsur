/* Talia Mix Comercial Standalone v1.0.5 - sin dependencias externas. */
(() => {
  'use strict';
  const root=document.getElementById('talia-mix-comercial');
  const cfg=window.TaliaMixConfig;
  if(!root || !cfg) return;
  const $=(s,ctx=root)=>ctx.querySelector(s);
  const $$=(s,ctx=root)=>Array.from(ctx.querySelectorAll(s));
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmt=n=>new Intl.NumberFormat('es-MX',{maximumFractionDigits:0}).format(Number(n||0));
  const money=n=> n==null?'—':new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN',minimumFractionDigits:2}).format(+n);
  const pct=n=>n==null?'—':(Number(n)>0?'+':'')+Number(n).toFixed(1)+'%';
  const num=n=>(Number(n)>0?'+':'')+fmt(n);
  const bar=n=>'<span class="mx-chip '+(n==null?'mx-neutral':Number(n)>=5?'mx-good':Number(n)<0?'mx-bad':'mx-warm')+'">'+pct(n)+'</span>';
  const DAYS=['LUN','MAR','MIÉ','JUE','VIE','SÁB','DOM'];
  const colors=['#c70dcc','#6548ed','#2dbfe8','#57c55d','#fc8651','#b84bc3','#3e53bb','#4e7a8c','#9b8ea9'];
  const period=cfg.periods;
  const state={view:cfg.defaultView||'mix',mode:period.mode,week:period.week||null,year:period.week_year||null,
    days:period.current.days||[],dateFrom:period.current.from,dateTo:period.current.to,
    monthView:(period.current.to||'').slice(0,7),selectedPlay:'TODAS',node:'',district:'TODOS',scopePos:'',
    response:null,busy:false,controller:null,refreshSerial:0,pickStart:null,pickEnd:null};
  const parse=s=>new Date(`${s}T12:00:00`);
  const iso=d=>[d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-');
  const moved=(s,days)=>{const d=parse(s);d.setDate(d.getDate()+days);return iso(d);};
  function monthMove(s,m){const d=parse(s),day=d.getDate();d.setDate(1);d.setMonth(d.getMonth()+m);const last=new Date(d.getFullYear(),d.getMonth()+1,0).getDate();d.setDate(Math.min(day,last));return iso(d);}
  function weekOf(s){let d=parse(s);d=new Date(Date.UTC(d.getFullYear(),d.getMonth(),d.getDate()));d.setUTCDate(d.getUTCDate()+4-(d.getUTCDay()||7));const year=d.getUTCFullYear();const yearStart=new Date(Date.UTC(year,0,1));return {year,week:Math.ceil((((d-yearStart)/86400000)+1)/7)};}
  function mondayOf(y,w){const d=new Date(Date.UTC(y,0,4));d.setUTCDate(d.getUTCDate()-(d.getUTCDay()||7)+1+(w-1)*7);return `${d.getUTCFullYear()}-${String(d.getUTCMonth()+1).padStart(2,'0')}-${String(d.getUTCDate()).padStart(2,'0')}`;}
  function compareDates(a,b){return a.localeCompare(b);}
  function labelRange(p){if(state.mode==='semanal')return `Semana ${state.week} · ${state.year} · ${p.current.from}–${p.current.to}`;return `${p.current.from}–${p.current.to}`;}
  function heroMeta(){
    const p=state.response?.periods||period;
    const labels=state.response?periodNames():['Periodo anterior','Periodo seleccionado'];
    const modeLabel=state.mode==='semanal'?'Semanal':'Mensual';
    const viewLabel=state.view==='mix'?'Mix comercial por velocidad':'Matriz de desempeño';
    const scopeLabel=state.view==='mix'
      ? (($('#mx-mix-scope')?.textContent||($('#mx-person')?.selectedOptions?.[0]?.textContent||'Mi tablero / vista inicial')).trim())
      : (($('#mx-current-name')?.textContent||($('#mx-district')?.selectedOptions?.[0]?.textContent||'Todos los distritos')).trim());
    return `Comparativo ${labels[0]} vs ${labels[1]} · ${modeLabel} · ${viewLabel} · ${scopeLabel}`;
  }
  function updateHero(){
    const title=$('#mx-page-title'),meta=$('#mx-page-meta');
    if(title) title.textContent=state.view==='mix'?'Mix comercial':'Matriz de desempeño';
    if(meta) meta.textContent=heroMeta();
    document.title=title?title.textContent:'Mix comercial';
  }
  function setBusy(working){state.busy=working;$('.mx-loading').hidden=!working;}
  function showError(e){$('.mx-error').textContent=e?String(e):'';$('.mx-error').hidden=!e;}
  function readParams(){return {csrf:cfg.csrf,view:state.view,periodo:state.mode,
    anio_semana:state.year,semana:state.week,dias_semana:state.days.join(','),
    rango_mode:'custom',fecha_inicio:state.dateFrom,fecha_fin:state.dateTo,mes_ref:state.monthView,
    district:state.district,node:state.node,scope_pos:state.scopePos};}
  function updateURL(){const u=new URL(location.href);const p=readParams();delete p.csrf;
    Object.entries(p).forEach(([key,v])=>v==null?u.searchParams.delete(key):u.searchParams.set(key,String(v)));
    history.replaceState(null,'',u.toString());}
  async function reload(){const serial=++state.refreshSerial;state.controller?.abort();const controller=new AbortController();state.controller=controller;
    setBusy(true);showError('');
    try {const res=await fetch(`${location.pathname}?api=1`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(readParams()),signal:controller.signal,cache:'no-store'});
      const body=await res.json();if(!res.ok||body.error)throw new Error(body.error||`Error HTTP ${res.status}`);
      if(serial!==state.refreshSerial)return;state.response=body;
      if(body.periods){const p=body.periods;state.dateFrom=p.current.from;state.dateTo=p.current.to;
        if(state.mode==='semanal'){state.week=p.week;state.year=p.week_year;state.days=p.current.days;}}
      updateURL();render();
    }catch(e){if(e.name!=='AbortError' && serial===state.refreshSerial){showError(e.message||'No fue posible consultar el servidor');state.response=null;clearTables();}}
    finally{if(serial===state.refreshSerial)setBusy(false);}
  }
  function clearTables(){['mx-play-table','mx-matrix-table','mx-speed-table'].forEach(id=>{const t=$('#'+id);t.tHead.innerHTML='';t.tBodies[0].innerHTML='';});$('#mx-speed-chart').innerHTML='';$('#mx-arpu-chart').innerHTML='';}
  function periodNames(){const p=state.response?.periods;
    if(!p)return ['ANTERIOR','SELECCIONADO'];
    if(p.mode==='semanal')return [`SEMANA ${weekOf(p.previous.from).week}`,`SEMANA ${weekOf(p.current.from).week}`];
    return [p.previous.from.slice(5)+'–'+p.previous.to.slice(5),p.current.from.slice(5)+'–'+p.current.to.slice(5)];
  }
  function heading(){const labels=periodNames();const first=state.view==='matrix'?'<th rowspan="2">PERSONA / PUESTO</th><th rowspan="2">DISTRITO</th>':'<th rowspan="2">UNIVERSO</th>';
    return `<tr>${first}<th colspan="4">INSTALACIONES</th><th colspan="3">ARPU</th></tr><tr><th>${labels[0]}</th><th>${labels[1]}</th><th>DIFERENCIA</th><th>DIFERENCIA %</th><th>${labels[0]}</th><th>${labels[1]}</th><th>VARIACIÓN %</th></tr>`;}
  function cells(c){return `<td>${fmt(c.previous.instalaciones)}</td><td>${fmt(c.current.instalaciones)}</td><td>${num(c.diff)}</td><td>${bar(c.pct)}</td><td>${money(c.previous.arpu)}</td><td>${money(c.current.arpu)}</td><td>${bar(c.arpu_pct)}</td>`;}
  function renderPlay(d){const t=$('#mx-play-table');t.tHead.innerHTML=heading();
    t.tBodies[0].innerHTML=['2P','3P','TODAS'].map(id=>`<tr class="mx-row-btn ${state.selectedPlay===id?'mx-row-active':''}" data-play="${id}" tabindex="0" role="button"><th>${({'2P':'DOBLE PLAY (2P)','3P':'TRIPLE PLAY (3P)','TODAS':'TODAS LAS INSTALACIONES'})[id]}</th>${cells(d.cards[id])}</tr>`).join('');
    $$('[data-play]',t).forEach(tr=>{const choose=()=>{state.selectedPlay=tr.dataset.play;render();};tr.addEventListener('click',choose);tr.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();choose();}});});
    $('#mx-mix-scope').textContent=d.scope+' · '+(d.universe==='REGISTROS_INSTALACIONES'?'Todos los registros':'HC certificado');
  }
  function renderMatrix(d){const t=$('#mx-matrix-table');t.tHead.innerHTML=heading();
    const fallback={previous:{instalaciones:0,arpu:null},current:{instalaciones:0,arpu:null},diff:0,pct:0,arpu_pct:null};
    t.tBodies[0].innerHTML=d.rows.map(r=>`<tr class="mx-row-btn" data-node="${esc(r.id)}" tabindex="0" role="button"><th><span class="mx-expand">›</span> ${esc(r.name)}</th><td>${esc(r.district)}</td>${cells(r.stats)}</tr>`).join('')+
      `<tr class="mx-total"><th>TOTAL</th><td>—</td>${cells(d.summary||fallback)}</tr>`;
    $$('[data-node]',t).forEach(tr=>{const choose=()=>{state.node=tr.dataset.node;reload();};tr.addEventListener('click',choose);tr.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();choose();}});});
    const names={'':'DIRECTORES','d':'LÍDERES','l':'COACHES','c':'VENDEDORES','v':'VENDEDOR'};
    $('#mx-matrix-level').textContent='NIVEL: '+names[(d.selected.split(':')[0]||'')] ;
    const bc=$('#mx-breadcrumb');bc.innerHTML=`<button type="button" data-go-node="">Región</button>`+d.trail.map(n=>` <span>›</span> <button type="button" data-go-node="${esc(n.id)}">${esc(n.name)}</button>`).join('');
    $$('[data-go-node]',bc).forEach(b=>b.addEventListener('click',()=>{state.node=b.dataset.goNode;reload();}));
    $('#mx-matrix-audit').textContent=`Universo: cuentas certificadas por Motor HC. Pendientes de atribución, fuera del total visible (auditoría regional): periodo anterior ${fmt(d.audit.unattributed_previous)}, seleccionado ${fmt(d.audit.unattributed_current)}.`;
  }
  // El Motor Comercial entrega cuentas por categoría para CADA periodo.
  // El porcentaje de Bundle siempre usa como denominador las instalaciones de
  // la misma fila y periodo, nunca las de la semana seleccionada al mostrar la anterior.
  function bundleCell(info){
    const total=Number(info.instalaciones||0);
    const classes=[
      {label:'Sin bundle',count:Number(info.no_bundle||0),bar:''},
      {label:'Con bundle',count:Number(info.bundle||0),bar:'mx-bundle'},
      {label:'Sin clasificar',count:Number(info.bundle_sin_clasificar||0),bar:'mx-unknown'}
    ];
    const rows=classes.filter(item=>item.bar!=='mx-unknown'||item.count>0).map(item=>{
      const percentage=total>0?100*item.count/total:0;
      const width=Math.max(0,Math.min(100,percentage)).toFixed(2);
      // Cantidad sólo al pasar el puntero sobre el porcentaje o enfocarlo con teclado.
      return `<div class="mx-bundle-line"><span>${item.label}</span><strong class="mx-bundle-value" tabindex="0" data-installations="${fmt(item.count)} INST" aria-label="${item.label}: ${percentage.toFixed(1)}%, ${fmt(item.count)} instalaciones">${percentage.toFixed(1)}%</strong></div>`+
        `<div class="mx-mini-bar ${item.bar}"><span style="width:${width}%"></span></div>`;
    }).join('');
    return `<td class="mx-bundle-cell"><div class="mx-mix-bundle">${rows}</div></td>`;
  }
  function bundleTotals(data){
    const out={instalaciones:Number(data.total_instalaciones||0),bundle:0,no_bundle:0,bundle_sin_clasificar:0};
    Object.values(data.velocidades).forEach(v=>{
      out.bundle+=Number(v.bundle||0);
      out.no_bundle+=Number(v.no_bundle||0);
      out.bundle_sin_clasificar+=Number(v.bundle_sin_clasificar||0);
    });
    return out;
  }
  function renderSpeed(oldData,newData,showBundle){const table=$('#mx-speed-table');const labels=periodNames();
    table.tHead.innerHTML=`<tr><th rowspan="2">VELOCIDAD AGRUPADA</th><th colspan="4">INSTALACIONES</th><th colspan="2">PARTICIPACIÓN</th><th colspan="3">ARPU</th>${showBundle?'<th colspan="2">MIX BUNDLE</th>':''}</tr>
      <tr><th>${labels[0]}</th><th>${labels[1]}</th><th>DIFERENCIA</th><th>DIFERENCIA %</th><th>${labels[0]}</th><th>${labels[1]}</th><th>${labels[0]}</th><th>${labels[1]}</th><th>VARIACIÓN %</th>${showBundle?`<th>${esc(labels[0])}</th><th>${esc(labels[1])}</th>`:''}</tr>`;
    const speeds=Object.keys(newData.velocidades);
    const oldT=oldData.total_instalaciones,newT=newData.total_instalaciones;
    let body=speeds.map(speed=>{
      const a=oldData.velocidades[speed],b=newData.velocidades[speed];const va=a.arpu,vb=b.arpu;
      const delta=va!==null&&va>0&&vb!==null?(vb-va)*100/va:null;
      const instPct=a.instalaciones>0?100*(b.instalaciones-a.instalaciones)/a.instalaciones:(b.instalaciones>0?null:0);
      const mix=showBundle?bundleCell(a)+bundleCell(b):'';
      return `<tr><th>${esc(speed==='No clasificada'?'SIN VELOCIDAD IDENTIFICADA':speed)}</th><td>${fmt(a.instalaciones)}</td><td>${fmt(b.instalaciones)}</td><td>${num(b.instalaciones-a.instalaciones)}</td><td>${bar(instPct)}</td><td>${Number(a.participacion_pct).toFixed(1)}%</td><td>${Number(b.participacion_pct).toFixed(1)}%</td><td>${money(va)}</td><td>${money(vb)}</td><td>${bar(delta)}</td>${mix}</tr>`;
    }).join('');
    const del=oldT>0?100*(newT-oldT)/oldT:(newT>0?null:0);
    const ap=oldData.arpu!=null&&oldData.arpu>0&&newData.arpu!=null?100*(newData.arpu-oldData.arpu)/oldData.arpu:null;
    body+=`<tr class="mx-total"><th>TOTAL GENERAL</th><td>${fmt(oldT)}</td><td>${fmt(newT)}</td><td>${num(newT-oldT)}</td><td>${bar(del)}</td><td>${oldT>0?'100.0%':'0.0%'}</td><td>${newT>0?'100.0%':'0.0%'}</td><td>${money(oldData.arpu)}</td><td>${money(newData.arpu)}</td><td>${bar(ap)}</td>`;
    if(showBundle)body+=bundleCell(bundleTotals(oldData))+bundleCell(bundleTotals(newData));
    body+='</tr>';table.tBodies[0].innerHTML=body;
  }
  function render(){const r=state.response;const isMix=state.view==='mix';
    $$('[data-view]').forEach(b=>b.classList.toggle('is-active',b.dataset.view===state.view));
    $$('[data-panel]').forEach(p=>p.hidden=p.dataset.panel!==state.view);
    $$('[data-mix-only]').forEach(p=>p.hidden=!isMix);$$('[data-matrix-only]').forEach(p=>p.hidden=isMix);
    $$('[data-mode]').forEach(b=>b.classList.toggle('is-active',b.dataset.mode===state.mode));
    $('[data-popover-open]').firstChild.textContent=labelRange(r.periods)+' ';
    $('[data-next]').disabled=r.periods.current.to>=r.periods.limit;
    if(isMix){renderPlay(r);$('#mx-current-name').textContent=({'2P':'DOBLE PLAY','3P':'TRIPLE PLAY','TODAS':'TODAS LAS INSTALACIONES'})[state.selectedPlay];
      renderSpeed(r.old[state.selectedPlay],r.new[state.selectedPlay],true);renderCharts(r.old[state.selectedPlay],r.new[state.selectedPlay]);
    }else{renderMatrix(r);$('#mx-current-name').textContent=r.trail.length?r.trail.at(-1).name:'Región / todos los distritos';renderSpeed(r.old,r.new,false);renderCharts(r.old,r.new,r.selected.startsWith('v:'));}
    updateHero();
  }
  function legend(items,target){$(target).innerHTML=items.map(({name,color,dashed})=>`<span><i style="background:${color};${dashed?'outline:1px dashed '+color+';background:transparent':''}"></i>${esc(name)}</span>`).join('');}
  function svgChart(kind,oldData,newData,target){
    const W=930,H=275,left=52,right=20,top=20,bottom=44,innerW=W-left-right,innerH=H-top-bottom;
    const entries=Object.entries(newData.dias),previous=Object.entries(oldData.dias);
    const n=Math.max(1,entries.length,previous.length),x=i=>left+(n===1?innerW/2:i*innerW/(n-1));
    const ticks=entries.map(([date],i)=>i===0||i===n-1||n<=12||i%Math.ceil(n/12)===0?`<text x="${x(i)}" y="${H-10}" text-anchor="middle" class="mx-axis-label">${esc(date.slice(5))}</text>`:'').join('');
    let content=`<svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" role="img" aria-label="${kind==='speed'?'Distribución de instalaciones por velocidad':'ARPU por día'}">`;
    if(kind==='speed'){
      const rows=entries.map(([,v])=>v.participacion_velocidad_pct),speeds=Object.keys(newData.velocidades);
      for(let tick=0;tick<=100;tick+=20){const y=top+(1-tick/100)*innerH;content+=`<line x1="${left}" y1="${y}" x2="${W-right}" y2="${y}" class="mx-gridline"/><text x="${left-8}" y="${y+3}" text-anchor="end" class="mx-axis-label">${tick}%</text>`;}
      let prev=Array(n).fill(0);
      for(let j=0;j<speeds.length;j++){
        const cum=prev.map((v,i)=>v+(Number(rows[i]?.[speeds[j]]||0)));
        const coords=cum.map((v,i)=>`${x(i)},${top+(1-v/100)*innerH}`).join(' ');
        const lower=prev.map((v,i)=>`${x(i)},${top+(1-v/100)*innerH}`).reverse().join(' ');
        content+=`<polygon points="${coords} ${lower}" fill="${colors[j%colors.length]}" fill-opacity="0.93"><title>${esc(speeds[j])}</title></polygon>`;
        prev=cum;
      }
    }else{
      const vals=[...entries.map(([,d])=>d.arpu),...previous.map(([,d])=>d.arpu)].filter(n=>Number.isFinite(n));
      const minimum=vals.length?Math.floor(Math.min(...vals)/25)*25:0, maximum=vals.length?Math.ceil(Math.max(...vals)/25)*25:100;
      const span=Math.max(25,maximum-minimum);const y=v=>top+(1-(v-minimum)/span)*innerH;
      for(let k=0;k<=4;k++){let v=minimum+(k/4)*span;content+=`<line x1="${left}" y1="${y(v)}" x2="${W-right}" y2="${y(v)}" class="mx-gridline"/><text x="${left-7}" y="${y(v)+4}" text-anchor="end" class="mx-axis-label">$${Math.round(v)}</text>`;}
      const plot=(data,color,dash)=>{
        const parts=[];let seg=[];
        data.forEach(([,r],i)=>{if(r.arpu==null){if(seg.length)parts.push(seg);seg=[];return;}seg.push([x(i),y(r.arpu),r.arpu]);});if(seg.length)parts.push(seg);
        parts.forEach(points=>{const d=points.map(([xx,yy],i)=>`${i?'L':'M'}${xx.toFixed(2)} ${yy.toFixed(2)}`).join(' ');
          content+=`<path d="${d}" fill="none" stroke="${color}" stroke-width="3" ${dash?'stroke-dasharray="7 5"':''}/>`;
          points.forEach(([xx,yy,value])=>{content+=`<circle cx="${xx}" cy="${yy}" r="3.1" fill="${color}"><title>${money(value)}</title></circle>`;});});
      };plot(previous,'#34bfff',true);plot(entries,'#ed05a2',false);
    }
    content+=ticks+'</svg>';
    $(target).innerHTML=content;
  }
  // Sólo al seleccionar un vendedor individual en la Matriz: composición total
  // del periodo seleccionado, sin agregar una serie diaria innecesaria.
  function pieChart(newData){
    const total=Number(newData.total_instalaciones||0),all=Object.entries(newData.velocidades||{});
    const active=all.map(([name,row],i)=>({name,count:Number(row.instalaciones||0),color:colors[i%colors.length]})).filter(item=>item.count>0);
    if(total<=0||!active.length){$('#mx-speed-chart').innerHTML='<div class="mx-chart-empty">Sin instalaciones en el periodo seleccionado</div>';return;}
    const cx=300,cy=155,r=117;let angle=-Math.PI/2;
    const polar=a=>[cx+r*Math.cos(a),cy+r*Math.sin(a)];
    let svg='<svg class="mx-pie-svg" viewBox="0 0 600 310" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Gráfica de pastel: participación por velocidad del periodo seleccionado">';
    for(const item of active){
      const ratio=item.count/total,span=ratio*2*Math.PI;
      if(ratio>=1-1e-10){svg+=`<circle cx="${cx}" cy="${cy}" r="${r}" fill="${item.color}"><title>${esc(item.name)}: ${fmt(item.count)} instalaciones (100.0%)</title></circle>`;}
      else {const [sx,sy]=polar(angle),[ex,ey]=polar(angle+span),large=span>Math.PI?1:0;
        svg+=`<path d="M ${cx} ${cy} L ${sx.toFixed(4)} ${sy.toFixed(4)} A ${r} ${r} 0 ${large} 1 ${ex.toFixed(4)} ${ey.toFixed(4)} Z" fill="${item.color}" stroke="#fff" stroke-width="1.8"><title>${esc(item.name)}: ${fmt(item.count)} instalaciones (${(ratio*100).toFixed(1)}%)</title></path>`;}
      angle+=span;
    }
    svg+='</svg>';$('#mx-speed-chart').innerHTML=svg;
  }
  function renderCharts(oldData,newData,sellerPie=false){
    const speeds=Object.keys(newData.velocidades);
    $('#mx-speed-description').textContent=sellerPie?'Distribución de instalaciones por velocidad del periodo seleccionado':'Distribución porcentual diaria de instalaciones';
    if(sellerPie){
      const total=Number(newData.total_instalaciones||0);
      const items=speeds.map((name,i)=>({name,count:Number(newData.velocidades[name].instalaciones||0),color:colors[i%colors.length]}))
        .filter(x=>x.count>0).map(x=>({name:`${x.name}: ${(100*x.count/total).toFixed(1)}% · ${fmt(x.count)} INST`,color:x.color}));
      legend(items,'#mx-speed-legend');pieChart(newData);
    }else{legend(speeds.map((name,i)=>({name,color:colors[i%colors.length]})),'#mx-speed-legend');svgChart('speed',oldData,newData,'#mx-speed-chart');}
    legend([{name:'Periodo seleccionado',color:'#ed05a2'},{name:'Periodo anterior',color:'#34bfff',dashed:true}],'#mx-arpu-legend');
    svgChart('arpu',oldData,newData,'#mx-arpu-chart');
  }
  function switchView(next){state.view=next;state.node='';$('#mx-popover').hidden=true;reload();}
  $$('[data-view]').forEach(b=>b.addEventListener('click',()=>switchView(b.dataset.view)));
  $('#mx-person').addEventListener('change',e=>{state.scopePos=e.target.value;reload();});
  $('#mx-district').addEventListener('change',e=>{state.district=e.target.value;state.node='';reload();});
  $$('[data-mode]').forEach(b=>b.addEventListener('click',()=>{if(b.dataset.mode===state.mode)return;state.mode=b.dataset.mode;state.node='';$('#mx-popover').hidden=true;
    if(state.mode==='semanal'){const w=weekOf(state.dateTo);state.week=w.week;state.year=w.year;state.days=[1,2,3,4,5,6,7];}else{state.dateFrom=state.dateTo.slice(0,7)+'-01';state.monthView=state.dateTo.slice(0,7);}reload();}));
  function navigate(sign){if(state.mode==='semanal'){const mon=mondayOf(state.year,state.week),next=moved(mon,sign*7),w=weekOf(next);state.year=w.year;state.week=w.week;}
    else{state.dateFrom=monthMove(state.dateFrom,sign);state.dateTo=monthMove(state.dateTo,sign);state.monthView=state.dateTo.slice(0,7);}
    state.node='';reload();}
  $('[data-prev]').addEventListener('click',()=>navigate(-1));$('[data-next]').addEventListener('click',()=>navigate(1));
  const pop=$('#mx-popover');$('[data-popover-open]').addEventListener('click',()=>{pop.hidden=!pop.hidden;if(!pop.hidden)renderPopover();});
  // El clic sobre un día reconstruye sus botones. Si llega al document después de
  // reemplazar el botón original, pop.contains(e.target) sería false y cerraría el calendario.
  pop.addEventListener('click',e=>e.stopPropagation());
  document.addEventListener('click',e=>{if(!pop.hidden && !e.composedPath().includes(pop) && !e.target.closest('[data-popover-open]'))pop.hidden=true;});
  function renderPopover(){ $('.mx-week',pop).hidden=state.mode!=='semanal';$('.mx-month',pop).hidden=state.mode!=='mensual';
    if(state.mode==='semanal'){
      const mon=mondayOf(state.year,state.week);const limit=state.response?.periods?.limit||period.limit;
      $$('[data-weekday]',pop).forEach(btn=>{const n=Number(btn.dataset.weekday),date=moved(mon,n-1);btn.disabled=date>limit;btn.classList.toggle('is-active',state.days.includes(n));});
    }else {state.pickStart=state.dateFrom;state.pickEnd=state.dateTo;renderCalendar();}
  }
  $$('[data-weekday]',pop).forEach(b=>b.addEventListener('click',()=>{const day=Number(b.dataset.weekday);if(state.days.includes(day)&&state.days.length>1)state.days=state.days.filter(n=>n!==day);else if(!state.days.includes(day))state.days.push(day);state.days.sort((a,b)=>a-b);renderPopover();}));
  $('[data-apply-week]',pop).addEventListener('click',()=>{pop.hidden=true;reload();});
  function renderCalendar(){const first=state.monthView+'-01',start=parse(first),wd=(start.getDay()+6)%7;
    $('#mx-cal-title').textContent=new Intl.DateTimeFormat('es-MX',{month:'long',year:'numeric'}).format(start);
    const limit=state.response?.periods?.limit||period.limit;
    let html=DAYS.map(d=>`<span class="mx-week-label">${d}</span>`).join('');
    for(let i=0;i<42;i++){const day=moved(first,i-wd),outside=day.slice(0,7)!==state.monthView,disabled=day>limit;
      html+=`<button type="button" class="${outside?'is-outside':''} ${state.pickStart===day?'is-edge':''} ${state.pickEnd===day?'is-edge':''} ${state.pickStart&&state.pickEnd&&day>state.pickStart&&day<state.pickEnd?'is-between':''}" data-calendar-day="${day}" aria-label="${day}" ${disabled?'disabled':''}>${Number(day.slice(-2))}</button>`;
    }
    $('#mx-calendar').innerHTML=html;
    $('#mx-month-caption').textContent=state.pickStart?`${state.pickStart} — ${state.pickEnd||'Selecciona fecha final'}`:'Selecciona fecha inicial';
    $$('[data-calendar-day]').forEach(b=>b.addEventListener('click',()=>{const day=b.dataset.calendarDay;
      if(!state.pickStart||state.pickEnd){state.pickStart=day;state.pickEnd=null;}
      else if(day<state.pickStart){state.pickEnd=state.pickStart;state.pickStart=day;}
      else state.pickEnd=day;
      renderCalendar();}));
  }
  $('[data-cal-prev]').addEventListener('click',()=>{state.monthView=monthMove(state.monthView+'-01',-1).slice(0,7);renderCalendar();});
  $('[data-cal-next]').addEventListener('click',()=>{state.monthView=monthMove(state.monthView+'-01',1).slice(0,7);renderCalendar();});
  $('[data-full-month]').addEventListener('click',()=>{state.pickStart=state.monthView+'-01';const next=monthMove(state.pickStart,1);state.pickEnd=moved(next,-1);const limit=state.response?.periods?.limit||period.limit;if(state.pickEnd>limit)state.pickEnd=limit;renderCalendar();});
  $('[data-apply-month]').addEventListener('click',()=>{if(!state.pickStart)return;state.dateFrom=state.pickStart;state.dateTo=state.pickEnd||state.pickStart;state.node='';pop.hidden=true;reload();});
  // Selecciones iniciales provenientes de la página de Plataforma; no se confía en ellas para autorizar.
  const params=new URLSearchParams(location.search);
  if(params.get('scope_pos')&&[...$('#mx-person').options].some(o=>o.value===params.get('scope_pos'))){state.scopePos=params.get('scope_pos');$('#mx-person').value=state.scopePos;}
  if(params.get('district')&&[...$('#mx-district').options].some(o=>o.value===params.get('district'))){state.district=params.get('district');$('#mx-district').value=state.district;}
  reload();
})();
