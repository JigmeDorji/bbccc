<?php
require_once "include/config.php";
require_once "include/sponsor_program_data.php";
$settings = bbcc_load_sponsor_program_settings($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
$view = bbcc_get_sponsor_program_view_data($settings, 2);
$styleClass = 'sp-style-' . $view['style'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($view['title']) ?> — BBCC</title>
    <?php include_once 'include/global_css.php'; ?>
    <style>
        .pr-split { display:grid; grid-template-columns:1fr 1.2fr; gap:34px; align-items:center; background:#fff; border-radius:var(--radius-xl); box-shadow:var(--shadow-md); overflow:hidden; }
        .pr-left { min-height:320px; background:linear-gradient(160deg,#fff3ec,#fde8dc); display:flex; align-items:center; justify-content:center; }
        .pr-left img { width:100%; height:100%; object-fit:cover; }
        .pr-left i { font-size:3rem; color:var(--brand); }
        .pr-right { padding:34px; }
        .pr-meta { margin:10px 0 16px; color:var(--gray-700); font-weight:700; }
        .pr-body { color:var(--gray-700); line-height:1.9; }
        .sp-style-classic.pr-split { border-top:5px solid var(--brand); }
        .sp-style-highlight.pr-split { background:linear-gradient(145deg,#fff,#fff7f2); }
        @media (max-width: 991px){ .pr-split{grid-template-columns:1fr;} .pr-left{min-height:240px;} }
    </style>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>
<div class="bbcc-page-hero"><div class="bbcc-page-hero__content">
    <h1><i class="fa-solid fa-hand-holding-heart"></i> Program Details</h1>
    <p class="bbcc-page-hero__subtitle">Monthly ritual sponsorship information</p>
    <ul class="bbcc-page-hero__breadcrumb"><li><a href="index">Home</a></li><li class="sep">/</li><li><a href="events">Events</a></li><li class="sep">/</li><li>Program 2</li></ul>
</div></div>
<section class="bbcc-section"><div class="bbcc-container"><div class="pr-split <?= htmlspecialchars($styleClass) ?> fade-up">
    <div class="pr-left"><?php if ($view['image'] !== ''): ?><img src="<?= htmlspecialchars($view['image']) ?>" alt="<?= htmlspecialchars($view['title']) ?>"><?php else: ?><i class="fa-solid <?= htmlspecialchars($view['icon']) ?>"></i><?php endif; ?></div>
    <div class="pr-right">
        <h2><?= htmlspecialchars($view['title']) ?></h2>
        <p class="pr-meta"><strong>Date:</strong> <?= htmlspecialchars($view['date']) ?></p>
        <p class="pr-body"><?= nl2br(htmlspecialchars($view['detail'])) ?></p>
        <a href="events#monthly-ritual-programs" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm" style="margin-top:18px;">Back to Events</a>
    </div>
</div></div></section>
<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
