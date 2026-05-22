<?php
require_once "include/config.php";
require_once "include/auth.php";
require_once "include/role_helpers.php";
require_once "include/sponsor_program_data.php";
require_login();
if (!is_admin_role() && !is_website_admin_role()) {
    header("Location: unauthorized");
    exit;
}

$flashMessage = "";
$flashType = "success";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['sponsor_program_detail_flash']) && is_array($_SESSION['sponsor_program_detail_flash'])) {
    $flashMessage = (string)($_SESSION['sponsor_program_detail_flash']['message'] ?? '');
    $flashType = (string)($_SESSION['sponsor_program_detail_flash']['type'] ?? 'success');
    unset($_SESSION['sponsor_program_detail_flash']);
}

$settings = bbcc_default_sponsor_program_settings();

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    $columns = [
        'detail_image_one' => "ALTER TABLE sponsor_settings ADD COLUMN detail_image_one VARCHAR(255) NULL",
        'detail_image_two' => "ALTER TABLE sponsor_settings ADD COLUMN detail_image_two VARCHAR(255) NULL",
        'detail_image_three' => "ALTER TABLE sponsor_settings ADD COLUMN detail_image_three VARCHAR(255) NULL",
        'detail_one' => "ALTER TABLE sponsor_settings ADD COLUMN detail_one TEXT NULL",
        'detail_two' => "ALTER TABLE sponsor_settings ADD COLUMN detail_two TEXT NULL",
        'detail_three' => "ALTER TABLE sponsor_settings ADD COLUMN detail_three TEXT NULL",
        'style_one' => "ALTER TABLE sponsor_settings ADD COLUMN style_one VARCHAR(30) NULL",
        'style_two' => "ALTER TABLE sponsor_settings ADD COLUMN style_two VARCHAR(30) NULL",
        'style_three' => "ALTER TABLE sponsor_settings ADD COLUMN style_three VARCHAR(30) NULL",
    ];
    foreach ($columns as $col => $sql) {
        $chk = $pdo->query("SHOW COLUMNS FROM sponsor_settings LIKE " . $pdo->quote($col));
        if (!$chk || !$chk->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($sql);
        }
    }

    $settings = bbcc_load_sponsor_program_settings($DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD);
} catch (Exception $e) {
    $flashMessage = $e->getMessage();
    $flashType = 'error';
}

$rows = [
    [
        'id' => 1,
        'label' => 'Program 1',
        'title' => (string)$settings['texts']['title_one'],
        'date' => (string)$settings['texts']['date_one'],
        'detail' => (string)$settings['texts']['detail_one'],
        'style' => (string)$settings['styles']['style_one'],
        'image' => (string)$settings['images']['detail_image_one'],
    ],
    [
        'id' => 2,
        'label' => 'Program 2',
        'title' => (string)$settings['texts']['title_two'],
        'date' => (string)$settings['texts']['date_two'],
        'detail' => (string)$settings['texts']['detail_two'],
        'style' => (string)$settings['styles']['style_two'],
        'image' => (string)$settings['images']['detail_image_two'],
    ],
    [
        'id' => 3,
        'label' => 'Program 3',
        'title' => (string)$settings['texts']['title_three'],
        'date' => (string)$settings['texts']['date_three'],
        'detail' => (string)$settings['texts']['detail_three'],
        'style' => (string)$settings['styles']['style_three'],
        'image' => (string)$settings['images']['detail_image_three'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Setup Program Details</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .setup-grid-card {
            border: 0;
            border-radius: 16px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 0 10px 26px rgba(17, 24, 39, 0.08);
            transition: transform .22s ease, box-shadow .22s ease;
            overflow: hidden;
        }
        .setup-grid-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 34px rgba(17, 24, 39, 0.14);
        }
        .setup-grid-card .card-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 1.05rem 1rem;
        }
        .setup-grid-card__left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .setup-grid-card__icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1rem;
            flex: 0 0 44px;
        }
        .setup-grid-card__meta {
            display: block;
            margin: 0;
            font-size: .68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: #6b7280;
        }
        .setup-grid-card__title {
            margin: 2px 0 0;
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
            line-height: 1.2;
        }
        .setup-grid-card .btn {
            border-radius: 999px;
            font-weight: 700;
            padding: .4rem .95rem;
            box-shadow: none !important;
        }
        .bg-about { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
        .bg-tara { background: linear-gradient(135deg, #059669, #047857); }
        .bg-monthly { background: linear-gradient(135deg, #d97706, #b45309); }
        .bg-banner { background: linear-gradient(135deg, #0ea5e9, #0369a1); }
        .btn-about { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
        .btn-tara { background:#047857; border-color:#047857; color:#fff; }
        .btn-monthly { background:#b45309; border-color:#b45309; color:#fff; }
        .btn-banner { background:#0369a1; border-color:#0369a1; color:#fff; }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
<?php include_once 'include/admin-nav.php'; ?>
<div id="content-wrapper" class="d-flex flex-column"><div id="content">
<?php include_once 'include/admin-header.php'; ?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4"><h1 class="h3 mb-0 text-gray-800">Setup Program Details</h1></div>

    <div class="row mb-4">
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="card setup-grid-card h-100">
                <div class="card-body">
                    <div class="setup-grid-card__left">
                        <div class="setup-grid-card__icon bg-about"><i class="fas fa-circle-info"></i></div>
                        <div>
                            <span class="setup-grid-card__meta">Quick Setup</span>
                            <p class="setup-grid-card__title">Setup About Page</p>
                        </div>
                    </div>
                    <a href="aboutPageSetup" class="btn btn-about btn-sm">
                        <i class="fas fa-edit mr-1"></i> Open
                    </a>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="card setup-grid-card h-100">
                <div class="card-body">
                    <div class="setup-grid-card__left">
                        <div class="setup-grid-card__icon bg-tara"><i class="fas fa-om"></i></div>
                        <div>
                            <span class="setup-grid-card__meta">Quick Setup</span>
                            <p class="setup-grid-card__title">Setup Tara Content</p>
                        </div>
                    </div>
                    <a href="taraContentSetup" class="btn btn-tara btn-sm">
                        <i class="fas fa-edit mr-1"></i> Open
                    </a>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="card setup-grid-card h-100">
                <div class="card-body">
                    <div class="setup-grid-card__left">
                        <div class="setup-grid-card__icon bg-monthly"><i class="fas fa-calendar-days"></i></div>
                        <div>
                            <span class="setup-grid-card__meta">Quick Setup</span>
                            <p class="setup-grid-card__title">Setup Montly Events</p>
                        </div>
                    </div>
                    <a href="sponsorSetup" class="btn btn-monthly btn-sm">
                        <i class="fas fa-edit mr-1"></i> Open
                    </a>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="card setup-grid-card h-100">
                <div class="card-body">
                    <div class="setup-grid-card__left">
                        <div class="setup-grid-card__icon bg-banner"><i class="fas fa-images"></i></div>
                        <div>
                            <span class="setup-grid-card__meta">Quick Setup</span>
                            <p class="setup-grid-card__title">Setup Banner</p>
                        </div>
                    </div>
                    <a href="bannerSetup" class="btn btn-banner btn-sm">
                        <i class="fas fa-edit mr-1"></i> Open
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Program Detail List</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" width="100%">
                    <thead class="thead-light">
                        <tr>
                            <th style="width:100px;">Program</th>
                            <th style="min-width:220px;">Title</th>
                            <th style="min-width:180px;">Date</th>
                            <th>Detail Content</th>
                            <th style="width:120px;">Style</th>
                            <th style="width:120px;">Image</th>
                            <th style="width:120px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['label']) ?></strong></td>
                            <td><?= htmlspecialchars($r['title']) ?></td>
                            <td><?= htmlspecialchars($r['date']) ?></td>
                            <td><?= htmlspecialchars(mb_strimwidth($r['detail'], 0, 120, '...')) ?></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars(ucfirst($r['style'])) ?></span></td>
                            <td>
                                <?php if ($r['image'] !== ''): ?>
                                    <img src="<?= htmlspecialchars($r['image']) ?>" alt="detail image" style="width:52px;height:52px;border-radius:8px;object-fit:cover;">
                                <?php else: ?>
                                    <span class="text-muted">No image</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="sponsorProgramDetailEdit?program=<?= (int)$r['id'] ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div><?php include_once 'include/admin-footer.php'; ?></div></div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
<script><?php if ($flashMessage): ?>Swal.fire({ icon:'<?= $flashType ?>', title:'<?= addslashes($flashMessage) ?>', showConfirmButton:false, timer:1800 });<?php endif; ?></script>
</body>
</html>
