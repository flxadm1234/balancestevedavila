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
  <title>Login</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
</head>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="card card-outline card-primary">
    <div class="card-header text-center">
      <b>Sistemas</b> Web
    </div>
    <div class="card-body">
      <p class="login-box-msg">Iniciar sesión</p>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post">
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
        <div class="input-group mb-3">
          <input type="email" name="email" class="form-control" placeholder="Correo" required>
          <div class="input-group-append"><div class="input-group-text"><span class="fas fa-envelope"></span></div></div>
        </div>
        <div class="input-group mb-3">
          <input type="password" name="password" class="form-control" placeholder="Contraseña" required>
          <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Iniciar sesión</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
