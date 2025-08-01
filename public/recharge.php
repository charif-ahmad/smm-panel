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
$error_message = '';
$success_message = '';
$crypto_payment_details = null; // To store crypto payment address and amount

// Function to create payment with NowPayments API
function createNowPaymentsPayment($api_key, $api_url, $order_id, $amount, $currency, $ipn_callback_url) {
    $payload = [
        'price_amount' => $amount,
        'price_currency' => 'usd',
        'pay_currency' => strtolower(str_replace('USDT ', '', $currency)),
        'order_id' => $order_id,
        'ipn_callback_url' => $ipn_callback_url,
        'success_url' => 'https://yourdomain.com/public/balance.php?payment=success', // Replace with your actual domain
        'cancel_url' => 'https://yourdomain.com/public/recharge.php?payment=cancelled' // Replace with your actual domain
    ];

    $headers = [
        'x-api-key: ' . $api_key,
        'Content-Type: application/json'
    ];

    $ch = curl_init(NOWPAYMENTS_API_URL . '/payment');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("NowPayments cURL Error: " . curl_error($ch));
        return false;
    } else {
        $payment_data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("NowPayments JSON Decode Error: " . json_last_error_msg() . ": " . $response);
            return false;
        }
        return $payment_data;
    }
    curl_close($ch);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['recharge_amount'])) {
    $recharge_amount = filter_input(INPUT_POST, 'recharge_amount', FILTER_VALIDATE_FLOAT);
    $payment_method = htmlspecialchars(trim($_POST['payment_method']));

    if ($recharge_amount === false || $recharge_amount <= 0) {
        $error_message = "Invalid recharge amount.";
    } elseif (empty($payment_method)) {
        $error_message = "Please select a payment method.";
    } else {
        if (strpos($payment_method, 'USDT') !== false) {
            // Crypto payment
            $order_id = 'recharge_' . $user_id . '_' . time(); // Unique order ID
            $ipn_callback_url = 'https://yourdomain.com/includes/nowpayments_webhook.php'; // Replace with your actual domain and webhook path

            $payment_response = createNowPaymentsPayment(NOWPAYMENTS_API_KEY, NOWPAYMENTS_API_URL, $order_id, $recharge_amount, $payment_method, $ipn_callback_url);

            if ($payment_response && isset($payment_response['pay_address']) && isset($payment_response['pay_amount']) && isset($payment_response['payment_id'])) {
                $crypto_payment_details = [
                    'pay_address' => $payment_response['pay_address'],
                    'pay_amount' => $payment_response['pay_amount'],
                    'pay_currency' => strtoupper($payment_response['pay_currency']),
                    'payment_id' => $payment_response['payment_id'],
                    'order_id' => $order_id
                ];
                // Store payment_id and order_id in session or temporary DB table for webhook verification
                $_SESSION['current_np_payment'] = $crypto_payment_details;
                $success_message = "Please send " . $crypto_payment_details['pay_amount'] . " " . $crypto_payment_details['pay_currency'] . " to the address below.";
            } elseif ($payment_response && isset($payment_response['message'])) {
                $error_message = "NowPayments API Error: " . htmlspecialchars($payment_response['message']);
            } else {
                $error_message = "Failed to initiate cryptocurrency payment. Unknown API error.";
                error_log("NowPayments Raw Response [Recharge]: " . json_encode($payment_response));
            }
        } else {
            // Manual payment (OMT, Whish, Cash United) - Existing logic
            // Update user's balance
            $update_balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $update_balance_stmt->bind_param("di", $recharge_amount, $user_id);

            if ($update_balance_stmt->execute()) {
                $_SESSION['balance'] += $recharge_amount; // Update session balance

                // Record deposit transaction
                $transaction_desc = "Recharge via " . $payment_method;
                $insert_transaction_stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description) VALUES (?, ?, ?, ?)");
                $transaction_type = 'deposit';
                $insert_transaction_stmt->bind_param("isds", $user_id, $transaction_type, $recharge_amount, $transaction_desc);
                $insert_transaction_stmt->execute();
                $insert_transaction_stmt->close();

                $success_message = "Balance recharged successfully! Please contact support to confirm your manual payment.";
            } else {
                $error_message = "Failed to process recharge. Please try again.";
            }
            $update_balance_stmt->close();
        }
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recharge Balance</title>
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
        <h2 class="text-3xl font-bold mb-4 text-gray-800" data-aos="fade-right">Recharge Your Balance</h2>
        <?php if ($error_message): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?></p>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <p class="text-green-500 mb-4" data-aos="fade-left"><i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?></p>
        <?php endif; ?>

        <p class="text-lg text-gray-700 mb-6" data-aos="fade-left">Your current balance: <span class="font-semibold text-blue-600">$<?php echo number_format($_SESSION['balance'], 2); ?></span></p>

        <?php if ($crypto_payment_details): ?>
            <div class="bg-yellow-100 p-4 rounded-lg shadow-sm mb-6 text-center" data-aos="zoom-in">
                <h3 class="text-xl font-semibold text-yellow-800 mb-3">Cryptocurrency Payment Instructions</h3>
                <p class="text-gray-700 mb-2">Please send exactly <span class="font-bold text-lg text-yellow-900"><?php echo htmlspecialchars($crypto_payment_details['pay_amount']); ?> <?php echo htmlspecialchars($crypto_payment_details['pay_currency']); ?></span> to the address below:</p>
                <p class="font-mono text-sm break-all bg-gray-200 p-3 rounded-md mb-3"><i class="fas fa-wallet mr-2"></i><span id="crypto-address"><?php echo htmlspecialchars($crypto_payment_details['pay_address']); ?></span></p>
                <button onclick="copyToClipboard('crypto-address')" class="bg-gray-700 hover:bg-gray-900 text-white font-bold py-2 px-4 rounded-md inline-flex items-center"><i class="fas fa-copy mr-2"></i>Copy Address</button>
                <p class="text-red-600 text-sm mt-3"><i class="fas fa-exclamation-triangle mr-1"></i>Important: Send the EXACT amount. Sending less or more may result in loss of funds.</p>
                <p class="text-gray-600 text-sm mt-2">Your payment will be automatically detected and credited to your account once confirmed on the blockchain.</p>
            </div>
            <script>
                function copyToClipboard(elementId) {
                    var copyText = document.getElementById(elementId);
                    var textArea = document.createElement("textarea");
                    textArea.value = copyText.textContent;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand("Copy");
                    textArea.remove();
                    alert("Address copied to clipboard!");
                }
            </script>
        <?php else: ?>
            <form action="recharge.php" method="POST" class="max-w-md mx-auto" data-aos="zoom-in">
                <div class="mb-4">
                    <label for="recharge_amount" class="block text-gray-700 text-sm font-bold mb-2">Recharge Amount:</label>
                    <input type="number" id="recharge_amount" name="recharge_amount" step="0.01" min="0.01" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required placeholder="e.g., 10.00">
                </div>
                <div class="mb-6">
                    <label for="payment_method" class="block text-gray-700 text-sm font-bold mb-2">Payment Method:</label>
                    <select id="payment_method" name="payment_method" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                        <option value="">Select a method</option>
                        <option value="OMT">OMT</option>
                        <option value="Whish">Whish</option>
                        <option value="Cash United">Cash United</option>
                        <option value="USDT TRC20">USDT (TRC20)</option>
                        <option value="USDT ERC20">USDT (ERC20)</option>
                    </select>
                    <p class="text-gray-600 text-xs italic mt-1"><i class="fas fa-info-circle mr-1"></i>For cryptocurrency payments, instructions will be provided upon submission.</p>
                </div>
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full">
                    <i class="fas fa-money-check-alt mr-2"></i>Submit Recharge Request
                </button>
            </form>

            <p class="mt-6 text-gray-600 text-sm">
                <i class="fas fa-exclamation-triangle mr-1"></i>Note: For manual payment methods (OMT, Whish, Cash United), please contact support after submitting your request to complete the top-up.
            </p>
        <?php endif; ?>
    </div>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
    </script>
</body>
</html> 