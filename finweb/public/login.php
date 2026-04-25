<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
session_start();

$error = '';
$pdo = db();
$companies = $pdo->query("SELECT id, name FROM companies WHERE is_active = true ORDER BY id ASC")->fetchAll() ?: [];
$selected_company_id = (int)($_POST['company_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  $company_id = (int)($_POST['company_id'] ?? 0);

  $st = $pdo->prepare("SELECT id, name, email, password_hash, role, is_active FROM web_users WHERE email = :e LIMIT 1");
  $st->execute([':e' => $email]);
  $u = $st->fetch();

  if (!$u || !$u['is_active'] || !password_verify($pass, $u['password_hash'])) {
    $error = 'Credenciales inválidas.';
  } else {
    if ($company_id <= 0) {
      $error = 'Selecciona una empresa.';
    } else {
      $company = null;
      foreach ($companies as $c) {
        if ((int)$c['id'] === $company_id) { $company = $c; break; }
      }

      if (!$company) {
        $error = 'Empresa inválida.';
      } else {
        $isAdmin = (($u['role'] ?? '') === 'admin');
        if (!$isAdmin) {
          $st = $pdo->prepare("SELECT 1 FROM web_user_companies WHERE web_user_id = :wid AND company_id = :cid LIMIT 1");
          $st->execute([':wid' => (int)$u['id'], ':cid' => $company_id]);
          if (!$st->fetchColumn()) {
            $error = 'No tienes acceso a esa empresa.';
          }
        }
      }
    }

    if ($error !== '') {
      // continuar para renderizar
    } else {
    $_SESSION['web_user'] = [
      'id' => (int)$u['id'],
      'name' => $u['name'],
      'email' => $u['email'],
      'role' => $u['role'],
    ];
    $_SESSION['company'] = [
      'id' => $company_id,
      'name' => $company['name'],
    ];
    header('Location: /dashboard.php');
    exit;
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Acceso</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="hold-transition">
<div class="bs-login">
  <div class="login-box">
    <div class="card">
      <div class="card-header">
        <div class="brand-title">Balance & Control</div>
        <div class="brand-subtitle">Administración contable • Ingresos y egresos por empresa</div>
      </div>
      <div class="card-body">
        <p class="login-box-msg">Acceso al sistema</p>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <div class="form-group">
          <label class="text-muted mb-1">Empresa</label>
        <div class="input-group mb-3">
          <select name="company_id" class="form-control" required>
            <option value="">Seleccionar empresa...</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $selected_company_id) ? 'selected' : '' ?>>
                <?= h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="input-group-append"><div class="input-group-text"><span class="fas fa-building"></span></div></div>
        </div>
        </div>
        <div class="form-group">
          <label class="text-muted mb-1">Correo</label>
        <div class="input-group mb-3">
          <input type="email" name="email" class="form-control" placeholder="Correo" required>
          <div class="input-group-append"><div class="input-group-text"><span class="fas fa-envelope"></span></div></div>
        </div>
        </div>
        <div class="form-group">
          <label class="text-muted mb-1">Contraseña</label>
        <div class="input-group mb-3">
          <input id="password" type="password" name="password" class="form-control" placeholder="Contraseña" required>
          <div class="input-group-append">
            <div class="input-group-text toggle-pass" id="togglePass" title="Mostrar/ocultar contraseña">
              <span class="fas fa-eye"></span>
            </div>
          </div>
        </div>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Iniciar sesión</button>
        <div class="helper">
          Si no tienes acceso a una empresa, solicita al administrador que te asigne permisos.
        </div>
      </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script>
  (function(){
    const input = document.getElementById('password');
    const btn = document.getElementById('togglePass');
    if (!input || !btn) return;
    btn.addEventListener('click', function(){
      const isPwd = input.type === 'password';
      input.type = isPwd ? 'text' : 'password';
      const icon = btn.querySelector('span');
      if (icon) {
        icon.classList.remove(isPwd ? 'fa-eye' : 'fa-eye-slash');
        icon.classList.add(isPwd ? 'fa-eye-slash' : 'fa-eye');
      }
    });
  })();
</script>
</body>
</html>
