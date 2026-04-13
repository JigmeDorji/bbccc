<?php
$publicCssPath = __DIR__ . '/../css/bbcc-public.css';
$formsCssPath = __DIR__ . '/../css/bbcc-forms.css';

$publicCssVer = file_exists($publicCssPath) ? (string)filemtime($publicCssPath) : (string)time();
$formsCssVer = file_exists($formsCssPath) ? (string)filemtime($formsCssPath) : (string)time();
?>
<!-- Favicon -->
<link rel="icon" type="image/jpeg" href="bbccassests/img/logo/logo5.jpg">
<link rel="apple-touch-icon" href="bbccassests/img/logo/logo5.jpg">

<!-- Font Awesome 6 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<!-- BBCC Modern Design System -->
<link rel="stylesheet" href="css/bbcc-public.css?v=<?= htmlspecialchars($publicCssVer, ENT_QUOTES, 'UTF-8') ?>">

<!-- BBCC Admin Form Styles (shared) -->
<link rel="stylesheet" href="css/bbcc-forms.css?v=<?= htmlspecialchars($formsCssVer, ENT_QUOTES, 'UTF-8') ?>">

