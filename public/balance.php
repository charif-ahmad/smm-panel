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
        <h2 class="text-3xl font-bold mb-4 text-gray-800" data-aos="fade-right">Your Current Balance</h2>
        <?php if ($error_message): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?></p>
        <?php else: ?>
            <p class="text-lg text-gray-700 mb-6" data-aos="fade-left"><strong>Balance:</strong> <span class="font-semibold text-blue-600"><?php echo $currency; ?> <?php echo $balance; ?></span></p>
            <a href="recharge.php" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline" data-aos="zoom-in"><i class="fas fa-money-check-alt mr-2"></i>Recharge Balance</a>
        <?php endif; ?>
    </div>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
    </script>
</body>
</html> 