/* TalIA · Reto Buen Mes v1.2.0 */
(function () {
  'use strict';
  const form = document.querySelector('.rbm-toolbar');
  const trigger = document.getElementById('rbm-range-trigger');
  const pop = document.getElementById('rbm-range-popover');
  const label = document.getElementById('rbm-range-label');
  const from = document.getElementById('rbm-from-input');
  const to = document.getElementById('rbm-to-input');
  if (form && trigger && pop && label && from && to) {
    let start = Number(from.value), end = Number(to.value), awaitingEnd = false;
    const days = Array.from(pop.querySelectorAll('[data-day]'));
    const fmt = n => String(n).padStart(2, '0') + '/11/2026';
    function paint() {
      label.textContent = fmt(start) + ' – ' + fmt(end);
      for (const b of days) {
        const d = Number(b.dataset.day);
        b.classList.toggle('is-selected', d === start || d === end);
        b.classList.toggle('is-in-range', d > start && d < end);
        b.setAttribute('aria-pressed', String(d >= start && d <= end));
      }
    }
    function position() {
      if (pop.hidden) return;
      const r = trigger.getBoundingClientRect(), pad = 10, gap = 8;
      const vw = document.documentElement.clientWidth, vh = window.innerHeight;
      const w = Math.min(350, vw - 2 * pad);
      pop.style.width = w + 'px';
      const left = Math.max(pad, Math.min(r.right - w, vw - w - pad));
      const below = vh - r.bottom - gap - pad, above = r.top - gap - pad;
      const desired = Math.min(pop.scrollHeight + 4, vh - 2 * pad);
      const useAbove = below < desired && above > below;
      const available = Math.max(0, useAbove ? above : below);
      // If the trigger is near a viewport edge, use the available viewport instead of clipping.
      const height = Math.min(desired, Math.max(available, Math.min(260, vh - 2 * pad)));
      const top = useAbove ? r.top - gap - height : r.bottom + gap;
      pop.style.maxHeight = height + 'px';
      pop.style.left = left + 'px';
      pop.style.top = Math.max(pad, Math.min(top, vh - height - pad)) + 'px';
    }
    function open() {
      // Move to body: shared sidebar/main and glass cards can establish clipping/stacking contexts.
      document.body.appendChild(pop);
      pop.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      paint(); position();
    }
    function close() { pop.hidden = true; trigger.setAttribute('aria-expanded', 'false'); }
    function sync() { from.value = String(start); to.value = String(end); }
    trigger.addEventListener('click', () => pop.hidden ? open() : close());
    for (const b of days) b.addEventListener('click', () => {
      const d = Number(b.dataset.day);
      if (!awaitingEnd) { start = end = d; awaitingEnd = true; }
      else { end = Math.max(start, d); start = Math.min(start, d); awaitingEnd = false; }
      paint();
    });
    document.getElementById('rbm-cal-reset').addEventListener('click', () => {
      start = 1; end = Number(form.dataset.defaultEnd) || 1; awaitingEnd = false; paint();
    });
    document.getElementById('rbm-cal-apply').addEventListener('click', () => {
      sync(); close();
      if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
    });
    form.addEventListener('submit', () => { sync(); close(); });
    document.addEventListener('click', e => { if (!pop.hidden && !pop.contains(e.target) && !trigger.contains(e.target)) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !pop.hidden) { close(); trigger.focus(); } });
    window.addEventListener('resize', position);
    window.addEventListener('scroll', e => { if (!pop.contains(e.target)) position(); }, true);
    paint();
  }

  const collator = new Intl.Collator('es-MX', {numeric:true, sensitivity:'base'});
  for (const table of document.querySelectorAll('.rbm-table[data-default-sort]')) {
    const headers = Array.from(table.querySelectorAll('thead th[data-key]'));
    const tbody = table.tBodies[0];
    if (!tbody || !headers.length) continue;
    let key = table.dataset.defaultSort, dir = table.dataset.defaultDir || 'desc';
    function sort(index, direction) {
      const th = headers[index], type = th.dataset.type;
      const rows = Array.from(tbody.rows).filter(r => r.cells.length === headers.length);
      const value = r => r.cells[index].dataset.sort ?? r.cells[index].textContent.trim();
      rows.sort((a,b) => {
        const av = value(a), bv = value(b);
        if (av === '' || bv === '') return av === bv ? 0 : av === '' ? 1 : -1;
        const cmp = type === 'num' ? Number(av) - Number(bv) : collator.compare(av,bv);
        return (direction === 'asc' ? cmp : -cmp) || collator.compare(valueName(a),valueName(b));
      });
      for (const row of rows) tbody.appendChild(row);
    }
    function valueName(r) { return r.cells[0].dataset.sort ?? r.cells[0].textContent.trim(); }
    for (const [i, th] of headers.entries()) {
      th.querySelector('button')?.addEventListener('click', () => {
        const next = th.dataset.key;
        dir = next === key ? (dir === 'desc' ? 'asc' : 'desc') : (th.dataset.type === 'text' ? 'asc' : 'desc');
        key = next;
        if (table.dataset.serverSort === '1') {
          const url = new URL(window.location.href);
          url.searchParams.set('orden',key); url.searchParams.set('dir',dir); url.searchParams.delete('pagina');
          window.location.assign(url.toString()); return;
        }
        sort(i,dir);
        for (const h of headers) {
          const active = h === th;
          h.setAttribute('aria-sort', active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
          h.querySelector('.rbm-sort-icon').textContent = active ? (dir === 'asc' ? '↑' : '↓') : '↕';
        }
      });
    }
  }
})();
