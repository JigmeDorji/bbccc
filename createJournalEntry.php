<?php
require_once "include/config.php";
require_once "include/auth.php";

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$message = "";
$reloadPage = false;

$journalEntries = [];
$accountHeads = [];
$companies = [];
$subAccountHeadID = "";

// Initialize existing values
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

// Search parameters
$searchAccountHead = $_GET['search_accountHead'] ?? '';
$searchRefNo = $_GET['search_refNo'] ?? '';
$searchDate = $_GET['search_date'] ?? '';

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
    if ($searchRefNo !== '') {
        $query .= " AND je.refNo LIKE :refNo";
        $params[':refNo'] = "%$searchRefNo%";
    }
    if ($searchDate !== '') {
        $query .= " AND je.date = :searchDate";
        $params[':searchDate'] = $searchDate;
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
    error_log($message); // Log the error for debugging
}

// Edit functionality
if (isset($_GET['edit'])) {
    try {
        $id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM journal_entry WHERE id = :id AND companyID = :companyID AND projectID = :projectID");
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':companyID', $companyID);
        $stmt->bindParam(':projectID', $projectID);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            $existing_id = $data['id'];
            $existing_date = $data['date'];
            $existing_accountHeadID = $data['accountHeadID'];
            $existing_description = $data['description'];
            $existing_refNo = $data['refNo'];
            $existing_amount = $data['amount'];
            $existing_remarks = $data['remarks'];
            $existing_companyID = $data['companyID'];
            $existing_projectID = $data['projectID'];
            $subAccountHeadID = $data['subAccountHeadID'] ?? null;
        }
    } catch (Exception $e) {
        $message = "Edit Error: " . $e->getMessage();
        error_log($message);
    }
}

// Save functionality
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $id = $_POST['id'] ?? null;
        $date = $_POST['date'];
        $accountHeadID = $_POST['accountHeadID'];
        $subAccountHeadID = $_POST['subAccountHeadID'] ?? null; // Make sure this can be null
        $description = trim($_POST['description']);
        $refNo = trim($_POST['refNo']);
        $amount = $_POST['amount'];
        $remarks = trim($_POST['remarks']);
        $companyID = logged_in_company();
        $projectID = logged_in_project();

        // Validate required fields
        if (empty($date) || empty($accountHeadID) || empty($description) || empty($refNo) || empty($amount) || empty($companyID)) {
            throw new Exception("Please fill in all required fields.");
        }

        // Prepare the SQL statement
        if ($id) {
            $stmt = $pdo->prepare("UPDATE journal_entry SET 
                date = :date, 
                accountHeadID = :accountHeadID, 
                description = :description, 
                refNo = :refNo, 
                amount = :amount, 
                remarks = :remarks, 
                companyID = :companyID, 
                projectID = :projectID, 
                subAccountHeadID = :subAccountHeadID  
                WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare("INSERT INTO journal_entry 
                (date, accountHeadID, description, refNo, amount, remarks, companyID, projectID, subAccountHeadID) 
                VALUES 
                (:date, :accountHeadID, :description, :refNo, :amount, :remarks, :companyID, :projectID, :subAccountHeadID)");
        }

        // Bind parameters
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':accountHeadID', $accountHeadID);

        // Handle NULL for subAccountHeadID if empty
        if (empty($subAccountHeadID)) {
            $stmt->bindValue(':subAccountHeadID', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':subAccountHeadID', $subAccountHeadID);
        }

        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':refNo', $refNo);
        $stmt->bindParam(':amount', $amount);
        $stmt->bindParam(':remarks', $remarks);
        $stmt->bindParam(':companyID', $companyID);
        $stmt->bindParam(':projectID', $projectID);

        // Execute the statement
        if (!$stmt->execute()) {
            throw new Exception("Failed to save journal entry: " . implode(" ", $stmt->errorInfo()));
        }

        $message = $id ? "Journal Entry updated successfully." : "Journal Entry added successfully.";
        $reloadPage = true;

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        error_log("Journal Entry Save Error: " . $e->getMessage());
    }
}

// Delete functionality
if (isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM journal_entry WHERE id = :id AND companyID = :companyID AND projectID = :projectID");
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':companyID', $companyID);
        $stmt->bindParam(':projectID', $projectID);
        $stmt->execute();
        $message = "Journal Entry deleted successfully.";
        $reloadPage = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

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
                    window.location.href = 'createJournalEntry.php';
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

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" />
    <!-- DataTables JS -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

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

                <!-- New Transaction Form -->
                <div class="accordion" id="journalAccordion">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3" id="headingJournal">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <button class="btn btn-link <?= (!empty($existing_id) ? '' : 'collapsed') ?>"
                                        type="button" data-toggle="collapse" data-target="#collapseJournal"
                                        aria-expanded="<?= (!empty($existing_id) ? 'true' : 'false') ?>"
                                        aria-controls="collapseJournal">
                                    <i class="fas fa-edit"></i> New Transaction
                                </button>
                            </h6>
                        </div>

                        <div id="collapseJournal" class="collapse <?= (!empty($existing_id) ? 'show' : '') ?>" aria-labelledby="headingJournal" data-parent="#journalAccordion">
                            <div class="card-body">
                                <form id="journalForm" action="createJournalEntry.php<?= isset($_GET['edit']) ? '?edit=' . urlencode($_GET['edit']) : '' ?>" method="POST">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($existing_id) ?>" />

                                    <div class="form-row row">
                                        <div class="form-group col-md-2">
                                            <label>Date</label>
                                            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($existing_date ?: date('Y-m-d')) ?>" required />
                                        </div>

                                        <div class="form-group col-md-4">
                                            <label>Account Head</label>
                                            <select name="accountHeadID" id="accountHeadSelect" class="form-control" required>
                                                <option value="">Select Account Head</option>
                                                <?php foreach ($accountHeads as $head): ?>
                                                    <option value="<?= $head['id'] ?>" <?= ($head['id'] == $existing_accountHeadID) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($head['accountHeadName']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <!-- Sub Account Dropdown (Optional) -->
                                        <div class="form-group col-md-4" id="subAccountGroup" style="display: none;">
                                            <label>Sub Account</label>
                                            <select name="subAccountHeadID" id="subAccountSelect" class="form-control">
                                                <option value="">Select Sub Account</option>
                                            </select>
                                        </div>

                                        <div class="form-group col-md-6">
                                            <label>Description</label>
                                            <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($existing_description) ?>" required />
                                        </div>
                                    </div>

                                    <div class="form-row row">
                                        <div class="form-group col-md-2">
                                            <label>Ref No</label>
                                            <input type="text" name="refNo" class="form-control" value="<?= htmlspecialchars($existing_refNo) ?>" required />
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label>Amount (Nu)</label>
                                            <input type="number" step="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($existing_amount) ?>" required />
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label>Remarks</label>
                                            <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($existing_remarks) ?>" />
                                        </div>
                                    </div>

                                    <div class="form-row mt-3">
                                        <div class="col-md-4">
                                            <button type="submit" class="btn btn-primary">Submit</button>
                                            <a href="createJournalEntry.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Form -->
                <div class="accordion" id="searchAccordion">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3" id="headingSearch">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <button class="btn btn-link <?= (!empty($searchAccountHead) || !empty($searchRefNo) || !empty($searchDate)) ? '' : 'collapsed' ?>"
                                        type="button" data-toggle="collapse" data-target="#collapseSearch"
                                        aria-expanded="<?= (!empty($searchAccountHead) || !empty($searchRefNo) || !empty($searchDate)) ? 'true' : 'false' ?>"
                                        aria-controls="collapseSearch">
                                    <i class="fas fa-search"></i> Search Transaction Entries
                                </button>
                            </h6>
                        </div>

                        <div id="collapseSearch" class="collapse <?= (!empty($searchAccountHead) || !empty($searchRefNo) || !empty($searchDate)) ? 'show' : '' ?>" aria-labelledby="headingSearch" data-parent="#searchAccordion">
                            <div class="card-body">
                                <form id="searchForm" method="GET">
                                    <div class="form-row">
                                        <div class="form-group col-md-3">
                                            <label>Account Head</label>
                                            <select name="search_accountHead" class="form-control">
                                                <option value="">All</option>
                                                <?php foreach ($accountHeads as $ah): ?>
                                                    <option value="<?= $ah['id'] ?>" <?= $searchAccountHead == $ah['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($ah['accountHeadName']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label>Ref No</label>
                                            <input type="text" name="search_refNo" value="<?= htmlspecialchars($searchRefNo) ?>" class="form-control" />
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label>Date</label>
                                            <input type="date" name="search_date" value="<?= htmlspecialchars($searchDate) ?>" class="form-control" />
                                        </div>
                                        <div class="form-group col-md-3 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary mr-2">Search</button>
                                            <a href="createJournalEntry.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Journal Entry List -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Overall Transaction History</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Account Head</th>
                                    <th>Sub Account Head</th>
                                    <th>Description</th>
                                    <th>Ref No</th>
                                    <th>Amount</th>
                                    <th>Remarks</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($journalEntries)): ?>
                                    <?php foreach ($journalEntries as $entry): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date_format(new DateTime($entry['date']), 'd-M-Y')) ?></td>                                            <td><?= htmlspecialchars($entry['accountHeadName']) ?></td>
                                            <td><?= htmlspecialchars($entry['subAccountName'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($entry['description']) ?></td>
                                            <td><?= htmlspecialchars($entry['refNo']) ?></td>
                                            <td><?= htmlspecialchars(number_format($entry['amount'], 2)) ?></td>
                                            <td><?= htmlspecialchars($entry['remarks']) ?></td>
                                            <td>
                                                <a href="createJournalEntry.php?edit=<?= urlencode($entry['id']) ?>" class="btn btn-info btn-sm">Edit</a>
                                                <a href="createJournalEntry.php?delete=<?= urlencode($entry['id']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this journal entry?')">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center">No journal entries found.</td></tr>
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
            subAccountSelect.innerHTML = '<option value="">Select Sub Account</option>';
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

        // If editing, auto-select sub-account
    <?php if (!empty($_GET['edit']) && !empty($subAccountHeadID)): ?>
            populateSubAccounts("<?= $existing_accountHeadID ?>");
            setTimeout(() => {
                document.getElementById('subAccountSelect').value = "<?= $subAccountHeadID ?>";
            }, 100);
        <?php endif; ?>
    });
</script>

<script>
    $(document).ready(function () {
        $('#dataTable').DataTable({
            pageLength: 10,
            lengthMenu: [5, 10, 25, 50, 100],
            ordering: true,
            searching: true,
            info: true,
            paging: true
        });
    });
</script>
</body>
</html>