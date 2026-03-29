<?php
require_once "include/config.php";

$menu = null;
try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM menu WHERE id = :id LIMIT 1");
        $stmt->bindParam(":id", $_GET['id'], PDO::PARAM_INT);
        $stmt->execute();
        $menu = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // silent
}

if (!$menu) {
    header('Location: index');
    exit;
}

$eventDate = "Date Not Set";
if (!empty($menu['eventStartDateTime'])) {
    $eventDate = date("d M Y - g:i A", strtotime($menu['eventStartDateTime']));
}
// Social sharing metadata
$_baseUrl      = rtrim(BASE_URL, '/');
$_canonicalUrl = $_baseUrl . '/event_detail?id=' . (int)$_GET['id'];
$_ogImage      = !empty($menu['menuImgUrl'])
    ? (strpos($menu['menuImgUrl'], 'http') === 0
        ? $menu['menuImgUrl']
        : $_baseUrl . '/' . ltrim($menu['menuImgUrl'], '/'))
    : $_baseUrl . '/bbccassests/img/logo/logo5.jpg';
$_ogDesc = !empty($menu['menuDetail'])
    ? mb_substr(strip_tags($menu['menuDetail']), 0, 200)
    : 'View event details at BBCC Canberra.';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title><?= htmlspecialchars($menu['menuName']) ?> — BBCC Canberra</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
    <!-- Open Graph / Facebook -->
    <meta property="og:type"         content="article">
    <meta property="og:url"          content="<?= htmlspecialchars($_canonicalUrl) ?>">
    <meta property="og:title"        content="<?= htmlspecialchars($menu['menuName'] . ' — BBCC Canberra') ?>">
    <meta property="og:description"  content="<?= htmlspecialchars($_ogDesc) ?>">
    <meta property="og:image"        content="<?= htmlspecialchars($_ogImage) ?>">
    <meta property="og:image:width"  content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name"    content="Bhutanese Buddhist &amp; Cultural Center Canberra">
    <meta property="og:locale"       content="en_AU">
    <!-- Twitter Card -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="<?= htmlspecialchars($menu['menuName'] . ' — BBCC Canberra') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($_ogDesc) ?>">
    <meta name="twitter:image"       content="<?= htmlspecialchars($_ogImage) ?>">
    <link rel="canonical"            href="<?= htmlspecialchars($_canonicalUrl) ?>">
    <style>
        .ed-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 48px; align-items: flex-start; }
        .ed-img { width: 100%; height: 420px; object-fit: cover; border-radius: var(--radius-xl); box-shadow: var(--shadow-lg); }
        .ed-meta { display: flex; gap: 20px; font-size: .88rem; color: var(--gray-600); margin-bottom: 24px; flex-wrap: wrap; }
        .ed-meta i { color: var(--gold); margin-right: 6px; }
        .ed-body { font-size: 1.05rem; line-height: 1.9; color: var(--gray-700); }
        .ed-body p { margin-bottom: 16px; }
        @media (max-width: 991px) {
            .ed-grid { grid-template-columns: 1fr; gap: 32px; }
            .ed-img { height: 280px; }
        }
        /* Share Buttons */
        .share-btns { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:20px; }
        .share-label { font-size:.82rem; font-weight:600; color:var(--gray-500); white-space:nowrap; }
        .share-btn {
            display:inline-flex; align-items:center; gap:6px;
            padding:8px 14px; border-radius:var(--radius-md);
            font-size:.82rem; font-weight:600; text-decoration:none;
            cursor:pointer; border:none; transition:opacity .18s;
            font-family:var(--font-body); line-height:1;
        }
        .share-btn--fb   { background:#1877F2; color:#fff; }
        .share-btn--fb:hover { opacity:.85; color:#fff; }
        .share-btn--wa   { background:#25D366; color:#fff; }
        .share-btn--wa:hover { opacity:.85; color:#fff; }
        .share-btn--copy { background:var(--gray-200); color:var(--gray-900); }
        .share-btn--copy:hover { background:var(--gray-300); }
    </style>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<!-- Page Hero -->
<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-calendar-check"></i> Event Detail</h1>
        <p class="bbcc-page-hero__subtitle">View event information and details</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li><a href="events">Events</a></li>
            <li class="sep">/</li>
            <li>Detail</li>
        </ul>
    </div>
</div>

<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="ed-grid">
            <div class="fade-up">
                <img src="<?= htmlspecialchars($menu['menuImgUrl']) ?>" alt="<?= htmlspecialchars($menu['menuName']) ?>" class="ed-img">
            </div>
            <div class="fade-up">
                <h2 style="font-size:2rem;font-weight:800;margin-bottom:12px;"><?= htmlspecialchars($menu['menuName']) ?></h2>
                <div class="ed-meta">
                    <span><i class="fa-regular fa-calendar"></i> <?= $eventDate ?></span>
                    <span><i class="fa-solid fa-user"></i> BBCC</span>
                </div>
                <div class="ed-body">
                    <p><?= nl2br(htmlspecialchars($menu['menuDetail'])) ?></p>
                </div>
                <a href="events" class="bbcc-btn bbcc-btn--outline bbcc-btn--sm" style="margin-top:24px;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Events
                </a>
                <!-- Share Buttons -->
                <div class="share-btns">
                    <span class="share-label"><i class="fa-solid fa-share-nodes"></i> Share:</span>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($_canonicalUrl) ?>"
                       class="share-btn share-btn--fb"
                       onclick="bbccOpenShare(this.href);return false;"
                       title="Share on Facebook"
                       rel="noopener noreferrer">
                        <i class="fa-brands fa-facebook-f"></i> Facebook
                    </a>
                    <a href="https://wa.me/?text=<?= urlencode($menu['menuName'] . ' - ' . $_canonicalUrl) ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="share-btn share-btn--wa"
                       title="Share on WhatsApp">
                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                    </a>
                    <button onclick="bbccCopyLink('<?= htmlspecialchars($_canonicalUrl, ENT_QUOTES) ?>')"
                            class="share-btn share-btn--copy"
                            title="Copy event link">
                        <i class="fa-solid fa-link"></i> <span id="copyLbl">Copy Link</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>
<script>
function bbccOpenShare(url) {
    var w = 600, h = 450;
    var left = ((screen.width  - w) / 2)|0;
    var top  = ((screen.height - h) / 3)|0;
    window.open(url, 'bbccShare', 'width='+w+',height='+h+',left='+left+',top='+top+',resizable=yes,scrollbars=yes');
}
function bbccCopyLink(url) {
    var lbl = document.getElementById('copyLbl');
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(function() {
            lbl.textContent = 'Copied!';
            setTimeout(function(){ lbl.textContent = 'Copy Link'; }, 2000);
        });
    } else {
        var el = document.createElement('textarea');
        el.value = url; el.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(el); el.select();
        try { document.execCommand('copy'); lbl.textContent = 'Copied!'; } catch(e) {}
        document.body.removeChild(el);
        setTimeout(function(){ lbl.textContent = 'Copy Link'; }, 2000);
    }
}
</script>

</body>
</html>






