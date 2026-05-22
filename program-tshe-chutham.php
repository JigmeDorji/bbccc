<?php
require_once "include/config.php";
require_once "include/sponsor_program_data.php";
$settings = bbcc_load_sponsor_program_settings($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
$view = bbcc_get_sponsor_program_view_data($settings, 1);
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
        .pr-card { background:#fff; border-radius:var(--radius-xl); padding:34px; box-shadow:var(--shadow-md); border-top:5px solid var(--brand); }
        .pr-media { width:140px; height:140px; border-radius:50%; overflow:hidden; margin:0 auto 20px; border:4px solid rgba(136,27,18,.14); display:flex; align-items:center; justify-content:center; background:#fff8f4; }
        .pr-media img { width:100%; height:100%; object-fit:cover; }
        .pr-media i { font-size:2.2rem; color:var(--brand); }
        .pr-date { font-weight:700; color:var(--gray-700); margin:10px 0 16px; }
        .pr-detail { color:var(--gray-700); line-height:1.9; font-size:1.04rem; }
        .sp-style-split.pr-card { text-align:left !important; border-top:none; border-left:5px solid var(--brand); }
        .sp-style-highlight.pr-card { background:linear-gradient(145deg,#fff,#fff7f2); border-top:none; }
    </style>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>
<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-hand-holding-heart"></i> Program Details</h1>
        <p class="bbcc-page-hero__subtitle">Monthly ritual sponsorship information</p>
        <ul class="bbcc-page-hero__breadcrumb"><li><a href="index">Home</a></li><li class="sep">/</li><li><a href="events">Events</a></li><li class="sep">/</li><li>Program 1</li></ul>
    </div>
</div>
<section class="bbcc-section"><div class="bbcc-container"><div class="pr-card <?= htmlspecialchars($styleClass) ?> fade-up" style="text-align:center;">
    <div class="pr-media"><?php if ($view['image'] !== ''): ?><img src="<?= htmlspecialchars($view['image']) ?>" alt="<?= htmlspecialchars($view['title']) ?>"><?php else: ?><i class="fa-solid <?= htmlspecialchars($view['icon']) ?>"></i><?php endif; ?></div>
    <h2><?= htmlspecialchars($view['title']) ?></h2>
    <p class="pr-date"><strong>Date:</strong> <?= htmlspecialchars($view['date']) ?></p>
    <p class="pr-detail"><?= nl2br(htmlspecialchars($view['detail'])) ?></p>
    <a href="events#monthly-ritual-programs" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm" style="margin-top:18px;">Back to Events</a>
</div></div></section>
<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
