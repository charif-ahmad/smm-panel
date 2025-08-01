<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Redirect to login if not logged in or not an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit;
}

$total_profit = 0;
$orders = [];

// Fetch total profit and orders
$stmt = $conn->prepare("SELECT id, user_id, service_id, secsers_order, quantity, user_price, real_price, profit, status, created_at FROM orders");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
        $total_profit += $row['profit'];
    }
}
$stmt->close();

// Fetch current markup amount
$markup_amount = GLOBAL_MARKUP_AMOUNT;
$stmt = $conn->prepare("SELECT markup_amount FROM admin_settings WHERE id = 1");
$stmt->execute();
$stmt->bind_result($db_markup_amount);
$stmt->fetch();
$stmt->close();
if ($db_markup_amount !== null) {
    $markup_amount = $db_markup_amount;
}

// Handle markup update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_markup'])) {
    $new_markup = filter_input(INPUT_POST, 'markup_amount', FILTER_VALIDATE_FLOAT);
    if ($new_markup !== false && $new_markup >= 0) {
        $update_stmt = $conn->prepare("UPDATE admin_settings SET markup_amount = ? WHERE id = 1");
        $update_stmt->bind_param("d", $new_markup);
        if ($update_stmt->execute()) {
            // Update successful, refresh page to show new value
            header("Location: admin_dashboard.php?success=markup_updated");
            exit();
        } else {
            // Handle error
            header("Location: admin_dashboard.php?error=markup_update_failed");
            exit();
        }
        $update_stmt->close();
    } else {
        header("Location: admin_dashboard.php?error=invalid_markup_amount");
        exit();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Admin Dashboard - Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
        <p>Total Profit: $<?php echo number_format($total_profit, 2); ?></p>

        <h3>Manage Global Markup</h3>
        <form action="admin_dashboard.php" method="POST">
            <div class="form-group">
                <label for="markup_amount">Global Markup Amount:</label>
                <input type="number" id="markup_amount" name="markup_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($markup_amount); ?>" required>
            </div>
            <button type="submit" name="update_markup">Update Markup</button>
        </form>

        <h3>All Orders</h3>
        <?php if (!empty($orders)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>User ID</th>
                        <th>Service ID</th>
                        <th>Secsers Order ID</th>
                        <th>Quantity</th>
                        <th>User Price</th>
                        <th>Real Price</th>
                        <th>Profit</th>
                        <th>Status</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['id']); ?></td>
                            <td><?php echo htmlspecialchars($order['user_id']); ?></td>
                            <td><?php echo htmlspecialchars($order['service_id']); ?></td>
                            <td><?php echo htmlspecialchars($order['secsers_order']); ?></td>
                            <td><?php echo htmlspecialchars($order['quantity']); ?></td>
                            <td>$<?php echo number_format($order['user_price'], 2); ?></td>
                            <td>$<?php echo number_format($order['real_price'], 2); ?></td>
                            <td>$<?php echo number_format($order['profit'], 2); ?></td>
                            <td><?php echo htmlspecialchars($order['status']); ?></td>
                            <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No orders placed yet.</p>
        <?php endif; ?>

        <p><a href="logout.php">Logout</a></p>
    </div>
</body>
</html> 