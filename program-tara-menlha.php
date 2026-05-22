<?php
require_once "include/config.php";
require_once "include/sponsor_program_data.php";
$settings = bbcc_load_sponsor_program_settings($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
$view = bbcc_get_sponsor_program_view_data($settings, 3);
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
        .pr-highlight { background:linear-gradient(145deg,#fff,#fff7f2); border:1px solid #f3e0d5; border-radius:var(--radius-xl); padding:34px; box-shadow:var(--shadow-md); position:relative; overflow:hidden; }
        .pr-highlight::after { content:""; position:absolute; top:-70px; right:-50px; width:220px; height:220px; border-radius:50%; background:rgba(136,27,18,.07); }
        .pr-row { display:grid; grid-template-columns:110px 1fr; gap:20px; align-items:start; position:relative; z-index:1; }
        .pr-media { width:110px; height:110px; border-radius:16px; overflow:hidden; border:3px solid rgba(136,27,18,.14); display:flex; align-items:center; justify-content:center; background:#fff; }
        .pr-media img { width:100%; height:100%; object-fit:cover; }
        .pr-media i { font-size:2rem; color:var(--brand); }
        .pr-meta { margin:10px 0 14px; color:var(--gray-700); font-weight:700; }
        .pr-body { color:var(--gray-700); line-height:1.9; }
        .sp-style-classic.pr-highlight { background:#fff; border-top:5px solid var(--brand); }
        .sp-style-split.pr-highlight { background:linear-gradient(145deg,#fff,#fff7f2); }
        @media (max-width: 767px){ .pr-row{grid-template-columns:1fr;} .pr-media{margin-bottom:4px;} }
    </style>
</head>
<body class="bbcc-public">
<?php include_once 'include/nav.php'; ?>
<div class="bbcc-page-hero"><div class="bbcc-page-hero__content">
    <h1><i class="fa-solid fa-hand-holding-heart"></i> Program Details</h1>
    <p class="bbcc-page-hero__subtitle">Monthly ritual sponsorship information</p>
    <ul class="bbcc-page-hero__breadcrumb"><li><a href="index">Home</a></li><li class="sep">/</li><li><a href="events">Events</a></li><li class="sep">/</li><li>Program 3</li></ul>
</div></div>
<section class="bbcc-section"><div class="bbcc-container"><div class="pr-highlight <?= htmlspecialchars($styleClass) ?> fade-up">
    <div class="pr-row">
        <div class="pr-media"><?php if ($view['image'] !== ''): ?><img src="<?= htmlspecialchars($view['image']) ?>" alt="<?= htmlspecialchars($view['title']) ?>"><?php else: ?><i class="fa-solid <?= htmlspecialchars($view['icon']) ?>"></i><?php endif; ?></div>
        <div>
            <h2><?= htmlspecialchars($view['title']) ?></h2>
            <p class="pr-meta"><strong>Date:</strong> <?= htmlspecialchars($view['date']) ?></p>
            <p class="pr-body"><?= nl2br(htmlspecialchars($view['detail'])) ?></p>
            <a href="events#monthly-ritual-programs" class="bbcc-btn bbcc-btn--primary bbcc-btn--sm" style="margin-top:18px;">Back to Events</a>
        </div>
    </div>
</div></div></section>
<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
</body>
</html>
