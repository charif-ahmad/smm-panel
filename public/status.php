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
$error_message = '';

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
$stmt = $conn->prepare("SELECT o.id, s.name as service_name, o.quantity, o.user_price, o.real_price, o.profit, o.status, o.created_at, o.secsers_order FROM orders o JOIN services s ON o.service_id = s.id WHERE o.user_id = ? ORDER BY o.created_at DESC");
$stmt->bind_param("i", $user_id);
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
$conn->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Status</title>
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
        <h2>Your Order Status</h2>
        <?php if ($error_message): ?>
            <p style="color: red;"><?php echo $error_message; ?></p>
        <?php endif; ?>

        <?php if (!empty($orders)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Service</th>
                        <th>Quantity</th>
                        <th>Your Price</th>
                        <th>Status</th>
                        <th>Ordered On</th>
                        <!-- <th>Secsers Order ID</th> -->
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['id']); ?></td>
                            <td><?php echo htmlspecialchars($order['service_name']); ?></td>
                            <td><?php echo htmlspecialchars($order['quantity']); ?></td>
                            <td>$<?php echo number_format($order['user_price'], 2); ?></td>
                            <td><?php echo htmlspecialchars($order['status']); ?></td>
                            <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                            <!-- <td><?php echo htmlspecialchars($order['secsers_order']); ?></td> -->
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p><a href="index.php">Back to Services</a> | <a href="logout.php">Logout</a></p>
    </div>
</body>
</html> 