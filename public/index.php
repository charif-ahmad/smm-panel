<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Redirect to login if not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Function to fetch services from Secsers API
function fetchServicesFromAPI($api_key, $api_url) {
    $post_data = array(
        'key' => $api_key,
        'action' => 'services'
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
        $services = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Decode Error: " . json_last_error_msg());
            return false;
        }
        return $services;
    }

    curl_close($ch);
}

$services = [];
$stmt = $conn->prepare("SELECT service_id, name, type, category, rate, min, max, refill FROM services");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
    }
} else {
    // If no services in DB, fetch from API and store
    $api_services = fetchServicesFromAPI(API_KEY, API_URL);
    if ($api_services) {
        foreach ($api_services as $api_service) {
            $service_id = $api_service['service'];
            $name = $api_service['name'];
            $type = $api_service['type'];
            $category = $api_service['category'];
            $rate = $api_service['rate'];
            $min = $api_service['min'];
            $max = $api_service['max'];
            $refill = $api_service['refill'] ? 1 : 0;

            $insert_stmt = $conn->prepare("INSERT INTO services (service_id, name, type, category, rate, min, max, refill) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("isssdiii", $service_id, $name, $type, $category, $rate, $min, $max, $refill);
            $insert_stmt->execute();
            $insert_stmt->close();
            $services[] = $api_service;
        }
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
    <title>Services</title>
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
        <h2 class="text-3xl font-bold mb-4 text-gray-800" data-aos="fade-right">Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
        <p class="text-lg text-gray-700 mb-6" data-aos="fade-left">Your current balance: <span class="font-semibold text-blue-600">$<?php echo number_format($_SESSION['balance'], 2); ?></span></p>
        <h3 class="text-2xl font-semibold mb-4 text-gray-800" data-aos="fade-right">Available Services</h3>
        <?php if (!empty($services)): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($services as $service): ?>
                    <div class="bg-white rounded-lg shadow-lg overflow-hidden transition-transform duration-300 hover:scale-105" data-aos="zoom-in">
                        <div class="p-6">
                            <h4 class="text-xl font-semibold text-gray-900 mb-2"><i class="fas fa-cogs mr-2"></i><?php echo htmlspecialchars($service['name']); ?></h4>
                            <p class="text-gray-600 text-sm mb-4"><i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($service['category']); ?></p>
                            <div class="flex justify-between items-center mb-4">
                                <span class="text-2xl font-bold text-blue-600">$<?php echo number_format($service['rate'] * GLOBAL_MARKUP_PERCENTAGE, 2); ?></span>
                                <span class="text-sm text-gray-500">per unit</span>
                            </div>
                            <ul class="text-gray-700 text-sm mb-6 space-y-1">
                                <li><i class="fas fa-arrow-down mr-2"></i><strong>Min Order:</strong> <?php echo htmlspecialchars($service['min']); ?></li>
                                <li><i class="fas fa-arrow-up mr-2"></i><strong>Max Order:</strong> <?php echo htmlspecialchars($service['max']); ?></li>
                                <li><i class="fas fa-redo mr-2"></i><strong>Refill:</strong> <?php echo ($service['refill'] ? 'Yes' : 'No'); ?></li>
                            </ul>
                            <a href="order.php?service_id=<?php echo htmlspecialchars($service['service_id']); ?>" class="block w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md text-center transition-colors duration-300">
                                <i class="fas fa-shopping-cart mr-2"></i>Order Now
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-gray-700">No services available at the moment. Please try again later.</p>
        <?php endif; ?>
    </div>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
    </script>
</body>
</html> 