<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

require_login();
require_company_selected();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo "ID inválido";
  exit;
}

$pdo = db();
$company_id = current_company_id();
$web_user_id = (int)($_SESSION['web_user']['id'] ?? 0);

$st = $pdo->prepare("
  SELECT
    b.id,
    b.friendly_name,
    b.confirmed_at,
    COALESCE(b.kind,'expense') AS kind,
    b.company_ruc,
    b.invoice_number
  FROM batches b
  JOIN web_user_companies wuc ON wuc.web_user_id = :wid AND wuc.company_id = b.company_id
  WHERE b.id = :id AND b.company_id = :cid AND b.status='confirmed'
");
$st->execute([':id'=>$id, ':cid'=>$company_id, ':wid'=>$web_user_id]);
$batch = $st->fetch(PDO::FETCH_ASSOC);
if (!$batch) {
  http_response_code(404);
  echo "Registro no encontrado";
  exit;
}

$st = $pdo->prepare("SELECT COALESCE(SUM(price),0) AS total FROM items WHERE batch_id = :bid");
$st->execute([':bid'=>$id]);
$total = (float)($st->fetchColumn() ?: 0);

$st = $pdo->prepare("
  SELECT id, description, price, payment_method, item_datetime
  FROM items
  WHERE batch_id = :bid
  ORDER BY item_datetime ASC, id ASC
");
$st->execute([':bid'=>$id]);
$items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$st = $pdo->prepare("
  SELECT input_type, file_path, mime_type, original_file_name, created_at, content_text
  FROM raw_inputs
  WHERE batch_id = :bid
  ORDER BY id DESC
");
$st->execute([':bid'=>$id]);
$raws = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$image = null;
$audio = null;
foreach ($raws as $r) {
  if (!$r['file_path']) continue;
  if (!$image && $r['input_type'] === 'image') $image = $r;
  if (!$audio && $r['input_type'] === 'audio') $audio = $r;
  if ($image && $audio) break;
}

require __DIR__ . '/../app/layout/header.php';
require __DIR__ . '/../app/layout/sidebar.php';
?>

<section class="content pt-3">
  <div class="container-fluid">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Registro #<?= (int)$batch['id'] ?></h3>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-md-6">
            <dl class="row">
              <dt class="col-sm-4">Fecha</dt>
              <dd class="col-sm-8"><?= h((new DateTime($batch['confirmed_at']))->format('Y-m-d H:i')) ?></dd>
              <dt class="col-sm-4">Tipo</dt>
              <dd class="col-sm-8"><?= h(($batch['kind'] === 'income') ? 'Ingreso' : 'Gasto') ?></dd>
              <dt class="col-sm-4">Descripción</dt>
              <dd class="col-sm-8"><?= h($batch['friendly_name'] ?: '-') ?></dd>
              <dt class="col-sm-4">Factura</dt>
              <dd class="col-sm-8"><?= h($batch['invoice_number'] ?: '-') ?></dd>
              <dt class="col-sm-4">RUC</dt>
              <dd class="col-sm-8"><?= h($batch['company_ruc'] ?: '-') ?></dd>
              <dt class="col-sm-4">Total</dt>
              <dd class="col-sm-8"><strong>S/ <?= h(money($total)) ?></strong></dd>
            </dl>
          </div>
          <div class="col-md-6">
            <?php if ($image): ?>
              <div class="mb-3">
                <div><strong>Voucher</strong></div>
                <a href="/<?= h($image['file_path']) ?>" target="_blank">
                  <img src="/<?= h($image['file_path']) ?>" style="max-width:100%;height:auto;border:1px solid #ddd;">
                </a>
              </div>
            <?php endif; ?>

            <?php if ($audio): ?>
              <div class="mb-3">
                <div><strong>Audio</strong></div>
                <audio controls style="width:100%;" src="/<?= h($audio['file_path']) ?>"></audio>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <hr>
        <h5>Items</h5>
        <table class="table table-bordered table-striped">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Descripción</th>
              <th>Monto</th>
              <th>Medio</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td><?= h((new DateTime($it['item_datetime']))->format('Y-m-d H:i')) ?></td>
                <td><?= h($it['description']) ?></td>
                <td>S/ <?= h(money((float)$it['price'])) ?></td>
                <td><?= h(pm_label(($it['payment_method'] ?? 'cash') ?: 'cash')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer">
        <a class="btn btn-secondary" href="/records.php">Volver</a>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../app/layout/footer.php'; ?>

