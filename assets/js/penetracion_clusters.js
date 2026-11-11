/* TotalXpedient | Cuadrantes de Penetración v1.4 | SVG autocontenido, sin CDN. */
(() => {
  'use strict';
  const raw = document.getElementById('pc-chart-data');
  if (!raw) return;
  let data;
  try { data = JSON.parse(raw.textContent); } catch (_) { return; }
  const svgNS = 'http://www.w3.org/2000/svg';
  const nf = new Intl.NumberFormat('es-MX', {maximumFractionDigits: 0});
  const pct = v => Number(v).toFixed(1) + '%';
  const W = 760, H = 468, L = 66, R = 24, T = 35, B = 68;
  const pw = W - L - R, ph = H - T - B;
  const all = [...(data.NORMAL || []), ...(data.CLUSTER_A || [])];
  const limits = data.umbrales || {NORMAL:{pen:29,vel:5},CLUSTER_A:{pen:20,vel:7}};
  const allLimits = Object.values(limits);
  const xMax = Math.ceil(Math.max(80, ...allLimits.map(l => Number(l.pen) + 12), ...all.map(p => Number(p.pen) + 5)) / 10) * 10;
  const yMin = Math.floor(Math.min(-15, ...allLimits.map(l => Number(l.vel) - 10), ...all.map(p => Number(p.vel) - 4)) / 5) * 5;
  const yMax = Math.ceil(Math.max(25, ...allLimits.map(l => Number(l.vel) + 10), ...all.map(p => Number(p.vel) + 4)) / 5) * 5;
  const maxHP = Math.max(1, ...all.map(p => p.hp));
  const x = v => L + (Math.max(0, Math.min(xMax, v)) / xMax) * pw;
  const y = v => T + (yMax - Math.max(yMin, Math.min(yMax, v))) / (yMax - yMin) * ph;
  const radius = hp => 7 + 24 * Math.sqrt(Math.max(0, hp) / maxHP);
  const tooltip = document.createElement('div');
  tooltip.className = 'pc-chart-tooltip'; tooltip.setAttribute('role','tooltip');
  document.body.appendChild(tooltip);
  const create = (tag, props = {}, parent) => {
    const el = document.createElementNS(svgNS, tag);
    for (const [k, v] of Object.entries(props)) el.setAttribute(k, String(v));
    if (parent) parent.appendChild(el);
    return el;
  };
  const text = (parent, s, xx, yy, cls, extra={}) => {
    const el = create('text', {x:xx,y:yy,class:cls,...extra},parent); el.textContent=s; return el;
  };
  function showTooltip(ev, p){
    tooltip.replaceChildren();
    const heading = document.createElement('b'); heading.textContent = p.label;
    tooltip.appendChild(heading);
    const lines = [
      p.distrito + ' · ' + p.municipio,
      'Penetración: ' + pct(p.pen) + ' · Velocidad: ' + pct(p.vel),
      'HPs: ' + nf.format(p.hp) + ' · Usuarios: ' + nf.format(p.usuarios),
      'Hijos graficables: ' + p.hijos,
      'Cuadrante: ' + p.cuadrante
    ];
    for (const line of lines){tooltip.appendChild(document.createElement('br'));tooltip.appendChild(document.createTextNode(line));}
    tooltip.appendChild(document.createElement('br'));
    const note = document.createElement('em');note.textContent = 'Clic para bajar al detalle del padre';tooltip.appendChild(note);
    tooltip.classList.add('visible'); positionTooltip(ev);
  }
  function positionTooltip(ev){
    const gap=15;
    let xx=ev.clientX+gap, yy=ev.clientY+gap;
    const {width,height}=tooltip.getBoundingClientRect();
    if (xx+width>window.innerWidth-8)xx=ev.clientX-width-gap;
    if (yy+height>window.innerHeight-8) yy=ev.clientY-height-gap;
    tooltip.style.left=Math.max(8,xx)+'px';tooltip.style.top=Math.max(8,yy)+'px';
  }
  const hideTip=()=>tooltip.classList.remove('visible');
  function drill(p){
    const url = new URL(window.location.href);
    if (data.corte) url.searchParams.set('corte',data.corte);
    if (data.periodo) url.searchParams.set('periodo',data.periodo);
    if (data.canDistrict) url.searchParams.set('distrito',p.distrito);
    url.searchParams.set('padre',p.padre);
    url.searchParams.set('nivel','HIJO');
    // Un clúster padre puede tener hijos en distintos municipios;
    // se elimina localidad para evitar excluir descendientes silenciosamente.
    url.searchParams.delete('municipio');
    window.location.assign(url.toString());
  }
  const showLabels = document.getElementById('show-labels');
  const labelGroups=[];
  function render(targetId, points, color, pale, limit){
    const root=document.getElementById(targetId);if(!root)return;
    root.replaceChildren();
    const svg=create('svg',{viewBox:`0 0 ${W} ${H}`,role:'img','aria-label':'Penetración horizontal y velocidad anual vertical; cada burbuja representa casas pasadas del clúster'},root);
    const defs=create('defs',{},svg);
    const clip=create('clipPath',{id:targetId+'-clip'},defs);
    create('rect',{x:L,y:T,width:pw,height:ph},clip);
    // Colores sutiles de oportunidad y rezago, sin sustituir el significado de los límites.
    const splitX=x(limit.pen),splitY=y(limit.vel);
    const colorRects=[
      [L,T,splitX-L,splitY-T,'#e9f5ff'], [splitX,T,W-R-splitX,splitY-T,'#e6f9f0'],
      [L,splitY,splitX-L,H-B-splitY,'#fbeffa'], [splitX,splitY,W-R-splitX,H-B-splitY,'#fff8e7']
    ];
    for (const [rx,ry,rw,rh,c] of colorRects)create('rect',{x:rx,y:ry,width:Math.max(0,rw),height:Math.max(0,rh),fill:c},svg);
    // Líneas guía iguales en ambas vistas.
    for(let i=0;i<=xMax;i+=10){let px=x(i);create('line',{x1:px,y1:T,x2:px,y2:H-B,stroke:'#e0e9ef','stroke-width':1},svg);text(svg,i+'%',px,H-B+19,'pc-svg-axis',{'text-anchor':'middle'});}
    const yStep= (yMax-yMin)>90?20:10;
    for(let i=Math.ceil(yMin/yStep)*yStep;i<=yMax;i+=yStep){let py=y(i);create('line',{x1:L,y1:py,x2:W-R,y2:py,stroke:'#e0e9ef','stroke-width':1},svg);text(svg,i+'%',L-9,py+4,'pc-svg-axis',{'text-anchor':'end'});}
    create('rect',{x:L,y:T,width:pw,height:ph,fill:'none',stroke:'#9eb9ca','stroke-width':1.4},svg);
    create('line',{x1:splitX,y1:T,x2:splitX,y2:H-B,stroke:'#ec6559','stroke-width':2.2,'stroke-dasharray':'5 3'},svg);
    create('line',{x1:L,y1:splitY,x2:W-R,y2:splitY,stroke:'#ec6559','stroke-width':2.2,'stroke-dasharray':'5 3'},svg);
    text(svg,pct(limit.pen),splitX,H-B+34,'pc-svg-axis',{'text-anchor':'middle',fill:'#cd3c34'}).setAttribute('style','font-weight:800;fill:#c34b40');
    text(svg,pct(limit.vel),L-9,splitY-7,'pc-svg-axis',{'text-anchor':'end'}).setAttribute('style','font-weight:800;fill:#c34b40');
    text(svg,'Penetración (%)',L+pw/2,H-9,'pc-svg-axis-title',{'text-anchor':'middle'});
    text(svg,'Velocidad anual (%)',21,T+ph/2,'pc-svg-axis-title',{'text-anchor':'middle',transform:`rotate(-90 21 ${T+ph/2})`});
    const corners=[
      ['BAJA PEN. / ALTA VEL.',L+7,T+17],
      ['ALTA PEN. / ALTA VEL.',W-R-7,T+17],
      ['BAJA PEN. / BAJA VEL.',L+7,H-B-10],
      ['ALTA PEN. / BAJA VEL.',W-R-7,H-B-10]
    ];
    corners.forEach(([t,cx,cy],j)=>text(svg,t,cx,cy,'pc-svg-quadrant',{'text-anchor':j%2?'end':'start'}));
    const group=create('g',{'clip-path':`url(#${targetId}-clip)`},svg);
    // Círculos grandes primero: los clústeres pequeños siguen siendo clicables.
    const sorted=[...points].sort((a,b)=>b.hp-a.hp);
    const labelGroup=create('g',{},svg);labelGroups.push(labelGroup);
    // Hasta 18 etiquetas para mantener legibilidad; siempre disponibles en tooltip y tabla.
    const labelCandidates=[...points].sort((a,b)=>b.hp-a.hp).slice(0,18);
    for (const p of sorted) {
      const cx=x(p.pen),cy=y(p.vel),rr=radius(p.hp);
      const bubble=create('circle',{cx,cy,r:rr,fill:color,'fill-opacity':0.59,stroke:color,'stroke-width':1.5,
        class:'pc-svg-bubble',tabindex:0,role:'button',
        'aria-label':`${p.label}, penetración ${pct(p.pen)}, velocidad ${pct(p.vel)}, ${nf.format(p.hp)} HPs. Presiona Enter para ver hijos.`},group);
      const title=create('title',{},bubble);title.textContent=p.label+' · '+pct(p.pen)+' · '+pct(p.vel);
      bubble.addEventListener('mousemove',ev=>showTooltip(ev,p));
      bubble.addEventListener('mouseleave',hideTip);
      bubble.addEventListener('focus',ev=>showTooltip({clientX:ev.target.getBoundingClientRect().x,clientY:ev.target.getBoundingClientRect().y},p));
      bubble.addEventListener('blur',hideTip);
      bubble.addEventListener('click',()=>drill(p));
      bubble.addEventListener('keydown',ev=>{if(ev.key==='Enter'||ev.key===' '){ev.preventDefault();drill(p);}});
    }
    for (const p of labelCandidates) {
      const cx=x(p.pen),cy=y(p.vel),rr=radius(p.hp);
      const left=cx>W-R-105;
      const labelX=Math.max(L+3,Math.min(W-R-3,cx+(left?-rr-5:rr+5)));
      const t=text(labelGroup,p.label,labelX,Math.max(T+25,Math.min(H-B-24,cy+4)),'pc-svg-label'+(targetId==='chart-a'?' pc-a':''),{'text-anchor':left?'end':'start'});
      // Truncar textos muy extensos, pero el nombre completo está en el tooltip.
      if(p.label.length>24)t.textContent=p.label.slice(0,22)+'…';
    }
    if (!points.length){text(svg,'Sin burbujas graficables en este filtro',L+pw/2,T+ph/2,'pc-svg-axis-title',{'text-anchor':'middle'});}
  }
  render('chart-normal',data.NORMAL || [],'#2688cc','#e9f5ff',limits.NORMAL);
  render('chart-a',data.CLUSTER_A || [],'#9060cf','#eee7fa',limits.CLUSTER_A);
  const toggle=()=>labelGroups.forEach(el=>{el.style.display=showLabels?.checked?'':'none';});
  showLabels?.addEventListener('change',toggle);
  toggle();
  // Navegación semanal/mensual mediante enlaces del servidor y cortes existentes.
  // Sin calendarios diarios ni selección de días comparables.
  // Filtros que cambian el universo actual invalidan selects dependientes.
  document.getElementById('distrito')?.addEventListener('change',()=>{
    document.getElementById('municipio').value='';
    document.getElementById('padre').value='';
  });
  document.getElementById('municipio')?.addEventListener('change',()=>{document.getElementById('padre').value='';});
  // Ordenamiento interactivo de las dos tablas de brechas.
  // En columnas numericas, primer clic: mayor a menor; segundo: menor a mayor.
  // Datos sin base (ND) se dejan al final en cualquier direccion.
  for (const tableEl of document.querySelectorAll('table.pc-rank-table[data-sortable="true"]')) {
    const headers = [...tableEl.querySelectorAll('thead th')];
    const tbody = tableEl.querySelector('tbody');
    if (!tbody) continue;
    let currentCol = -1, currentDir = 'desc';
    headers.forEach((th, col) => {
      const btn = th.querySelector('button[data-sort-key]');
      if (!btn) return;
      btn.addEventListener('click', () => {
        const numeric = btn.dataset.sortType === 'number';
        const dir = currentCol === col ? (currentDir === 'desc' ? 'asc' : 'desc') : (numeric ? 'desc' : 'asc');
        currentCol = col; currentDir = dir;
        const lines = [...tbody.rows];
        lines.sort((a,b) => {
          const va = a.cells[col]?.getAttribute('data-sort-value') ?? '';
          const vb = b.cells[col]?.getAttribute('data-sort-value') ?? '';
          if (va === '' && vb === '') return 0;
          if (va === '') return 1;
          if (vb === '') return -1;
          let cmp = numeric ? (Number(va) - Number(vb)) : va.localeCompare(vb,'es',{numeric:true,sensitivity:'base'});
          if (!Number.isFinite(cmp)) cmp=0;
          return dir === 'asc' ? cmp : -cmp;
        });
        tbody.append(...lines);
        headers.forEach(h => {
          h.setAttribute('aria-sort','none');
          const mark=h.querySelector('.pc-sort-indicator');
          if (mark) mark.textContent='↕';
        });
        th.setAttribute('aria-sort', dir === 'asc' ? 'ascending':'descending');
        const mark=btn.querySelector('.pc-sort-indicator');
        if (mark) mark.textContent=dir === 'asc' ? '↑':'↓';
      });
    });
  }
  const search=document.getElementById('pc-search');
  const table=document.getElementById('pc-detail-table');
  const count=document.getElementById('pc-table-count');
  search?.addEventListener('input',()=>{
    const q=search.value.trim().toLocaleLowerCase('es');let n=0;
    for(const row of table.querySelectorAll('tbody tr')){
      const visible=row.textContent.toLocaleLowerCase('es').includes(q);
      row.hidden=!visible;if(visible)n++;
    }
    count.textContent=n+' de '+table.querySelectorAll('tbody tr').length+' registros visibles';
  });
})();
