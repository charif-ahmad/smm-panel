<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Redirect to login if not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$balance = 'N/A';
$currency = 'N/A';
$error_message = '';

// Fetch user balance from Secsers API
$post_data = array(
    'key' => API_KEY,
    'action' => 'balance'
);

$ch = curl_init(API_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $error_message = "cURL Error: " . curl_error($ch);
    error_log($error_message);
} else {
    $balance_response = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error_message = "JSON Decode Error: " . json_last_error_msg();
        error_log($error_message . ": " . $response);
    } elseif (isset($balance_response['balance']) && isset($balance_response['currency'])) {
        $balance = number_format($balance_response['balance'], 2);
        $currency = htmlspecialchars($balance_response['currency']);
    } elseif (isset($balance_response['error'])) {
        $error_message = "Secsers API Error: " . htmlspecialchars($balance_response['error']);
    } else {
        $error_message = "Unknown error from Secsers API or invalid response.";
        error_log("Secsers API Raw Response: " . $response);
    }
}
curl_close($ch);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Balance</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h2>Your Current Balance</h2>
        <?php if ($error_message): ?>
            <p style="color: red;"><?php echo $error_message; ?></p>
        <?php else: ?>
            <p><strong>Balance:</strong> <?php echo $currency; ?> <?php echo $balance; ?></p>
        <?php endif; ?>
        <p><a href="index.php">Back to Services</a> | <a href="status.php">View Orders</a> | <a href="logout.php">Logout</a></p>
    </div>
</body>
</html> 