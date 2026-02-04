<?php
// Backward compatible wrapper
// Old endpoint: /platform/ajax-ticket-upload.php
// New unified endpoint: /platform/api/ticket.php?action=upload

$_REQUEST['action'] = 'upload';
$_POST['action'] = 'upload';
require_once __DIR__ . '/../../api/ticket.php';
