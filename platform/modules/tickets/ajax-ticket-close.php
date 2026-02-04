<?php
// Backward compatible wrapper
// Old endpoint: /platform/ajax-ticket-close.php
// New unified endpoint: /platform/api/ticket.php?action=close

$_REQUEST['action'] = 'close';
$_POST['action'] = 'close';
require_once __DIR__ . '/../../api/ticket.php';
