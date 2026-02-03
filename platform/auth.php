<?php
// /platform/auth.php
// Mutabakat V2 - Authentication helpers

require_once __DIR__ . '/helpers.php';

// Session başlat
ensure_session();

/**
 * Login gerektiren sayfalarda çağrılır
 */
function check_auth(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Kullanıcı bilgilerini döndürür
 */
function current_user(): array {
    return [
        'id'        => (int)($_SESSION['user_id'] ?? 0),
        'agency_id' => (int)($_SESSION['agency_id'] ?? 0),
        'role'      => (string)($_SESSION['role'] ?? ''),
        'name'      => (string)($_SESSION['name'] ?? ''),
    ];
}

/**
 * MAIN acente mi?
 */
function is_main_role(): bool {
    $role = (string)($_SESSION['role'] ?? '');
    return in_array($role, ['SUPERADMIN', 'ACENTE_YETKILISI'], true);
}

/**
 * TALI acente mi?
 */
function is_tali_role(): bool {
    $role = (string)($_SESSION['role'] ?? '');
    return in_array($role, ['TALI_ACENTE_YETKILISI', 'PERSONEL'], true) && !is_main_role();
}

/**
 * Mutabakat sayfalarına erişim kontrolü
 */
function require_mutabakat_access(): void {
    ensure_session();
    
    if (empty($_SESSION['user_id'])) {
        redirect(base_url('login.php'));
    }
    
    $role = (string)($_SESSION['role'] ?? '');
    $allowed = ['SUPERADMIN', 'ACENTE_YETKILISI', 'TALI_ACENTE_YETKILISI', 'PERSONEL'];
    
    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        exit('Mutabakat modulune erisim yetkiniz yok.');
    }
}
