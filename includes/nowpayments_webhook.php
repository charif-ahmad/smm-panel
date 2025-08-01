<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Capture raw POST data
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// For debugging: log incoming IPN data
file_put_contents('nowpayments_ipn_log.txt', "\n---" . date('Y-m-d H:i:s') . "---\n" . $json_data . "\n---\n", FILE_APPEND);

// Verify IPN signature
$recived_hmac = isset($_SERVER['HTTP_X_NOWPAYMENTS_SIG']) ? $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] : '';
if (empty($recived_hmac)) {
    http_response_code(400); // Bad Request
    error_log("NowPayments IPN Error: No signature header sent.");
    die('No signature header sent.');
}

$request_data = json_decode($json_data, true);
ksort($request_data);
$sorted_json = json_encode($request_data);

if ($json_data === false || $sorted_json === false) {
    http_response_code(400); // Bad Request
    error_log("NowPayments IPN Error: Invalid JSON payload.");
    die('Invalid JSON payload.');
}

$calculated_hmac = hash_hmac("sha512", $sorted_json, NOWPAYMENTS_IPN_SECRET);

if ($calculated_hmac !== $recived_hmac) {
    http_response_code(403); // Forbidden
    error_log("NowPayments IPN Error: HMAC signature mismatch.");
    die('HMAC signature mismatch.');
}

// Process IPN data
if (isset($data['payment_id']) && isset($data['payment_status']) && isset($data['order_id']) && isset($data['price_amount'])) {
    $payment_id = htmlspecialchars($data['payment_id']);
    $payment_status = htmlspecialchars($data['payment_status']);
    $order_id = htmlspecialchars($data['order_id']); // This is our internal order_id (recharge_{user_id}_{timestamp})
    $price_amount = floatval($data['price_amount']); // Amount in USD

    // Extract user_id from our internal order_id
    preg_match('/recharge_(\d+)_(\d+)/', $order_id, $matches);
    $user_id = isset($matches[1]) ? intval($matches[1]) : 0;

    if ($user_id === 0) {
        http_response_code(400);
        error_log("NowPayments IPN Error: Invalid user ID extracted from order_id: " . $order_id);
        die('Invalid user ID.');
    }

    // In a real system, you would also check if this payment_id has already been processed
    // to prevent double-spending or duplicate transactions.

    switch ($payment_status) {
        case 'finished':
            // Payment completed successfully
            // Update user balance and record transaction
            $conn->begin_transaction();
            try {
                // Update user's balance
                $update_balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $update_balance_stmt->bind_param("di", $price_amount, $user_id);
                $update_balance_stmt->execute();
                $update_balance_stmt->close();

                // Record deposit transaction
                $transaction_desc = "Crypto Recharge via NowPayments (Payment ID: " . $payment_id . ")";
                $insert_transaction_stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description) VALUES (?, ?, ?, ?)");
                $transaction_type = 'deposit';
                $insert_transaction_stmt->bind_param("isds", $user_id, $transaction_type, $price_amount, $transaction_desc);
                $insert_transaction_stmt->execute();
                $insert_transaction_stmt->close();

                $conn->commit();
                http_response_code(200);
                echo "OK";
                error_log("NowPayments IPN: Payment finished and balance updated for user " . $user_id . ", Payment ID: " . $payment_id);
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                http_response_code(500);
                error_log("NowPayments IPN Error: Database transaction failed for payment " . $payment_id . ": " . $e->getMessage());
                echo "Database error.";
            }
            break;

        case 'failed':
        case 'expired':
        case 'refunded':
        case 'partially_paid':
            // Handle other payment statuses as needed (e.g., log, notify user)
            error_log("NowPayments IPN: Payment " . $payment_id . " has status: " . $payment_status);
            http_response_code(200);
            echo "OK"; // Always return OK to NowPayments to avoid repeated notifications
            break;

        default:
            // Payment is still pending or in an unknown state
            error_log("NowPayments IPN: Received unknown or pending status for payment " . $payment_id . ": " . $payment_status);
            http_response_code(200);
            echo "OK";
            break;
    }
} else {
    http_response_code(400); // Bad Request
    error_log("NowPayments IPN Error: Missing required fields in payload.");
    die('Missing required fields.');
}

$conn->close();
?> 