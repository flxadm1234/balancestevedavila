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
$companies = $pdo->query("SELECT id,name FROM companies WHERE is_active=true ORDER BY id ASC")->fetchAll() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'create';
  if ($action === 'update_companies') {
    $target_id = (int)($_POST['web_user_id'] ?? 0);
    $selected = $_POST['companies'] ?? [];
    if (!is_array($selected)) $selected = [];
    $ids = [];
    foreach ($selected as $v) {
      $cid = (int)$v;
      if ($cid > 0) $ids[$cid] = true;
    }

    if ($target_id > 0) {
      $pdo->beginTransaction();
      $st = $pdo->prepare("DELETE FROM web_user_companies WHERE web_user_id = :wid");
      $st->execute([':wid' => $target_id]);
      if (!empty($ids)) {
        $st = $pdo->prepare("INSERT INTO web_user_companies (web_user_id, company_id) VALUES (:wid, :cid)");
        foreach (array_keys($ids) as $cid) {
          $st->execute([':wid' => $target_id, ':cid' => $cid]);
        }
      }
      $pdo->commit();
    }

    header('Location: /users.php');
    exit;
  }

  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass = $_POST['password'] ?? '';
  $role = $_POST['role'] ?? 'staff';

  if ($name && $email && $pass) {
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $st = $pdo->prepare("INSERT INTO web_users(name,email,password_hash,role) VALUES(:n,:e,:h,:r)");
    $st->execute([':n'=>$name, ':e'=>$email, ':h'=>$hash, ':r'=>$role]);
    $new_id = (int)$pdo->lastInsertId();
    if ($new_id > 0 && $role === 'admin' && !empty($companies)) {
      $st = $pdo->prepare("INSERT INTO web_user_companies (web_user_id, company_id) VALUES (:wid, :cid) ON CONFLICT DO NOTHING");
      foreach ($companies as $c) {
        $st->execute([':wid' => $new_id, ':cid' => (int)$c['id']]);
      }
    }
  }
  header('Location: /users.php');
  exit;
}

$rows = $pdo->query("SELECT id,name,email,role,is_active,created_at FROM web_users ORDER BY id DESC")->fetchAll() ?: [];
$map = [];
foreach ($pdo->query("SELECT web_user_id, company_id FROM web_user_companies ORDER BY web_user_id, company_id") as $r) {
  $wid = (int)$r['web_user_id'];
  $cid = (int)$r['company_id'];
  if (!isset($map[$wid])) $map[$wid] = [];
  $map[$wid][$cid] = true;
}

$perm_user_id = (int)($_GET['perm_user_id'] ?? 0);
$perm_user_email = '';
if ($perm_user_id > 0) {
  foreach ($rows as $rr) {
    if ((int)$rr['id'] === $perm_user_id) { $perm_user_email = (string)$rr['email']; break; }
  }
}
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
        <div class="table-responsive">
          <table class="table table-bordered table-striped" id="tbl">
            <thead>
              <tr>
                <th>Nombre</th><th>Email</th><th>Rol</th><th>Empresas</th><th>Activo</th><th>Fecha</th><th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td class="text-nowrap"><?= h($r['name']) ?></td>
                <td class="text-nowrap"><?= h($r['email']) ?></td>
                <td class="text-nowrap"><?= h($r['role']) ?></td>
                <td class="text-wrap">
                  <?php
                    $wid = (int)$r['id'];
                    $assigned = array_keys($map[$wid] ?? []);
                    if (empty($assigned)) {
                      echo '-';
                    } else {
                      $names = [];
                      foreach ($companies as $c) {
                        if (isset($map[$wid][(int)$c['id']])) $names[] = $c['name'];
                      }
                      echo h(implode(', ', $names));
                    }
                  ?>
                </td>
                <td class="text-nowrap"><?= $r['is_active'] ? 'Sí' : 'No' ?></td>
                <td class="text-nowrap"><?= (new DateTime($r['created_at']))->format('Y-m-d') ?></td>
                <td class="text-nowrap">
                  <a class="btn btn-sm btn-info btn-perms" href="/users.php?perm_user_id=<?= (int)$r['id'] ?>">
                    <i class="fas fa-building"></i>
                  </a>
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

<div class="modal fade" id="modalPerms" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="update_companies">
      <input type="hidden" name="web_user_id" id="perm_user_id" value="<?= (int)$perm_user_id ?>">
      <div class="modal-header">
        <h5 class="modal-title">Acceso por empresa</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="mb-2 text-muted" id="perm_user_email"><?= h($perm_user_email) ?></div>
        <?php foreach ($companies as $c): ?>
          <div class="custom-control custom-checkbox">
            <input class="custom-control-input perm-company" type="checkbox" id="c<?= (int)$c['id'] ?>" name="companies[]" value="<?= (int)$c['id'] ?>" <?= (($perm_user_id > 0) && isset($map[$perm_user_id][(int)$c['id']])) ? 'checked' : '' ?>>
            <label class="custom-control-label" for="c<?= (int)$c['id'] ?>"><?= h($c['name']) ?></label>
          </div>
        <?php endforeach; ?>
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
  $('#tbl').DataTable();
  const permUserId = <?= (int)$perm_user_id ?>;
  if (permUserId > 0) {
    $('#modalPerms').modal('show');
  }
});
</script>

<?php require __DIR__ . '/../app/layout/footer.php'; ?>
