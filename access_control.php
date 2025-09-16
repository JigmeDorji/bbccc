<?php

function allowRoles($allowedRoles = []) {
    if (!in_array($_SESSION['role'], $allowedRoles)) {
        header("Location: unauthorized.php");
        exit;
    }
}
?>
