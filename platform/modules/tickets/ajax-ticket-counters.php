<?php
// Backward compatible wrapper
// Old endpoint: /platform/ajax-ticket-counters.php
// New unified endpoint: /platform/api/ticket.php?action=counters

$_REQUEST['action'] = 'counters';
$_GET['action'] = 'counters';
require_once __DIR__ . '/../../api/ticket.php';
