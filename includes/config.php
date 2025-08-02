<?php

define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'smm_reseller');

define('API_KEY', 'fda14a84ed59996cc089a38c9fdbc48e'); // Your Secsers.com API Key
define('API_URL', 'https://secsers.com/api/v2');

// Global profit margin (e.g., 0.25 for +$0.25 margin)
define('GLOBAL_MARKUP_PERCENTAGE', 1.00); // 1.00 for 0% markup, 1.25 for 25% markup

// NowPayments API Configuration
define('NOWPAYMENTS_API_KEY', 'TKWD5YY-Y5F4HBX-HS5YFNX-MHCGJ80'); // Get this from your NowPayments account
define('NOWPAYMENTS_IPN_SECRET', 'urtQ7XzWfHrIxhd9s3S+u4RHAzxBZkay'); // Set a strong secret in your NowPayments IPN settings
define('NOWPAYMENTS_API_URL', 'https://api.nowpayments.io/v1');

?> 