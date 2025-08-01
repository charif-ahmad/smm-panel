<?php

define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'smm_reseller');

define('API_KEY', 'fda14a84ed59996cc089a38c9fdbc48e'); // Your Secsers.com API Key
define('API_URL', 'https://secsers.com/api/v2');

// Global profit margin (e.g., 0.25 for +$0.25 margin)
define('GLOBAL_MARKUP_AMOUNT', 0.25);

// NowPayments API Configuration
define('NOWPAYMENTS_API_KEY', 'YOUR_NOWPAYMENTS_API_KEY'); // Get this from your NowPayments account
define('NOWPAYMENTS_IPN_SECRET', 'YOUR_NOWPAYMENTS_IPN_SECRET'); // Set a strong secret in your NowPayments IPN settings
define('NOWPAYMENTS_API_URL', 'https://api.nowpayments.io/v1');

// Google reCAPTCHA v2 (Checkbox) Configuration
define('RECAPTCHA_SITE_KEY', 'YOUR_RECAPTCHA_SITE_KEY');
define('RECAPTCHA_SECRET_KEY', 'YOUR_RECAPTCHA_SECRET_KEY');

?> 