<?php
// app/config.php
declare(strict_types=1);

return [
  'db' => [
    'dsn'  => getenv('WEB_DB_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=balancesteve',
    'user' => getenv('WEB_DB_USER') ?: 'postgres',
    'pass' => getenv('WEB_DB_PASS') ?: '',
  ],
  'app' => [
    'name' => 'Sistemas Web',
    'timezone' => 'America/Lima', // Iquitos = America/Lima
  ]
];
