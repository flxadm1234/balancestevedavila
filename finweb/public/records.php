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
        <div class="table-responsive">
          <table id="records" class="table table-bordered table-striped">
            <thead>
              <tr>
                <th>Fecha (Voucher)</th>
                <th>Tipo</th>
                <th>Descripción</th>
                <th>Factura</th>
                <th>RUC</th>
                <th>Monto</th>
                <th>Voucher/Audio</th>
                <th>Acciones</th>
              </tr>
            </thead>
          </table>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../app/layout/footer.php'; ?>

<div class="modal fade" id="modalEditRecord" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formEditRecord">
      <input type="hidden" name="id" id="rec_id" value="">
      <div class="modal-header">
        <h5 class="modal-title">Editar registro</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Tipo</label>
          <select class="form-control" name="kind" id="rec_kind" required>
            <option value="income">Ingreso</option>
            <option value="expense">Gasto</option>
          </select>
        </div>
        <div class="form-group">
          <label>Descripción</label>
          <input class="form-control" name="friendly_name" id="rec_friendly_name" required>
        </div>
        <div class="form-group">
          <label>Factura</label>
          <input class="form-control" name="invoice_number" id="rec_invoice_number" placeholder="Ej: F001-00001234">
        </div>
        <div class="form-group">
          <label>RUC</label>
          <input class="form-control" name="company_ruc" id="rec_company_ruc" placeholder="11 dígitos">
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
  const startDefault = moment().startOf('month');
  const endDefault = moment().endOf('month');
  let start = startDefault.format('YYYY-MM-DD');
  let end = endDefault.format('YYYY-MM-DD');

  function setRangeLabel(s, e) {
    $('#range').val(`${s.format('YYYY-MM-DD')} - ${e.format('YYYY-MM-DD')}`);
  }

  $(function() {
    let table = null;
    $('#range').daterangepicker({
      startDate: startDefault,
      endDate: endDefault,
      locale: { format: 'YYYY-MM-DD' }
    }, function(s, e) {
      start = s.format('YYYY-MM-DD');
      end = e.format('YYYY-MM-DD');
      setRangeLabel(s, e);
      if (table) table.ajax.reload();
    });
    setRangeLabel(startDefault, endDefault);

    table = $('#records').DataTable({
      ajax: {
        url: '/api/records.php',
        data: function(d) {
          d.start = start;
          d.end = end;
          d.kind = $('#kind').val();
        }
      },
      columnDefs: [
        { targets: 2, className: 'text-wrap', render: function(data){ return $('<div>').text(data || '').html(); } },
        { targets: [0,1,3,4,5,6,7], className: 'text-nowrap' }
      ],
      columns: [
        { data: 'confirmed_at' },
        { data: 'kind_label' },
        { data: 'friendly_name' },
        { data: 'invoice_number' },
        { data: 'company_ruc' },
        { data: 'total_label' },
        { data: 'media' },
        { data: 'actions' }
      ],
      order: [[0, 'desc']]
    });

    $('#kind').on('change', () => table.ajax.reload());

    $('#btnExport').on('click', function() {
      const kind = encodeURIComponent($('#kind').val() || '');
      const url = `/api/records.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}&kind=${kind}&export=csv`;
      window.location.href = url;
    });

    $('#records').on('click', '.btn-edit-record', function() {
      const id = $(this).data('id');
      const kind = $(this).data('kind');
      const fname = $(this).data('friendly-name');
      const inv = $(this).data('invoice-number');
      const ruc = $(this).data('company-ruc');
      $('#rec_id').val(id);
      $('#rec_kind').val(kind);
      $('#rec_friendly_name').val(fname);
      $('#rec_invoice_number').val(inv);
      $('#rec_company_ruc').val(ruc);
      $('#modalEditRecord').modal('show');
    });

    $('#formEditRecord').on('submit', async function(e) {
      e.preventDefault();
      const form = $(this);
      const payload = form.serialize();
      try {
        const res = await fetch('/api/records.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload
        });
        const js = await res.json();
        if (!res.ok || !js.ok) {
          alert((js && js.error) ? js.error : 'No se pudo guardar.');
          return;
        }
        $('#modalEditRecord').modal('hide');
        table.ajax.reload(null, false);
      } catch (err) {
        alert('Error de red al guardar.');
      }
    });

    $('#records').on('click', '.btn-del-record', async function() {
      const id = $(this).data('id');
      const ok = confirm('¿Eliminar este registro? Esto borrará también sus items.');
      if (!ok) return;
      try {
        const res = await fetch('/api/records.php', {
          method: 'DELETE',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `id=${encodeURIComponent(id)}`
        });
        const js = await res.json();
        if (!res.ok || !js.ok) {
          alert((js && js.error) ? js.error : 'No se pudo eliminar.');
          return;
        }
        table.ajax.reload(null, false);
      } catch (err) {
        alert('Error de red al eliminar.');
      }
    });
  });
</script>
