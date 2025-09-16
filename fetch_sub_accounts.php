<?php
require_once "include/config.php";

if (isset($_GET['accountHeadId'])) {
    $accountHeadId = $_GET['accountHeadId'];
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT id, subAccountName FROM account_head_sub WHERE id = ?");
    $stmt->execute([$accountHeadId]);
    $subAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo '<option value="">All</option>';
    foreach ($subAccounts as $sub) {
        echo '<option value="' . htmlspecialchars($sub['id']) . '">' . htmlspecialchars($sub['subAccountHeadName']) . '</option>';
    }
}
