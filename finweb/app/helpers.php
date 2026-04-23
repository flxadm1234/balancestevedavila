<?php
// app/helpers.php
declare(strict_types=1);

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function money(float $n): string {
  return number_format($n, 2, '.', ',');
}

function pm_label(string $pm): string {
  $map = [
    'cash' => 'Efectivo',
    'yape' => 'Yape',
    'plin' => 'Plin',
    'transfer' => 'Transferencia',
    'other' => 'Otros',
  ];
  return $map[$pm] ?? $pm;
}

function category_label(string $cat): string {
  $map = [
    'business' => '<span class="badge badge-success">Negocio</span>',
    'personal' => '<span class="badge badge-warning">Personal</span>',
    'loan' => '<span class="badge badge-info">Préstamo</span>',
    'third_party' => '<span class="badge badge-secondary">Terceros</span>',
    'various' => '<span class="badge badge-dark">Pagos varios</span>',
    'capital' => '<span class="badge badge-primary">Capital</span>',
    'workers' => '<span class="badge badge-danger">Pago Trabajadores</span>',
  ];
  return $map[$cat] ?? $cat;
}
