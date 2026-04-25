<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_login();
require_company_selected();
$u = current_user();
if (($u['role'] ?? '') !== 'admin') { http_response_code(403); exit('No autorizado'); }
 
$pdo = db();
$msg = trim($_GET['msg'] ?? '');
$error = trim($_GET['error'] ?? '');
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
 
  try {
    if ($action === 'create') {
      $name = trim($_POST['name'] ?? '');
      if ($name === '') {
        header('Location: /companies.php?error=' . rawurlencode('Nombre inválido.'));
        exit;
      }
 
      $pdo->beginTransaction();
      $newId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM companies")->fetchColumn();
      $st = $pdo->prepare("INSERT INTO companies (id, name, is_active) VALUES (:id, :n, true)");
      $st->execute([':id' => $newId, ':n' => $name]);
 
      $st = $pdo->prepare("
        INSERT INTO web_user_companies (web_user_id, company_id)
        SELECT id, :cid
        FROM web_users
        WHERE role = 'admin'
        ON CONFLICT DO NOTHING
      ");
      $st->execute([':cid' => $newId]);
 
      $pdo->commit();
      header('Location: /companies.php?msg=' . rawurlencode('Empresa creada.'));
      exit;
    }
 
    if ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      if ($id <= 0 || $name === '') {
        header('Location: /companies.php?error=' . rawurlencode('Datos inválidos.'));
        exit;
      }
 
      $st = $pdo->prepare("UPDATE companies SET name = :n, updated_at = now() WHERE id = :id");
      $st->execute([':n' => $name, ':id' => $id]);
      header('Location: /companies.php?msg=' . rawurlencode('Empresa actualizada.'));
      exit;
    }
 
    if ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      $val = (int)($_POST['is_active'] ?? 0);
      if ($id <= 0 || ($val !== 0 && $val !== 1)) {
        header('Location: /companies.php?error=' . rawurlencode('Datos inválidos.'));
        exit;
      }
      $st = $pdo->prepare("UPDATE companies SET is_active = :v, updated_at = now() WHERE id = :id");
      $st->execute([':v' => ($val === 1), ':id' => $id]);
      header('Location: /companies.php?msg=' . rawurlencode('Estado actualizado.'));
      exit;
    }
 
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) {
        header('Location: /companies.php?error=' . rawurlencode('ID inválido.'));
        exit;
      }
 
      $st = $pdo->prepare("SELECT 1 FROM batches WHERE company_id = :id LIMIT 1");
      $st->execute([':id' => $id]);
      if ($st->fetchColumn()) {
        header('Location: /companies.php?error=' . rawurlencode('No se puede eliminar: la empresa tiene registros. Desactívala.'));
        exit;
      }
 
      $st = $pdo->prepare("SELECT 1 FROM users WHERE active_company_id = :id LIMIT 1");
      $st->execute([':id' => $id]);
      if ($st->fetchColumn()) {
        header('Location: /companies.php?error=' . rawurlencode('No se puede eliminar: la empresa está en uso por usuarios. Desactívala.'));
        exit;
      }
 
      $st = $pdo->prepare("DELETE FROM companies WHERE id = :id");
      $st->execute([':id' => $id]);
      header('Location: /companies.php?msg=' . rawurlencode('Empresa eliminada.'));
      exit;
    }
 
    header('Location: /companies.php?error=' . rawurlencode('Acción inválida.'));
    exit;
 
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: /companies.php?error=' . rawurlencode('Error al guardar cambios.'));
    exit;
  }
}
 
$rows = $pdo->query("SELECT id, name, is_active, created_at FROM companies ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
 
require __DIR__ . '/../app/layout/header.php';
require __DIR__ . '/../app/layout/sidebar.php';
?>
<section class="content pt-3">
  <div class="container-fluid">
 
    <?php if ($msg): ?>
      <div class="alert alert-success"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>
 
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Empresas</h3>
        <div class="card-tools">
          <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modalAdd">
            <i class="fas fa-plus"></i> Agregar Empresa
          </button>
        </div>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped" id="tbl">
            <thead>
              <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Activa</th>
                <th>Fecha</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td class="text-nowrap"><?= (int)$r['id'] ?></td>
                  <td class="text-wrap"><?= h((string)$r['name']) ?></td>
                  <td class="text-nowrap"><?= ((bool)$r['is_active']) ? 'Sí' : 'No' ?></td>
                  <td class="text-nowrap"><?= (new DateTime((string)$r['created_at']))->format('Y-m-d') ?></td>
                  <td class="text-nowrap">
                    <button
                      class="btn btn-sm btn-info btn-edit"
                      data-id="<?= (int)$r['id'] ?>"
                      data-name="<?= h((string)$r['name']) ?>"
                      type="button"
                    ><i class="fas fa-edit"></i></button>
 
                    <?php if ((bool)$r['is_active']): ?>
                      <button class="btn btn-sm btn-warning btn-toggle" data-id="<?= (int)$r['id'] ?>" data-active="0" type="button"><i class="fas fa-ban"></i></button>
                    <?php else: ?>
                      <button class="btn btn-sm btn-success btn-toggle" data-id="<?= (int)$r['id'] ?>" data-active="1" type="button"><i class="fas fa-check"></i></button>
                    <?php endif; ?>
 
                    <button class="btn btn-sm btn-danger btn-del" data-id="<?= (int)$r['id'] ?>" type="button"><i class="fas fa-trash"></i></button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
 
  </div>
</section>
 
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="create">
      <div class="modal-header">
        <h5 class="modal-title">Nueva empresa</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Nombre</label>
          <input name="name" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-dismiss="modal" type="button">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>
 
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit_id" value="">
      <div class="modal-header">
        <h5 class="modal-title">Editar empresa</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Nombre</label>
          <input name="name" id="edit_name" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-dismiss="modal" type="button">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>
 
<form id="formToggle" method="post" class="d-none">
  <input type="hidden" name="action" value="toggle">
  <input type="hidden" name="id" id="toggle_id" value="">
  <input type="hidden" name="is_active" id="toggle_active" value="">
</form>
 
<form id="formDelete" method="post" class="d-none">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="del_id" value="">
</form>
 
<script>
$(function(){
  $('#tbl').DataTable();
 
  $('.btn-edit').on('click', function(){
    const id = $(this).data('id');
    const name = $(this).data('name');
    $('#edit_id').val(id);
    $('#edit_name').val(name);
    $('#modalEdit').modal('show');
  });
 
  $('.btn-toggle').on('click', function(){
    const id = $(this).data('id');
    const active = $(this).data('active');
    const ok = confirm(active === 1 ? '¿Activar esta empresa?' : '¿Desactivar esta empresa?');
    if (!ok) return;
    $('#toggle_id').val(id);
    $('#toggle_active').val(active);
    $('#formToggle').trigger('submit');
  });
 
  $('.btn-del').on('click', function(){
    const id = $(this).data('id');
    const ok = confirm('¿Eliminar esta empresa? Esta acción no se puede deshacer.');
    if (!ok) return;
    $('#del_id').val(id);
    $('#formDelete').trigger('submit');
  });
});
</script>
 
<?php require __DIR__ . '/../app/layout/footer.php'; ?>
