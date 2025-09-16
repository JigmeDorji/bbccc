<?php
require_once "include/config.php";
require_once "access_control.php";

// Only for system owner
allowRoles(['System_owner']);



$message = "";
$orders = [];
$reloadPage = false;

try {
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch existing data from the orders table along with menu items
    $stmt = $pdo->prepare("SELECT orders.*, GROUP_CONCAT(order_items.menu_name, ' (', order_items.quantity, ') ' SEPARATOR ', ') AS menu_items 
                            FROM orders 
                            LEFT JOIN order_items ON orders.id = order_items.order_id 
                            GROUP BY orders.id
                            ORDER BY orders.order_date DESC"); // Order by order date in descending order
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

// Mark Order as Viewed
if (isset($_GET['viewed'])) {
    try {
        $id = $_GET['viewed'];
        $stmt = $pdo->prepare("UPDATE orders SET viewed = 1 WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $message = "Order marked as viewed.";
        $reloadPage = true;
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@10'></script>";
echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        if ('$message' !== '') {
            Swal.fire({
                icon: '" . ($message == 'Order marked as viewed.' ? 'success' : 'error') . "',
                title: '$message',
                showConfirmButton: false,
                timer: 1500
            }).then((result) => {
                if ('$reloadPage') {
                    window.location.href = 'viewOrder.php';
                }
            });
        }
    });

    function printOrder(id) {
        var printContents = document.getElementById('order_' + id).innerHTML;
        var popupWin = window.open('', '_blank', 'width=600,height=600');
        popupWin.document.open();
        popupWin.document.write('<html><head><title>Print</title></head><body>' + printContents + '</body></html>');
        popupWin.document.close();
        popupWin.print();
        return false;
    }

    function markAsViewed(id) {
        window.location.href = 'viewOrder.php?viewed=' + id;
    }
</script>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Admin Rest - View Orders</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <style>
        .printable {
            display: none;
        }
        @media print {
            .non-printable { display: none; }
            .printable { display: block; }
            .page-header, .page-footer {
                display: block;
                position: fixed;
                width: 100%;
                text-align: center;
            }
            .page-header {
                top: 0;
                background-color: #4e73df;
                padding: 10px;
                color: #ffffff;
            }
            .page-footer {
                bottom: 0;
                background-color: #4e73df;
                padding: 10px;
                color: #ffffff;
            }
        }
        .report-card {
            background-color: #f8f9fc;
            border: 1px solid #d1d3e2;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .report-title {
            font-size: 24px;
            font-weight: bold;
            color: #4e73df;
            margin-bottom: 20px;
        }
        .report-table th {
            background-color: #4e73df;
            color: #ffffff;
        }
        .btn-info {
            background-color: #1cc88a;
            border-color: #1cc88a;
        }
    </style>
</head>
<body id="page-top">
<!-- Page Wrapper -->
<div id="wrapper">
    <?php
    include_once 'include/admin-nav.php'
    ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php
            include_once 'include/admin-header.php'
            ?>

            <!-- Begin Page Content -->
            <div class="container-fluid">

                <!-- Page Heading -->
                <h1 class="h3 mb-2 text-gray-800">View Orders</h1>

                <!-- DataTales Example -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Order List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                <thead>
                                <tr>
                                    <th style="display:none;">ID</th>
                                    <th>SL No.</th>
                                    <th>Order Name</th>
                                    <th>Order Date</th>
                                    <th>Menu Items</th>
                                    <th>Status</th>
                                    <th>Actions</th> <!-- Keep Action column -->
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                $serialNumber = 1;
                                foreach ($orders as $order): ?>
                                    <tr>
                                        <td style="display:none;"><?php echo $order['id']; ?></td>
                                        <td><?php echo $serialNumber++; ?></td>
                                        <td><?php echo $order['order_name']; ?></td>
                                        <td><?php echo $order['order_date']; ?></td>
                                        <td><?php echo $order['menu_items']; ?></td>
                                        <td>
                                            <?php if (!$order['viewed']): ?>
                                                <span class="badge badge-danger">New Order</span>
                                            <?php else: ?>
                                                Viewed
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$order['viewed']): ?>
                                                <div id="order_<?php echo $order['id']; ?>" class="printable">
                                                    <div class="container mt-5">
                                                        <div class="row justify-content-center">
                                                            <div class="col-lg-6">
                                                                <div class="report-card">
                                                                    <div class="report-title">Order Details</div>
                                                                    <table class="table report-table">
                                                                        <tr>
                                                                            <th>ID</th>
                                                                            <td><?php echo $order['id']; ?></td>
                                                                        </tr>
                                                                        <tr>
                                                                            <th>Order Name</th>
                                                                            <td><?php echo $order['order_name']; ?></td>
                                                                        </tr>
                                                                        <tr>
                                                                            <th>Order Date</th>
                                                                            <td><?php echo $order['order_date']; ?></td>
                                                                        </tr>
                                                                        <tr>
                                                                            <th>Menu Items</th>
                                                                            <td><?php echo $order['menu_items']; ?></td>
                                                                        </tr>
                                                                        <tr>
                                                                            <th>Status</th>
                                                                            <td><?php echo (!$order['viewed']) ? '<span class="badge badge-danger">New Order</span>' : 'Viewed'; ?></td>
                                                                        </tr>
                                                                    </table>
                                                                    <div class="text-center">
                                                                        <button class="btn btn-info" onclick="printOrder(<?php echo $order['id']; ?>)">Print Report</button>
                                                                        <button class="btn btn-success" onclick="markAsViewed(<?php echo $order['id']; ?>)">Mark as Viewed</button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <div class="non-printable">
                                                <button class="btn btn-info btn-sm" onclick="printOrder(<?php echo $order['id']; ?>)">Print Report</button>
                                                <?php if (!$order['viewed']): ?>
                                                    <button class="btn btn-success btn-sm" onclick="markAsViewed(<?php echo $order['id']; ?>)">Mark as Viewed</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>N
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
            <!-- /.container-fluid -->

        </div>
        <?php
        include_once 'include/admin-footer.php'
        ?>
    </div>
</div>
</body>
</html>
