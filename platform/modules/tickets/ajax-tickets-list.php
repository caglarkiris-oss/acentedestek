<?php
// Backward compatible wrapper
// Old endpoint: /platform/ajax-tickets-list.php
// New unified endpoint: /platform/api/ticket.php?action=list

$_REQUEST['action'] = 'list';
$_GET['action'] = 'list';
require_once __DIR__ . '/../../api/ticket.php';
