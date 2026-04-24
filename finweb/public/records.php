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
        <h3 class="card-title">Registros (Resumen)</h3>
        <div class="card-tools d-flex" style="gap:8px;">
          <input id="range" class="form-control form-control-sm" style="width:260px;">
          <select id="kind" class="form-control form-control-sm" style="width:120px;">
            <option value="">Tipo: Todos</option>
            <option value="income">Ingresos</option>
            <option value="expense">Gastos</option>
          </select>
          <button id="btnExport" class="btn btn-sm btn-success" title="Descargar Excel/CSV">
            <i class="fas fa-file-download"></i>
          </button>
        </div>
      </div>
      <div class="card-body">
        <table id="records" class="table table-bordered table-striped">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Tipo</th>
              <th>Descripción</th>
              <th>Factura</th>
              <th>RUC</th>
              <th>Monto</th>
              <th>Voucher/Audio</th>
              <th>Ver</th>
            </tr>
          </thead>
        </table>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../app/layout/footer.php'; ?>

<script>
  const startDefault = moment().startOf('month');
  const endDefault = moment().endOf('month');
  let start = startDefault.format('YYYY-MM-DD');
  let end = endDefault.format('YYYY-MM-DD');

  function setRangeLabel(s, e) {
    $('#range').val(`${s.format('YYYY-MM-DD')} - ${e.format('YYYY-MM-DD')}`);
  }

  $(function() {
    $('#range').daterangepicker({
      startDate: startDefault,
      endDate: endDefault,
      locale: { format: 'YYYY-MM-DD' }
    }, function(s, e) {
      start = s.format('YYYY-MM-DD');
      end = e.format('YYYY-MM-DD');
      setRangeLabel(s, e);
      table.ajax.reload();
    });
    setRangeLabel(startDefault, endDefault);

    const table = $('#records').DataTable({
      ajax: {
        url: '/api/records.php',
        data: function(d) {
          d.start = start;
          d.end = end;
          d.kind = $('#kind').val();
        }
      },
      columns: [
        { data: 'confirmed_at' },
        { data: 'kind_label' },
        { data: 'friendly_name' },
        { data: 'invoice_number' },
        { data: 'company_ruc' },
        { data: 'total_label' },
        { data: 'media' },
        { data: 'view' }
      ],
      order: [[0, 'desc']]
    });

    $('#kind').on('change', () => table.ajax.reload());

    $('#btnExport').on('click', function() {
      const kind = encodeURIComponent($('#kind').val() || '');
      const url = `/api/records.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}&kind=${kind}&export=csv`;
      window.location.href = url;
    });
  });
</script>

