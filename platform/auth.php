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
        redirect(route_url('login'));
    }
    
    $role = (string)($_SESSION['role'] ?? '');
    $allowed = ['SUPERADMIN', 'ACENTE_YETKILISI', 'TALI_ACENTE_YETKILISI', 'PERSONEL'];
    
    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        exit('Mutabakat modulune erisim yetkiniz yok.');
    }
}

/**
 * Kullanıcının görebileceği agency_id listesi.
 * - Ana acente: kendi + alt tali acenteler (agencies.parent_id = main)
 * - Tali acente: sadece kendi
 */
function agency_scope_ids(mysqli $conn): array {
    $u = current_user();
    $agencyId = (int)($u['agency_id'] ?? 0);
    if ($agencyId <= 0) return [];

    if (!is_main_role()) {
        return [$agencyId];
    }

    $ids = [$agencyId];
    $stmt = $conn->prepare('SELECT id FROM agencies WHERE parent_id = ? AND is_active = 1');
    if ($stmt) {
        $stmt->bind_param('i', $agencyId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int)$row['id'];
            }
        }
        $stmt->close();
    }
    $ids = array_values(array_unique(array_filter($ids, fn($x) => $x > 0)));
    return $ids;
}
