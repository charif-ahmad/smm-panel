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

$services = [];
$stmt = $conn->prepare("SELECT service_id, name, type, category, rate, min, max, refill FROM services");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Get dynamic description for each service
        $row['description'] = getServiceDescription($row);
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
            
            // Add dynamic description
            $api_service['description'] = getServiceDescription($api_service);
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
        <!-- Welcome Section -->
        <div class="service-card rounded-lg p-6 mb-6" data-aos="fade-up">
            <h2 class="text-3xl font-bold mb-4 text-white">Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
            <p class="text-lg text-gray-300 mb-4">Your current balance: <span class="font-semibold text-purple-400">$<?php echo number_format($_SESSION['balance'], 2); ?></span></p>
        </div>

        <!-- Dynamic Service Selection Form -->
        <div class="service-card rounded-lg p-6" data-aos="fade-up">
            <h3 class="text-2xl font-semibold mb-6 text-white">Select Your Service</h3>
            
            <?php if (!empty($services)): ?>
                <!-- Department Selection -->
                <div class="mb-6">
                    <h4 class="section-title">Department</h4>
                    <select id="departmentSelect" class="form-select rounded-lg w-full py-3 px-4 focus:outline-none transition-all duration-300">
                        <option value="">Select a department...</option>
                        <?php
                        $departments = array_unique(array_column($services, 'category'));
                        foreach ($departments as $dept):
                        ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Service Selection (initially hidden) -->
                <div id="serviceSelection" class="mb-6" style="display: none;">
                    <h4 class="section-title">Services</h4>
                    <select id="serviceSelect" class="form-select rounded-lg w-full py-3 px-4 focus:outline-none transition-all duration-300">
                        <option value="">Select a service...</option>
                    </select>
                </div>

                <!-- Service Details Card (initially hidden) -->
                <div id="serviceDetails" style="display: none;">
                    <!-- Service details will be populated here -->
                </div>

            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-exclamation-circle text-gray-400 text-4xl mb-4"></i>
                    <p class="text-gray-400">No services available at the moment. Please try again later.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Service Data for JavaScript -->
    <script>
        const servicesData = <?php echo json_encode($services); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            const departmentSelect = document.getElementById('departmentSelect');
            const serviceSelect = document.getElementById('serviceSelect');
            const serviceSelection = document.getElementById('serviceSelection');
            const serviceDetails = document.getElementById('serviceDetails');

            // Department selection change
            departmentSelect.addEventListener('change', function() {
                const selectedDept = this.value;
                
                if (selectedDept) {
                    // Filter services by department
                    const filteredServices = servicesData.filter(service => service.category === selectedDept);
                    
                    // Populate service dropdown
                    serviceSelect.innerHTML = '<option value="">Select a service...</option>';
                    filteredServices.forEach(service => {
                        const option = document.createElement('option');
                        option.value = service.service_id;
                        option.textContent = `${service.service_id} - ${service.name}`;
                        option.dataset.service = JSON.stringify(service);
                        serviceSelect.appendChild(option);
                    });
                    
                    serviceSelection.style.display = 'block';
                    serviceDetails.style.display = 'none';
                } else {
                    serviceSelection.style.display = 'none';
                    serviceDetails.style.display = 'none';
                }
            });

            // Service selection change
            serviceSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                
                if (this.value) {
                    const service = JSON.parse(selectedOption.dataset.service);
                    displayServiceDetails(service);
                } else {
                    serviceDetails.style.display = 'none';
                }
            });
        });

        function displayServiceDetails(service) {
            const serviceDetails = document.getElementById('serviceDetails');
            
            // Use the dynamic description from API
            const description = service.description || {
                'Speed': 'Fast',
                'Quality': 'Real accounts',
                'Guarantee': 'Lifetime',
                'Drop-Ratio': 'Non-Drop',
                'Link Format': 'Post Link',
                'Max': 'Unlimited',
                'Location': 'Worldwide',
                'Unit': 'per unit',
                'PricingModel': 'per_1000'
            };

            // Check if this is a single-use service
            const isSingleUse = checkIfSingleUse(service);

            // Get appropriate icon based on category
            let iconClass = 'fas fa-globe';
            if (service.category.includes('Instagram')) {
                iconClass = 'fab fa-instagram';
            } else if (service.category.includes('TikTok')) {
                iconClass = 'fab fa-tiktok';
            } else if (service.category.includes('YouTube')) {
                iconClass = 'fab fa-youtube';
            } else if (service.category.includes('Twitter')) {
                iconClass = 'fab fa-twitter';
            }

            const baseRate = (service.rate * <?php echo GLOBAL_MARKUP_PERCENTAGE; ?>).toFixed(3);
            
            serviceDetails.innerHTML = `
                <div class="service-card rounded-lg p-6 transition-all duration-300 hover:scale-105 hover:border-purple-400" data-aos="zoom-in">
                    <!-- Department Section -->
                    <div class="mb-4">
                        <h4 class="section-title">Department</h4>
                        <div class="service-dropdown rounded-lg p-3 flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="${iconClass} text-pink-500 mr-2"></i>
                                <span class="text-gray-300">${service.category}</span>
                            </div>
                            <i class="fas fa-chevron-down text-gray-400"></i>
                        </div>
                    </div>

                    <!-- Services Section -->
                    <div class="mb-4">
                        <h4 class="section-title">Services</h4>
                        <div class="service-dropdown rounded-lg p-3 flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="${iconClass} text-pink-500 mr-2"></i>
                                <span class="text-gray-300">${service.name}</span>
                                <i class="fas fa-check-circle text-green-500 ml-2"></i>
                            </div>
                            <div class="text-right">
                                <span class="text-purple-400 font-semibold">$${baseRate}</span>
                                <span class="text-gray-400 text-sm ml-1">${description.Unit}</span>
                                <i class="fas fa-chevron-down text-gray-400 ml-2"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Description Section -->
                    <div class="mb-4">
                        <h4 class="section-title">Description</h4>
                        <ul class="feature-list text-sm space-y-1">
                            <li class="flex items-center">
                                <span class="text-pink-400 mr-2">-</span>
                                <span>Speed: ${description.Speed}</span>
                            </li>
                            <li class="flex items-center">
                                <span class="text-pink-400 mr-2">-</span>
                                <span>Quality: ${description.Quality}</span>
                            </li>
                            <li class="flex items-center">
                                <span class="text-pink-400 mr-2">-</span>
                                <span>Guarantee: <span class="underline">${description.Guarantee}</span></span>
                            </li>
                            <li class="flex items-center">
                                <span class="text-pink-400 mr-2">-</span>
                                <span>Drop-Ratio: ${description['Drop-Ratio']}</span>
                            </li>
                            <li class="flex items-center">
                                <span class="text-pink-400 mr-2">-</span>
                                <span>Link Format: ${description['Link Format']}</span>
                            </li>
                            <li class="flex items-center">
                                <span class="text-pink-400 mr-2">-</span>
                                <span>Max Order: ${description.Max}</span>
                            </li>
                            <li class="flex items-center">
                                <span class="text-pink-400 mr-2">-</span>
                                <span>Location: ${description.Location}</span>
                            </li>
                            <li class="flex items-center">
                                <span class="text-pink-400 mr-2">-</span>
                                <span>Pricing: ${description.Unit}</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Notes Section -->
                    <div class="mb-4">
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
                    <div class="mb-4">
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

                    <!-- Quantity Calculator Section -->
                    <div class="mb-4">
                        <h4 class="section-title">Quantity Calculator</h4>
                        <div class="mb-3">
                            <label class="block text-gray-300 text-sm mb-2">
                                ${isSingleUse ? 'Quantity (Single Use Service):' : 'Enter Quantity:'}
                            </label>
                            <input type="number" id="quantityInput" 
                                   value="${isSingleUse ? '1' : ''}"
                                   min="${isSingleUse ? '1' : service.min}" 
                                   max="${isSingleUse ? '1' : service.max}" 
                                   class="form-input rounded-lg w-full py-3 px-4 focus:outline-none transition-all duration-300 ${isSingleUse ? 'bg-gray-700 cursor-not-allowed' : ''}" 
                                   placeholder="${isSingleUse ? 'Single use service - quantity locked to 1' : `Enter quantity (min: ${service.min}, max: ${service.max})`}"
                                   ${isSingleUse ? 'disabled' : ''}>
                            ${!isSingleUse ? `<p class="text-gray-400 text-xs mt-1">
                                <i class="fas fa-info-circle mr-1"></i>Min: ${service.min}, Max: ${service.max}
                            </p>` : ''}
                        </div>
                        
                        <!-- Total Price Display -->
                        <div class="mb-4">
                            <h4 class="section-title">Total Charge</h4>
                            <div class="price-display rounded-lg p-3 text-center">
                                <span class="text-2xl font-bold text-purple-400" id="totalPrice">$${isSingleUse ? baseRate : '0.000'}</span>
                                <span class="text-gray-400 ml-2">total</span>
                            </div>
                        </div>
                    </div>

                    <!-- Service Details -->
                    <div class="mb-4">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-gray-400">Min Order:</span>
                                <span class="text-white font-semibold">${service.min}</span>
                            </div>
                            <div>
                                <span class="text-gray-400">Max Order:</span>
                                <span class="text-white font-semibold">${service.max}</span>
                            </div>
                            <div>
                                <span class="text-gray-400">Refill:</span>
                                <span class="text-white font-semibold">${service.refill ? 'Yes' : 'No'}</span>
                            </div>
                            <div>
                                <span class="text-gray-400">Base Rate:</span>
                                <span class="text-white font-semibold">$${baseRate}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Order Button -->
                    <a href="order.php?service_id=${service.service_id}" 
                       class="block w-full bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-bold py-3 px-4 rounded-lg text-center transition-all duration-300 transform hover:scale-105">
                        <i class="fas fa-shopping-cart mr-2"></i>Order Now
                    </a>
                </div>
            `;
            
            serviceDetails.style.display = 'block';
            
            // Add event listener for quantity input with dynamic pricing
            const quantityInput = document.getElementById('quantityInput');
            const totalPriceElement = document.getElementById('totalPrice');
            const pricingModel = description.PricingModel || 'per_1000';
            const baseRateValue = parseFloat(baseRate);
            
            // For single-use services, set total immediately
            if (isSingleUse) {
                totalPriceElement.textContent = `$${baseRateValue.toFixed(3)}`;
            } else {
                // Regular event listener for non-single-use services
                quantityInput.addEventListener('input', function() {
                    const quantity = parseFloat(this.value) || 0;
                    let total;
                    
                    // Calculate total based on pricing model
                    if (pricingModel === 'per_1') {
                        // Per 1 unit pricing
                        total = quantity * baseRateValue;
                    } else {
                        // Per 1000 units pricing (default)
                        total = (quantity / 1000) * baseRateValue;
                    }
                    
                    // Format to 3 decimal places
                    totalPriceElement.textContent = `$${total.toFixed(3)}`;
                });
            }
        }

        // Function to check if service is single-use
        function checkIfSingleUse(service) {
            // Check if min and max are both 1
            if (service.min === 1 && service.max === 1) {
                return true;
            }
            
            // Check if service name contains single-use keywords
            const serviceNameLower = service.name.toLowerCase() + ' ' + service.category.toLowerCase();
            const singleUseKeywords = ['comment', 'vote', 'reaction', 'mention', 'share', 'retweet'];
            
            return singleUseKeywords.some(keyword => serviceNameLower.includes(keyword));
        }
    </script>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
    </script>
</body>
</html> 