<?php
// Backward compatible wrapper
// Old endpoint: /platform/ajax-ticket-workflow-update.php
// New unified endpoint: /platform/api/ticket.php?action=workflow_update

$_REQUEST['action'] = 'workflow_update';
$_POST['action'] = 'workflow_update';
$_GET['action']  = 'workflow_update';
require_once __DIR__ . '/../../api/ticket.php';
