<?php
declare(strict_types=1);

ini_set('display_errors', '0');          // prod: 0
ini_set('display_startup_errors', '0');  // prod: 0
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';

require_login();
require_company_selected();

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

function json_error(string $msg, ?Throwable $e = null, bool $debug = false, int $code = 400): void {
  http_response_code($code);
  $out = ['ok' => false, 'error' => $msg];
  if ($debug && $e) {
    $out['debug'] = [
      'type' => get_class($e),
      'message' => $e->getMessage(),
    ];
  }
  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}

function norm_date(string $s, string $fallback): string {
  $s = trim($s);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  return $fallback;
}

try {
  // web user id desde sesión
  $web_user_id = (int)($_SESSION['web_user']['id'] ?? 0);
  if ($web_user_id <= 0) {
    json_error('No autenticado.', null, $debug, 401);
  }
  $company_id = current_company_id();
  if ($company_id <= 0) {
    json_error('Empresa no seleccionada.', null, $debug, 400);
  }

  $start = norm_date($_GET['start'] ?? '', date('Y-m-01'));
  $end   = norm_date($_GET['end'] ?? '', date('Y-m-t'));
  $category = trim($_GET['category'] ?? 'business');

  $whereBalance = '';
  $whereChart   = '';

  $paramsBalance = [
    ':wid' => $web_user_id,
    ':start' => $start,
    ':end' => $end,
    ':cid' => $company_id
  ];
  $paramsChart = $paramsBalance; // Copia inicial

  if ($category === 'all' || $category === '') {
    // Caso "Todas":
    // 1. Balance/Saldos de caja: Incluye todo (Capital también) -> $whereBalance vacío.
    // 2. Gráficos y Totales Operativos: Excluye Capital -> $whereChart filtra != capital.
    $whereChart = " AND i.category != 'capital' ";
  } else {
    // Caso Categoría Específica (incluyendo 'capital'):
    // Ambos filtran por la categoría seleccionada.
    $whereBalance = " AND i.category = :category ";
    $whereChart   = " AND i.category = :category ";
    
    $paramsBalance[':category'] = $category;
    $paramsChart[':category']   = $category;
  }

  // ---------------------------
  // Totales Operativos (Ingresos/Gastos)
  // ---------------------------
  // AHORA Usamos $whereBalance para INCLUIR Capital en los totales numéricos (petición de usuario)
  // "debeemos calcular con capital tambien debe incluir solo que no debe mostrar en los graficos"
  $sqlTotals = "
    SELECT
      COALESCE(SUM(CASE WHEN COALESCE(b.kind,'expense')='income' THEN i.price ELSE 0 END), 0) AS income_total,
      COALESCE(SUM(CASE WHEN COALESCE(b.kind,'expense')='expense' THEN i.price ELSE 0 END), 0) AS expense_total,
      COUNT(i.id) AS count_items
    FROM items i
    JOIN batches b ON b.id = i.batch_id
    JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
    WHERE 1=1
      AND b.company_id = :cid
      AND b.status = 'confirmed'
      AND i.item_datetime >= :start::date
      AND i.item_datetime < (:end::date + interval '1 day')
      $whereBalance
  ";

  $st = $pdo->prepare($sqlTotals);
  $st->execute($paramsBalance);
  $t = $st->fetch(PDO::FETCH_ASSOC) ?: ['income_total'=>0,'expense_total'=>0,'count_items'=>0];

  // ---------------------------
  // Desglose de Balance (Dinero en Caja)
  // ---------------------------
  // Usamos $whereBalance para INCLUIR Capital en la vista general (porque es dinero real)
  $sqlBreakdown = "
    SELECT
      -- Efectivo: payment_method = 'cash' OR NULL
      COALESCE(SUM(
        CASE 
          WHEN (i.payment_method = 'cash' OR i.payment_method IS NULL) THEN
            CASE WHEN COALESCE(b.kind,'expense')='income' THEN i.price ELSE -i.price END
          ELSE 0 
        END
      ), 0) AS balance_cash,

      -- Digital: payment_method != 'cash'
      COALESCE(SUM(
        CASE 
          WHEN (i.payment_method IS NOT NULL AND i.payment_method != 'cash') THEN
            CASE WHEN COALESCE(b.kind,'expense')='income' THEN i.price ELSE -i.price END
          ELSE 0 
        END
      ), 0) AS balance_digital
    FROM items i
    JOIN batches b ON b.id = i.batch_id
    JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
    WHERE 1=1
      AND b.company_id = :cid
      AND b.status = 'confirmed'
      AND i.item_datetime >= :start::date
      AND i.item_datetime < (:end::date + interval '1 day')
      $whereBalance
  ";
  
  $st2 = $pdo->prepare($sqlBreakdown);
  $st2->execute($paramsBalance);
  $tb = $st2->fetch(PDO::FETCH_ASSOC) ?: ['balance_cash'=>0, 'balance_digital'=>0];

  $income  = (float)$t['income_total'];
  $expense = (float)$t['expense_total'];

  $totals = [
    'income_total'  => 'S/ ' . number_format($income, 2, '.', ','),
    'expense_total' => 'S/ ' . number_format($expense, 2, '.', ','),
    'balance'       => 'S/ ' . number_format($income - $expense, 2, '.', ','), // Balance operativo (sin capital)
    'count_items'   => (int)$t['count_items'],
    // Datos del desglose global (Caja real, incluye capital)
    'balance_cash'    => 'S/ ' . number_format((float)$tb['balance_cash'], 2, '.', ','),
    'balance_digital' => 'S/ ' . number_format((float)$tb['balance_digital'], 2, '.', ','),
  ];

  // ---------------------------
  // Serie diaria: ingresos vs gastos
  // ---------------------------
  // Usamos $whereChart (sin Capital en vista general)
  $sqlSeries = "
    SELECT
      to_char(date_trunc('day', i.item_datetime), 'YYYY-MM-DD') AS d,
      COALESCE(SUM(CASE WHEN COALESCE(b.kind,'expense')='income' THEN i.price ELSE 0 END), 0) AS income,
      COALESCE(SUM(CASE WHEN COALESCE(b.kind,'expense')='expense' THEN i.price ELSE 0 END), 0) AS expense
    FROM items i
    JOIN batches b ON b.id = i.batch_id
    JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
    WHERE 1=1
      AND b.company_id = :cid
      AND b.status = 'confirmed'
      AND i.item_datetime >= :start::date
      AND i.item_datetime < (:end::date + interval '1 day')
      $whereChart
    GROUP BY 1
    ORDER BY 1
  ";

  $st = $pdo->prepare($sqlSeries);
  $st->execute($paramsChart);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $labels = [];
  $incomeArr = [];
  $expenseArr = [];

  foreach ($rows as $r) {
    $labels[] = (string)$r['d'];
    $incomeArr[] = (float)$r['income'];
    $expenseArr[] = (float)$r['expense'];
  }

  echo json_encode([
    'ok' => true,
    'totals' => $totals,
    'series' => [
      'labels' => $labels,
      'income' => $incomeArr,
      'expense' => $expenseArr,
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  json_error('Error interno en dashboard.', $e, $debug, 500);
}
