(()=>{'use strict';
  const detailButtons=Array.from(document.querySelectorAll('[data-detail]'));
  function setDetail(panel,visible){
    panel.hidden=!visible;
    for(const btn of detailButtons)if('detalle-'+btn.dataset.detail===panel.id){btn.setAttribute('aria-expanded',String(visible));}
  }
  detailButtons.forEach(button=>{
    button.setAttribute('aria-controls','detalle-'+button.dataset.detail);
    button.setAttribute('aria-expanded','false');
    button.addEventListener('click',()=>{const panel=document.getElementById('detalle-'+button.dataset.detail);if(!panel)return;
      const opening=panel.hidden;
      document.querySelectorAll('.tp-details').forEach(el=>setDetail(el,false));
      setDetail(panel,opening);
      if(opening)panel.scrollIntoView({behavior:'smooth',block:'start'});
    });
  });
  document.querySelectorAll('[data-close-detail]').forEach(button=>{
    button.addEventListener('click',()=>{
      const panel=document.getElementById('detalle-'+button.dataset.closeDetail);
      if(!panel)return;
      setDetail(panel,false);
      const opener=detailButtons.find(btn=>btn.dataset.detail===button.dataset.closeDetail);
      if(opener){opener.focus({preventScroll:true});opener.scrollIntoView({behavior:'smooth',block:'nearest'});}
    });
  });
  // Scope options are bound to immutable HC position ids, never commercial names.
  // A city change immediately narrows the director/leader/coach selector.
  const citySelect=document.getElementById('tpDistrictSelect');
  const hierarchySelect=document.getElementById('tpHierarchySelect');
  if(citySelect && hierarchySelect){
    const updateHierarchyOptions=()=>{
      const district=citySelect.value;
      const selected=hierarchySelect.value;
      let selectedAllowed=!selected;
      for(const option of hierarchySelect.options){
        if(!option.value)continue;
        const allowed=!district||option.dataset.district===district;
        option.hidden=!allowed;
        option.disabled=!allowed;
        if(option.value===selected && allowed)selectedAllowed=true;
      }
      if(!selectedAllowed)hierarchySelect.value='';
    };
    citySelect.addEventListener('change',updateHierarchyOptions);
    updateHierarchyOptions();
  }
  // Regional report shortcuts are available only when all three filters
  // are at their unrestricted "Todos" values. Pending form changes hide them
  // immediately; hidden shortcuts are never a substitute for server permissions.
  const regionalLinks=Array.from(document.querySelectorAll('[data-regional-link]'));
  const regionalPlaceholders=Array.from(document.querySelectorAll('[data-regional-placeholder]'));
  if(regionalPlaceholders.length){
    const form=document.querySelector('.tp-filterform');
    const channelSelect=form?.querySelector('[name="channel"]');
    const updateRegionalShortcuts=()=>{
      const all=!!form && !citySelect?.value && !channelSelect?.value && !hierarchySelect?.value;
      const show=all && regionalLinks.length===2;
      regionalLinks.forEach(link=>{link.hidden=!show;});
      regionalPlaceholders.forEach(label=>{label.hidden=show;});
    };
    for(const control of [citySelect,channelSelect,hierarchySelect]){
      if(control)control.addEventListener('change',updateRegionalShortcuts);
    }
    updateRegionalShortcuts();
  }
  // Shared, read-only detail dialog: each metric opens precisely the rows used to count it.
  const recordModal=document.getElementById('tpRecordModal');
  const dataNode=document.getElementById('tpDetailsData');
  if(recordModal&&dataNode){
    let datasets={};try{datasets=JSON.parse(dataNode.textContent||'{}');}catch(err){datasets={};}
    const dialog=recordModal.querySelector('.tp-record-dialog');
    const head=recordModal.querySelector('#tpRecordTable thead');
    const body=recordModal.querySelector('#tpRecordTable tbody');
    const title=recordModal.querySelector('#tp-record-title');
    const count=recordModal.querySelector('#tp-record-count');
    const closer=recordModal.querySelector('#tpRecordClose');
    const pager=document.createElement('div');pager.className='tp-record-pager';
    const prev=document.createElement('button');prev.type='button';prev.textContent='Anterior';
    const status=document.createElement('span');
    const next=document.createElement('button');next.type='button';next.textContent='Siguiente';
    pager.append(prev,status,next);recordModal.querySelector('.tp-record-body').appendChild(pager);
    const limit=80;let page=0,active=null,opener=null;
    const cell=(tag,value)=>{const node=document.createElement(tag);node.textContent=String(value??'—');return node;};
    const draw=()=>{
      head.replaceChildren();body.replaceChildren();
      if(!active)return;
      const tr=document.createElement('tr');for(const name of active.columns||[])tr.appendChild(cell('th',name));head.appendChild(tr);
      const items=active.rows||[];
      for(const [localIndex,row] of items.slice(page*limit,(page+1)*limit).entries()){
        const absoluteIndex=page*limit+localIndex;
        const t=document.createElement('tr');
        const issue=active.invalidCells?.[String(absoluteIndex)];
        if(issue)t.classList.add('tp-excluded-row');
        for(const [columnIndex,value] of row.entries()){
          const td=cell('td',value);
          if(issue && (columnIndex===Number(issue[0]) || columnIndex===0 || columnIndex===13)){
            td.classList.add('tp-invalid-field');
            if(columnIndex===Number(issue[0])){
              td.title=String(issue[2]||'Campo inválido');
              if(!String(value??'').trim() || value==='—')td.textContent='SIN DATO';
            }
          }
          t.appendChild(td);
        }
        body.appendChild(t);
      }
      if(!items.length){const tr=document.createElement('tr');const td=cell('td','No hay registros en este corte.');td.colSpan=Math.max(1,(active.columns||[]).length);tr.appendChild(td);body.appendChild(tr);}
      const pages=Math.max(1,Math.ceil(items.length/limit));
      status.textContent=`Página ${page+1} de ${pages}`;
      prev.disabled=page<=0;next.disabled=page>=pages-1;
    };
    const close=()=>{
      if(recordModal.hidden)return;
      recordModal.hidden=true;recordModal.setAttribute('aria-hidden','true');
      document.body.classList.remove('tp-modal-active');
      if(opener&&opener.isConnected)opener.focus({preventScroll:true});
    };
    function open(key,button){
      const data=datasets[key];if(!data)return;
      active=data;page=0;opener=button;
      title.textContent=data.title||'Detalle de registros';
      count.textContent=data.summary||`${(data.rows||[]).length.toLocaleString('es-MX')} registros contabilizados`;
      draw();recordModal.hidden=false;recordModal.setAttribute('aria-hidden','false');
      document.body.classList.add('tp-modal-active');dialog.focus();
    }
    document.querySelectorAll('[data-record-set]').forEach(button=>button.addEventListener('click',()=>open(button.dataset.recordSet,button)));
    closer.addEventListener('click',close);
    recordModal.addEventListener('click',event=>{if(event.target===recordModal)close();});
    document.addEventListener('keydown',event=>{
      if(recordModal.hidden)return;
      if(event.key==='Escape'){event.preventDefault();close();}
      if(event.key==='Tab'){
        const focusable=[...dialog.querySelectorAll('button:not(:disabled),[href],input,[tabindex="0"]')].filter(el=>el.getClientRects().length);
        if(focusable.length){const first=focusable[0],last=focusable[focusable.length-1];
          if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
          else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
        }
      }
    });
    prev.addEventListener('click',()=>{if(page>0){page--;draw();}});
    next.addEventListener('click',()=>{if(active&&page<Math.ceil(active.rows.length/limit)-1){page++;draw();}});
  }
  const el=document.getElementById('rhythmChart'), script=document.getElementById('rhythmData');if(!el||!script)return;
  let d;try{d=JSON.parse(script.textContent);}catch(e){return;}
  const ns='http://www.w3.org/2000/svg';const svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox','0 0 940 330');svg.setAttribute('preserveAspectRatio','none');
  const make=(name,attrs={},parent=svg)=>{const node=document.createElementNS(ns,name);for(const [k,v]of Object.entries(attrs))node.setAttribute(k,String(v));parent.appendChild(node);return node;};
  const cutoff=Math.max(0,Math.min(24,Number.isInteger(d.cutoff)?d.cutoff:24));
  const visible=a=>(a||[]).slice(0,cutoff+1);
  const all=[...visible(d.sales),...visible(d.installs),...visible(d.previousSales),...visible(d.previousInstalls)].filter(Number.isFinite);
  const yMax=Math.max(9,Math.ceil(Math.max(0,...all)/9)*9);const W=850,H=245,x0=59,y0=277,x=i=>x0+(i/Math.max(1,cutoff))*W,y=v=>y0-(v/yMax)*H;
  for(let tick=0;tick<=4;tick++){const v=tick*yMax/4,yp=y(v);make('line',{x1:x0,y1:yp,x2:910,y2:yp,stroke:'#edf0f8','stroke-width':1});const t=make('text',{x:20,y:yp+4,'font-size':12,fill:'#8897ae'});t.textContent=Number.isInteger(v)?v:v.toFixed(1);}
  const ticks=[];for(let i=0;i<=cutoff;i+=2)ticks.push(i);if(ticks[ticks.length-1]!==cutoff)ticks.push(cutoff);
  for(const i of ticks){const t=make('text',{x:x(i),y:306,'text-anchor':i===0?'start':(i===cutoff?'end':'middle'),'font-size':10,fill:'#7e8fa7'});t.textContent=String(i).padStart(2,'0')+':00';}
  const series=[['previousSales','#b18cfa',1.8],['previousInstalls','#ff9fdd',1.8],['sales','#7e24ff',4],['installs','#f224a9',4]];
  for(const [name,color,width] of series){const data=d[name]||[];let path='';let seg=0;
    for(let i=0;i<=cutoff;i++){const v=data[i];if(!Number.isFinite(v)){seg=0;continue;}path+=(seg?' L ':' M ')+x(i).toFixed(1)+' '+y(v).toFixed(1);seg++;}
    make('path',{d:path,fill:'none',stroke:color,'stroke-width':width,'stroke-linejoin':'round','stroke-linecap':'round',opacity:width<3?'.82':'1'});
    if(width>3)for(let i=0;i<=cutoff;i++){const v=data[i];if(!Number.isFinite(v)||v===0)continue;const c=make('circle',{cx:x(i),cy:y(v),r:4.3,fill:'#fff',stroke:color,'stroke-width':3});const title=make('title',{},c);title.textContent=`${name==='sales'?'Ventas':'Instalaciones'} ${String(i-1).padStart(2,'0')}:00–${String(i-1).padStart(2,'0')}:59 (cierre ${String(i).padStart(2,'0')}:00): ${v}`;if(v>0){const label=make('text',{x:x(i),y:y(v)-11,'font-size':11,'font-weight':700,'text-anchor':'middle',fill:color});label.textContent=v;}}
  }
  el.appendChild(svg);
})();
