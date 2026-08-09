<?php
// admin-fee-verification.php — merged into update-payments.php's Verify/Reject/Email actions.
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_login();

if (!is_admin_role()) { header("Location: unauthorized"); exit; }

header("Location: update-payments");
exit;
