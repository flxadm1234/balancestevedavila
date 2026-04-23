<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_login();
require_company_selected();

$pdo = db();
$u = current_user();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $pass = $_POST['password'] ?? '';

  if ($name) {
    $st = $pdo->prepare("UPDATE web_users SET name=:n WHERE id=:id");
    $st->execute([':n'=>$name, ':id'=>$u['id']]);
    $_SESSION['web_user']['name'] = $name;
    $msg = 'Nombre actualizado.';
  }

  if ($pass) {
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $st = $pdo->prepare("UPDATE web_users SET password_hash=:h WHERE id=:id");
    $st->execute([':h'=>$hash, ':id'=>$u['id']]);
    $msg = $msg ? $msg.' Clave actualizada.' : 'Clave actualizada.';
  }
}

require __DIR__ . '/../app/layout/header.php';
require __DIR__ . '/../app/layout/sidebar.php';
?>
<section class="content pt-3">
  <div class="container-fluid">

    <div class="card">
      <div class="card-header"><h3 class="card-title">Configuración</h3></div>
      <div class="card-body">
        <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

        <form method="post">
          <div class="form-group">
            <label>Nombre</label>
            <input name="name" class="form-control" value="<?= h($_SESSION['web_user']['name']) ?>" required>
          </div>
          <div class="form-group">
            <label>Nueva contraseña (opcional)</label>
            <input name="password" type="password" class="form-control" placeholder="Dejar vacío para no cambiar">
          </div>
          <button class="btn btn-primary">Actualizar Datos</button>
        </form>
      </div>
    </div>

  </div>
</section>
<?php require __DIR__ . '/../app/layout/footer.php'; ?>
