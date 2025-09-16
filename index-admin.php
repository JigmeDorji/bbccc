<?php
require_once "include/config.php";

$filterCompanyID = $_SESSION['companyID'] ?? null;
$filterProjectID = $_SESSION['projectID'] ?? null;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Step 1: Fetch all account types
    $stmtTypes = $pdo->query("SELECT id, typeName FROM account_head_type");
    $accountTypes = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);

    // Step 2: Fetch all account heads with their types
    $stmtHeads = $pdo->prepare("SELECT id, accountHeadName, accountHeadTypeID FROM account_head WHERE companyID = ? AND projectID = ?");
    $stmtHeads->execute([$filterCompanyID, $filterProjectID]);
    $accountHeads = [];
    while ($row = $stmtHeads->fetch(PDO::FETCH_ASSOC)) {
        $accountHeads[$row['accountHeadTypeID']][] = $row;
    }

    // Step 3: Get total amount for each accountHeadID, filtered by companyID/projectID
    $query = "SELECT accountHeadID, SUM(amount) as total FROM journal_entry WHERE 1=1";
    $params = [];

    if ($filterCompanyID) {
        $query .= " AND companyID = ?";
        $params[] = $filterCompanyID;
    }

    if ($filterProjectID) {
        $query .= " AND projectID = ?";
        $params[] = $filterProjectID;
    }

    $query .= " GROUP BY accountHeadID";
    $stmtAmounts = $pdo->prepare($query);
    $stmtAmounts->execute($params);
    $amounts = $stmtAmounts->fetchAll(PDO::FETCH_KEY_PAIR);

    // Step 4: Build grouped report
    $groupedReport = [];
    $totals = [];
    foreach ($accountTypes as $type) {
        $typeID = $type['id'];
        $typeName = $type['typeName'];
        $groupedReport[$typeName] = [];

        $total = 0;
        if (!empty($accountHeads[$typeID])) {
            foreach ($accountHeads[$typeID] as $head) {
                $amount = isset($amounts[$head['id']]) ? $amounts[$head['id']] : 0;
                $groupedReport[$typeName][] = [
                    'name' => $head['accountHeadName'],
                    'amount' => $amount
                ];
                $total += $amount;
            }
        }
        $totals[strtoupper(trim($typeName))] = $total;
    }

    $income = $totals['INCOME/RECEIPTS'] ?? 0;
    $directExpenses = $totals['DIRECT EXPENSES'] ?? 0;
    $indirectExpenses = $totals['INDIRECT EXPENSES'] ?? 0;
    $totalExpenses = $directExpenses + $indirectExpenses;
    $remaining = $income - $totalExpenses;

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="account_summary_'.date('Y-m-d').'.xls"');

    echo "<table border='1'>";
    echo "<tr>
            <th>Sl.No</th>
            <th>Particulars</th>
            <th>Net Value (Nu.)</th>
            <th>Total (Nu.)</th>
          </tr>";

    $sectionLabel = 'A';
    foreach ($groupedReport as $typeName => $items) {
        $isIncome = strtoupper($typeName) === 'INCOME/RECEIPTS';

        // Section header row
        echo "<tr style='background-color:".($isIncome ? '#cfe2ff' : '#f8d7da')."'>";
        echo "<td>".$sectionLabel."</td>";
        echo "<td colspan='3'><strong>".htmlspecialchars($typeName)."</strong></td>";
        echo "</tr>";

        $i = 1;
        foreach ($items as $entry) {
            echo "<tr>";
            echo "<td>".$i++."</td>";
            echo "<td>".htmlspecialchars($entry['name'])."</td>";
            echo "<td>".number_format($entry['amount'], 2)."</td>";
            echo "<td></td>";
            echo "</tr>";
        }

        // Section total row
        echo "<tr style='background-color:".($isIncome ? '#d1e7dd' : '#fff3cd').";font-weight:bold;'>";
        echo "<td colspan='3' style='text-align:right;'>Total</td>";
        echo "<td>".number_format($totals[$typeName], 2)."</td>";
        echo "</tr>";

        $sectionLabel = chr(ord($sectionLabel) + 1);
    }

    // Remaining balance row
    echo "<tr style='background-color:#e7f1ff;font-weight:bold;'>";
    echo "<td>".$sectionLabel."</td>";
    echo "<td>Remaining Fund Balance</td>";
    echo "<td></td>";
    echo "<td>".number_format($remaining, 2)."</td>";
    echo "</tr>";

    echo "</table>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Admin Rest</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">

<!-- Page Wrapper -->
<div id="wrapper">
    <?php include_once 'include/admin-nav.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include_once 'include/admin-header.php'; ?>

            <div id="content-wrapper" class="d-flex flex-column">
                <div id="content">
                    <?php include_once 'include/admin-header.php'; ?>
                    <div class="container-fluid">
                        <div class="container-fluid">
                            <h1 class="h3 mb-2 text-gray-900">Report</h1>
                            <div class="row">
                                <!-- LEFT COLUMN: Compact Table -->
                                <div class="col-md-7">
                                    <div class="card shadow mb-4">
                                        <div class="card-header py-2 bg-info text-white d-flex justify-content-between align-items-center">
                                            <h6 class="m-0 font-weight-bold">Account Summary</h6>
                                            <a href="?export=excel" class="btn btn-success btn-sm">
                                                <i class="fas fa-file-excel"></i> Export Excel
                                            </a>
                                        </div>
                                        <div class="card-body" style="font-size: 13px; max-height: 700px; overflow-y: auto;">
                                            <table class="table table-sm table-bordered table-hover">
                                                <thead class="thead-light">
                                                <tr>
                                                    <th>Sl.No</th>
                                                    <th>Particulars</th>
                                                    <th>Net Value (Nu.)</th>
                                                    <th>Total (Nu.)</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php
                                                $sectionLabel = 'A';
                                                foreach ($groupedReport as $typeName => $items):
                                                    $isIncome = strtoupper($typeName) === 'INCOME/RECEIPTS';
                                                    $sectionColor = $isIncome ? 'table-primary' : 'table-danger';
                                                    $totalColor = $isIncome ? 'table-success' : 'table-warning';
                                                    ?>
                                                    <tr class="<?= $sectionColor ?>">
                                                        <td><?= $sectionLabel ?></td>
                                                        <td colspan="3"><strong><?= htmlspecialchars($typeName) ?></strong></td>
                                                    </tr>
                                                    <?php
                                                    $i = 1;
                                                    foreach ($items as $entry): ?>
                                                        <tr>
                                                            <td><?= $i++ ?></td>
                                                            <td><?= htmlspecialchars($entry['name']) ?></td>
                                                            <td><?= number_format($entry['amount'], 2) ?></td>
                                                            <td></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr class="<?= $totalColor ?> font-weight-bold">
                                                        <td colspan="3" class="text-right">Total</td>
                                                        <td><?= number_format($totals[$typeName], 2) ?></td>
                                                    </tr>
                                                    <?php $sectionLabel = chr(ord($sectionLabel) + 1); ?>
                                                <?php endforeach; ?>
                                                <!-- Remaining Fund Balance -->
                                                <tr class="table-info font-weight-bold">
                                                    <td><?= $sectionLabel ?></td>
                                                    <td>Remaining Fund Balance</td>
                                                    <td></td>
                                                    <td><?= number_format($remaining, 2) ?></td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- RIGHT COLUMN: Charts stacked -->
                                <div class="col-md-5">
                                    <div class="card shadow mb-4">
                                        <div class="card-header py-2 bg-secondary text-white">
                                            <h6 class="m-0 font-weight-bold">Income vs Expenses</h6>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="incomeExpenseChart" height="200"></canvas>
                                        </div>
                                    </div>

                                    <div class="card shadow mb-4">
                                        <div class="card-header py-2 bg-secondary text-white">
                                            <h6 class="m-0 font-weight-bold">Account Head Details</h6>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="detailedBarChart" height="300"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php include_once 'include/admin-footer.php'; ?>
            </div>
        </div>
        <?php include_once 'include/admin-footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4"></script>
<script>
    const grouped = <?= json_encode($groupedReport); ?>;
    const income = <?= $income ?>;
    const totalExpenses = <?= $totalExpenses ?>;
    const remaining = <?= $remaining ?>;

    const ctx1 = document.getElementById('incomeExpenseChart').getContext('2d');
    new Chart(ctx1, {
        type: 'doughnut',
        data: {
            labels: ['Income', 'Expenses', 'Remaining'],
            datasets: [{
                data: [
                    income,
                    totalExpenses,
                    remaining
                ],
                backgroundColor: ['#28a745', '#dc3545', '#ffc107'],
                borderWidth: 1
            }]
        },
        options: {
            title: {
                display: true,
                text: 'Income vs Expenses vs Balance'
            }
        }
    });

    // Bar Chart: Combined "Expenses" and "Income"
    const labels = [];
    const data = [];
    const colors = [];

    Object.entries(grouped).forEach(([group, heads]) => {
        let labelGroup = group;
        if (group === 'DIRECT EXPENSES' || group === 'INDIRECT EXPENSES') {
            labelGroup = 'EXPENSES';
        }

        heads.forEach(entry => {
            labels.push(entry.name);
            data.push(entry.amount);
            colors.push(labelGroup === 'INCOME/RECEIPTS' ? '#007bff' : '#e83e8c'); // blue for income, pink for expenses
        });
    });

    const ctx2 = document.getElementById('detailedBarChart').getContext('2d');
    new Chart(ctx2, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Amount (Nu.)',
                data: data,
                backgroundColor: colors
            }]
        },
        options: {
            title: {
                display: true,
                text: 'Account Head Details'
            },
            legend: { display: false },
            scales: {
                yAxes: [{
                    ticks: { beginAtZero: true }
                }]
            }
        }
    });
</script>
</body>
</html>