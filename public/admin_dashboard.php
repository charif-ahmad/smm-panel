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

// Calculate daily and monthly profits
$today_profit = 0;
$month_profit = 0;

$stmt_daily_profit = $conn->prepare("SELECT SUM(amount) AS total_profit FROM transactions WHERE type = 'profit' AND DATE(created_at) = CURDATE()");
$stmt_daily_profit->execute();
$result_daily_profit = $stmt_daily_profit->get_result();
if ($row_daily_profit = $result_daily_profit->fetch_assoc()) {
    $today_profit = $row_daily_profit['total_profit'] ?: 0;
}
$stmt_daily_profit->close();

$stmt_monthly_profit = $conn->prepare("SELECT SUM(amount) AS total_profit FROM transactions WHERE type = 'profit' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$stmt_monthly_profit->execute();
$result_monthly_profit = $stmt_monthly_profit->get_result();
if ($row_monthly_profit = $result_monthly_profit->fetch_assoc()) {
    $month_profit = $row_monthly_profit['total_profit'] ?: 0;
}
$stmt_monthly_profit->close();

// Fetch total profit from all transactions (type 'profit')
$stmt_total_profit = $conn->prepare("SELECT SUM(amount) AS total_profit FROM transactions WHERE type = 'profit'");
$stmt_total_profit->execute();
$result_total_profit = $stmt_total_profit->get_result();
if ($row_total_profit = $result_total_profit->fetch_assoc()) {
    $total_profit = $row_total_profit['total_profit'] ?: 0;
}
$stmt_total_profit->close();

// Fetch all orders for display (before potential filtering)
$sql_orders = "SELECT id, user_id, service_id, secsers_order, quantity, user_price, real_price, profit, status, created_at FROM orders";

// Filtering logic
$filter_status = isset($_GET['filter_status']) ? htmlspecialchars($_GET['filter_status']) : '';

if (!empty($filter_status) && $filter_status != 'all') {
    $sql_orders .= " WHERE status = ?";
}
$sql_orders .= " ORDER BY created_at DESC";

$stmt_orders = $conn->prepare($sql_orders);

if (!empty($filter_status) && $filter_status != 'all') {
    $stmt_orders->bind_param("s", $filter_status);
}

$stmt_orders->execute();
$result_orders = $stmt_orders->get_result();

if ($result_orders->num_rows > 0) {
    while ($row = $result_orders->fetch_assoc()) {
        $orders[] = $row;
    }
}
$stmt_orders->close();

// Fetch all users for manual balance control and financial reports
$users = [];
$stmt_users = $conn->prepare("SELECT id, name, email, balance FROM users ORDER BY name ASC");
$stmt_users->execute();
$result_users = $stmt_users->get_result();

if ($result_users->num_rows > 0) {
    while ($row = $result_users->fetch_assoc()) {
        $users[] = $row;
    }
}
$stmt_users->close();

// Handle manual balance adjustment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['adjust_balance'])) {
    $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $adjustment_amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $adjustment_type = htmlspecialchars(trim($_POST['adjustment_type'])); // 'credit' or 'debit'
    $description = htmlspecialchars(trim($_POST['description']));

    if ($target_user_id && $adjustment_amount !== false && $adjustment_amount > 0 && !empty($adjustment_type)) {
        $current_balance = 0;
        $stmt_get_balance = $conn->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt_get_balance->bind_param("i", $target_user_id);
        $stmt_get_balance->execute();
        $stmt_get_balance->bind_result($current_balance);
        $stmt_get_balance->fetch();
        $stmt_get_balance->close();

        $new_balance = $current_balance;
        $transaction_amount = $adjustment_amount;

        if ($adjustment_type == 'credit') {
            $new_balance += $adjustment_amount;
            $transaction_type_db = 'credit';
        } elseif ($adjustment_type == 'debit') {
            $new_balance -= $adjustment_amount;
            $transaction_type_db = 'debit';
            $transaction_amount = -$adjustment_amount; // Store debit as negative
        } else {
            header("Location: admin_dashboard.php?error=invalid_adjustment_type");
            exit();
        }

        $conn->begin_transaction();
        try {
            $update_balance_stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $update_balance_stmt->bind_param("di", $new_balance, $target_user_id);
            $update_balance_stmt->execute();

            $insert_transaction_stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description) VALUES (?, ?, ?, ?)");
            $insert_transaction_stmt->bind_param("isds", $target_user_id, $transaction_type_db, $transaction_amount, $description);
            $insert_transaction_stmt->execute();

            $conn->commit();
            header("Location: admin_dashboard.php?success=balance_adjusted");
            exit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log("Balance adjustment failed: " . $e->getMessage());
            header("Location: admin_dashboard.php?error=balance_adjustment_failed");
            exit();
        }
    } else {
        header("Location: admin_dashboard.php?error=invalid_adjustment_params");
        exit();
    }
}

// Function to fetch order status from Secsers API (duplicated for self-containment)
function fetchOrderStatusFromAPI($api_key, $api_url, $secsers_order_id) {
    $post_data = array(
        'key' => $api_key,
        'action' => 'status',
        'order' => $secsers_order_id
    );

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("cURL Error [Admin Dashboard]: " . curl_error($ch));
        return false;
    } else {
        $status_response = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Decode Error [Admin Dashboard]: " . json_last_error_msg() . ": " . $response);
            return false;
        }
        return $status_response;
    }
    curl_close($ch);
}

// Handle order status update from API
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_order_status'])) {
    $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $secsers_order_id = filter_input(INPUT_POST, 'secsers_order_id', FILTER_VALIDATE_INT);

    if ($order_id && $secsers_order_id) {
        $api_status_response = fetchOrderStatusFromAPI(API_KEY, API_URL, $secsers_order_id);

        if ($api_status_response && isset($api_status_response['status'])) {
            $new_status = $api_status_response['status'];
            
            $update_stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ? AND secsers_order = ?");
            $update_stmt->bind_param("sii", $new_status, $order_id, $secsers_order_id);
            
            if ($update_stmt->execute()) {
                header("Location: admin_dashboard.php?success=status_updated");
                exit();
            } else {
                header("Location: admin_dashboard.php?error=status_update_failed_db");
                exit();
            }
            $update_stmt->close();
        } elseif ($api_status_response && isset($api_status_response['error'])) {
            header("Location: admin_dashboard.php?error=status_update_api_error&msg=" . urlencode($api_status_response['error']));
            exit();
        } else {
            header("Location: admin_dashboard.php?error=status_update_api_invalid");
            exit();
        }
    } else {
        header("Location: admin_dashboard.php?error=invalid_order_params");
        exit();
    }
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
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
</head>
<body class="bg-gray-100 font-sans">
    <nav class="bg-blue-600 p-4 text-white shadow-md">
        <div class="container mx-auto flex justify-between items-center">
            <a href="index.php" class="text-2xl font-bold"><i class="fas fa-users mr-2"></i>SMM Reseller</a>
            <div class="space-x-4">
                <a href="index.php" class="hover:underline"><i class="fas fa-th-list mr-1"></i>Services</a>
                <a href="status.php" class="hover:underline"><i class="fas fa-history mr-1"></i>My Orders</a>
                <a href="balance.php" class="hover:underline"><i class="fas fa-wallet mr-1"></i>My Balance</a>
                <?php if ($_SESSION['is_admin']): ?>
                    <a href="admin_dashboard.php" class="hover:underline"><i class="fas fa-tachometer-alt mr-1"></i>Admin Dashboard</a>
                <?php endif; ?>
                <a href="logout.php" class="bg-red-500 hover:bg-red-700 px-3 py-2 rounded-md"><i class="fas fa-sign-out-alt mr-1"></i>Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mx-auto p-6 bg-white rounded-lg shadow-md mt-6" data-aos="fade-up">
        <h2 class="text-3xl font-bold mb-4 text-gray-800" data-aos="fade-right">Admin Dashboard - Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-blue-100 p-4 rounded-lg shadow" data-aos="zoom-in">
                <h3 class="text-xl font-semibold text-blue-800">Total Profit <i class="fas fa-money-bill-wave ml-1"></i></h3>
                <p class="text-2xl font-bold text-blue-600">$<?php echo number_format($total_profit, 2); ?></p>
            </div>
            <div class="bg-green-100 p-4 rounded-lg shadow" data-aos="zoom-in" data-aos-delay="100">
                <h3 class="text-xl font-semibold text-green-800">Today's Profit <i class="fas fa-chart-line ml-1"></i></h3>
                <p class="text-2xl font-bold text-green-600">$<?php echo number_format($today_profit, 2); ?></p>
            </div>
            <div class="bg-yellow-100 p-4 rounded-lg shadow" data-aos="zoom-in" data-aos-delay="200">
                <h3 class="text-xl font-semibold text-yellow-800">This Month's Profit <i class="fas fa-calendar-alt ml-1"></i></h3>
                <p class="text-2xl font-bold text-yellow-600">$<?php echo number_format($month_profit, 2); ?></p>
            </div>
        </div>

        <h3 class="text-2xl font-semibold mb-4 text-gray-800" data-aos="fade-right">Manage Global Markup</h3>
        <?php if (isset($_GET['success']) && $_GET['success'] == 'markup_updated'): ?>
            <p class="text-green-500 mb-4" data-aos="fade-left"><i class="fas fa-check-circle mr-2"></i>Markup updated successfully!</p>
        <?php elseif (isset($_GET['error']) && $_GET['error'] == 'markup_update_failed'): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i>Failed to update markup. Please try again.</p>
        <?php elseif (isset($_GET['error']) && $_GET['error'] == 'invalid_markup_amount'): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i>Invalid markup amount. Please enter a positive number.</p>
        <?php endif; ?>
        <form action="admin_dashboard.php" method="POST" class="max-w-md mx-auto mb-8" data-aos="zoom-in">
            <div class="mb-4">
                <label for="markup_amount" class="block text-gray-700 text-sm font-bold mb-2">Global Markup Amount:</label>
                <input type="number" id="markup_amount" name="markup_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($markup_amount); ?>" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            <button type="submit" name="update_markup" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full">
                <i class="fas fa-sliders-h mr-2"></i>Update Markup
            </button>
        </form>

        <h3 class="text-2xl font-semibold mb-4 text-gray-800" data-aos="fade-right">Manual Balance Adjustment</h3>
        <?php if (isset($_GET['success']) && $_GET['success'] == 'balance_adjusted'): ?>
            <p class="text-green-500 mb-4" data-aos="fade-left"><i class="fas fa-check-circle mr-2"></i>Balance adjusted successfully!</p>
        <?php elseif (isset($_GET['error']) && $_GET['error'] == 'balance_adjustment_failed'): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i>Failed to adjust balance. Please try again.</p>
        <?php elseif (isset($_GET['error']) && $_GET['error'] == 'invalid_adjustment_type'): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i>Invalid adjustment type.</p>
        <?php elseif (isset($_GET['error']) && $_GET['error'] == 'invalid_adjustment_params'): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i>Invalid adjustment parameters.</p>
        <?php endif; ?>
        <form action="admin_dashboard.php" method="POST" class="max-w-md mx-auto mb-8" data-aos="zoom-in">
            <div class="mb-4">
                <label for="user_id" class="block text-gray-700 text-sm font-bold mb-2">Select User:</label>
                <select id="user_id" name="user_id" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    <option value="">Select User</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo htmlspecialchars($user['id']); ?>"><?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['email']); ?>) - Balance: $<?php echo number_format($user['balance'], 2); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label for="amount" class="block text-gray-700 text-sm font-bold mb-2">Amount:</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0.01" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            <div class="mb-6">
                <label for="adjustment_type" class="block text-gray-700 text-sm font-bold mb-2">Type:</label>
                <select id="adjustment_type" name="adjustment_type" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    <option value="">Select Type</option>
                    <option value="credit">Credit <i class="fas fa-plus-circle ml-1"></i></option>
                    <option value="debit">Debit <i class="fas fa-minus-circle ml-1"></i></option>
                </select>
            </div>
            <div class="mb-6">
                <label for="description" class="block text-gray-700 text-sm font-bold mb-2">Description:</label>
                <input type="text" id="description" name="description" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" placeholder="e.g., Bonus, Correction, etc." required>
            </div>
            <button type="submit" name="adjust_balance" class="bg-purple-500 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full">
                <i class="fas fa-exchange-alt mr-2"></i>Adjust Balance
            </button>
        </form>

        <h3 class="text-2xl font-semibold mb-4 text-gray-800" data-aos="fade-right">All Orders</h3>
        <?php if (isset($_GET['success']) && $_GET['success'] == 'status_updated'): ?>
            <p class="text-green-500 mb-4" data-aos="fade-left"><i class="fas fa-check-circle mr-2"></i>Order status updated successfully!</p>
        <?php elseif (isset($_GET['error']) && $_GET['error'] == 'status_update_failed_db'): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i>Failed to update order status in database.</p>
        <?php elseif (isset($_GET['error']) && $_GET['error'] == 'status_update_api_error'): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i>Secsers API Error: <?php echo htmlspecialchars($_GET['msg'] ?? 'Unknown API error'); ?></p>
        <?php elseif (isset($_GET['error']) && $_GET['error'] == 'status_update_api_invalid'): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i>Invalid response from Secsers API when updating status.</p>
        <?php elseif (isset($_GET['error']) && $_GET['error'] == 'invalid_order_params'): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i>Invalid order parameters for status update.</p>
        <?php endif; ?>

        <div class="mb-4" data-aos="zoom-in">
            <label for="filter_status" class="block text-gray-700 text-sm font-bold mb-2">Filter by Status:</label>
            <select id="filter_status" onchange="window.location.href='admin_dashboard.php?filter_status=' + this.value" class="shadow appearance-none border rounded py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                <option value="all" <?php echo ($filter_status == 'all' || empty($filter_status)) ? 'selected' : ''; ?>>All <i class="fas fa-list-ul ml-1"></i></option>
                <option value="Pending" <?php echo ($filter_status == 'Pending') ? 'selected' : ''; ?>>Pending <i class="fas fa-hourglass-half ml-1"></i></option>
                <option value="Processing" <?php echo ($filter_status == 'Processing') ? 'selected' : ''; ?>>Processing <i class="fas fa-sync-alt ml-1"></i></option>
                <option value="In progress" <?php echo ($filter_status == 'In progress') ? 'selected' : ''; ?>>In Progress <i class="fas fa-spinner ml-1"></i></option>
                <option value="Completed" <?php echo ($filter_status == 'Completed') ? 'selected' : ''; ?>>Completed <i class="fas fa-check-circle ml-1"></i></option>
                <option value="Partial" <?php echo ($filter_status == 'Partial') ? 'selected' : ''; ?>>Partial <i class="fas fa-adjust ml-1"></i></option>
                <option value="Canceled" <?php echo ($filter_status == 'Canceled') ? 'selected' : ''; ?>>Canceled <i class="fas fa-times-circle ml-1"></i></option>
                <option value="Refunded" <?php echo ($filter_status == 'Refunded') ? 'selected' : ''; ?>>Refunded <i class="fas fa-undo-alt ml-1"></i></option>
            </select>
        </div>

        <?php if (!empty($orders)): ?>
            <div class="overflow-x-auto" data-aos="zoom-in">
                <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Order ID <i class="fas fa-hashtag ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">User ID <i class="fas fa-user ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Service ID <i class="fas fa-box ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Secsers Order ID <i class="fas fa-external-link-alt ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Quantity <i class="fas fa-sort-numeric-up-alt ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">User Price <i class="fas fa-dollar-sign ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Real Price <i class="fas fa-money-bill-wave ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Profit <i class="fas fa-hand-holding-usd ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Status <i class="fas fa-info-circle ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Created At <i class="fas fa-calendar-alt ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Action <i class="fas fa-cogs ml-1"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['id']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['user_id']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['service_id']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['secsers_order']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['quantity']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800">$<?php echo number_format($order['user_price'], 2); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800">$<?php echo number_format($order['real_price'], 2); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800">$<?php echo number_format($order['profit'], 2); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['status']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['created_at']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800">
                                    <form action="admin_dashboard.php" method="POST">
                                        <input type="hidden" name="update_order_status" value="1">
                                        <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order['id']); ?>">
                                        <input type="hidden" name="secsers_order_id" value="<?php echo htmlspecialchars($order['secsers_order']); ?>">
                                        <button type="submit" class="bg-green-500 hover:bg-green-700 text-white text-xs font-bold py-1 px-2 rounded">
                                            <i class="fas fa-sync-alt mr-1"></i>Update Status
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-700">No orders placed yet.</p>
        <?php endif; ?>

        <h3 class="text-2xl font-semibold mb-4 text-gray-800" data-aos="fade-right">User Financial Reports</h3>
        <?php if (!empty($users)): ?>
            <div class="overflow-x-auto" data-aos="zoom-in">
                <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">User ID <i class="fas fa-id-badge ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Name <i class="fas fa-user ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Email <i class="fas fa-envelope ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Current Balance <i class="fas fa-wallet ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">View Transactions <i class="fas fa-file-invoice-dollar ml-1"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($user['id']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($user['name']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($user['email']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800">$<?php echo number_format($user['balance'], 2); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800">
                                    <a href="user_transactions.php?user_id=<?php echo htmlspecialchars($user['id']); ?>" class="text-blue-500 hover:underline text-sm">
                                        <i class="fas fa-eye mr-1"></i>View Transactions
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-700">No users found.</p>
        <?php endif; ?>
    </div>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
    </script>
</body>
</html> 