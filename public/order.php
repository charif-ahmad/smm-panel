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
    $service_id = $_GET['service_id'];

    $stmt = $conn->prepare("SELECT id, service_id, name, type, category, rate, min, max, refill FROM services WHERE id = ?");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $service = $result->fetch_assoc();
    } else {
        $error_message = "Service not found.";
    }
    $stmt->close();
} else {
    $error_message = "Invalid service ID.";
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
            $markup_amount = GLOBAL_MARKUP_AMOUNT;
            $real_price_per_unit = $service['rate'];
            $user_price_per_unit = $real_price_per_unit + $markup_amount;
            
            $real_total_cost = $real_price_per_unit * $order_quantity;
            $user_total_price = $user_price_per_unit * $order_quantity;
            $profit = $user_total_price - $real_total_cost;

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
                    $success_message = "Order placed successfully! Secsers Order ID: " . $secsers_order_id;
                } else {
                    $error_message = "Failed to save order to database.";
                }
                $insert_stmt->close();
            } elseif (isset($secsers_response['error'])) {
                $error_message = "Secsers API Error: " . htmlspecialchars($secsers_response['error']);
            } else {
                $error_message = "Unknown error from Secsers API or invalid response.";
                error_log("Secsers API Raw Response: " . $response);
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
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h2>Place Order</h2>
        <?php if ($error_message): ?>
            <p style="color: red;"><?php echo $error_message; ?></p>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <p style="color: green;"><?php echo $success_message; ?></p>
        <?php endif; ?>

        <?php if ($service): ?>
            <h3>Service: <?php echo htmlspecialchars($service['name']); ?> (<?php echo htmlspecialchars($service['category']); ?>)</h3>
            <p>Rate (with markup): $<?php echo number_format($service['rate'] + GLOBAL_MARKUP_AMOUNT, 2); ?> per unit</p>
            <p>Min Quantity: <?php echo htmlspecialchars($service['min']); ?></p>
            <p>Max Quantity: <?php echo htmlspecialchars($service['max']); ?></p>
            <p>Refill: <?php echo ($service['refill'] ? 'Yes' : 'No'); ?></p>

            <form action="order.php?service_id=<?php echo htmlspecialchars($service['id']); ?>" method="POST">
                <div class="form-group">
                    <label for="link">Link:</label>
                    <input type="url" id="link" name="link" required>
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity:</label>
                    <input type="number" id="quantity" name="quantity" min="<?php echo htmlspecialchars($service['min']); ?>" max="<?php echo htmlspecialchars($service['max']); ?>" required>
                </div>
                <button type="submit" name="place_order">Place Order</button>
            </form>
        <?php else: ?>
            <p>Please select a service from the <a href="index.php">services list</a>.</p>
        <?php endif; ?>
        <p><a href="index.php">Back to Services</a> | <a href="logout.php">Logout</a></p>
    </div>
</body>
</html> 