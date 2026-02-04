<?php
// Backward compatible wrapper
// Old endpoint: /platform/ajax-ticket-cardinfo-save.php
// New unified endpoint: /platform/api/ticket.php?action=cardinfo_save

$_REQUEST['action'] = 'cardinfo_save';
$_POST['action'] = 'cardinfo_save';
$_GET['action']  = 'cardinfo_save';
require_once __DIR__ . '/../../api/ticket.php';
