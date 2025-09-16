<?php
require_once "include/config.php";
require_once "include/auth.php";

// Add TCPDF for PDF export (make sure to install via composer)
// require_once 'vendor/autoload.php';

$message = "";
$reloadPage = false;

$journalEntries = [];
$accountHeads = [];
$companies = [];
$subAccountHeadID = "";

$existing_id = "";
$existing_date = "";
$existing_accountHeadID = "";
$existing_subAccountHeadID = "";
$existing_description = "";
$existing_refNo = "";
$existing_amount = "";
$existing_remarks = "";
$existing_companyID = "";
$existing_projectID = "";

$searchAccountHead = $_GET['search_accountHead'] ?? '';
$searchRefNo = $_GET['search_refNo'] ?? '';
$searchDate = $_GET['search_date'] ?? '';

$searchSubAccountHead = $_GET['search_subAccountHead'] ?? '';
$searchStartDate = $_GET['search_startDate'] ?? '';
$searchEndDate = $_GET['search_endDate'] ?? '';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get logged-in company ID
    $companyID = logged_in_company();
    $projectID = logged_in_project();

    // Load account heads
    $stmt = $pdo->prepare("SELECT id, accountHeadName FROM account_head WHERE companyID = :companyID AND projectID = :projectID ORDER BY accountHeadName");
    $stmt->bindParam(':companyID', $companyID);
    $stmt->bindParam(':projectID', $projectID);
    $stmt->execute();
    $accountHeads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load companies
    $stmt = $pdo->prepare("SELECT companyID, companyName FROM company ORDER BY companyName");
    $stmt->execute();
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$companyID || !$projectID) {
        throw new Exception("No company or project selected.");
    }

    // Load sub-accounts grouped by parent account head
    $subAccountsByHead = [];

    $stmt = $pdo->prepare("SELECT id, subAccountName, accountHeadID FROM account_head_sub WHERE companyID = :companyID AND projectID = :projectID");
    $stmt->execute([':companyID' => $companyID, ':projectID' => $projectID]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($subs as $s) {
        $subAccountsByHead[$s['accountHeadID']][] = $s;
    }

    // Build dynamic query
    $query = "SELECT je.*, ah.accountHeadName, sub.subAccountName, c.companyName FROM journal_entry je
          INNER JOIN account_head ah ON je.accountHeadID = ah.id
          LEFT JOIN account_head_sub sub ON je.subAccountHeadID = sub.id
          INNER JOIN company c ON je.companyID COLLATE utf8mb4_unicode_ci = c.companyID COLLATE utf8mb4_unicode_ci
          WHERE je.companyID = :companyID AND je.projectID = :projectID";

    $params = [
        ':companyID' => $companyID,
        ':projectID' => $projectID
    ];

    if ($searchAccountHead !== '') {
        $query .= " AND je.accountHeadID = :accountHeadID";
        $params[':accountHeadID'] = $searchAccountHead;
    }

    if ($searchSubAccountHead !== '') {
        $query .= " AND je.subAccountHeadID = :subAccountHeadID";
        $params[':subAccountHeadID'] = $searchSubAccountHead;
    }

    if ($searchStartDate !== '' && $searchEndDate !== '') {
        $query .= " AND je.date BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $searchStartDate;
        $params[':endDate'] = $searchEndDate;
    } elseif ($searchStartDate !== '') {
        $query .= " AND je.date >= :startDate";
        $params[':startDate'] = $searchStartDate;
    } elseif ($searchEndDate !== '') {
        $query .= " AND je.date <= :endDate";
        $params[':endDate'] = $searchEndDate;
    }

    $query .= " ORDER BY je.date DESC, je.id DESC";
    $stmt = $pdo->prepare($query);
    foreach ($params as $key => &$val) {
        $stmt->bindParam($key, $val);
    }
    $stmt->execute();
    $journalEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Database Error: " . $e->getMessage();
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="journal_entries_'.date('Y-m-d').'.xls"');

    echo "<table border='1'>";
    echo "<tr>
            <th>Date</th>
            <th>Account Head</th>
            <th>Sub Account Head</th>
            <th>Description</th>
            <th>Ref No</th>
            <th>Amount</th>
            <th>Remarks</th>
          </tr>";

    foreach ($journalEntries as $entry) {
        echo "<tr>
                <td>".htmlspecialchars(date('d-M-Y', strtotime($entry['date'])))."</td>
                <td>".htmlspecialchars($entry['accountHeadName'])."</td>
                <td>".htmlspecialchars($entry['subAccountName'] ?? '-')."</td>
                <td>".htmlspecialchars($entry['description'])."</td>
                <td>".htmlspecialchars($entry['refNo'])."</td>
                <td>".htmlspecialchars(number_format($entry['amount'], 2))."</td>
                <td>".htmlspecialchars($entry['remarks'])."</td>
            </tr>";
    }

    echo "</table>";
    exit;
}

// Handle PDF Export (requires TCPDF library)
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // Uncomment after installing TCPDF
    /*
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Your Company');
    $pdf->SetTitle('Journal Entries');
    $pdf->SetSubject('Journal Entries Report');
    $pdf->AddPage();

    $html = "<h1>Journal Entries</h1>
            <table border='1' cellpadding='4'>
            <tr>
                <th>Date</th>
                <th>Account Head</th>
                <th>Sub Account</th>
                <th>Description</th>
                <th>Ref No</th>
                <th>Amount</th>
                <th>Remarks</th>
            </tr>";

    foreach ($journalEntries as $entry) {
        $html .= "<tr>
                    <td>".htmlspecialchars($entry['date'])."</td>
                    <td>".htmlspecialchars($entry['accountHeadName'])."</td>
                    <td>".htmlspecialchars($entry['subAccountName'] ?? '-')."</td>
                    <td>".htmlspecialchars($entry['description'])."</td>
                    <td>".htmlspecialchars($entry['refNo'])."</td>
                    <td>".htmlspecialchars(number_format($entry['amount'], 2))."</td>
                    <td>".htmlspecialchars($entry['remarks'])."</td>
                  </tr>";
    }

    $html .= "</table>";

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('journal_entries_'.date('Y-m-d').'.pdf', 'D');
    exit;
    */
    $message = "PDF export requires TCPDF library. Please install via composer.";
}

// [Rest of your PHP code for edit/save/delete operations remains the same]

// SweetAlert for messages
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@10'></script>";
echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        if ('$message' !== '') {
            Swal.fire({
                icon: '" . (str_contains($message, 'successfully') ? 'success' : 'error') . "',
                title: '$message',
                showConfirmButton: false,
                timer: 1500
            }).then(() => {
                if ($reloadPage) {
                    window.location.href = 'generateStatement.php';
                }
            });
        }
    });
</script>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Journal Entry</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" />
    <link href="css/sb-admin-2.min.css" rel="stylesheet" />

    <style>
        body {
            font-size: 0.85rem !important;
        }
        table, input, select, label, .form-control, .btn, .card, .accordion {
            font-size: 0.85rem !important;
        }
        h1, h6 {
            font-size: 1rem !important;
        }
        .export-buttons {
            margin-bottom: 15px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        table.dataTable {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
    </style>
</head>
<body id="page-top">
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>
            <div class="container-fluid">

                <h1 class="h3 mb-2 text-gray-800">Transaction</h1>

                <!-- Accordion Wrapper -->
                <div class="accordion" id="journalAccordion">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3" id="headingJournal">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <button class="btn btn-link <?= (!empty($existing_id) ? '' : 'collapsed') ?>"
                                        type="button" data-toggle="collapse" data-target="#collapseJournal"
                                        aria-expanded="<?= (!empty($existing_id) ? 'true' : 'false') ?>"
                                        aria-controls="collapseJournal">
                                    <i class="fas fa-edit"></i> Filter Statement
                                </button>
                            </h6>
                        </div>

                        <div id="collapseJournal" class="collapse <?= (!empty($existing_id) ? 'show' : '') ?>" aria-labelledby="headingJournal" data-parent="#journalAccordion">
                            <div class="card-body">
                                <form method="GET" action="generateStatement.php">
                                    <div class="form-row row">
                                        <div class="form-group col-md-3">
                                            <label>Account Head</label>
                                            <select id="accountHeadSelect" name="search_accountHead" class="form-control">
                                                <option value="">All</option>
                                                <?php foreach ($accountHeads as $head): ?>
                                                    <option value="<?= $head['id'] ?>" <?= ($searchAccountHead == $head['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($head['accountHeadName']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="form-group col-md-3" id="subAccountGroup">
                                            <label>Sub Account</label>
                                            <select id="subAccountSelect" name="search_subAccountHead" class="form-control">
                                                <option value="">All</option>
                                                <?php foreach ($subs as $sub): ?>
                                                    <option value="<?= $sub['id'] ?>" <?= ($searchSubAccountHead == $sub['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($sub['subAccountName']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="form-group col-md-2">
                                            <label>Start Date</label>
                                            <input type="date" name="search_startDate" class="form-control" value="<?= htmlspecialchars($_GET['search_startDate'] ?? '') ?>" />
                                        </div>

                                        <div class="form-group col-md-2">
                                            <label>End Date</label>
                                            <input type="date" name="search_endDate" class="form-control" value="<?= htmlspecialchars($_GET['search_endDate'] ?? '') ?>" />
                                        </div>

                                        <div class="form-group col-md-2 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary mr-2">Search</button>
                                            <a href="generateStatement.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Journal Entry List Table -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Transaction Statement</h6>
                        <div class="export-buttons">
                            <a href="?export=excel&<?= http_build_query($_GET) ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>

                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Account Head</th>
                                    <th>Sub Account Head</th>
                                    <th>Description</th>
                                    <th>Ref No</th>
                                    <th>Amount</th>
                                    <th>Remarks</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($journalEntries)): ?>
                                    <?php foreach ($journalEntries as $entry): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date_format(new DateTime($entry['date']), 'd-M-Y')) ?></td>
                                            <td><?= htmlspecialchars($entry['accountHeadName']) ?></td>
                                            <td><?= htmlspecialchars($entry['subAccountName'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($entry['description']) ?></td>
                                            <td><?= htmlspecialchars($entry['refNo']) ?></td>
                                            <td><?= htmlspecialchars(number_format($entry['amount'], 2)) ?></td>
                                            <td><?= htmlspecialchars($entry['remarks']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center">No journal entries found.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<script>
    const subAccounts = <?= json_encode($subAccountsByHead) ?>;

    document.addEventListener('DOMContentLoaded', () => {
        const accountHeadSelect = document.getElementById('accountHeadSelect');
        const subAccountGroup = document.getElementById('subAccountGroup');
        const subAccountSelect = document.getElementById('subAccountSelect');

        function populateSubAccounts(accountHeadID) {
            subAccountSelect.innerHTML = '<option value="">All</option>';
            const subs = subAccounts[accountHeadID];

            if (subs && subs.length > 0) {
                subAccountGroup.style.display = 'block';

                subs.forEach(sub => {
                    const option = document.createElement('option');
                    option.value = sub.id;
                    option.textContent = sub.subAccountName;
                    subAccountSelect.appendChild(option);
                });
            } else {
                subAccountGroup.style.display = 'none';
            }
        }

        accountHeadSelect.addEventListener('change', function () {
            populateSubAccounts(this.value);
        });

    <?php if (!empty($_GET['edit']) && !empty($subAccountHeadID)): ?>
            populateSubAccounts("<?= $existing_accountHeadID ?>");
            setTimeout(() => {
                document.getElementById('subAccountSelect').value = "<?= $subAccountHeadID ?>";
            }, 100);
        <?php endif; ?>
    });
</script>

</body>
</html>