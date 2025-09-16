<?php
// unauthorized.php
require_once "include/config.php";
require_once "include/auth.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unauthorized Access</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/fontawesome.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8d7da;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            color: #721c24;
        }
        .error-box {
            text-align: center;
            border: 1px solid #f5c6cb;
            background-color: #f8d7da;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .btn-back {
            margin-top: 20px;
        }
    </style>
</head>
<body>
<div class="error-box">
    <h1><i class="fas fa-exclamation-triangle"></i> 403 - Unauthorized</h1>
    <p>You do not have permission to access this page.</p>
    <a href="index-admin.php" class="btn btn-danger btn-back">Back to Dashboard</a>
</div>
</body>
</html>
