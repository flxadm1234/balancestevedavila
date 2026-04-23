<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_login();
require_company_selected();
$u = current_user();
if (($u['role'] ?? '') !== 'admin') { http_response_code(403); exit('No autorizado'); }

require __DIR__ . '/../app/layout/header.php';
require __DIR__ . '/../app/layout/sidebar.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass = $_POST['password'] ?? '';
  $role = $_POST['role'] ?? 'staff';

  if ($name && $email && $pass) {
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $st = $pdo->prepare("INSERT INTO web_users(name,email,password_hash,role) VALUES(:n,:e,:h,:r)");
    $st->execute([':n'=>$name, ':e'=>$email, ':h'=>$hash, ':r'=>$role]);
  }
  header('Location: /users.php');
  exit;
}

$rows = $pdo->query("SELECT id,name,email,role,is_active,created_at FROM web_users ORDER BY id DESC")->fetchAll();
?>
<section class="content pt-3">
  <div class="container-fluid">

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Usuarios</h3>
        <div class="card-tools">
          <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modalAdd">
            <i class="fas fa-user-plus"></i> Agregar Usuario
          </button>
        </div>
      </div>
      <div class="card-body">
        <table class="table table-bordered table-striped" id="tbl">
          <thead>
            <tr>
              <th>Nombre</th><th>Email</th><th>Rol</th><th>Activo</th><th>Fecha</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?= h($r['name']) ?></td>
              <td><?= h($r['email']) ?></td>
              <td><?= h($r['role']) ?></td>
              <td><?= $r['is_active'] ? 'Sí' : 'No' ?></td>
              <td><?= (new DateTime($r['created_at']))->format('Y-m-d') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</section>

<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title">Nuevo usuario</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Nombre</label><input name="name" class="form-control" required></div>
        <div class="form-group"><label>Email</label><input name="email" type="email" class="form-control" required></div>
        <div class="form-group"><label>Contraseña</label><input name="password" type="password" class="form-control" required></div>
        <div class="form-group">
          <label>Rol</label>
          <select name="role" class="form-control">
            <option value="admin">admin</option>
            <option value="staff" selected>staff</option>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal" type="button">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div>
    </form>
  </div>
</div>

<script>
$(function(){ $('#tbl').DataTable(); });
</script>

<?php require __DIR__ . '/../app/layout/footer.php'; ?>
