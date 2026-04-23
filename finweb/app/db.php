<?php
// app/db.php
declare(strict_types=1);

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $cfg = require __DIR__ . '/config.php';
  date_default_timezone_set($cfg['app']['timezone']);

  $pdo = new PDO(
    $cfg['db']['dsn'],
    $cfg['db']['user'],
    $cfg['db']['pass'],
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );

  // Asegura TZ por conexión (evita “Europe/Berlin”)
  $pdo->exec("SET TIME ZONE 'America/Lima'");

  return $pdo;
}
