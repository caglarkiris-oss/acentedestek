<?php
declare(strict_types=1);

$logFile = __DIR__ . '/php-errors.log';

header('Content-Type: text/plain; charset=utf-8');

if (!file_exists($logFile)) {
  echo "Log yok: php-errors.log bulunamadı.\n";
  exit;
}

echo file_get_contents($logFile);
