<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Redirect to login if not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['id'];
$orders = [];
$transactions = [];
$error_message = '';
$filter_status = isset($_GET['filter_status']) ? htmlspecialchars($_GET['filter_status']) : 'all'; // Initialize filter status

// Function to fetch order status from Secsers API
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
        error_log("cURL Error: " . curl_error($ch));
        return false;
    } else {
        $status_response = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Decode Error: " . json_last_error_msg());
            return false;
        }
        return $status_response;
    }
    curl_close($ch);
}

// Fetch orders placed by the current user
$sql_orders = "SELECT o.id, s.name as service_name, o.quantity, o.user_price, o.real_price, o.profit, o.status, o.created_at, o.secsers_order FROM orders o JOIN services s ON o.service_id = s.id WHERE o.user_id = ?";

if (!empty($filter_status) && $filter_status != 'all') {
    $sql_orders .= " AND o.status = ?";
}
$sql_orders .= " ORDER BY o.created_at DESC";

$stmt = $conn->prepare($sql_orders);

if (!empty($filter_status) && $filter_status != 'all') {
    $stmt->bind_param("is", $user_id, $filter_status);
} else {
    $stmt->bind_param("i", $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Optionally fetch live status from Secsers API
        // $api_status = fetchOrderStatusFromAPI(API_KEY, API_URL, $row['secsers_order']);
        // if ($api_status && isset($api_status['status'])) {
        //     $row['status'] = $api_status['status']; // Update status with live data
        // }
        $orders[] = $row;
    }
} else {
    $error_message = "You haven't placed any orders yet.";
}
$stmt->close();

// Fetch transaction history for the current user
$stmt = $conn->prepare("SELECT id, type, amount, description, created_at, order_id FROM transactions WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
}
$stmt->close();
$conn->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Status & Transactions</title>
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
        <h2 class="text-3xl font-bold mb-4 text-gray-800" data-aos="fade-right">Your Order Status</h2>
        <?php if ($error_message && empty($orders)): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?></p>
        <?php endif; ?>

        <div class="mb-4" data-aos="zoom-in">
            <label for="filter_status" class="block text-gray-700 text-sm font-bold mb-2">Filter by Status:</label>
            <select id="filter_status" onchange="window.location.href='status.php?filter_status=' + this.value" class="shadow appearance-none border rounded py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
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
            <div class="overflow-x-auto mb-8" data-aos="zoom-in">
                <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Order ID <i class="fas fa-hashtag ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Service <i class="fas fa-box ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Quantity <i class="fas fa-sort-numeric-up-alt ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Your Price <i class="fas fa-dollar-sign ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Status <i class="fas fa-info-circle ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Ordered On <i class="fas fa-calendar-alt ml-1"></i></th>
                            <!-- <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Secsers Order ID <i class="fas fa-external-link-alt ml-1"></i></th> -->
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['id']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['service_name']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['quantity']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800">$<?php echo number_format($order['user_price'], 2); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['status']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['created_at']); ?></td>
                                <!-- <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($order['secsers_order']); ?></td> -->
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-700 mb-8">No orders placed yet.</p>
        <?php endif; ?>

        <h2 class="text-3xl font-bold mb-4 text-gray-800" data-aos="fade-right">Transaction History</h2>
        <?php if (!empty($transactions)): ?>
            <div class="overflow-x-auto" data-aos="zoom-in">
                <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">ID <i class="fas fa-hashtag ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Type <i class="fas fa-exchange-alt ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Amount <i class="fas fa-dollar-sign ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Description <i class="fas fa-align-left ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Order ID <i class="fas fa-file-invoice ml-1"></i></th>
                            <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Date <i class="fas fa-calendar-alt ml-1"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($transaction['id']); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars(ucfirst($transaction['type'])); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800 <?php echo ($transaction['type'] == 'deposit' || $transaction['type'] == 'profit' || $transaction['type'] == 'credit') ? 'text-green-600' : 'text-red-600'; ?>">$<?php echo number_format($transaction['amount'], 2); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($transaction['description'] ?? '-'); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($transaction['order_id'] ?? '-'); ?></td>
                                <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($transaction['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-700">No transactions recorded yet.</p>
        <?php endif; ?>
    </div>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
    </script>
</body>
</html> 