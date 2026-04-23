<?php
declare(strict_types=1);

ini_set('display_errors', '0');          // en producción: 0
ini_set('display_startup_errors', '0');  // en producción: 0
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';

require_login();
require_company_selected();

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

// --- AUTO-MIGRATION START (Asegurar columna category) ---
try {
    $pdo->query("SELECT category FROM items LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE items ADD COLUMN category VARCHAR(50) DEFAULT 'business'");
        $pdo->exec("UPDATE items SET category = 'business' WHERE category IS NULL");
    } catch (Throwable $t) {}
}
// --- AUTO-MIGRATION END ---

// Tu sesión guarda: $_SESSION['web_user']['id']
$web_user_id = (int)($_SESSION['web_user']['id'] ?? 0);
if ($web_user_id <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'No autenticado']);
  exit;
}
$company_id = current_company_id();
if ($company_id <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Empresa no seleccionada']);
  exit;
}

/**
 * Devuelve lista de user_id (Telegram) permitidos para este web_user.
 */
function allowed_telegram_user_ids(PDO $pdo, int $web_user_id): array {
  $st = $pdo->prepare("
    SELECT user_id
    FROM web_user_users
    WHERE web_user_id = :wid
    ORDER BY user_id
  ");
  $st->execute([':wid' => $web_user_id]);
  return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Respuesta JSON de error estándar.
 */
function json_error(string $msg, ?Throwable $e = null, bool $debug = false, int $code = 400): void {
  http_response_code($code);
  $out = ['ok' => false, 'error' => $msg];
  if ($debug && $e) {
    $out['debug'] = [
      'type' => get_class($e),
      'message' => $e->getMessage(),
    ];
  }
  echo json_encode($out);
  exit;
}

/**
 * Convierte strings de fecha tipo YYYY-MM-DD a formato seguro.
 */
function norm_date(string $s, string $fallback): string {
  $s = trim($s);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  return $fallback;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {

  // ==========================================================
  // GET: Listado de movimientos (filtrado por cuentas vinculadas)
  // ==========================================================
  if ($method === 'GET') {

    $start = norm_date($_GET['start'] ?? '', date('Y-m-01'));
    $end   = norm_date($_GET['end'] ?? '', date('Y-m-t'));
    $kind  = trim($_GET['kind'] ?? '');
    $cat   = trim($_GET['category'] ?? '');
    $pm    = trim($_GET['payment_method'] ?? '');
    $export = isset($_GET['export']) && $_GET['export'] === 'csv';

    $whereClause = '';
    $params = [
      ':start' => $start,
      ':end' => $end,
      ':web_user_id' => $web_user_id,
      ':company_id' => $company_id
    ];

    if ($kind === 'income' || $kind === 'expense') {
      $whereClause .= " AND COALESCE(b.kind, 'expense') = :kind ";
      $params[':kind'] = $kind;
    }

    if ($cat !== '') {
      $whereClause .= " AND i.category = :cat ";
      $params[':cat'] = $cat;
    }

    if ($pm !== '') {
      if ($pm === 'cash') {
        $whereClause .= " AND (i.payment_method = 'cash' OR i.payment_method IS NULL) ";
      } elseif ($pm === 'digital') {
        $whereClause .= " AND (i.payment_method IS NOT NULL AND i.payment_method != 'cash') ";
      } else {
        $whereClause .= " AND i.payment_method = :pm ";
        $params[':pm'] = $pm;
      }
    }

    // Importante:
    // - Unimos batches->users (telegram) para mostrar quién registró.
    // - Filtramos por web_user_users para limitar el scope.
    // - Usamos item_datetime (timestamptz) comparando por rango de fechas.
    $sql = "
      SELECT
        i.id,
        i.description,
        i.price,
        i.payment_method,
        i.item_datetime,
        COALESCE(i.category, 'business') AS category,
        COALESCE(b.kind, 'expense') AS kind,
        b.id AS batch_id,
        b.friendly_name,
        b.company_ruc,
        b.invoice_number,
        u.first_name,
        u.username
      FROM items i
      JOIN batches b ON b.id = i.batch_id
      JOIN users u ON u.id = b.user_id
      JOIN web_user_users wuu ON wuu.user_id = b.user_id
      JOIN web_user_companies wuc ON wuc.web_user_id = :web_user_id AND wuc.company_id = b.company_id
      WHERE wuu.web_user_id = :web_user_id
        AND b.company_id = :company_id
        AND b.status = 'confirmed'
        AND i.item_datetime >= :start::date
        AND i.item_datetime < (:end::date + interval '1 day')
        $whereClause
      ORDER BY i.item_datetime DESC, i.id DESC
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ==========================================================
    // EXPORT CSV
    // ==========================================================
    if ($export) {
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="transacciones_' . $start . '_' . $end . '.csv"');
      $out = fopen('php://output', 'w');
      // BOM para Excel
      fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
      
      fputcsv($out, ['Empresa', 'RUC', 'Factura', 'ID', 'Fecha', 'Tipo', 'Categoría', 'Descripción', 'Monto', 'Medio', 'Usuario']);
      
      foreach ($rows as $r) {
        $dt = (new DateTime($r['item_datetime']))->format('Y-m-d H:i');
        fputcsv($out, [
          current_company_name(),
          $r['company_ruc'] ?? '',
          $r['invoice_number'] ?? '',
          $r['id'],
          $dt,
          ($r['kind'] === 'income') ? 'Ingreso' : 'Gasto',
          category_label($r['category']),
          $r['description'],
          $r['price'],
          pm_label(($r['payment_method'] ?? 'cash') ?: 'cash'),
          ($r['first_name'] ?? '') . ' ' . ($r['username'] ?? '')
        ]);
      }
      fclose($out);
      exit;
    }

    $data = [];
    foreach ($rows as $r) {
      $kindLabel = ($r['kind'] === 'income') ? 'Ingreso' : 'Gasto';
      $userLabel = ($r['first_name'] ?? '') . (!empty($r['username']) ? ' (@' . $r['username'] . ')' : '');
      $batchLabel = 'ID ' . (int)$r['batch_id'] . ' — ' . (!empty($r['friendly_name']) ? $r['friendly_name'] : '(sin nombre)');

      // Ojo: DateTime respeta timezone del server. Si quieres forzar America/Lima aquí, lo hacemos luego.
      $dt = (new DateTime($r['item_datetime']))->format('Y-m-d H:i');

      $data[] = [
        'id' => (int)$r['id'],
        'item_datetime' => $dt,
        'kind_label' => $kindLabel,
        'category_label' => category_label($r['category']),
        'description' => $r['description'],
        'price_label' => 'S/ ' . money((float)$r['price']),
        'payment_method_label' => pm_label(($r['payment_method'] ?? 'cash') ?: 'cash'),
        'company_ruc' => $r['company_ruc'] ?? '',
        'invoice_number' => $r['invoice_number'] ?? '',
        'batch_label' => $batchLabel,
        'user_label' => $userLabel,
        'actions' => '
          <button class="btn btn-sm btn-info btn-edit" 
                  data-id="'.(int)$r['id'].'"
                  data-kind="'.htmlspecialchars($r['kind'] ?? '').'"
                  data-category="'.htmlspecialchars($r['category'] ?? 'business').'"
                  data-description="'.htmlspecialchars($r['description'] ?? '').'"
                  data-price="'.(float)$r['price'].'"
                  data-payment-method="'.htmlspecialchars($r['payment_method'] ?? '').'"
                  data-company-ruc="'.htmlspecialchars($r['company_ruc'] ?? '').'"
                  data-invoice-number="'.htmlspecialchars($r['invoice_number'] ?? '').'"
                  data-datetime="'.$dt.'"
          ><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-danger btn-del" data-id="'.(int)$r['id'].'"><i class="fas fa-trash"></i></button>
        '
      ];
    }

    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ==========================================================
  // POST: Crear movimiento manual (solo en cuentas vinculadas)
  // ==========================================================
  if ($method === 'POST') {

    $allowed = allowed_telegram_user_ids($pdo, $web_user_id);
    if (!$allowed) {
      json_error('Tu cuenta web no tiene usuarios (Telegram) vinculados. No puedes registrar movimientos.', null, $debug, 403);
    }

    // Opcional: si mandas user_id en el formulario, se respeta
    $telegram_user_id = (int)($_POST['user_id'] ?? 0);
    if ($telegram_user_id <= 0) {
      // Por defecto el primer user_id vinculado
      $telegram_user_id = (int)$allowed[0];
    }

    if (!in_array($telegram_user_id, $allowed, true)) {
      json_error('No tienes permiso para registrar movimientos en ese usuario (Telegram).', null, $debug, 403);
    }

    $kind = $_POST['kind'] ?? 'expense';
    if ($kind !== 'income' && $kind !== 'expense') $kind = 'expense';

    $desc  = trim($_POST['description'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $pm    = trim($_POST['payment_method'] ?? 'cash');
    $category = trim($_POST['category'] ?? 'business');
    $dt    = trim($_POST['item_datetime'] ?? '');
    $company_ruc = trim($_POST['company_ruc'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $company_ruc = ($company_ruc === '' ? null : $company_ruc);
    $invoice_number = ($invoice_number === '' ? null : $invoice_number);

    if ($desc === '' || $price <= 0 || $dt === '') {
      json_error('Datos inválidos (descripción, monto o fecha).', null, $debug, 422);
    }

    // Validación simple del datetime esperado: "YYYY-MM-DD HH:MM" o "YYYY-MM-DDTHH:MM"
    $dt = str_replace('T', ' ', $dt); // Normalizamos a espacio
    if (!preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $dt)) {
      json_error('Formato de fecha inválido. Usa: YYYY-MM-DD HH:MM', null, $debug, 422);
    }

    $id = (int)($_POST['id'] ?? 0);

    // ==========================================================
    // UPDATE
    // ==========================================================
    if ($id > 0) {
        // 1) validar ownership (scope)
        $st = $pdo->prepare("
          SELECT i.id, i.batch_id
          FROM items i
          JOIN batches b ON b.id = i.batch_id
          JOIN web_user_users wuu ON wuu.user_id = b.user_id
          JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
          WHERE i.id = :id
            AND wuu.web_user_id = :wid
            AND b.company_id = :cid
          LIMIT 1
        ");
        $st->execute([':id' => $id, ':wid' => $web_user_id, ':cid' => $company_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
          json_error('No tienes permiso para editar este movimiento o no existe.', null, $debug, 403);
        }

        $batchId = (int)$row['batch_id'];

        $pdo->beginTransaction();
        try {
            // Actualizar item
            $st = $pdo->prepare("
                UPDATE items
                SET description = :d, price = :p, item_datetime = :dt::timestamptz, payment_method = :pm, category = :cat
                WHERE id = :id
            ");
            $st->execute([
                ':d'   => $desc,
                ':p'   => $price,
                ':dt'  => $dt,
                ':pm'  => $pm ?: 'cash',
                ':cat' => $category,
                ':id'  => $id
            ]);

            $st = $pdo->prepare("UPDATE batches SET kind = :kind, company_ruc = :ruc, invoice_number = :inv WHERE id = :bid AND company_id = :cid");
            $st->execute([':kind' => $kind, ':ruc' => $company_ruc, ':inv' => $invoice_number, ':bid' => $batchId, ':cid' => $company_id]);

            $pdo->commit();
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('Error al actualizar movimiento.', $e, $debug, 500);
        }
    }

    // ==========================================================
    // CREATE (si no hay ID)
    // ==========================================================
    $pdo->beginTransaction();
    try {
      // 1) crear batch confirmado
      $st = $pdo->prepare("
        INSERT INTO batches(user_id, friendly_name, status, confirmed_at, kind, company_id, company_ruc, invoice_number)
        VALUES(:uid, :fn, 'confirmed', now(), :kind, :cid, :ruc, :inv)
        RETURNING id
      ");
      $st->execute([
        ':uid' => $telegram_user_id,
        ':fn'  => 'Manual web',
        ':kind'=> $kind,
        ':cid' => $company_id,
        ':ruc' => $company_ruc,
        ':inv' => $invoice_number
      ]);
      $batchId = (int)$st->fetchColumn();

      // 2) insertar item
      $st = $pdo->prepare("
        INSERT INTO items(batch_id, description, price, item_datetime, payment_method, category)
        VALUES(:bid, :d, :p, :dt::timestamptz, :pm, :cat)
      ");
      $st->execute([
        ':bid' => $batchId,
        ':d'   => $desc,
        ':p'   => $price,
        ':dt'  => $dt,
        ':pm'  => $pm ?: 'cash',
        ':cat' => $category
      ]);

      $pdo->commit();
      echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
      exit;

    } catch (Throwable $e) {
      $pdo->rollBack();
      json_error('Error al registrar movimiento.', $e, $debug, 500);
    }
  }

  // ==========================================================
  // DELETE: Borrar item (solo si pertenece a una cuenta vinculada)
  // ==========================================================
  if ($method === 'DELETE') {

    parse_str((string)file_get_contents("php://input"), $del);
    $id = (int)($del['id'] ?? 0);

    if ($id <= 0) {
      json_error('ID inválido.', null, $debug, 422);
    }

    // 1) validar ownership (scope) antes de borrar
    $st = $pdo->prepare("
      SELECT 1
      FROM items i
      JOIN batches b ON b.id = i.batch_id
      JOIN web_user_users wuu ON wuu.user_id = b.user_id
      JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
      WHERE i.id = :id
        AND wuu.web_user_id = :wid
        AND b.company_id = :cid
      LIMIT 1
    ");
    $st->execute([':id' => $id, ':wid' => $web_user_id, ':cid' => $company_id]);
    $ok = (bool)$st->fetchColumn();

    if (!$ok) {
      json_error('No tienes permiso para eliminar este movimiento.', null, $debug, 403);
    }

    // 2) borrar
    $st = $pdo->prepare("DELETE FROM items WHERE id = :id");
    $st->execute([':id' => $id]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  json_error('Error interno.', $e, $debug, 500);
}
