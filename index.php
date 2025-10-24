<?php // index.php ?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Canlı İnternet Takip (10 dk grafik)</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  body { font-family: system-ui, Arial; margin: 25px; background: #fafafa; color: #222; }
  .card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); max-width: 1000px; margin:auto; }
  .status { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
  .dot { width:14px; height:14px; border-radius:50%; }
  .green{ background:#16a34a;}
  .red{ background:#ef4444;}
  .stats {margin:10px 0; font-size:15px; color:#333;}
  .hosts{margin-top:10px;font-size:14px;color:#444;}
  canvas{width:100%!important; height:380px!important;}
  small{color:#555;}
  table { border-collapse: collapse; width:100%; margin-top:20px; font-size:14px;}
  th, td { border:1px solid #ddd; padding:6px 10px; text-align:center;}
  th { background:#f3f4f6; }
  tr:nth-child(even){ background:#fafafa; }
</style>
</head>
<body>
<div class="card">
  <h2>Keydata Konya Canlı İnternet Durumu (2 saniyede bir test)</h2>
  <div class="status">
    <div id="dot" class="dot red"></div>
    <div id="label">Durum: bilinmiyor</div>
  </div>

  <div class="stats">
    <div>Kesinti süresi: <span id="downtime">00:00</span></div>
    <div>Son 10 dakikada kesinti sayısı: <span id="cutCount">0</span></div>
  </div>

  <canvas id="chart"></canvas>
  <p><small>Son 10 dakikanın ping değerleri (ms). Kırmızı: kesinti anı, Mavi: aktif bağlantı.</small></p>

  <div class="hosts">
    Test edilen sunucular: <span id="hostsList">yükleniyor...</span>
  </div>

  <h3 style="margin-top:25px;">Kesinti Kayıtları (Log)</h3>
  <table id="logTable">
    <thead>
      <tr><th>#</th><th>Başlama Zamanı</th><th>Bitiş Zamanı</th><th>Süre (sn)</th></tr>
    </thead>
    <tbody></tbody>
  </table>
</div>

<!-- Sesler -->
<audio id="pingSound" preload="auto">
  <source src="https://actions.google.com/sounds/v1/cartoon/wood_plank_flicks.ogg" type="audio/ogg">
</audio>
<audio id="alertSound" preload="auto">
  <source src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg" type="audio/ogg">
</audio>

<script>
const dot = document.getElementById('dot');
const label = document.getElementById('label');
const downtime = document.getElementById('downtime');
const cutCountEl = document.getElementById('cutCount');
const hostsList = document.getElementById('hostsList');
const pingSound = document.getElementById('pingSound');
const alertSound = document.getElementById('alertSound');
const logTableBody = document.querySelector('#logTable tbody');

const ctx = document.getElementById('chart').getContext('2d');
const MAX_POINTS = 300; // 10 dakika (2sn aralık)
let lastInternetStatus = null;
let cutCount = 0;
let downtimeSeconds = 0;
let downtimeTimer = null;
let outageStartTime = null;
let outageLogs = [];

const chart = new Chart(ctx, {
  type: 'line',
  data: {
    labels: [],
    datasets: [{
      label: 'Ping (ms)',
      data: [],
      borderWidth: 2,
      fill: false,
      tension: 0.25,
      pointRadius: 3,
      pointBackgroundColor: [],
      borderColor: ctx => {
        const dataset = ctx.chart.data.datasets[0];
        const idx = ctx.p0DataIndex;
        return dataset.pointBackgroundColor[idx] || '#3b82f6';
      },
      segment: {
        borderColor: ctx => {
          const dataset = ctx.chart.data.datasets[0];
          const idx = ctx.p0DataIndex;
          return dataset.pointBackgroundColor[idx] || '#3b82f6';
        }
      }
    }]
  },
  options: {
    animation: false,
    scales: {
      x: { title: {display:true, text:'Zaman'}, ticks:{maxTicksLimit:10}},
      y: { title: {display:true, text:'ms'}, suggestedMin:0, suggestedMax:500 }
    },
    plugins:{ legend:{display:false}}
  }
});

function formatTime(sec){
  const m = Math.floor(sec/60).toString().padStart(2,'0');
  const s = (sec%60).toString().padStart(2,'0');
  return `${m}:${s}`;
}

function startDowntimeCounter(){
  if(downtimeTimer) return;
  downtimeTimer = setInterval(()=>{
    downtimeSeconds++;
    downtime.textContent = formatTime(downtimeSeconds);
  },1000);
}

function stopDowntimeCounter(){
  if(downtimeTimer){
    clearInterval(downtimeTimer);
    downtimeTimer = null;
  }
}

function addLog(start, end, duration){
  const tr = document.createElement('tr');
  tr.innerHTML = `<td>${outageLogs.length}</td>
                  <td>${start}</td>
                  <td>${end}</td>
                  <td>${duration}</td>`;
  logTableBody.appendChild(tr);
}

async function checkInternet(){
  try{
    const resp = await fetch('check.php?ts=' + Date.now(), {cache:'no-store'});
    const data = await resp.json();

    if(data.hosts) hostsList.textContent = data.hosts.join(', ');

    const t = new Date();
    const timeLabel = t.toLocaleTimeString();
    const internetUp = data.internet;
    const avgMs = data.avg_ms ? data.avg_ms.toFixed(0) : 0;

    // Grafik veri ekle
    chart.data.labels.push(timeLabel);
    chart.data.datasets[0].data.push(avgMs);
    chart.data.datasets[0].pointBackgroundColor.push(internetUp ? '#3b82f6' : '#ef4444');
    if(chart.data.labels.length > MAX_POINTS){
      chart.data.labels.shift();
      chart.data.datasets[0].data.shift();
      chart.data.datasets[0].pointBackgroundColor.shift();
    }
    chart.update();

    // Durum göstergesi
    if(internetUp){
      dot.className = 'dot green';
      label.textContent = `İnternet: VAR (${avgMs} ms)`;

      // Eğer önceden kesintideyse, logu kapat
      if(lastInternetStatus === false && outageStartTime){
        const endTime = new Date();
        const duration = Math.round((endTime - outageStartTime) / 1000);
        addLog(outageStartTime.toLocaleTimeString(), endTime.toLocaleTimeString(), duration);
        outageStartTime = null;
      }

      stopDowntimeCounter();
      downtimeSeconds = 0;
      downtime.textContent = '00:00';
    } else {
      dot.className = 'dot red';
      label.textContent = 'İnternet: YOK';

      // Eğer yeni kesinti başladıysa log başlat
      if(lastInternetStatus !== false){
        cutCount++;
        cutCountEl.textContent = cutCount;
        outageStartTime = new Date();
      }
      startDowntimeCounter();
    }

    // Sesler
    if(lastInternetStatus !== null && lastInternetStatus !== internetUp){
      if(internetUp){
        pingSound.currentTime = 0; pingSound.play().catch(()=>{});
      } else {
        alertSound.currentTime = 0; alertSound.play().catch(()=>{});
      }
    }

    lastInternetStatus = internetUp;
  } catch(e){
    // check.php erişilemiyor
    const t = new Date();
    const timeLabel = t.toLocaleTimeString();
    chart.data.labels.push(timeLabel);
    chart.data.datasets[0].data.push(0);
    chart.data.datasets[0].pointBackgroundColor.push('#ef4444');
    if(chart.data.labels.length > MAX_POINTS){
      chart.data.labels.shift();
      chart.data.datasets[0].data.shift();
      chart.data.datasets[0].pointBackgroundColor.shift();
    }
    chart.update();

    dot.className = 'dot red';
    label.textContent = 'Sunucuya erişilemiyor';

    if(lastInternetStatus !== false){
      cutCount++;
      cutCountEl.textContent = cutCount;
      outageStartTime = new Date();
    }
    startDowntimeCounter();
    lastInternetStatus = false;
  }
}

checkInternet();
setInterval(checkInternet, 2000);
</script>
</body>


<br></br>
<center> Yapan Eden : <a href="https://mehmetbagcivan.com/" target="_self"> Mehmet BAĞCIVAN </a> </center>
</html>
