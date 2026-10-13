<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rep Tracker</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        :root {
            --bg: #f5f6f8; --panel: #ffffff; --text: #1d2330; --muted: #6b7385; --border: #e3e6ec;
            --accent: #2563eb; --move: #2563eb; --stop: #ea7a17; --gap: #dc2626; --ok: #16a34a;
            --shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 1px 3px rgba(16, 24, 40, .08);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1218; --panel: #171b23; --text: #e6e9ef; --muted: #8b93a5; --border: #262c38;
                --accent: #60a5fa; --move: #60a5fa; --stop: #f59e0b; --gap: #f87171; --ok: #4ade80;
                --shadow: none;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }
        header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; padding: 12px 20px; background: var(--panel); border-bottom: 1px solid var(--border); }
        header h1 { font-size: 17px; margin: 0 12px 0 0; }
        header h1 span { color: var(--muted); font-weight: 400; }
        select, input[type=date], button {
            font: inherit; color: var(--text); background: var(--panel); border: 1px solid var(--border);
            border-radius: 8px; padding: 6px 10px;
        }
        button { cursor: pointer; }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        button:disabled { opacity: .5; cursor: default; }
        .spacer { flex: 1; }
        main { display: grid; grid-template-columns: 220px 1fr 380px; gap: 16px; padding: 16px 20px; height: calc(100vh - 62px); }
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; display: flex; flex-direction: column; min-height: 0; }
        .panel h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin: 0; padding: 12px 14px; border-bottom: 1px solid var(--border); }
        .scroll { overflow-y: auto; flex: 1; }
        .days a { display: block; padding: 9px 14px; border-bottom: 1px solid var(--border); color: inherit; text-decoration: none; }
        .days a:hover { background: color-mix(in srgb, var(--accent) 6%, transparent); }
        .days a.active { background: color-mix(in srgb, var(--accent) 12%, transparent); box-shadow: inset 3px 0 var(--accent); }
        .days .d { font-weight: 600; }
        .days .m { color: var(--muted); font-size: 12px; }
        .badge { display: inline-block; font-size: 10px; padding: 1px 6px; border-radius: 99px; margin-left: 4px; vertical-align: 1px; border: 1px solid currentColor; }
        .badge.live { color: var(--ok); }
        .badge.gap { color: var(--gap); }
        .center { display: flex; flex-direction: column; gap: 16px; min-height: 0; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
        .stat { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 10px 12px; box-shadow: var(--shadow); }
        .stat .v { font-size: 19px; white-space: nowrap; font-weight: 650; font-variant-numeric: tabular-nums; }
        .stat .l { color: var(--muted); font-size: 12px; }
        #map { flex: 1; min-height: 320px; border-radius: 12px; border: 1px solid var(--border); }
        .scrub { display: flex; align-items: center; gap: 10px; background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 8px 12px; }
        .scrub input { flex: 1; }
        .scrub .t { font-variant-numeric: tabular-nums; min-width: 160px; text-align: right; color: var(--muted); font-size: 12px; }
        .timeline .item { display: grid; grid-template-columns: 54px 22px 1fr; gap: 8px; padding: 8px 14px 8px 10px; cursor: pointer; border-bottom: 1px solid var(--border); }
        .timeline .item:hover, .timeline .item.active { background: color-mix(in srgb, var(--accent) 8%, transparent); }
        .timeline .time { color: var(--muted); font-variant-numeric: tabular-nums; font-size: 12px; text-align: right; padding-top: 1px; }
        .dot { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; font-weight: 700; color: #fff; }
        .dot.move { background: var(--move); }
        .dot.stop { background: var(--stop); }
        .dot.gap { background: var(--gap); }
        .timeline .title { font-weight: 600; }
        .timeline .sub { color: var(--muted); font-size: 12px; }
        .empty { padding: 40px 20px; text-align: center; color: var(--muted); }
        .storage { padding: 10px 14px; font-size: 12px; color: var(--muted); border-top: 1px solid var(--border); }
        .stop-icon { background: var(--stop); color: #fff; border: 2px solid #fff; border-radius: 50%; display: grid !important; place-items: center; font: 700 11px system-ui; box-shadow: 0 1px 4px rgba(0,0,0,.4); }
        .end-icon { border-radius: 50%; border: 3px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4); }
        @media (max-width: 1100px) {
            main { grid-template-columns: 1fr; height: auto; }
            .days .scroll { max-height: 220px; }
            #map { height: 420px; }
        }
    </style>
</head>
<body>
<header>
    <h1>Rep Tracker <span>daily coverage</span></h1>
    <select id="rep" aria-label="Sales rep"></select>
    <input type="date" id="date" aria-label="Day">
    <div class="spacer"></div>
    <button id="simulate" title="Generate a realistic shift for this rep and day through the ingestion API">Simulate this day</button>
    <a id="csv" href="#"><button>Export CSV</button></a>
</header>

<main>
    <section class="panel days">
        <h2>Recent days</h2>
        <div class="scroll" id="days"></div>
    </section>

    <section class="center">
        <div class="stats" id="stats"></div>
        <div id="map"></div>
        <div class="scrub">
            <button id="play" aria-label="Play">▶</button>
            <input type="range" id="scrubber" min="0" max="0" value="0" aria-label="Replay position">
            <div class="t" id="scrubTime">–</div>
        </div>
    </section>

    <section class="panel timeline">
        <h2>Timeline</h2>
        <div class="scroll" id="timeline"></div>
        <div class="storage" id="storage"></div>
    </section>
</main>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
const api = '/api/v1';
const $ = (id) => document.getElementById(id);
const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const state = { rep: null, date: null, reps: {}, layers: {}, points: [], timer: null };

const map = L.map('map', { zoomControl: true }).setView([52.37, 4.89], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap contributors',
}).addTo(map);
const trackLayer = L.featureGroup().addTo(map);
const cursor = L.circleMarker([0, 0], { radius: 7, color: '#fff', weight: 3, fillColor: '#111', fillOpacity: 1 });

const fmtDuration = (s) => {
    const h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60);
    return h ? `${h}h ${String(m).padStart(2, '0')}m` : `${m} min`;
};
const fmtKm = (m) => m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${m} m`;
const fmtTime = (iso) => iso ? iso.slice(11, 16) : '–';
const tz = () => state.reps[state.rep]?.timezone || 'Europe/Amsterdam';
const today = () => new Date().toLocaleDateString('sv-SE', { timeZone: tz() });

async function getJson(url, options) {
    const res = await fetch(url, { headers: { Accept: 'application/json' }, ...options });
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || res.statusText);
    return res.json();
}

async function init() {
    const reps = (await getJson(`${api}/reps`)).data;
    reps.forEach(r => state.reps[r.id] = r);
    $('rep').innerHTML = reps.map(r => `<option value="${r.id}">${r.name}</option>`).join('');
    const params = new URLSearchParams(location.search);
    state.rep = params.get('rep') || reps[0]?.id;
    state.date = params.get('date') || today();
    $('rep').value = state.rep;
    $('date').value = state.date;
    $('date').max = today();
    await loadDays();
    await loadDay();
}

async function loadDays() {
    const { data } = await getJson(`${api}/reps/${state.rep}/days?to=${today()}`);
    $('days').innerHTML = data.length ? data.map(d => `
        <a href="#" data-date="${d.date}" class="${d.date === state.date ? 'active' : ''}">
            <div class="d">${new Date(d.date + 'T12:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })}
                ${d.archived ? '' : '<span class="badge live">live</span>'}
                ${d.gap_count ? `<span class="badge gap">${d.gap_count} gap</span>` : ''}</div>
            <div class="m">${fmtKm(d.distance_m)} · ${d.stop_count} stops · ${fmtTime(d.first_at)}–${fmtTime(d.last_at)}</div>
        </a>`).join('') : '<div class="empty">No tracked days yet.</div>';
    $('days').querySelectorAll('a').forEach(a => a.addEventListener('click', (e) => {
        e.preventDefault();
        selectDate(a.dataset.date);
    }));
}

function selectDate(date) {
    state.date = date;
    $('date').value = date;
    $('days').querySelectorAll('a').forEach(a => a.classList.toggle('active', a.dataset.date === date));
    history.replaceState(null, '', `?rep=${state.rep}&date=${date}`);
    loadDay();
}

async function loadDay() {
    stopPlayback();
    const base = `${api}/reps/${state.rep}/days/${state.date}`;
    $('csv').href = `${base}/points?format=csv`;
    const [day, geo, pts] = await Promise.all([getJson(base), getJson(`${base}/track`), getJson(`${base}/points`)]);
    state.points = pts.data;
    renderStats(day);
    renderTimeline(day);
    renderMap(geo);
    setupScrubber();
}

function renderStats(day) {
    const s = day.summary;
    const stats = [
        [fmtKm(s.distance_m), 'walked'],
        [s.first_at ? `${fmtTime(s.first_at)}–${fmtTime(s.last_at)}` : '–', 'shift'],
        [fmtDuration(s.moving_seconds), 'moving'],
        [s.stop_count, 'stops'],
        [s.gap_count ? `${s.gap_count} (${fmtDuration(s.longest_gap_seconds)})` : '0', s.gap_count ? 'gaps (longest)' : 'coverage gaps'],
        [s.point_count.toLocaleString(), 'GPS fixes'],
    ];
    $('stats').innerHTML = stats.map(([v, l]) => `<div class="stat"><div class="v">${v}</div><div class="l">${l}</div></div>`).join('');

    const st = day.storage;
    $('storage').innerHTML = st.archived
        ? `Archived: ${st.archive_points.toLocaleString()} fixes in ${(st.archive_bytes / 1024).toFixed(1)} KB (${(st.archive_bytes / st.archive_points).toFixed(1)} B/fix)${st.hot_points ? ` · still ${st.hot_points.toLocaleString()} raw rows in hot storage` : ' · raw rows pruned'}`
        : (st.hot_points ? `Live: ${st.hot_points.toLocaleString()} raw fixes, archived after the day ends` : '');
}

function renderTimeline(day) {
    if (!day.timeline.length) {
        $('timeline').innerHTML = '<div class="empty">No location data for this day.<br><br>Use “Simulate this day” to generate a shift.</div>';
        return;
    }
    let stopNo = 0;
    $('timeline').innerHTML = day.timeline.map((s, i) => {
        const label = s.type === 'move'
            ? ['→', `Walked ${fmtKm(s.distance_m)}`, `${fmtDuration(s.duration_seconds)}${s.avg_speed_kmh ? ` · ${s.avg_speed_kmh} km/h` : ''}`]
            : s.type === 'stop'
                ? [++stopNo, `Stop ${stopNo}`, `${fmtDuration(s.duration_seconds)} · ${s.point_count} fixes`]
                : ['!', 'No GPS data', `${fmtDuration(s.duration_seconds)} · moved ${fmtKm(s.distance_m)} meanwhile`];
        return `<div class="item" data-index="${i}">
            <div class="time">${fmtTime(s.start)}<br>${fmtTime(s.end)}</div>
            <div class="dot ${s.type}">${label[0]}</div>
            <div><div class="title">${label[1]}</div><div class="sub">${label[2]}</div></div>
        </div>`;
    }).join('');
    $('timeline').querySelectorAll('.item').forEach(el => el.addEventListener('click', () => focusSegment(+el.dataset.index)));
}

function renderMap(geo) {
    trackLayer.clearLayers();
    state.layers = {};
    let stopNo = 0;
    for (const f of geo.features) {
        const p = f.properties;
        let layer;
        if (p.type === 'stop') {
            stopNo++;
            layer = L.marker([f.geometry.coordinates[1], f.geometry.coordinates[0]], {
                icon: L.divIcon({ className: 'stop-icon', html: stopNo, iconSize: [22, 22] }),
            }).bindTooltip(`Stop ${stopNo}: ${fmtTime(p.start)}–${fmtTime(p.end)} (${fmtDuration(p.duration_seconds)})`);
        } else {
            const latlngs = f.geometry.coordinates.map(([lng, lat]) => [lat, lng]);
            layer = p.type === 'gap'
                ? L.polyline(latlngs, { color: css('--gap'), weight: 3, dashArray: '6 8' })
                    .bindTooltip(`No GPS data ${fmtTime(p.start)}–${fmtTime(p.end)}`)
                : L.polyline(latlngs, { color: css('--move'), weight: 4, opacity: .85 })
                    .bindTooltip(`${fmtTime(p.start)}–${fmtTime(p.end)} · ${fmtKm(p.distance_m)}`);
        }
        layer.on('click', () => focusSegment(p.index));
        state.layers[p.index] = layer;
        trackLayer.addLayer(layer);
    }
    const pts = state.points;
    if (pts.length) {
        const endpoint = (pt, color, label) => L.marker([pt.lat, pt.lng], {
            icon: L.divIcon({ className: 'end-icon', html: '', iconSize: [14, 14] }), zIndexOffset: 1000,
        }).bindTooltip(label).on('add', (e) => e.target.getElement().style.background = color);
        trackLayer.addLayer(endpoint(pts[0], css('--ok'), `Start ${fmtTime(pts[0].recorded_at)}`));
        trackLayer.addLayer(endpoint(pts.at(-1), '#111', `End ${fmtTime(pts.at(-1).recorded_at)}`));
        map.fitBounds(trackLayer.getBounds(), { padding: [30, 30] });
    }
}

function focusSegment(index) {
    $('timeline').querySelectorAll('.item').forEach(el => el.classList.toggle('active', +el.dataset.index === index));
    $('timeline').querySelector(`[data-index="${index}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    const layer = state.layers[index];
    if (!layer) return;
    if (layer.getLatLng) {
        map.setView(layer.getLatLng(), Math.max(map.getZoom(), 17));
    } else {
        map.fitBounds(layer.getBounds(), { padding: [60, 60], maxZoom: 18 });
    }
    layer.openTooltip?.();
    Object.values(state.layers).forEach(l => l.setStyle?.({ weight: l === layer ? 7 : 4 }));
}

function setupScrubber() {
    const n = state.points.length;
    $('scrubber').max = Math.max(0, n - 1);
    $('scrubber').value = 0;
    $('play').disabled = $('scrubber').disabled = !n;
    cursor.remove();
    $('scrubTime').textContent = n ? 'Drag to replay the day' : '–';
}

function moveCursor(i) {
    const pt = state.points[i];
    if (!pt) return;
    cursor.setLatLng([pt.lat, pt.lng]).addTo(map);
    const t = new Date(pt.recorded_at).toLocaleTimeString('en-GB', { timeZone: tz() });
    $('scrubTime').textContent = `${t} · ±${pt.accuracy ?? '?'} m · ${pt.battery ?? '?'}% battery`;
}

function stopPlayback() {
    clearInterval(state.timer);
    state.timer = null;
    $('play').textContent = '▶';
}

$('scrubber').addEventListener('input', (e) => moveCursor(+e.target.value));
$('play').addEventListener('click', () => {
    if (state.timer) return stopPlayback();
    $('play').textContent = '❚❚';
    state.timer = setInterval(() => {
        const s = $('scrubber');
        if (+s.value >= +s.max) return stopPlayback();
        s.value = +s.value + 3;
        moveCursor(+s.value);
    }, 30);
});
$('rep').addEventListener('change', async (e) => {
    state.rep = e.target.value;
    history.replaceState(null, '', `?rep=${state.rep}&date=${state.date}`);
    await loadDays();
    await loadDay();
});
$('date').addEventListener('change', (e) => e.target.value && selectDate(e.target.value));
$('simulate').addEventListener('click', async () => {
    const btn = $('simulate');
    btn.disabled = true;
    btn.textContent = 'Simulating…';
    try {
        const r = await getJson(`${api}/reps/${state.rep}/days/${state.date}/simulate`, { method: 'POST' });
        btn.textContent = `+${r.upload.stored} fixes stored`;
        await loadDays();
        await loadDay();
    } catch (e) {
        alert(e.message);
    } finally {
        setTimeout(() => { btn.disabled = false; btn.textContent = 'Simulate this day'; }, 1500);
    }
});

init().catch(e => $('timeline').innerHTML = `<div class="empty">Failed to load: ${e.message}</div>`);
</script>
</body>
</html>
