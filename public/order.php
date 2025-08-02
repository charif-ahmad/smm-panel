<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Redirect to login if not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$service = null;
$error_message = '';
$success_message = '';

// Fetch service details based on service_id from URL
if (isset($_GET['service_id']) && is_numeric($_GET['service_id'])) {
    $service_id_from_url = $_GET['service_id'];

    $stmt = $conn->prepare("SELECT id, service_id, name, type, category, rate, min, max, refill FROM services WHERE service_id = ?");
    $stmt->bind_param("i", $service_id_from_url);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $service = $result->fetch_assoc();
    } else {
        $error_message = "Service not found.";
    }
    $stmt->close();
} else {
    $error_message = "Invalid service ID provided.";
}

// Handle order placement
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    if ($service) {
        $user_id = $_SESSION['id'];
        $order_quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        $link = trim($_POST['link']);

        if ($order_quantity === false || $order_quantity < $service['min'] || $order_quantity > $service['max']) {
            $error_message = "Invalid quantity. Please enter a value between " . $service['min'] . " and " . $service['max'] . ".";
        } elseif (empty($link)) {
            $error_message = "Link cannot be empty.";
        } else {
            // Calculate prices
            $markup_percentage = GLOBAL_MARKUP_PERCENTAGE;
            $real_price_per_unit = $service['rate'];
            $user_price_per_unit = $real_price_per_unit * $markup_percentage; // Apply percentage markup
            
            $real_total_cost = $real_price_per_unit * $order_quantity;
            $user_total_price = $user_price_per_unit * $order_quantity;
            $profit = $user_total_price - $real_total_cost;

            // Check if user has sufficient balance
            if ($_SESSION['balance'] < $user_total_price) {
                $error_message = "Insufficient balance. Please top up your account.";
            } else {
                // Deduct balance from user's account
                $new_balance = $_SESSION['balance'] - $user_total_price;
                $update_balance_stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
                $update_balance_stmt->bind_param("di", $new_balance, $user_id);
                
                if ($update_balance_stmt->execute()) {
                    $_SESSION['balance'] = $new_balance; // Update session balance

                    // Record debit transaction for the user
                    $transaction_desc = "Order for " . $service['name'] . " (Qty: " . $order_quantity . ")";
                    $insert_transaction_stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description) VALUES (?, ?, ?, ?)");
                    $debit_amount = -$user_total_price; // Store as negative for debit
                    $transaction_type = 'debit';
                    $insert_transaction_stmt->bind_param("isds", $user_id, $transaction_type, $debit_amount, $transaction_desc);
                    $insert_transaction_stmt->execute();
                    $insert_transaction_stmt->close();

                    // --- Call Secsers.com API to place order ---
                    $post_data = array(
                        'key' => API_KEY,
                        'action' => 'add',
                        'service' => $service['service_id'],
                        'link' => $link,
                        'quantity' => $order_quantity
                    );
                    
                    $ch = curl_init(API_URL);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));

                    $response = curl_exec($ch);
                    $secsers_response = json_decode($response, true);
                    curl_close($ch);

                    if (isset($secsers_response['order'])) {
                        $secsers_order_id = $secsers_response['order'];
                        $order_status = 'Pending'; // Initial status

                        // Insert order into your database
                        $insert_stmt = $conn->prepare("INSERT INTO orders (user_id, service_id, secsers_order, quantity, user_price, real_price, profit, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $insert_stmt->bind_param("iiisddds", $user_id, $service['id'], $secsers_order_id, $order_quantity, $user_total_price, $real_total_cost, $profit, $order_status);
                        
                        if ($insert_stmt->execute()) {
                            $order_id = $conn->insert_id; // Get the ID of the newly inserted order

                            // Record profit transaction for the manager (or system)
                            $profit_desc = "Profit from order #" . $order_id;
                            $insert_profit_stmt = $conn->prepare("INSERT INTO transactions (user_id, order_id, type, amount, description) VALUES (?, ?, ?, ?, ?)");
                            $admin_user_id = 1; 
                            $profit_type = 'profit';
                            $insert_profit_stmt->bind_param("iisds", $admin_user_id, $order_id, $profit_type, $profit, $profit_desc);
                            $insert_profit_stmt->execute();
                            $insert_profit_stmt->close();

                            $success_message = "Order placed successfully! Secsers Order ID: " . $secsers_order_id;
                        } else {
                            $error_message = "Failed to save order to database.";
                            $rollback_balance = $_SESSION['balance'] + $user_total_price;
                            $rollback_stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
                            $rollback_stmt->bind_param("di", $rollback_balance, $user_id);
                            $rollback_stmt->execute();
                            $_SESSION['balance'] = $rollback_balance;
                        }
                        $insert_stmt->close();
                    } elseif (isset($secsers_response['error'])) {
                        $error_message = "Secsers API Error: " . htmlspecialchars($secsers_response['error']);
                        $rollback_balance = $_SESSION['balance'] + $user_total_price;
                        $rollback_stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
                        $rollback_stmt->bind_param("di", $rollback_balance, $user_id);
                        $rollback_stmt->execute();
                        $_SESSION['balance'] = $rollback_balance;
                    } else {
                        $error_message = "Unknown error from Secsers API or invalid response.";
                        error_log("Secsers API Raw Response: " . $response);
                        $rollback_balance = $_SESSION['balance'] + $user_total_price;
                        $rollback_stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
                        $rollback_stmt->bind_param("di", $rollback_balance, $user_id);
                        $rollback_stmt->execute();
                        $_SESSION['balance'] = $rollback_balance;
                    }
                } else {
                    $error_message = "Failed to deduct balance. Please try again.";
                }
                $update_balance_stmt->close();
            }
        }
    } else {
        $error_message = "Cannot place order: Service details not available.";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place Order</title>
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
        <h2 class="text-3xl font-bold mb-4 text-gray-800" data-aos="fade-right">Place Order</h2>
        <?php if ($error_message): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?></p>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <p class="text-green-500 mb-4" data-aos="fade-left"><i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?></p>
        <?php endif; ?>

        <?php if ($service): ?>
            <h3 class="text-2xl font-semibold mb-4 text-gray-800" data-aos="fade-right">Service: <?php echo htmlspecialchars($service['name']); ?> (<?php echo htmlspecialchars($service['category']); ?>)</h3>
            <p class="text-gray-700 mb-2" data-aos="fade-left">Rate (with markup): <span class="font-semibold">$<?php echo number_format($service['rate'] * GLOBAL_MARKUP_PERCENTAGE, 2); ?></span> per unit</p>
            <p class="text-gray-700 mb-2" data-aos="fade-left">Min Quantity: <span class="font-semibold"><?php echo htmlspecialchars($service['min']); ?></span></p>
            <p class="text-gray-700 mb-2" data-aos="fade-left">Max Quantity: <span class="font-semibold"><?php echo htmlspecialchars($service['max']); ?></span></p>
            <p class="text-gray-700 mb-6" data-aos="fade-left">Refill: <span class="font-semibold"><?php echo ($service['refill'] ? 'Yes' : 'No'); ?></span></p>

            <form action="order.php?service_id=<?php echo htmlspecialchars($service['id']); ?>" method="POST" data-aos="zoom-in">
                <div class="mb-4">
                    <label for="link" class="block text-gray-700 text-sm font-bold mb-2">Link:</label>
                    <input type="url" id="link" name="link" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required placeholder="e.g., Your Instagram post URL">
                    <p class="text-gray-600 text-xs italic mt-1"><i class="fas fa-info-circle mr-1"></i>The link to your social media post or profile.</p>
                </div>
                <div class="mb-6">
                    <label for="quantity" class="block text-gray-700 text-sm font-bold mb-2">Quantity:</label>
                    <input type="number" id="quantity" name="quantity" min="<?php echo htmlspecialchars($service['min']); ?>" max="<?php echo htmlspecialchars($service['max']); ?>" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:shadow-outline" required placeholder="Enter quantity">
                    <p class="text-gray-600 text-xs italic mt-1"><i class="fas fa-info-circle mr-1"></i>Min: <?php echo htmlspecialchars($service['min']); ?>, Max: <?php echo htmlspecialchars($service['max']); ?></p>
                </div>
                <button type="submit" name="place_order" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full">
                    <i class="fas fa-paper-plane mr-2"></i>Place Order
                </button>
            </form>
        <?php else: ?>
            <p class="text-gray-700">Please select a service from the <a href="index.php" class="text-blue-500 hover:underline">services list</a>.</p>
        <?php endif; ?>
    </div>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
    </script>
</body>
</html> 