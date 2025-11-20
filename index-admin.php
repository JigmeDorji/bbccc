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

    <title>Admin</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>

<body id="page-top">
    <div id="wrapper">
        <?php include_once 'include/admin-nav.php'; ?>

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <?php include_once 'include/admin-header.php'; ?>

                <div class="container-fluid">
                    <!-- Your main content here -->
                </div>
            </div>

            <!-- Footer (only once) -->
            <?php include_once 'include/admin-footer.php'; ?>
        </div>
    </div>
</body>
</html>