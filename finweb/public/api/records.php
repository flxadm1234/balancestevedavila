<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';

require_login();
require_company_selected();

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
 
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function json_error(string $msg, ?Throwable $e = null, bool $debug = false, int $code = 400): void {
  http_response_code($code);
  $out = ['ok' => false, 'error' => $msg];
  if ($debug && $e) {
    $out['debug'] = ['type' => get_class($e), 'message' => $e->getMessage()];
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
  $web_user_id = (int)($_SESSION['web_user']['id'] ?? 0);
  if ($web_user_id <= 0) {
    json_error('No autenticado.', null, $debug, 401);
  }
  $company_id = current_company_id();
  if ($company_id <= 0) {
    json_error('Empresa no seleccionada.', null, $debug, 400);
  }

  if ($method === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $kind = trim($_POST['kind'] ?? '');
    $friendly_name = trim($_POST['friendly_name'] ?? '');
    $company_ruc = trim($_POST['company_ruc'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $company_ruc = ($company_ruc === '' ? null : $company_ruc);
    $invoice_number = ($invoice_number === '' ? null : $invoice_number);
 
    if ($id <= 0 || $friendly_name === '' || !in_array($kind, ['income', 'expense'], true)) {
      json_error('Datos inválidos.', null, $debug, 422);
    }
 
    $st = $pdo->prepare("
      SELECT 1
      FROM batches b
      JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
      WHERE b.id = :id
        AND b.company_id = :cid
        AND b.status = 'confirmed'
      LIMIT 1
    ");
    $st->execute([':wid' => $web_user_id, ':cid' => $company_id, ':id' => $id]);
    if (!$st->fetchColumn()) {
      json_error('No tienes permiso para editar este registro.', null, $debug, 403);
    }
 
    $st = $pdo->prepare("
      UPDATE batches
      SET kind = :k, friendly_name = :fn, company_ruc = :ruc, invoice_number = :inv
      WHERE id = :id AND company_id = :cid
    ");
    $st->execute([
      ':k' => $kind,
      ':fn' => $friendly_name,
      ':ruc' => $company_ruc,
      ':inv' => $invoice_number,
      ':id' => $id,
      ':cid' => $company_id,
    ]);
 
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }
 
  if ($method === 'DELETE') {
    parse_str((string)file_get_contents('php://input'), $del);
    $id = (int)($del['id'] ?? 0);
    if ($id <= 0) {
      json_error('ID inválido.', null, $debug, 422);
    }
 
    $st = $pdo->prepare("
      SELECT 1
      FROM batches b
      JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
      WHERE b.id = :id
        AND b.company_id = :cid
      LIMIT 1
    ");
    $st->execute([':wid' => $web_user_id, ':cid' => $company_id, ':id' => $id]);
    if (!$st->fetchColumn()) {
      json_error('No tienes permiso para eliminar este registro.', null, $debug, 403);
    }
 
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("DELETE FROM items WHERE batch_id = :id");
      $st->execute([':id' => $id]);
 
      $st = $pdo->prepare("DELETE FROM raw_inputs WHERE batch_id = :id");
      $st->execute([':id' => $id]);
 
      $st = $pdo->prepare("DELETE FROM batches WHERE id = :id AND company_id = :cid");
      $st->execute([':id' => $id, ':cid' => $company_id]);
 
      $pdo->commit();
      echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      json_error('Error al eliminar el registro.', $e, $debug, 500);
    }
  }
 
  $start = norm_date($_GET['start'] ?? '', date('Y-m-01'));
  $end   = norm_date($_GET['end'] ?? '', date('Y-m-t'));
  $kind  = trim($_GET['kind'] ?? '');
  $export = isset($_GET['export']) && $_GET['export'] === 'csv';

  $where = '';
  $params = [
    ':wid' => $web_user_id,
    ':cid' => $company_id,
    ':start' => $start,
    ':end' => $end,
  ];

  if ($kind === 'income' || $kind === 'expense') {
    $where .= " AND COALESCE(b.kind,'expense') = :kind ";
    $params[':kind'] = $kind;
  }

  $sql = "
    SELECT
      b.id,
      b.friendly_name,
      b.confirmed_at,
      COALESCE(b.kind,'expense') AS kind,
      b.company_ruc,
      b.invoice_number,
      COALESCE(SUM(i.price),0) AS total,
      MIN(i.item_datetime) AS voucher_at,
      r.input_type AS media_type,
      r.file_path AS media_path
    FROM batches b
    JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
    LEFT JOIN items i ON i.batch_id = b.id
    LEFT JOIN LATERAL (
      SELECT input_type, file_path
      FROM raw_inputs
      WHERE batch_id = b.id
        AND file_path IS NOT NULL
        AND input_type IN ('image','audio')
      ORDER BY id DESC
      LIMIT 1
    ) r ON true
    WHERE b.company_id = :cid
      AND b.status = 'confirmed'
      $where
    GROUP BY b.id, b.friendly_name, b.confirmed_at, kind, b.company_ruc, b.invoice_number, r.input_type, r.file_path
    HAVING COALESCE(MIN(i.item_datetime), b.confirmed_at) >= :start::date
      AND COALESCE(MIN(i.item_datetime), b.confirmed_at) < (:end::date + interval '1 day')
    ORDER BY COALESCE(MIN(i.item_datetime), b.confirmed_at) DESC, b.id DESC
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registros_' . $start . '_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Empresa', 'ID Registro', 'Fecha', 'Tipo', 'Descripción', 'Factura', 'RUC', 'Total', 'Tiene voucher', 'Tiene audio']);
    foreach ($rows as $r) {
      $dtSrc = $r['voucher_at'] ?: $r['confirmed_at'];
      $dt = (new DateTime($dtSrc))->format('Y-m-d H:i');
      $hasImage = ($r['media_type'] ?? '') === 'image';
      $hasAudio = ($r['media_type'] ?? '') === 'audio';
      fputcsv($out, [
        current_company_name(),
        (int)$r['id'],
        $dt,
        (($r['kind'] ?? 'expense') === 'income') ? 'Ingreso' : 'Gasto',
        $r['friendly_name'] ?? '',
        $r['invoice_number'] ?? '',
        $r['company_ruc'] ?? '',
        (float)$r['total'],
        $hasImage ? 'SI' : '',
        $hasAudio ? 'SI' : '',
      ]);
    }
    fclose($out);
    exit;
  }

  $data = [];
  foreach ($rows as $r) {
    $dtSrc = $r['voucher_at'] ?: $r['confirmed_at'];
    $dt = (new DateTime($dtSrc))->format('Y-m-d H:i');
    $kindLabel = (($r['kind'] ?? 'expense') === 'income') ? 'Ingreso' : 'Gasto';
    $mediaHtml = '';
    $mt = (string)($r['media_type'] ?? '');
    $mp = (string)($r['media_path'] ?? '');
    if ($mp !== '' && $mt === 'image') {
      $mediaHtml = '<a href="/' . htmlspecialchars($mp) . '" target="_blank"><img src="/' . htmlspecialchars($mp) . '" style="height:44px;max-width:120px;object-fit:cover;border:1px solid #ddd;"></a>';
    } elseif ($mp !== '' && $mt === 'audio') {
      $mediaHtml = '<audio controls style="width:180px;" src="/' . htmlspecialchars($mp) . '"></audio>';
    }

    $data[] = [
      'id' => (int)$r['id'],
      'kind' => ($r['kind'] ?? 'expense'),
      'confirmed_at' => $dt,
      'kind_label' => $kindLabel,
      'friendly_name' => $r['friendly_name'] ?? '',
      'invoice_number' => $r['invoice_number'] ?? '',
      'company_ruc' => $r['company_ruc'] ?? '',
      'total_label' => 'S/ ' . money((float)$r['total']),
      'media' => $mediaHtml,
      'actions' => '
        <a class="btn btn-sm btn-info" href="/record.php?id=' . (int)$r['id'] . '"><i class="fas fa-eye"></i></a>
        <button class="btn btn-sm btn-primary btn-edit-record"
                data-id="' . (int)$r['id'] . '"
                data-kind="' . htmlspecialchars((string)($r['kind'] ?? 'expense')) . '"
                data-friendly-name="' . htmlspecialchars((string)($r['friendly_name'] ?? '')) . '"
                data-invoice-number="' . htmlspecialchars((string)($r['invoice_number'] ?? '')) . '"
                data-company-ruc="' . htmlspecialchars((string)($r['company_ruc'] ?? '')) . '"
                type="button"><i class="fas fa-edit"></i></button>
        <button class="btn btn-sm btn-danger btn-del-record" data-id="' . (int)$r['id'] . '" type="button"><i class="fas fa-trash"></i></button>
      ',
    ];
  }

  echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  json_error('Error interno en registros.', $e, $debug, 500);
}
