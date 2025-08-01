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
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h2>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
        <p>Your current balance: (Will implement balance check later)</p>
        <h3>Available Services</h3>
        <?php if (!empty($services)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Service Name</th>
                        <th>Category</th>
                        <th>Rate</th>
                        <th>Min Order</th>
                        <th>Max Order</th>
                        <th>Refill</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($service['name']); ?></td>
                            <td><?php echo htmlspecialchars($service['category']); ?></td>
                            <td>$<?php echo number_format($service['rate'] + GLOBAL_MARKUP_AMOUNT, 2); ?></td>
                            <td><?php echo htmlspecialchars($service['min']); ?></td>
                            <td><?php echo htmlspecialchars($service['max']); ?></td>
                            <td><?php echo ($service['refill'] ? 'Yes' : 'No'); ?></td>
                            <td><a href="order.php?service_id=<?php echo htmlspecialchars($service['service_id']); ?>">Order</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No services available at the moment. Please try again later.</p>
        <?php endif; ?>
        <p><a href="logout.php">Logout</a></p>
    </div>
</body>
</html> 