<?php
// Include config file
require_once "include/config.php";

// Initialize message variable
$message = "";

// Initialize menus array
$menus = [];

// Start session

// Initialize cart session
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

try {
    // Create PDO instance
    $pdo = new PDO("mysql:host=" . $DB_HOST . ";dbname=" . $DB_NAME, $DB_USER, $DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch existing menu data
    $stmt = $pdo->prepare("SELECT * FROM menu");
    $stmt->execute();
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

// Handle adding item to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $menu_id = $_POST['menu_id'];
    $quantity = $_POST['quantity'];

    // Fetch menu item details based on menu_id
    $stmt = $pdo->prepare("SELECT * FROM menu WHERE id = ?");
    $stmt->execute([$menu_id]);
    $menu = $stmt->fetch(PDO::FETCH_ASSOC);

    // Validate menu item
    if ($menu) {
        // Add item to cart session
        $_SESSION['cart'][] = array(
            'menu_id' => $menu['id'],
            'menu_name' => $menu['menuName'],
            'quantity' => $quantity
        );

//        $message = "Item added to cart successfully!";
    } else {
//        $message = "Error: Menu item not found!";
    }
}

// Handle placing the order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $order_name = $_POST['order_name'];
    $order_date = $_POST['order_date'];

    try {
        // Start a transaction
        $pdo->beginTransaction();

        // Insert order data into 'orders' table
        $stmt = $pdo->prepare("INSERT INTO orders (order_name, order_date) VALUES (?, ?)");
        $stmt->execute([$order_name, $order_date]);
        $order_id = $pdo->lastInsertId();

        // Insert order items into 'order_items' table
        foreach ($_SESSION['cart'] as $cart_item) {
            $menu_id = $cart_item['menu_id'];
            $menu_name = $cart_item['menu_name'];
            $quantity = $cart_item['quantity'];
            $stmt = $pdo->prepare("INSERT INTO order_items (order_id, menu_name, quantity) VALUES (?, ?, ?)");
            $stmt->execute([$order_id, $menu_name, $quantity]);
        }

        // Commit the transaction
        $pdo->commit();

        // Clear the cart after placing the order
        $_SESSION['cart'] = [];

        // Set success message
        $message = "Order placed successfully!";
    } catch (Exception $e) {
        // Rollback the transaction if an error occurs
        $pdo->rollBack();
        $message = "Error placing order: " . $e->getMessage();
    }
}

// Include SweetAlert library
echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Contact Us</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/font/flaticon.css">
    <link rel="stylesheet" href="assets/css/plugins/owl.carousel.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include_once 'include/nav.php'; ?>

<main>
    <section class="abt-01">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="seting">
                        <h3>Order</h3>
                        <ol>
                            <li>Home <i class="flaticon-double-right-arrow"></i></li>
                            <li>Order</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-001">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="heading">
                        <h2>Place Order</h2>
                    </div>
                </div>

                <div class="col-lg-6 col-md-6 col-6">
                    <form id="add-to-cart-form" method="post">

                    <div class="form-group col-md-12">
                        <label for="menu_id">Select Menu:</label>
                        <select name="menu_id" id="menu_id" class="form-control" required>
                            <option value="">Select Menu</option>
                            <?php foreach ($menus as $menu): ?>
                                <option value="<?php echo $menu['id']; ?>"><?php echo $menu['menuName']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-12">
                        <label for="quantity">Quantity:</label>
                        <input type="number" name="quantity" id="quantity" class="form-control" value="1" min="1" required>
                    </div>
                    <div class="form-group col-md-4">
                        <button type="submit" name="add_to_cart" class="btn btn-primary">Add to Cart</button>
                    </div>
                    </form>
                </div>

                <div class="col-lg-6 col-md-6 col-6">
                    <form id="order-form" method="post">

                    <div class="form-group col-md-12">
                        <div class="cart-box" id="cart-box">
                        <h6>ITEM IN CART</h6>
                        <table class="table mt-2">
                            <thead class="table-header thead-light">
                            <tr>
                                <th>Menu Name</th>
                                <th>Quantity</th>
                            </tr>
                            </thead>
                            <tbody id="cart-items">
                            <!-- Cart items will be appended here dynamically -->
                            <?php foreach ($_SESSION['cart'] as $cart_item): ?>
                                <tr class="cart-item">
                                    <td><?php echo $cart_item['menu_name']; ?></td>
                                    <td><?php echo $cart_item['quantity']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>

                    <div class="col-lg-12 col-md-12 col-12">
                        <div class="contact-box">
                            <div class="form-group">
                                <label for="order_name">Order Name:</label>
                                <input type="text" name="order_name" id="order_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="order_date">Order Date:</label>
                                <input type="date" name="order_date" id="order_date" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <div class="link fa-align-right">
                                    <button type="submit" name="place_order" class="btn btn-primary btn col-5">Place Order</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include_once 'include/footer.php'; ?>

<!-- SweetAlert script -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($message === "Order placed successfully!"): ?>
        Swal.fire({
            icon: 'success',
            title: '<?php echo $message; ?>',
            showConfirmButton: false,
            timer: 1500
        });
        <?php elseif ($message): ?>
        Swal.fire({
            icon: 'error',
            title: '<?php echo $message; ?>',
            showConfirmButton: false,
            timer: 1500
        });
        <?php endif; ?>
    });
</script>

</body>
</html>
