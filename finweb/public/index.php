<?php
declare(strict_types=1);
session_start();
if (!empty($_SESSION['web_user'])) {
  header('Location: /dashboard.php');
} else {
  header('Location: /login.php');
}
exit;
