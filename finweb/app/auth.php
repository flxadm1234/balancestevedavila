<?php
// app/auth.php
declare(strict_types=1);
session_start();

function require_login(): void {
  if (empty($_SESSION['web_user'])) {
    header('Location: /login.php');
    exit;
  }
}

function current_user(): ?array {
  return $_SESSION['web_user'] ?? null;
}

function current_web_user_id(): int {
  return (int)($_SESSION['web_user']['id'] ?? 0);
}

function require_company_selected(): void {
  if (empty($_SESSION['company']) || empty($_SESSION['company']['id'])) {
    header('Location: /login.php');
    exit;
  }
}

function current_company_id(): int {
  return (int)($_SESSION['company']['id'] ?? 0);
}

function current_company_name(): string {
  return (string)($_SESSION['company']['name'] ?? '');
}
