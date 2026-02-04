<?php
// Backward compatible wrapper
// Old endpoint: /platform/ajax-ticket-reply.php
// New unified endpoint: /platform/api/ticket.php?action=reply

$_REQUEST['action'] = 'reply';
$_POST['action'] = 'reply';
require_once __DIR__ . '/../../api/ticket.php';
