<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/auth.php';
require_login();
require_company_selected();

require __DIR__ . '/../app/layout/header.php';
require __DIR__ . '/../app/layout/sidebar.php';
?>

<section class="content pt-3">
  <div class="container-fluid">

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Ingresos / Gastos</h3>
        <div class="card-tools">
          <input id="range" class="form-control form-control-sm bs-filter bs-filter-range" placeholder="Rango de fechas">
          <select id="kind" class="form-control form-control-sm bs-filter">
            <option value="">Tipo: Todos</option>
            <option value="income">Ingresos</option>
            <option value="expense">Gastos</option>
          </select>
          <select id="category_filter" class="form-control form-control-sm bs-filter">
            <option value="">Cat: Todas</option>
            <option value="business">Empresa</option>
            <option value="personal">Personal</option>
            <option value="loan">Préstamo</option>
            <option value="third_party">Terceros</option>
            <option value="various">Varios</option>
            <option value="capital">Capital</option>
            <option value="workers">Pago Trabajadores</option>
          </select>
          <select id="pm_filter" class="form-control form-control-sm bs-filter">
            <option value="">Medio: Todos</option>
            <option value="cash">Efectivo</option>
            <option value="digital">Digital (Todos)</option>
            <option value="yape"> - Yape</option>
            <option value="plin"> - Plin</option>
            <option value="transfer"> - Transferencia</option>
            <option value="other"> - Otros</option>
          </select>
          <button id="btnExport" class="btn btn-sm btn-success" title="Descargar Excel/CSV">
            <i class="fas fa-file-download"></i>
          </button>
          <button id="btnAdd" class="btn btn-sm btn-primary">
            <i class="fas fa-plus"></i> Agregar
          </button>
        </div>
      </div>

      <div class="card-body">
        <div class="table-responsive">
          <table id="tx" class="table table-bordered table-striped">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Categoría</th>
                <th>Descripción</th>
                <th>Factura</th>
                <th>RUC</th>
                <th>Monto</th>
                <th>Medio</th>
                <th>Registro</th>
                <th>Usuario (Telegram)</th>
                <th>Acción</th>
              </tr>
            </thead>
          </table>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- Modal agregar -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formAdd">
      <div class="modal-header">
        <h5 class="modal-title">Nuevo movimiento</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="entryId" value="0">
        <div class="form-group">
          <label>Tipo</label>
          <select name="kind" class="form-control" required>
            <option value="income">Ingreso</option>
            <option value="expense" selected>Egreso</option>
          </select>
        </div>

        <div class="form-group">
          <label>Categoría</label>
          <select name="category" class="form-control">
            <option value="business" selected>Empresa</option>
            <option value="personal">Personal</option>
            <option value="loan">Préstamo</option>
            <option value="third_party">Terceros</option>
            <option value="various">Pagos varios</option>
            <option value="capital">Capital (Inyección)</option>
            <option value="workers">Pago Trabajadores</option>
          </select>
        </div>

        <div class="form-group">
          <label>Descripción</label>
          <input name="description" class="form-control" required>
        </div>

        <div class="form-group">
          <label>N° Factura</label>
          <input name="invoice_number" class="form-control" placeholder="Ej: F001-00001234">
        </div>

        <div class="form-group">
          <label>RUC Empresa</label>
          <input name="company_ruc" class="form-control" placeholder="11 dígitos">
        </div>

        <div class="form-group">
          <label>Monto (S/)</label>
          <input name="price" type="number" step="0.01" class="form-control" required>
        </div>

        <div class="form-group">
          <label>Medio de pago</label>
          <select name="payment_method" class="form-control">
            <option value="cash" selected>Efectivo</option>
            <option value="yape">Yape</option>
            <option value="plin">Plin</option>
            <option value="transfer">Transferencia</option>
            <option value="other">Otros</option>
          </select>
        </div>

        <div class="form-group">
          <label>Fecha y hora</label>
          <input type="datetime-local" name="item_datetime" class="form-control" required>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-dismiss="modal" type="button">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>
<script>
$(function(){
  // Configuración de rangos para facilitar la búsqueda
  const ranges = {
    'Hoy': [moment(), moment()],
    'Ayer': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
    'Últimos 7 días': [moment().subtract(6, 'days'), moment()],
    'Últimos 30 días': [moment().subtract(29, 'days'), moment()],
    'Este mes': [moment().startOf('month'), moment().endOf('month')],
    'Mes pasado': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
    'Todo este año': [moment().startOf('year'), moment().endOf('year')]
  };

  // Por defecto mostramos "Este mes". Si estamos en los primeros 5 días, mostramos también el mes pasado.
  let start = moment().startOf('month');
  const end   = moment().endOf('month');
  
  if (moment().date() <= 5) {
      start = moment().subtract(1, 'month').startOf('month');
  }

  $('#range').daterangepicker({
    startDate: start, endDate: end,
    ranges: ranges,
    locale: { 
      format: 'DD/MM/YYYY', 
      applyLabel:'Aplicar', 
      cancelLabel:'Cancelar',
      customRangeLabel: 'Rango personalizado'
    }
  });

  const table = $('#tx').DataTable({
    scrollX: true,
    autoWidth: false,
    ajax: {
      url: '/api/transactions.php',
      data: function(d){
        const drp = $('#range').data('daterangepicker');
        d.start = drp.startDate.format('YYYY-MM-DD');
        d.end   = drp.endDate.format('YYYY-MM-DD');
        d.kind  = $('#kind').val();
        d.category = $('#category_filter').val();
        d.payment_method = $('#pm_filter').val();
      }
    },
    columnDefs: [
      { targets: 3, className: 'text-wrap', render: function(data){ return $('<div>').text(data || '').html(); } },
      { targets: [0,1,2,4,5,6,7,8,9,10], className: 'text-nowrap' }
    ],
    columns: [
      { data: 'item_datetime' },
      { data: 'kind_label' },
      { data: 'category_label' },
      { data: 'description' },
      { data: 'invoice_number' },
      { data: 'company_ruc' },
      { data: 'price_label' },
      { data: 'payment_method_label' },
      { data: 'batch_label' },
      { data: 'user_label' },
      { data: 'actions', orderable:false, searchable:false },
    ]
  });

  $('#kind').on('change', ()=> table.ajax.reload());
  $('#category_filter').on('change', ()=> table.ajax.reload());
  $('#pm_filter').on('change', ()=> table.ajax.reload());
  $('#range').on('apply.daterangepicker', ()=> table.ajax.reload());

  $('#btnExport').on('click', function(){
    const drp = $('#range').data('daterangepicker');
    const start = drp.startDate.format('YYYY-MM-DD');
    const end   = drp.endDate.format('YYYY-MM-DD');
    const kind  = $('#kind').val();
    const cat   = $('#category_filter').val();
    const pm    = $('#pm_filter').val();
    
    const qs = new URLSearchParams({
      start, end, kind, category: cat, payment_method: pm, export: 'csv'
    });
    window.location.href = '/api/transactions.php?' + qs.toString();
  });

  $('#formAdd').on('submit', function(e){
    e.preventDefault();
    $.post('/api/transactions.php', $(this).serialize(), function(res){
      if(res.ok){
        $('#modalAdd').modal('hide');
        $('#formAdd')[0].reset();
        table.ajax.reload();
      } else {
        alert(res.error || 'Error');
      }
    }, 'json');
  });

  // reset form on modal open (only when opening via the Add button)
  // MODIFICADO: Ahora manejamos todo con clicks explícitos para evitar conflictos con el modal
  $('#btnAdd').on('click', function(){
      $('#formAdd')[0].reset();
      $('#formAdd [name=id]').val(0);
      $('.modal-title').text('Nuevo movimiento');
      
      const now = moment().format('YYYY-MM-DDTHH:mm');
      $('[name=item_datetime]').val(now);

      $('#modalAdd').modal('show');
  });

  // edit
  $('#tx').on('click', '.btn-edit', function(){
    const btn = $(this);
    const id = btn.data('id');
    const kind = btn.data('kind');
    const cat = btn.data('category');
    const desc = btn.data('description');
    const inv = btn.data('invoice-number');
    const ruc = btn.data('company-ruc');
    const price = btn.data('price');
    const pm = btn.data('payment-method');
    const dt = btn.data('datetime'); // YYYY-MM-DD HH:MM

    const form = $('#formAdd');
    form.find('[name=id]').val(id);
    form.find('[name=kind]').val(kind);
    form.find('[name=category]').val(cat);
    form.find('[name=description]').val(desc);
    form.find('[name=invoice_number]').val(inv || '');
    form.find('[name=company_ruc]').val(ruc || '');
    form.find('[name=price]').val(price);
    form.find('[name=payment_method]').val(pm);
    
    // Format date for datetime-local input: replace space with T
    const dtLocal = dt.replace(' ', 'T');
    form.find('[name=item_datetime]').val(dtLocal);

    $('.modal-title').text('Editar movimiento');
    $('#modalAdd').modal('show');
  });

  // delete
  $('#tx').on('click', '.btn-del', function(){
    if(!confirm('¿Eliminar este item?')) return;
    $.ajax({
      url:'/api/transactions.php',
      method:'DELETE',
      data: { id: $(this).data('id') },
      dataType:'json',
      success: function(res){
        if(res.ok) table.ajax.reload();
        else alert(res.error || 'Error');
      }
    });
  });
});
</script>

<?php require __DIR__ . '/../app/layout/footer.php'; ?>
