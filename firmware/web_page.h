// Auto-extracted from the sketch: dashboard markup kept in its own header so the
// Arduino .ino preprocessor does not have to parse a large raw string literal.
#pragma once

static const char INDEX_HTML[] PROGMEM = R"HTML(
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ESP32 BMP280 Logger</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#0e1116;color:#e6edf3;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
header{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid #21262d}
h1{margin:0;font-size:17px;font-weight:600}
#status{font-size:13px;color:#8b949e}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#f85149;margin-right:6px}
.dot.on{background:#3fb950}
.dot.warn{background:#d29922}
main{max-width:1120px;margin:0 auto;padding:16px 20px 48px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.card{background:#161b22;border:1px solid #21262d;border-radius:10px;padding:14px 16px}
.card h2{margin:0 0 8px;font-size:11px;letter-spacing:.9px;text-transform:uppercase;color:#8b949e;font-weight:600}
.big{font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.2}
.unit{font-size:13px;color:#8b949e;font-weight:400}
.meta{font-size:12px;color:#8b949e;margin-top:4px}
.row{display:flex;justify-content:space-between;font-size:14px;padding:2px 0;font-variant-numeric:tabular-nums}
.row span{color:#8b949e}
.charts{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
@media(max-width:700px){.charts{grid-template-columns:1fr}}
canvas{width:100%;height:150px;display:block}
section.settings{margin-top:12px}
label{display:block;font-size:12px;color:#8b949e;margin:10px 0 4px}
input{width:100%;padding:8px 10px;border-radius:7px;border:1px solid #30363d;background:#0d1117;color:#e6edf3;font:14px ui-monospace,monospace}
button{margin-top:14px;margin-right:8px;padding:8px 14px;border-radius:7px;border:1px solid #30363d;background:#21262d;color:#e6edf3;font-weight:600;cursor:pointer}
button.primary{background:#238636;border-color:#2ea043}
button:hover{filter:brightness(1.15)}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:700px){.two{grid-template-columns:1fr}}
table{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}
th,td{text-align:right;padding:5px 6px;border-bottom:1px solid #21262d}
th{color:#8b949e;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.6px}
td:first-child,th:first-child{text-align:left;color:#8b949e}
#toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#21262d;border:1px solid #30363d;padding:10px 16px;border-radius:8px;font-size:13px;opacity:0;pointer-events:none;transition:opacity .2s}
#toast.show{opacity:1}
</style></head><body>
<header><h1>ESP32 BMP280 Logger</h1><div id="status"><span class="dot" id="dot"></span><span id="statustext">connecting…</span></div></header>
<main>
 <div class="grid">
  <div class="card"><h2>Temperature</h2><div class="big" id="temp">—</div><div class="meta" id="tempmeta">—</div></div>
  <div class="card"><h2>Pressure</h2><div class="big" id="press">—</div><div class="meta">hPa (station)</div></div>
  <div class="card"><h2>Sea-level pressure</h2><div class="big" id="sea">—</div><div class="meta" id="seameta">hPa</div></div>
  <div class="card"><h2>Altitude</h2><div class="big" id="alt">—</div><div class="meta">m (1013.25 ref)</div></div>
 </div>

 <div class="charts">
  <div class="card"><h2>Temperature history</h2><canvas id="c1"></canvas></div>
  <div class="card"><h2>Pressure history</h2><canvas id="c2"></canvas></div>
 </div>

 <div class="grid" style="margin-top:12px">
  <div class="card"><h2>Webhook</h2>
   <div class="row"><span>Last HTTP</span><b id="wh_code">—</b></div>
   <div class="row"><span>Delivered</span><b id="wh_ok">0</b></div>
   <div class="row"><span>Failed</span><b id="wh_fail">0</b></div>
   <div class="row"><span>Queued</span><b id="wh_q">0</b></div>
   <div class="row"><span>Last send</span><b id="wh_last">—</b></div>
  </div>
  <div class="card"><h2>Device</h2>
   <div class="row"><span>Sensor</span><b id="dev_sensor">—</b></div>
   <div class="row"><span>Wi-Fi</span><b id="dev_wifi">—</b></div>
   <div class="row"><span>RSSI</span><b id="dev_rssi">—</b></div>
   <div class="row"><span>Uptime</span><b id="dev_up">—</b></div>
   <div class="row"><span>Free heap</span><b id="dev_heap">—</b></div>
  </div>
 </div>

 <div class="card settings"><h2>Settings</h2>
  <form id="cfg" onsubmit="return false">
   <div class="two">
    <div><label>Device name</label><input name="device_name" id="f_name" placeholder="esp32-bmp280"></div>
    <div><label>Reporting interval (seconds)</label><input name="interval_s" id="f_interval" type="number" min="2" max="3600"></div>
    <div><label>Wi-Fi SSID</label><input name="wifi_ssid" id="f_ssid" autocomplete="off"></div>
    <div><label>Wi-Fi password</label><input name="wifi_pass" id="f_pass" type="password" autocomplete="off" placeholder="(unchanged)"></div>
    <div><label>Webhook URL</label><input name="webhook_url" id="f_url" placeholder="http://192.168.1.10:8080/hook"></div>
    <div><label>Bearer token (optional)</label><input name="webhook_token" id="f_token" type="password" placeholder="(unchanged)"></div>
    <div><label>Reference altitude for sea-level pressure (m)</label><input name="altitude_m" id="f_alt" type="number" step="0.1" min="-500" max="9000"></div>
   </div>
   <button class="primary" onclick="saveCfg()">Save</button>
   <button onclick="testHook()">Send test</button>
   <button onclick="reboot()">Reboot</button>
  </form>
  <div class="meta" id="cfgmsg"></div>
 </div>

 <div class="card" style="margin-top:12px"><h2>Recent samples</h2>
  <table><thead><tr><th>Time</th><th>Temp °C</th><th>Pressure hPa</th></tr></thead><tbody id="tbody"></tbody></table>
 </div>
</main>
<div id="toast"></div>
<script>
const $=id=>document.getElementById(id);
let toastTimer;
function toast(msg){const t=$('toast');t.textContent=msg;t.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.classList.remove('show'),2600);}
function fmt(v,d,unit){return (v===null||v===undefined)?'—':v.toFixed(d)+(unit||'');}
function fmtUp(s){const h=Math.floor(s/3600),m=Math.floor(s%3600/60);return h?`${h}h ${m}m`:`${m}m ${s%60}s`;}
function draw(id,arr,unit){
  const c=$(id); if(!c) return;
  const w=c.clientWidth||300, h=c.clientHeight||150, dpr=window.devicePixelRatio||1;
  c.width=w*dpr; c.height=h*dpr;
  const g=c.getContext('2d'); g.setTransform(dpr,0,0,dpr,0,0); g.clearRect(0,0,w,h);
  if(arr.length<2){g.fillStyle='#8b949e';g.font='12px system-ui';g.fillText('collecting…',10,h/2);return;}
  let min=Math.min.apply(null,arr), max=Math.max.apply(null,arr);
  if(max-min<0.6){const m=(max+min)/2;min=m-0.3;max=m+0.3;}
  const pad=8, x=i=>pad+(w-2*pad)*i/(arr.length-1), y=v=>h-pad-(h-2*pad)*(v-min)/(max-min);
  g.strokeStyle='rgba(47,129,247,.18)';g.lineWidth=1;
  for(let k=0;k<=3;k++){const yy=pad+(h-2*pad)*k/3;g.beginPath();g.moveTo(pad,yy);g.lineTo(w-pad,yy);g.stroke();}
  const grd=g.createLinearGradient(0,0,0,h);grd.addColorStop(0,'rgba(47,129,247,.35)');grd.addColorStop(1,'rgba(47,129,247,0)');
  g.beginPath();g.moveTo(x(0),y(arr[0]));
  for(let i=1;i<arr.length;i++)g.lineTo(x(i),y(arr[i]));
  g.lineTo(x(arr.length-1),h-pad);g.lineTo(x(0),h-pad);g.closePath();g.fillStyle=grd;g.fill();
  g.beginPath();g.moveTo(x(0),y(arr[0]));
  for(let i=1;i<arr.length;i++)g.lineTo(x(i),y(arr[i]));
  g.strokeStyle='#2f81f7';g.lineWidth=1.8;g.stroke();
  g.fillStyle='#8b949e';g.font='10px ui-monospace,monospace';
  g.fillText(max.toFixed(1),4,10);g.fillText(min.toFixed(1),4,h-3);
  g.fillStyle='#e6edf3';g.fillText(arr[arr.length-1].toFixed(2)+' '+unit,w-72,10);
}
async function tick(){
  try{
    const d=await (await fetch('/data',{cache:'no-store'})).json();
    const on=d.wifi.connected;
    $('dot').className='dot'+(on?' on':(d.wifi.ap?' warn':''));
    $('statustext').textContent=on?('online · '+d.wifi.ip+' · '+d.wifi.rssi+' dBm'):(d.wifi.ap?('setup AP · '+d.wifi.ip):'no network');
    const L=d.last||{};
    $('temp').innerHTML=fmt(L.temp_c,2)+'<span class="unit"> °C</span>';
    $('tempmeta').textContent=L.ts?('sample '+(L.seq||'')):'waiting for sensor';
    $('press').innerHTML=fmt(L.press_hpa,2)+'<span class="unit"> hPa</span>';
    $('sea').innerHTML=fmt(L.sea_level_hpa,2)+'<span class="unit"> hPa</span>';
    $('seameta').textContent='ref altitude '+d.altitude_ref_m+' m';
    $('alt').innerHTML=fmt(L.altitude_m,1)+'<span class="unit"> m</span>';
    $('dev_sensor').textContent=d.sensor.ok?(d.sensor.chip+' @ '+d.sensor.addr):'not detected';
    $('dev_wifi').textContent=on?'connected':(d.wifi.ap?'AP mode':'offline');
    $('dev_rssi').textContent=on?(d.wifi.rssi+' dBm'):'—';
    $('dev_up').textContent=fmtUp(d.uptime_s);
    $('dev_heap').textContent=(d.heap/1024).toFixed(0)+' kB';
    $('wh_code').textContent=d.webhook.last_code||'—';
    $('wh_ok').textContent=d.webhook.sent_ok;
    $('wh_fail').textContent=d.webhook.sent_fail;
    $('wh_q').textContent=d.webhook.queued;
    $('wh_last').textContent=d.webhook.last_send_s?d.webhook.last_send_s+' s ago':'—';
  }catch(e){
    $('dot').className='dot';$('statustext').textContent='connection lost';
  }
}
async function hist(){
  try{
    const d=await (await fetch('/history',{cache:'no-store'})).json();
    const t=d.samples.map(s=>s.temp_c), p=d.samples.map(s=>s.press_hpa);
    draw('c1',t,'°C'); draw('c2',p,'hPa');
    const rows=d.samples.slice(-8).reverse().map(s=>{
      const ts=s.ts?s.ts.slice(11,19):(s.uptime_s+'s');
      return `<tr><td>${ts}</td><td>${s.temp_c.toFixed(2)}</td><td>${s.press_hpa.toFixed(2)}</td></tr>`;
    }).join('');
    $('tbody').innerHTML=rows||'<tr><td colspan="3">no samples yet</td></tr>';
  }catch(e){}
}
async function loadCfg(){
  try{
    const d=await (await fetch('/settings',{cache:'no-store'})).json();
    $('f_name').value=d.device_name; $('f_interval').value=d.interval_s;
    $('f_ssid').value=d.wifi_ssid; $('f_url').value=d.webhook_url;
    $('f_alt').value=d.altitude_m;
    $('f_pass').placeholder=d.wifi_pass_set?'(unchanged)':'(not set)';
    $('f_token').placeholder=d.webhook_token_set?'(unchanged)':'(not set)';
  }catch(e){}
}
async function saveCfg(){
  const body=new URLSearchParams(new FormData($('cfg')));
  try{
    const r=await fetch('/settings',{method:'POST',body});
    const d=await r.json();
    $('cfgmsg').textContent=d.ok?'Saved and applied.':('Error: '+(d.error||'unknown'));
    toast(d.ok?'Settings saved':'Save failed: '+(d.error||''));
    if(d.ok){$('f_pass').value='';$('f_token').value='';loadCfg();}
  }catch(e){toast('Save failed');}
}
async function testHook(){
  try{
    const d=await (await fetch('/test',{method:'POST'})).json();
    toast(d.ok?('Webhook OK (HTTP '+d.code+')'):('Webhook failed ('+(d.error||d.code)+')'));
  }catch(e){toast('Test failed');}
}
async function reboot(){
  if(!confirm('Reboot the ESP32?'))return;
  await fetch('/reboot',{method:'POST'}); toast('Rebooting…');
}
tick();hist();loadCfg();
setInterval(tick,2000);setInterval(hist,10000);
window.addEventListener('resize',()=>hist());
</script></body></html>
)HTML";

