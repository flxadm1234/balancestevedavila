<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_login();
require_company_selected();

require __DIR__ . '/../app/layout/header.php';
require __DIR__ . '/../app/layout/sidebar.php';
?>
<section class="content pt-3">
  <div class="container-fluid">
    
    <!-- Filtros Globales -->
    <div class="row mb-3">
      <div class="col-12 d-flex justify-content-end align-items-center" style="gap:10px;">
        <label class="mb-0 text-muted mr-1">Filtros:</label>
        <select id="global_category" class="form-control form-control-sm" style="width:160px;">
          <option value="business">Negocio</option>
          <option value="personal">Personal</option>
          <option value="loan">Préstamo</option>
          <option value="third_party">Terceros</option>
          <option value="various">Pagos varios</option>
          <option value="capital">Capital</option>
          <option value="workers">Pago Trabajadores</option>
          <option value="all" selected>Todas</option>
        </select>
        <div class="input-group input-group-sm" style="width: 240px;">
          <div class="input-group-prepend">
            <span class="input-group-text"><i class="far fa-calendar-alt"></i></span>
          </div>
          <input type="text" class="form-control float-right" id="global_range">
        </div>
      </div>
    </div>

    <div class="row" id="cardsRow">
      <!-- cards por JS -->
    </div>

    <div class="row">
      <div class="col-md-8">
        <div class="card">
          <div class="card-header border-0">
            <h3 class="card-title">Ingresos vs Gastos</h3>
          </div>
          <div class="card-body">
            <canvas id="chart"></canvas>
          </div>
        </div>
      </div>
      <div class="col-md-4">
      </div>
      <div class="col-md-8">

        <div class="row">
          <div class="col-lg-12">
            <div class="card">
              <div class="card-header border-0">
                <h3 class="card-title">Efectivo &amp; Billetera Digital (Diario)</h3>
              </div>

              <div class="card-body">
                <canvas id="cdChart" height="130"></canvas>
              </div>
            </div>
          </div>
        </div>

      </div>
      <div class="col-md-4">
      </div>
    </div>

  </div>
</section>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/moment@2.30.1/min/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker@3.1/daterangepicker.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker@3.1/daterangepicker.css">

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
  $(function(){

  // ====== 1) Dashboard (Ingresos vs Gastos) ======
  const ranges = {
    'Hoy': [moment(), moment()],
    'Ayer': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
    'Últimos 7 días': [moment().subtract(6, 'days'), moment()],
    'Últimos 30 días': [moment().subtract(29, 'days'), moment()],
    'Este mes': [moment().startOf('month'), moment().endOf('month')],
    'Mes pasado': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
    'Todo este año': [moment().startOf('year'), moment().endOf('year')]
  };

  let start = moment().startOf('month');
  const end   = moment().endOf('month');
  
  if (moment().date() <= 5) {
      start = moment().subtract(1, 'month').startOf('month');
  }

  function loadDashboard(s, e){
    const cat = $('#global_category').val(); // Filtro global
    $.getJSON('/api/dashboard.php', {
      start: s.format('YYYY-MM-DD'),
      end:   e.format('YYYY-MM-DD'),
      category: cat,
      _: new Date().getTime()
    }, function(res){
      // console.log('Dashboard API response:', res); 

      const balance_cash = res.totals.balance_cash || 'S/ 0.00';
      const balance_digital = res.totals.balance_digital || 'S/ 0.00';

      const cards = [
        {title:'Ingresos totales', value: res.totals.income_total, icon:'fa-arrow-up', color:'success'},
        {title:'Gastos totales', value: res.totals.expense_total, icon:'fa-arrow-down', color:'danger'},
        {
            title:'Balance (saldo)', 
            value: res.totals.balance, 
            icon:'fa-wallet', 
            color:'info'
            // Ya no mostramos desglose aquí, se movió a la tarjeta 4
        },
        {
            title:'Efectivo vs Digital', 
            value: '', 
            icon:'fa-coins', 
            color:'warning',
            customHtml: `
              <div style="display:flex; flex-direction:column; gap:4px; margin-top: -5px;">
                <div style="font-size: 1.1rem; font-weight:bold;">
                  <i class="fas fa-money-bill-wave" style="opacity:0.6; margin-right:4px;"></i> ${balance_cash}
                </div>
                <div style="font-size: 1.1rem; font-weight:bold;">
                  <i class="fas fa-mobile-alt" style="opacity:0.6; margin-right:8px;"></i> ${balance_digital}
                </div>
              </div>
            `
        }
      ];

      $('#cardsRow').html(cards.map(c => `
        <div class="col-lg-3 col-6">
          <div class="small-box bg-${c.color}">
            <div class="inner">
              ${c.customHtml ? c.customHtml : `<h3>${c.value}</h3>`}
              <p>${c.title}</p>
              ${c.sub ? c.sub : ''}
            </div>
            <div class="icon"><i class="fas ${c.icon}"></i></div>
          </div>
        </div>
      `).join(''));

      const labels = res.series.labels;
      const income = res.series.income;
      const expense = res.series.expense;

      if (window._chart) window._chart.destroy();
      const ctx = document.getElementById('chart');
      window._chart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels,
          datasets: [
            {label:'Ingresos', data: income},
            {label:'Gastos', data: expense},
          ]
        },
        options: {responsive:true}
      });
    });
  }

  $('#global_range').daterangepicker({
    startDate: start, endDate: end,
    ranges: ranges,
    locale: { 
        format: 'DD/MM/YYYY', 
        applyLabel:'Aplicar', 
        cancelLabel:'Cancelar',
        customRangeLabel: 'Rango personalizado'
    }
  }, function(s, e){
      // Callback al cambiar fecha
      loadDashboard(s, e);
      loadCashDigital(); 
  });

  // Carga inicial
  loadDashboard(start, end);

  // Cambio de categoría
  $('#global_category').on('change', function(){
    const drp = $('#global_range').data('daterangepicker');
    loadDashboard(drp.startDate, drp.endDate);
    loadCashDigital();
  });

  // ====== 2) Efectivo & Billetera Digital ======
  const fmtMoney = (n)=> (Number(n)||0).toLocaleString('es-PE',{minimumFractionDigits:2, maximumFractionDigits:2});
  const fmtPct = (n)=> (Number(n)||0).toFixed(1);

  // Ya no usamos cd_range local, usamos el global
  // $('#cd_range').daterangepicker(...) -> Eliminado

  let cdChart;

  async function loadCashDigital(){
    const drp = $('#global_range').data('daterangepicker'); // Usar filtro global
    const cat = $('#global_category').val();                // Usar filtro global

    const qs = new URLSearchParams({
      start: drp.startDate.format('YYYY-MM-DD'),
      end:   drp.endDate.format('YYYY-MM-DD'),
      category: cat
    });

    const res = await fetch('/api/cash_digital.php?' + qs.toString(), { credentials:'same-origin' });
    const data = await res.json();

    if(!data.ok){
      console.error('cash_digital error:', data);
      return;
    }

    // Ya no actualizamos totales #cd_cash_total, etc. porque se eliminaron del DOM.
    // Solo actualizamos el gráfico.

    const labels = data.labels.map(d => moment(d, 'YYYY-MM-DD').format('DD/MM'));
    const cash = data.cash;
    const digital = data.digital;

    const ctx = document.getElementById('cdChart');
    if(cdChart) cdChart.destroy();

    cdChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { 
            label: 'Efectivo', 
            data: cash, 
            borderRadius: 4,
            backgroundColor: 'rgba(40, 167, 69, 0.7)', // Verde success
            borderColor: 'rgba(40, 167, 69, 1)',
            borderWidth: 1
          },
          { 
            label: 'Billetera Digital', 
            data: digital, 
            borderRadius: 4,
            backgroundColor: 'rgba(23, 162, 184, 0.7)', // Azul info
            borderColor: 'rgba(23, 162, 184, 1)',
            borderWidth: 1
          },
        ]
      },
      options: {
        responsive: true,
        // Eliminamos "mode: 'index'" para que no se superpongan los tooltips y se comporten como barras normales lado a lado
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        plugins: {
          tooltip: {
            callbacks: {
              label: function(ctx){
                return `${ctx.dataset.label}: S/. ${fmtMoney(ctx.raw || 0)}`;
              }
            }
          },
          legend: { display: true }
        },
        scales: {
          x: {
            // Asegura que las barras tengan un ancho máximo para que no se vean gigantes cuando hay pocos datos
            maxBarThickness: 50, 
            grid: { display: false } // Limpiar un poco el gráfico
          },
          y: { 
            beginAtZero: true,
            ticks: { callback: (v)=> 'S/. ' + v } 
          }
        }
      }
    });
  }
  
  // Carga inicial del gráfico
  loadCashDigital();

});


</script>

<?php require __DIR__ . '/../app/layout/footer.php'; ?>
