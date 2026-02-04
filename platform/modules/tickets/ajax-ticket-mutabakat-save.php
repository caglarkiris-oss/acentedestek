<?php
// Backward compatible wrapper
// Old endpoint: /platform/ajax-ticket-mutabakat-save.php
// New unified endpoint: /platform/api/ticket.php?action=mutabakat_save

$_REQUEST['action'] = 'mutabakat_save';
$_POST['action'] = 'mutabakat_save';
$_GET['action']  = 'mutabakat_save';
require_once __DIR__ . '/../../api/ticket.php';
