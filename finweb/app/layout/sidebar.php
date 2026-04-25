<?php
// app/layout/sidebar.php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
$u = current_user();
$companyName = current_company_name();
?>
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
    </li>
  </ul>
  <ul class="navbar-nav ml-auto">
    <li class="nav-item">
      <a class="nav-link" href="/login.php"><i class="fas fa-building"></i> <span class="nav-text"><?= h($companyName ?: 'Empresa') ?></span></a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="/settings.php"><i class="fas fa-user-cog"></i> <span class="nav-text"><?= h($u['name'] ?? 'Usuario') ?></span></a>
    </li>
    <li class="nav-item">
      <a class="nav-link text-danger" href="/logout.php"><i class="fas fa-sign-out-alt"></i> <span class="nav-text hide-xs">Salir</span></a>
    </li>
  </ul>
</nav>

<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <a href="/dashboard.php" class="brand-link">
    <span class="brand-text font-weight-light">Sistemas Web</span>
  </a>

  <div class="sidebar">
    <div class="user-panel mt-3 pb-3 mb-3 d-flex">
      <div class="image">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($u['name'] ?? 'SW') ?>&background=0D8ABC&color=fff" class="img-circle elevation-2" alt="User">
      </div>
      <div class="info">
        <a href="/settings.php" class="d-block"><?= h($u['name'] ?? 'Usuario') ?></a>
      </div>
    </div>

    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
        <li class="nav-item">
          <a href="/dashboard.php" class="nav-link">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>Dashboard</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="/transactions.php" class="nav-link">
            <i class="nav-icon fas fa-exchange-alt"></i>
            <p>Ingresos / Gastos</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="/records.php" class="nav-link">
            <i class="nav-icon fas fa-receipt"></i>
            <p>Registros</p>
          </a>
        </li>

        <?php if (($u['role'] ?? '') === 'admin'): ?>
        <li class="nav-item">
          <a href="/companies.php" class="nav-link">
            <i class="nav-icon fas fa-building"></i>
            <p>Empresas</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="/users.php" class="nav-link">
            <i class="nav-icon fas fa-users"></i>
            <p>Usuarios</p>
          </a>
        </li>
        <?php endif; ?>

        <li class="nav-item">
          <a href="/settings.php" class="nav-link">
            <i class="nav-icon fas fa-cog"></i>
            <p>Configuración</p>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</aside>

<div class="content-wrapper">
