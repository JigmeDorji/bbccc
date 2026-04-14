<?php
require_once "include/config.php";

$aboutData = [];
$teamData = [];
$boardMembers = [];
$executiveMembers = [];

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME . ";charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $stmt = $pdo->prepare("SELECT * FROM about ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $aboutData = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM ourteam");
    $stmt->execute();
    $teamData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($teamData as $member) {
        $type = strtolower(trim((string)($member['member_type'] ?? 'executive')));
        if ($type === 'board') {
            $boardMembers[] = $member;
        } else {
            $executiveMembers[] = $member;
        }
    }

} catch (Exception $e) {
    // silent
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>About Us — Buddhist Temple Canberra | BBCC</title>
    <meta name="description" content="Learn about BBCC, a Bhutanese Buddhist temple in Canberra dedicated to spiritual guidance, Dzongkha language and cultural preservation.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include_once 'include/global_css.php'; ?>
</head>
<body class="bbcc-public">

<?php include_once 'include/nav.php'; ?>

<!-- Page Hero -->
<div class="bbcc-page-hero">
    <div class="bbcc-page-hero__content">
        <h1><i class="fa-solid fa-circle-info"></i> About Us</h1>
        <p class="bbcc-page-hero__subtitle">Learn about our mission, vision and community</p>
        <ul class="bbcc-page-hero__breadcrumb">
            <li><a href="index">Home</a></li>
            <li class="sep">/</li>
            <li>About Us</li>
        </ul>
    </div>
</div>

<!-- About Content -->
<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="bbcc-about">
            <div class="bbcc-about__image fade-up">
                <?php if (!empty($aboutData['imgUrl'])): ?>
                <img src="<?= htmlspecialchars($aboutData['imgUrl']) ?>" alt="Bhutanese Buddhist and Cultural Centre">
                <?php else: ?>
                <img src="bbccassests/img/about/Gemini_Generated_Image_eenj50eenj50eenj.png" alt="BBCC">
                <?php endif; ?>
            </div>
            <div class="bbcc-about__content fade-up">
                <span class="section-badge"><i class="fa-solid fa-circle-info"></i> Our Story</span>
                <h2>A Glimpse into <span>Our Journey</span></h2>
                <?php if (!empty($aboutData['description'])): ?>
                <p><?= nl2br(htmlspecialchars($aboutData['description'])) ?></p>
                <?php else: ?>
                <p>The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to providing spiritual guidance, cultural preservation, and pastoral support to Bhutanese residents in Canberra.</p>
                <?php endif; ?>

                <div class="vision-mission">
                    <div class="vm-card">
                        <h4><i class="fa-solid fa-eye"></i> Our Vision</h4>
                        <p>To provide spiritual and pastoral services to all Bhutanese and other interested devotees, while preserving and promoting Bhutanese identity and culture.</p>
                    </div>
                    <div class="vm-card">
                        <h4><i class="fa-solid fa-bullseye"></i> Our Mission</h4>
                        <p>To build a vibrant community center and temple in Canberra, fostering unity, harmony, and providing a place of solace and guidance for all.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Team Section: Board Members -->
<section class="bbcc-section bbcc-section--gray">
    <div class="bbcc-container">
        <div class="section-header fade-up">
            <span class="section-badge"><i class="fa-solid fa-users"></i> Our Team</span>
            <h2>Board <span>Members</span></h2>
            <p>The BBCC board members provide governance and strategic direction to strengthen spiritual, cultural, and community outcomes.</p>
        </div>
        <?php if (!empty($boardMembers)): ?>
            <div class="bbcc-team-grid">
                <?php foreach ($boardMembers as $member): ?>
                <div class="bbcc-team-card fade-up">
                    <div class="bbcc-team-card__photo">
                        <img src="<?= htmlspecialchars($member['imgUrl']) ?>" alt="<?= htmlspecialchars($member['Name']) ?>">
                    </div>
                    <h4 class="bbcc-team-card__name"><?= htmlspecialchars($member['Name']) ?></h4>
                    <span class="bbcc-team-card__role"><?= htmlspecialchars($member['designation']) ?></span>
                    <div class="bbcc-team-card__accent"></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="fade-up" style="max-width:760px;margin:0 auto;text-align:center;background:#fff;border:1px dashed #d1d5db;border-radius:14px;padding:22px;color:#6b7280;">
                Board members will be displayed here.
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Team Section: Executive Members -->
<section class="bbcc-section">
    <div class="bbcc-container">
        <div class="section-header fade-up">
            <span class="section-badge"><i class="fa-solid fa-users"></i> Our Team</span>
            <h2>Executive <span>Members</span></h2>
            <p>The BBCC executive members are committed to delivering spiritual services, cultural programs, and community support.</p>
        </div>
        <?php if (!empty($executiveMembers)): ?>
            <div class="bbcc-team-grid">
                <?php foreach ($executiveMembers as $member): ?>
                <div class="bbcc-team-card fade-up">
                    <div class="bbcc-team-card__photo">
                        <img src="<?= htmlspecialchars($member['imgUrl']) ?>" alt="<?= htmlspecialchars($member['Name']) ?>">
                    </div>
                    <h4 class="bbcc-team-card__name"><?= htmlspecialchars($member['Name']) ?></h4>
                    <span class="bbcc-team-card__role"><?= htmlspecialchars($member['designation']) ?></span>
                    <div class="bbcc-team-card__accent"></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="fade-up" style="max-width:760px;margin:0 auto;text-align:center;background:#fff;border:1px dashed #d1d5db;border-radius:14px;padding:22px;color:#6b7280;">
                Executive members will be displayed here.
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- CTA -->
<section class="bbcc-cta">
    <div class="bbcc-container" style="position:relative;z-index:1;">
        <div class="bbcc-cta-grid">
            <div class="bbcc-cta-col">
                <h2>Register for Dzongkha class</h2>
                <p>Register your children for Dzongkha classes and stay connected with culture, language, and spiritual values in Canberra.</p>
                <div class="bbcc-cta-actions">
                    <a href="parentAccountSetup" class="bbcc-btn bbcc-btn--white">
                        <i class="fa-solid fa-user-plus"></i> Register Now
                    </a>
                    <a href="contact-us" class="bbcc-btn bbcc-btn--outline" style="border-color:rgba(255,255,255,.4);color:#fff;">
                        Contact Us <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="bbcc-cta-col bbcc-cta-col--patron">
                <h3><i class="fa-solid fa-hands-holding-circle"></i> Become a Patron</h3>
                <p>Support the Bhutanese Buddhist and Cultural Centre Canberra as a patron and help sustain spiritual and cultural activities for our community.</p>
                <a href="patronRegistration" class="bbcc-btn bbcc-btn--white">
                    <i class="fa-solid fa-heart"></i> Join as Patron
                </a>
            </div>
        </div>
    </div>
</section>

<?php include_once 'include/footer.php'; ?>
<?php include_once 'include/global_js.php'; ?>

</body>
</html>
