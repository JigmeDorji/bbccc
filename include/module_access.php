<?php
require_once __DIR__ . '/role_helpers.php';

function bbcc_normalize_role_key(string $role): string {
    $r = strtolower(trim($role));
    $r = str_replace(['-', '_'], ' ', $r);
    $r = preg_replace('/\s+/', ' ', $r) ?: $r;
    return $r;
}

function bbcc_is_superadmin_role(?string $role = null): bool {
    $r = bbcc_normalize_role_key((string)($role ?? ($_SESSION['role'] ?? '')));
    return in_array($r, ['administrator', 'system owner', 'system_owner'], true);
}

function bbcc_current_access_profile(): string {
    // Mixed-profile users follow active portal mode.
    $activePortal = strtolower(trim((string)($_SESSION['active_portal'] ?? '')));
    if (in_array($activePortal, ['parent', 'teacher'], true)) {
        return $activePortal;
    }

    $role = bbcc_normalize_role_key((string)($_SESSION['role'] ?? ''));
    if (in_array($role, ['website admin', 'website_admin'], true)) {
        return 'website_admin';
    }
    if (in_array($role, ['parent', 'teacher', 'patron'], true)) {
        return $role;
    }
    if (is_admin_role()) {
        return bbcc_is_superadmin_role($role) ? 'superadmin' : 'admin';
    }

    return 'guest';
}

function bbcc_module_catalog(): array {
    return [
        'website' => [
            'label' => 'Website',
            'actions' => ['view', 'manage'],
        ],
        'users_access' => [
            'label' => 'Users & Access',
            'actions' => ['view', 'manage'],
        ],
        'enrollment' => [
            'label' => 'Enrollment',
            'actions' => ['view', 'submit', 'approve', 'manage'],
        ],
        'classes_attendance' => [
            'label' => 'Classes & Attendance',
            'actions' => ['view', 'mark', 'edit', 'manage'],
        ],
        'fees_payments' => [
            'label' => 'Fees & Payments',
            'actions' => ['view', 'submit', 'verify', 'manage'],
        ],
        'communication' => [
            'label' => 'Communication',
            'actions' => ['view', 'send', 'manage'],
        ],
        'kiosk' => [
            'label' => 'Kiosk',
            'actions' => ['view', 'use', 'manage'],
        ],
        'reports_settings' => [
            'label' => 'Reports & Settings',
            'actions' => ['view', 'export', 'manage'],
        ],
    ];
}

function bbcc_role_default_module_access(): array {
    return [
        'superadmin' => [
            'website' => ['*'],
            'users_access' => ['*'],
            'enrollment' => ['*'],
            'classes_attendance' => ['*'],
            'fees_payments' => ['*'],
            'communication' => ['*'],
            'kiosk' => ['*'],
            'reports_settings' => ['*'],
        ],
        'admin' => [
            'website' => ['view', 'manage'],
            'users_access' => ['view', 'manage'],
            'enrollment' => ['view', 'approve', 'manage'],
            'classes_attendance' => ['view', 'mark', 'edit', 'manage'],
            'fees_payments' => ['view', 'verify', 'manage'],
            'communication' => ['view', 'send', 'manage'],
            'kiosk' => ['view', 'manage'],
            'reports_settings' => ['view', 'export', 'manage'],
        ],
        'website_admin' => [
            'website' => ['view', 'manage'],
            'communication' => ['view'],
            'reports_settings' => ['view'],
        ],
        'teacher' => [
            'website' => ['view'],
            'enrollment' => ['view'],
            'classes_attendance' => ['view', 'mark', 'edit'],
            'fees_payments' => ['view'],
            'communication' => ['view', 'send'],
            'kiosk' => ['view'],
            'reports_settings' => ['view'],
        ],
        'parent' => [
            'website' => ['view'],
            'enrollment' => ['view', 'submit'],
            'classes_attendance' => ['view', 'mark'],
            'fees_payments' => ['view', 'submit'],
            'communication' => ['view'],
            'kiosk' => ['view', 'use'],
            'reports_settings' => ['view'],
        ],
        'patron' => [
            'website' => ['view'],
            'communication' => ['view'],
            'reports_settings' => ['view'],
        ],
    ];
}

function bbcc_module_alias_map(): array {
    return [
        'website settings' => 'website',
        'website_settings' => 'website',
        'users' => 'users_access',
        'access' => 'users_access',
        'user access' => 'users_access',
        'fees' => 'fees_payments',
        'payments' => 'fees_payments',
        'attendance' => 'classes_attendance',
        'classes' => 'classes_attendance',
        'reports' => 'reports_settings',
        'settings' => 'reports_settings',
    ];
}

function bbcc_normalize_module_key(string $module): string {
    $m = strtolower(trim($module));
    $m = str_replace(['-', ' '], '_', $m);
    $aliases = bbcc_module_alias_map();
    if (isset($aliases[$m])) return $aliases[$m];
    if (isset($aliases[str_replace('_', ' ', $m)])) return $aliases[str_replace('_', ' ', $m)];
    return $m;
}

function bbcc_can(string $module, string $action = 'view'): bool {
    if (!isset($_SESSION['userid'])) return false;

    $moduleKey = bbcc_normalize_module_key($module);
    $actionKey = strtolower(trim($action));
    if ($actionKey === '') $actionKey = 'view';

    $catalog = bbcc_module_catalog();
    if (!isset($catalog[$moduleKey])) return false;

    // Keep action constrained to defined catalog actions.
    $moduleActions = $catalog[$moduleKey]['actions'] ?? [];
    if (!in_array($actionKey, $moduleActions, true) && $actionKey !== '*') {
        return false;
    }

    $profile = bbcc_current_access_profile();
    $defaults = bbcc_role_default_module_access();
    $grants = $defaults[$profile] ?? [];
    $isAllowedByDefault = false;
    if (isset($grants[$moduleKey])) {
        $allowedActions = array_map('strtolower', (array)$grants[$moduleKey]);
        $isAllowedByDefault = in_array('*', $allowedActions, true) || in_array($actionKey, $allowedActions, true);
    }

    $override = bbcc_module_override_effect($moduleKey, $actionKey);
    if ($override === 'grant') return true;
    if ($override === 'revoke') return false;
    return $isAllowedByDefault;
}

function bbcc_module_override_effect(string $moduleKey, string $actionKey): ?string {
    $overrides = bbcc_load_user_module_overrides();
    $moduleKey = bbcc_normalize_module_key($moduleKey);
    $actionKey = strtolower(trim($actionKey));
    if ($actionKey === '') $actionKey = 'view';

    if (isset($overrides[$moduleKey][$actionKey])) {
        return $overrides[$moduleKey][$actionKey];
    }
    if (isset($overrides[$moduleKey]['*'])) {
        return $overrides[$moduleKey]['*'];
    }
    return null;
}

function bbcc_load_user_module_overrides(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];

    if (!isset($_SESSION['userid']) && !isset($_SESSION['username'])) {
        return $cache;
    }

    try {
        global $DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME;
        $pdo = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
            $DB_USER,
            $DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $has = $pdo->query("SHOW TABLES LIKE 'user_module_access_overrides'")->fetch(PDO::FETCH_NUM);
        if (!$has) {
            return $cache;
        }

        $uid = trim((string)($_SESSION['userid'] ?? ''));
        $uname = trim((string)($_SESSION['username'] ?? ''));

        if ($uid === '' && $uname === '') {
            return $cache;
        }

        $stmt = $pdo->prepare("
            SELECT module_key, action_key, effect
            FROM user_module_access_overrides
            WHERE is_active = 1
              AND (
                    (:uid <> '' AND user_id = :uid2)
                 OR (:uname <> '' AND LOWER(username) = LOWER(:uname2))
              )
            ORDER BY id DESC
        ");
        $stmt->execute([
            ':uid' => $uid,
            ':uid2' => $uid,
            ':uname' => $uname,
            ':uname2' => $uname,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $moduleKey = bbcc_normalize_module_key((string)($row['module_key'] ?? ''));
            $actionKey = strtolower(trim((string)($row['action_key'] ?? 'view')));
            if ($actionKey === '') $actionKey = 'view';
            $effect = strtolower(trim((string)($row['effect'] ?? '')));
            if (!in_array($effect, ['grant', 'revoke'], true)) {
                continue;
            }
            if (!isset($cache[$moduleKey][$actionKey])) {
                // keep latest by DESC id
                $cache[$moduleKey][$actionKey] = $effect;
            }
        }
    } catch (Throwable $e) {
        // Fail closed to defaults only.
        $cache = [];
    }

    return $cache;
}
