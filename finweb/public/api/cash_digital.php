<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
require_company_selected();

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// Captura fatales (E_ERROR) que no pasan por try/catch
register_shutdown_function(function() use ($debug) {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    http_response_code(500);
    echo json_encode([
      'ok' => false,
      'error' => 'Fatal error',
      'detail' => $debug ? $err : null,
    ], JSON_UNESCAPED_UNICODE);
  }
});

function json_fail(string $msg, $detail = null, int $code = 500): void {
  http_response_code($code);
  echo json_encode([
    'ok' => false,
    'error' => $msg,
    'detail' => $detail,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

function norm_date(?string $s): ?string {
  if ($s === null) return null;
  $s = trim($s);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  return null;
}

try {
  // web user id desde sesión (según tu login)
  $web_user_id = (int)($_SESSION['web_user']['id'] ?? 0);
  if ($web_user_id <= 0) {
    json_fail('No autenticado.', null, 401);
  }
  $company_id = current_company_id();
  if ($company_id <= 0) {
    json_fail('Empresa no seleccionada.', null, 400);
  }

  $start = norm_date($_GET['start'] ?? null);
  $end   = norm_date($_GET['end'] ?? null);
  $category = trim($_GET['category'] ?? 'business');

  if (!$start || !$end) {
    json_fail("Faltan parámetros start y end (YYYY-MM-DD)", ['start'=>$start,'end'=>$end], 400);
  }

  $usd_rate = isset($_GET['usd']) ? (float)$_GET['usd'] : 3.75;
  if ($usd_rate <= 0) $usd_rate = 3.75;

  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $whereChart = '';
  $params = [
    ':wid'   => $web_user_id,
    ':start' => $start,
    ':end'   => $end,
    ':cid'   => $company_id,
  ];

  if ($category === 'all' || $category === '') {
      // Vista general: Excluir 'capital' del gráfico
      $whereChart = " AND i.category != 'capital' ";
  } else {
      // Vista específica: Filtrar por la categoría seleccionada
      $whereChart = " AND i.category = :category ";
      $params[':category'] = $category;
  }

  // Importante:
  // - Filtramos por wuu.web_user_id
  // - Solo b.kind (no i.kind)
  // - CAST(:start AS date) para que PostgreSQL no se queje
  $sql = "
    SELECT
      DATE(i.item_datetime AT TIME ZONE 'America/Lima') AS d,
      CASE
        WHEN COALESCE(i.payment_method,'cash') = 'cash' THEN 'cash'
        ELSE 'digital'
      END AS bucket,
      SUM(
        CASE COALESCE(b.kind, 'expense')
          WHEN 'income' THEN i.price
          ELSE -i.price
        END
      ) AS amount
    FROM items i
    JOIN batches b ON b.id = i.batch_id
    JOIN web_user_users wuu ON wuu.user_id = b.user_id
    JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
    WHERE wuu.web_user_id = :wid
      AND b.company_id = :cid
      AND b.status = 'confirmed'
      AND (i.item_datetime AT TIME ZONE 'America/Lima') >= CAST(:start AS date)
      AND (i.item_datetime AT TIME ZONE 'America/Lima') <  (CAST(:end AS date) + INTERVAL '1 day')
      $whereChart
    GROUP BY d, bucket
    ORDER BY d ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Construye serie diaria SOLO con los días que tienen datos (o dentro del rango si se prefiere)
  // El usuario solicitó: "si hay dias que no tiene registro no deberiamos visualizar vacios"
  // Por lo tanto, en lugar de iterar día a día forzando ceros, usaremos las fechas que vienen de la BD.
  // Sin embargo, la BD devuelve filas por (día, bucket), así que debemos agrupar primero por día.

  $map = []; // map[date][bucket]=amount
  foreach ($rows as $r) {
    $d = (string)$r['d'];
    $b = (string)$r['bucket'];
    $a = (float)$r['amount'];
    if (!isset($map[$d])) {
      $map[$d] = ['cash'=>0.0, 'digital'=>0.0];
    }
    $map[$d][$b] = $a;
  }
  
  // Ordenar las fechas cronológicamente
  ksort($map);

  $labels = [];
  $cash = [];
  $digital = [];

  foreach ($map as $date => $amounts) {
    $labels[] = $date;
    $cash[] = $amounts['cash'];
    $digital[] = $amounts['digital'];
  }
  
  // Totales y %
  $cash_total = array_sum($cash);
  $digital_total = array_sum($digital);
  $total = $cash_total + $digital_total;

  $cash_pct = $total != 0 ? ($cash_total / $total) * 100.0 : 0.0;
  $digital_pct = $total != 0 ? ($digital_total / $total) * 100.0 : 0.0;

  $out = [
    'ok' => true,
    'input' => [
      'start' => $start,
      'end' => $end,
      'usd_rate' => $usd_rate,
      'web_user_id' => $web_user_id,
    ],
    'labels' => $labels,
    'cash' => $cash,
    'digital' => $digital,
    'totals' => [
      'cash' => round($cash_total, 2),
      'digital' => round($digital_total, 2),
      'cash_pct' => round($cash_pct, 1),
      'digital_pct' => round($digital_pct, 1),
      'cash_usd' => round($cash_total / $usd_rate, 2),
      'digital_usd' => round($digital_total / $usd_rate, 2),
    ],
  ];

  if ($debug) {
    $out['debug'] = [
      'sql' => $sql,
      'params' => $params,
      'rows' => $rows,
      'days' => count($labels),
    ];
  }

  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  json_fail("Excepción en cash_digital.php", [
    'message' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'trace' => $debug ? $e->getTraceAsString() : null,
  ], 500);
}
