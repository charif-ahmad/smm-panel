<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Redirect to login if not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Function to get service description from API response
function getServiceDescription($service) {
    $description = [];
    
    // Extract guarantee/refill info
    if (isset($service['refill']) && $service['refill']) {
        $description['Guarantee'] = 'Lifetime';
    } else {
        $description['Guarantee'] = 'No Refill';
    }
    
    // Extract quality info
    if (isset($service['type'])) {
        $description['Quality'] = $service['type'];
    } else {
        $description['Quality'] = 'Real accounts';
    }
    
    // Extract max order info
    if (isset($service['max'])) {
        if ($service['max'] >= 1000000) {
            $description['Max'] = 'Unlimited';
        } else {
            $description['Max'] = number_format($service['max']);
        }
    } else {
        $description['Max'] = 'Unlimited';
    }
    
    // Extract location info (if available in API)
    if (isset($service['location'])) {
        $description['Location'] = $service['location'];
    } else {
        $description['Location'] = 'Worldwide';
    }
    
    // Extract link format based on service name/category
    if (strpos($service['name'], 'Followers') !== false || strpos($service['category'], 'Followers') !== false) {
        $description['Link Format'] = 'Profile Link';
    } elseif (strpos($service['name'], 'Subscribers') !== false || strpos($service['category'], 'Subscribers') !== false) {
        $description['Link Format'] = 'Channel Link';
    } elseif (strpos($service['name'], 'Views') !== false || strpos($service['category'], 'Views') !== false) {
        $description['Link Format'] = 'Video Link';
    } else {
        $description['Link Format'] = 'Post Link';
    }
    
    // Intelligent unit detection based on service name/category
    $service_name_lower = strtolower($service['name'] . ' ' . $service['category']);
    
    // Per 1000 units (bulk services)
    if (strpos($service_name_lower, 'followers') !== false || 
        strpos($service_name_lower, 'likes') !== false || 
        strpos($service_name_lower, 'views') !== false || 
        strpos($service_name_lower, 'subscribers') !== false) {
        
        $description['PricingModel'] = 'per_1000';
        $description['Unit'] = 'per 1000 ' . getUnitType($service);
        
    } 
    // Per 1 unit (individual services)
    elseif (strpos($service_name_lower, 'comments') !== false || 
            strpos($service_name_lower, 'mentions') !== false || 
            strpos($service_name_lower, 'shares') !== false || 
            strpos($service_name_lower, 'retweets') !== false || 
            strpos($service_name_lower, 'votes') !== false) {
        
        $description['PricingModel'] = 'per_1';
        $description['Unit'] = 'per ' . getUnitType($service);
        
    } 
    // Default fallback
    else {
        $description['PricingModel'] = 'per_1000';
        $description['Unit'] = 'per 1000 ' . getUnitType($service);
    }
    
    // Speed is usually consistent
    $description['Speed'] = 'Fast';
    
    // Drop ratio
    $description['Drop-Ratio'] = 'Non-Drop';
    
    return $description;
}

// Helper function to get unit type
function getUnitType($service) {
    $service_name_lower = strtolower($service['name'] . ' ' . $service['category']);
    
    if (strpos($service_name_lower, 'followers') !== false) {
        return 'follower';
    } elseif (strpos($service_name_lower, 'likes') !== false) {
        return 'like';
    } elseif (strpos($service_name_lower, 'views') !== false) {
        return 'view';
    } elseif (strpos($service_name_lower, 'subscribers') !== false) {
        return 'subscriber';
    } elseif (strpos($service_name_lower, 'comments') !== false) {
        return 'comment';
    } elseif (strpos($service_name_lower, 'mentions') !== false) {
        return 'mention';
    } elseif (strpos($service_name_lower, 'shares') !== false) {
        return 'share';
    } elseif (strpos($service_name_lower, 'retweets') !== false) {
        return 'retweet';
    } elseif (strpos($service_name_lower, 'votes') !== false) {
        return 'vote';
    } else {
        return 'unit';
    }
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
        // Get dynamic description for the service
        $service['description'] = getServiceDescription($service);
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
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
        }
        .service-card {
            background: rgba(30, 30, 50, 0.9);
            border: 1px solid rgba(147, 51, 234, 0.3);
            backdrop-filter: blur(10px);
        }
        .section-title {
            color: #e2e8f0;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .service-dropdown {
            background: rgba(30, 30, 50, 0.8);
            border: 1px solid rgba(147, 51, 234, 0.5);
            color: #e2e8f0;
        }
        .feature-list {
            color: #e2e8f0;
        }
        .feature-list li {
            color: #f0abfc;
        }
        .price-display {
            background: rgba(30, 30, 50, 0.8);
            border: 1px solid rgba(147, 51, 234, 0.5);
            color: #e2e8f0;
        }
        .form-input {
            background: rgba(30, 30, 50, 0.8);
            border: 1px solid rgba(147, 51, 234, 0.5);
            color: #e2e8f0;
        }
        .form-input:focus {
            border-color: #a855f7;
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.1);
        }
        .form-input::placeholder {
            color: #9ca3af;
        }
        .form-select {
            background: rgba(30, 30, 50, 0.8);
            border: 1px solid rgba(147, 51, 234, 0.5);
            color: #e2e8f0;
        }
        .form-select:focus {
            border-color: #a855f7;
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.1);
        }
        .form-select option {
            background: #1a1a2e;
            color: #e2e8f0;
        }
    </style>
</head>
<body class="font-sans">
    <nav class="bg-gradient-to-r from-purple-900 to-indigo-900 p-4 text-white shadow-lg">
        <div class="container mx-auto flex justify-between items-center">
            <a href="index.php" class="text-2xl font-bold"><i class="fas fa-users mr-2"></i>SMM Reseller</a>
            <div class="space-x-4">
                <a href="index.php" class="hover:underline"><i class="fas fa-th-list mr-1"></i>Services</a>
                <a href="status.php" class="hover:underline"><i class="fas fa-history mr-1"></i>My Orders</a>
                <a href="balance.php" class="hover:underline"><i class="fas fa-wallet mr-1"></i>My Balance</a>
                <?php if ($_SESSION['is_admin']): ?>
                    <a href="admin_dashboard.php" class="hover:underline"><i class="fas fa-tachometer-alt mr-1"></i>Admin Dashboard</a>
                <?php endif; ?>
                <a href="logout.php" class="bg-red-600 hover:bg-red-700 px-3 py-2 rounded-md transition-colors"><i class="fas fa-sign-out-alt mr-1"></i>Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto p-6 mt-6">
        <div class="service-card rounded-lg p-6" data-aos="fade-up">
            <h2 class="text-3xl font-bold mb-6 text-white" data-aos="fade-right">Place Order</h2>
            
            <?php if ($error_message): ?>
                <div class="bg-red-900/50 border border-red-500 text-red-200 p-4 rounded-lg mb-6" data-aos="fade-left">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="bg-green-900/50 border border-green-500 text-green-200 p-4 rounded-lg mb-6" data-aos="fade-left">
                    <i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if ($service): ?>
                <!-- Department Section -->
                <div class="mb-6">
                    <h4 class="section-title">Department</h4>
                    <div class="service-dropdown rounded-lg p-3 flex items-center justify-between">
                        <div class="flex items-center">
                            <?php
                            $iconClass = 'fas fa-globe';
                            if (strpos($service['category'], 'Instagram') !== false) {
                                $iconClass = 'fab fa-instagram';
                            } elseif (strpos($service['category'], 'TikTok') !== false) {
                                $iconClass = 'fab fa-tiktok';
                            } elseif (strpos($service['category'], 'YouTube') !== false) {
                                $iconClass = 'fab fa-youtube';
                            } elseif (strpos($service['category'], 'Twitter') !== false) {
                                $iconClass = 'fab fa-twitter';
                            }
                            ?>
                            <i class="<?php echo $iconClass; ?> text-pink-500 mr-2"></i>
                            <span class="text-gray-300"><?php echo htmlspecialchars($service['category']); ?></span>
                        </div>
                        <i class="fas fa-chevron-down text-gray-400"></i>
                    </div>
                </div>

                <!-- Services Section -->
                <div class="mb-6">
                    <h4 class="section-title">Services</h4>
                    <div class="service-dropdown rounded-lg p-3 flex items-center justify-between">
                        <div class="flex items-center">
                            <i class="<?php echo $iconClass; ?> text-pink-500 mr-2"></i>
                            <span class="text-gray-300"><?php echo htmlspecialchars($service['name']); ?></span>
                            <i class="fas fa-check-circle text-green-500 ml-2"></i>
                        </div>
                        <div class="text-right">
                            <span class="text-purple-400 font-semibold">$<?php echo number_format($service['rate'] * GLOBAL_MARKUP_PERCENTAGE, 2); ?></span>
                            <i class="fas fa-chevron-down text-gray-400 ml-2"></i>
                        </div>
                    </div>
                </div>

                <!-- Description Section -->
                <div class="mb-6">
                    <h4 class="section-title">Description</h4>
                    <?php
                    // Use dynamic description from the API response
                    $description = $service['description'];
                    ?>
                    <ul class="feature-list text-sm space-y-1">
                        <li class="flex items-center">
                            <span class="text-pink-400 mr-2">-</span>
                            <span>Speed: <?php echo $description['Speed']; ?></span>
                        </li>
                        <li class="flex items-center">
                            <span class="text-pink-400 mr-2">-</span>
                            <span>Quality: <?php echo $description['Quality']; ?></span>
                        </li>
                        <li class="flex items-center">
                            <span class="text-pink-400 mr-2">-</span>
                            <span>Guarantee: <span class="underline"><?php echo $description['Guarantee']; ?></span></span>
                        </li>
                        <li class="flex items-center">
                            <span class="text-pink-400 mr-2">-</span>
                            <span>Drop-Ratio: <?php echo $description['Drop-Ratio']; ?></span>
                        </li>
                        <li class="flex items-center">
                            <span class="text-pink-400 mr-2">-</span>
                            <span>Link Format: <?php echo $description['Link Format']; ?></span>
                        </li>
                        <li class="flex items-center">
                            <span class="text-pink-400 mr-2">-</span>
                            <span>Max Order: <?php echo $description['Max']; ?></span>
                        </li>
                        <li class="flex items-center">
                            <span class="text-pink-400 mr-2">-</span>
                            <span>Location: <?php echo $description['Location']; ?></span>
                        </li>
                        <li class="flex items-center">
                            <span class="text-pink-400 mr-2">-</span>
                            <span>Unit: <?php echo $description['Unit']; ?></span>
                        </li>
                    </ul>
                </div>

                <!-- Notes Section -->
                <div class="mb-6">
                    <h4 class="section-title">Notes</h4>
                    <ul class="feature-list text-sm space-y-1">
                        <li class="flex items-start">
                            <i class="fas fa-star text-pink-400 mr-2 mt-1"></i>
                            <span>We can not cancel your order once it has been submitted.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-star text-pink-400 mr-2 mt-1"></i>
                            <span>Check the link format carefully before placing the order.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-star text-pink-400 mr-2 mt-1"></i>
                            <span>Kindly make sure your account is public, Not private.</span>
                        </li>
                    </ul>
                </div>

                <!-- Alert Section -->
                <div class="mb-6">
                    <h4 class="section-title">Alert</h4>
                    <ul class="feature-list text-sm space-y-1">
                        <li class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mr-2 mt-1"></i>
                            <span>Do not put multiple orders for the same link before completion.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mr-2 mt-1"></i>
                            <span>We cannot refill your order if the drop is below the start count.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mr-2 mt-1"></i>
                            <span>The Quantity must be in multiples of 100, 200, 500, 1000, etc.</span>
                        </li>
                    </ul>
                </div>

                <!-- Link Section -->
                <div class="mb-6">
                    <h4 class="section-title">Link</h4>
                    <form action="order.php?service_id=<?php echo htmlspecialchars($service['id']); ?>" method="POST" data-aos="zoom-in">
                        <input type="url" id="link" name="link" 
                               class="form-input rounded-lg w-full py-3 px-4 focus:outline-none transition-all duration-300" 
                               required placeholder="Enter your <?php echo strtolower($description['Link Format']); ?> here">
                        <p class="text-gray-400 text-xs mt-2"><i class="fas fa-info-circle mr-1"></i>The <?php echo strtolower($description['Link Format']); ?> to your social media <?php echo strpos($description['Link Format'], 'Profile') !== false ? 'profile' : 'post'; ?>.</p>
                </div>

                <!-- Quantity Section -->
                <div class="mb-6">
                    <h4 class="section-title">Quantity</h4>
                    <?php
                    // Check if this is a single-use service
                    $isSingleUse = false;
                    
                    // Check if min and max are both 1
                    if ($service['min'] == 1 && $service['max'] == 1) {
                        $isSingleUse = true;
                    }
                    
                    // Check if service name contains single-use keywords
                    $serviceNameLower = strtolower($service['name'] . ' ' . $service['category']);
                    $singleUseKeywords = ['comment', 'vote', 'reaction', 'mention', 'share', 'retweet'];
                    
                    foreach ($singleUseKeywords as $keyword) {
                        if (strpos($serviceNameLower, $keyword) !== false) {
                            $isSingleUse = true;
                            break;
                        }
                    }
                    ?>
                    <input type="number" id="quantity" name="quantity" 
                           value="<?php echo $isSingleUse ? '1' : ''; ?>"
                           min="<?php echo $isSingleUse ? '1' : htmlspecialchars($service['min']); ?>" 
                           max="<?php echo $isSingleUse ? '1' : htmlspecialchars($service['max']); ?>" 
                           class="form-input rounded-lg w-full py-3 px-4 focus:outline-none transition-all duration-300 <?php echo $isSingleUse ? 'bg-gray-700 cursor-not-allowed' : ''; ?>" 
                           required 
                           placeholder="<?php echo $isSingleUse ? 'Single use service - quantity locked to 1' : 'Enter quantity'; ?>"
                           <?php echo $isSingleUse ? 'disabled' : ''; ?>>
                    <?php if (!$isSingleUse): ?>
                        <p class="text-gray-400 text-xs mt-2">
                            <i class="fas fa-info-circle mr-1"></i>Min: <?php echo htmlspecialchars($service['min']); ?>, Max: <?php echo htmlspecialchars($service['max']); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Charge Section -->
                <div class="mb-6">
                    <h4 class="section-title">Charge</h4>
                    <div class="price-display rounded-lg p-3 text-center">
                        <div class="mb-2">
                            <span class="text-lg font-semibold text-purple-400">$<?php echo number_format($service['rate'] * GLOBAL_MARKUP_PERCENTAGE, 3); ?></span>
                            <span class="text-gray-400 ml-2"><?php echo $description['Unit']; ?></span>
                        </div>
                        <div class="border-t border-gray-600 pt-2">
                            <span class="text-2xl font-bold text-purple-400" id="totalPrice">
                                $<?php echo $isSingleUse ? number_format($service['rate'] * GLOBAL_MARKUP_PERCENTAGE, 3) : '0.000'; ?>
                            </span>
                            <span class="text-gray-400 ml-2">total</span>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" name="place_order" 
                        class="w-full bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-bold py-4 px-6 rounded-lg transition-all duration-300 transform hover:scale-105">
                    <i class="fas fa-paper-plane mr-2"></i>Place Order
                </button>
                </form>

                <script>
                    // Dynamic pricing calculation with intelligent unit detection
                    const quantityInput = document.getElementById('quantity');
                    const totalPriceElement = document.getElementById('totalPrice');
                    const baseRate = <?php echo $service['rate'] * GLOBAL_MARKUP_PERCENTAGE; ?>;
                    const pricingModel = '<?php echo $description['PricingModel']; ?>';
                    const isSingleUse = <?php echo $isSingleUse ? 'true' : 'false'; ?>;
                    
                    // For single-use services, set total immediately and don't add event listener
                    if (!isSingleUse) {
                        quantityInput.addEventListener('input', function() {
                            const quantity = parseFloat(this.value) || 0;
                            let total;
                            
                            // Calculate total based on pricing model
                            if (pricingModel === 'per_1') {
                                // Per 1 unit pricing
                                total = quantity * baseRate;
                            } else {
                                // Per 1000 units pricing (default)
                                total = (quantity / 1000) * baseRate;
                            }
                            
                            // Format to 3 decimal places
                            totalPriceElement.textContent = `$${total.toFixed(3)}`;
                        });
                    }
                </script>

            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-exclamation-circle text-gray-400 text-4xl mb-4"></i>
                    <p class="text-gray-400">Please select a service from the <a href="index.php" class="text-purple-400 hover:underline">services list</a>.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
    </script>
</body>
</html> 