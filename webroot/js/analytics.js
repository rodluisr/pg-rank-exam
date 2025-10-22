(function () {
  const $ = (s, r = document) => r.querySelector(s);

  const dateInput = $('#anaDate');
  const reloadBtn = $('#anaReload');
  const emptyEl   = $('#anaEmpty');
  const canvas    = $('#anaCanvas');
  const ctx       = canvas.getContext('2d');

  // Recent logs table
  const logsTableBody = $('#logsTable tbody');
  const RECENT_COUNT = 100;

  document.addEventListener('DOMContentLoaded', init);

  async function init() {
    // default to today
    const today = new Date();
    if (dateInput) dateInput.value = toYMD(today);

    if (reloadBtn) {
      reloadBtn.addEventListener('click', async () => {
        await loadAndDraw();
        await loadRecentLogs();
      });
    }

    await loadAndDraw();
    await loadRecentLogs();
  }

  // -------------------- Chart (DAU by minute) --------------------
  async function loadAndDraw() {
    const date = (dateInput && dateInput.value) || toYMD(new Date());
    try {
      const res = await fetch(`/api/analytics?date=${encodeURIComponent(date)}`);
      const json = await res.json();
      const labels = json?.result?.labels || [];
      const counts = json?.result?.counts || [];
      if (emptyEl) emptyEl.hidden = labels.length > 0;

      drawBars(labels, counts, { title: `DAU per minute — ${date}` });
    } catch (e) {
      console.error('[analytics] fetch failed', e);
      if (emptyEl) emptyEl.hidden = false;
    }
  }

  function drawBars(labels, counts, { title } = {}) {
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Margins
    const W = canvas.width, H = canvas.height;
    const pad = { l: 50, r: 20, t: 30, b: 40 };
    const innerW = W - pad.l - pad.r;
    const innerH = H - pad.t - pad.b;

    // Title
    ctx.fillStyle = '#a0a5ad';
    ctx.font = '14px system-ui, -apple-system, Segoe UI, Roboto, Arial';
    ctx.fillText(title || '', pad.l, pad.t - 10);

    if (!counts.length) return;

    const max = Math.max(1, ...counts);
    const n = counts.length;
    const gap = 2; // px between bars
    const barW = Math.max(1, Math.floor((innerW - (n - 1) * gap) / n));

    // Axes
    ctx.strokeStyle = 'rgba(255,255,255,0.12)';
    ctx.lineWidth = 1;
    // y axis
    ctx.beginPath();
    ctx.moveTo(pad.l, pad.t);
    ctx.lineTo(pad.l, H - pad.b);
    ctx.stroke();
    // x axis
    ctx.beginPath();
    ctx.moveTo(pad.l, H - pad.b);
    ctx.lineTo(W - pad.r, H - pad.b);
    ctx.stroke();

    // Y ticks (0, max)
    ctx.fillStyle = '#a0a5ad';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    ctx.fillText('0', pad.l - 8, H - pad.b);
    ctx.fillText(String(max), pad.l - 8, pad.t);

    // Bars
    ctx.fillStyle = '#3773ff';
    for (let i = 0; i < n; i++) {
      const v = counts[i];
      const h = Math.round((v / max) * innerH);
      const x = pad.l + i * (barW + gap);
      const y = H - pad.b - h;
      ctx.fillRect(x, y, barW, h);
    }

    // X labels
    ctx.fillStyle = '#a0a5ad';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    const step = Math.ceil(n / 12); // up to ~12 labels
    for (let i = 0; i < n; i += step) {
      const x = pad.l + i * (barW + gap) + barW / 2;
      ctx.fillText(labels[i], x, H - pad.b + 6);
    }
  }

  // -------------------- Recent logs list --------------------
  async function loadRecentLogs() {
    if (!logsTableBody) return;

    try {
      const res = await fetch(`/api/analytics?recent=${RECENT_COUNT}`);
      const json = await res.json();
      const rows = Array.isArray(json?.result) ? json.result : [];
      renderRecentLogs(rows);
    } catch (e) {
      console.error('[analytics] recent logs failed', e);
      renderRecentLogs([]);
    }
  }

  function renderRecentLogs(rows) {
    while (logsTableBody.firstChild) logsTableBody.removeChild(logsTableBody.firstChild);

    if (!rows.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 6;
      td.className = 'muted';
      td.textContent = 'No recent logs.';
      tr.appendChild(td);
      logsTableBody.appendChild(tr);
      return;
    }

    rows.forEach(r => {
      const tr = document.createElement('tr');

      appendCell(tr, r.time || '');
      appendCell(tr, r.ip || '');
      appendCell(tr, r.method || '');
      appendCell(tr, r.path || '');
      appendCell(tr, r.status || '');
      appendCell(tr, r.ua || '');

      logsTableBody.appendChild(tr);
    });
  }

  function appendCell(tr, text) {
    const td = document.createElement('td');
    td.textContent = String(text);
    tr.appendChild(td);
  }

  // -------------------- Utils --------------------
  function toYMD(d) {
    const y = d.getFullYear();
    const m = `${d.getMonth() + 1}`.padStart(2, '0');
    const day = `${d.getDate()}`.padStart(2, '0');
    return `${y}-${m}-${day}`;
  }
})();
