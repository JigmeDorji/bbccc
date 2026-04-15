# BBCC Role vs Module Access Matrix

This document defines the default module access for each profile in the system.

## Modules

1. `website`
2. `users_access`
3. `enrollment`
4. `classes_attendance`
5. `fees_payments`
6. `communication`
7. `kiosk`
8. `reports_settings`

## Actions

- `view`
- `submit`
- `approve`
- `mark`
- `edit`
- `verify`
- `send`
- `use`
- `export`
- `manage`

## Default Role Access

### Superadmin (`Administrator`, `System_owner`)

- All modules: `*` (full access to all actions)

### Admin (`Admin`, `Company Admin`, `Staff`)

- `website`: `view`, `manage`
- `users_access`: `view`, `manage`
- `enrollment`: `view`, `approve`, `manage`
- `classes_attendance`: `view`, `mark`, `edit`, `manage`
- `fees_payments`: `view`, `verify`, `manage`
- `communication`: `view`, `send`, `manage`
- `kiosk`: `view`, `manage`
- `reports_settings`: `view`, `export`, `manage`

### Website Admin (`Website Admin`)

- `website`: `view`, `manage`
- `communication`: `view`
- `reports_settings`: `view`

### Teacher (`teacher`)

- `website`: `view`
- `enrollment`: `view`
- `classes_attendance`: `view`, `mark`, `edit`
- `fees_payments`: `view`
- `communication`: `view`, `send`
- `kiosk`: `view`
- `reports_settings`: `view`

### Parent (`parent`)

- `website`: `view`
- `enrollment`: `view`, `submit`
- `classes_attendance`: `view`, `mark`
- `fees_payments`: `view`, `submit`
- `communication`: `view`
- `kiosk`: `view`, `use`
- `reports_settings`: `view`

### Patron (`patron`)

- `website`: `view`
- `communication`: `view`
- `reports_settings`: `view`

## Override Model (Superadmin Controls)

Per-user access can be changed using **Module Access**:

- `grant` = allow an action even if default denies
- `revoke` = deny an action even if default allows
- `default` = use role default

Final decision:

`role defaults + grants - revokes`

## Mixed Profile Rule

For users with both Parent + Teacher profiles:

- `active_portal = parent` -> parent defaults apply
- `active_portal = teacher` -> teacher defaults apply

## Enforcement

- Route role checks are enforced in `include/acl.php`
- Module/action checks are enforced in `include/acl.php` using `bbcc_can(...)`
- Module defaults and override resolution live in `include/module_access.php`

## Admin Operation Notes

1. Run migration `009_module_access_overrides.sql` (via `run-migration`) on each environment.
2. Open `module-access` as superadmin to manage per-user overrides.
3. Use `Reset To Defaults` for any user to remove all custom overrides.
