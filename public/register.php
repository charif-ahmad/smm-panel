<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-sm text-center">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">Register</h2>
        <?php
        if (isset($_GET['error'])) {
            $error_message = htmlspecialchars($_GET['error']);
            $message = "";
            switch ($error_message) {
                case 'empty_fields':
                    $message = "Please fill in all fields.";
                    break;
                case 'invalid_email':
                    $message = "Invalid email format.";
                    break;
                case 'password_mismatch':
                    $message = "Passwords do not match.";
                    break;
                case 'email_taken':
                    $message = "This email is already registered.";
                    break;
                case 'db_error':
                    $message = "A database error occurred. Please try again later.";
                    break;
                case 'recaptcha_failed':
                    $message = "reCAPTCHA verification failed. Please try again.";
                    break;
                default:
                    $message = "An unknown error occurred.";
            }
            echo '<p class="text-red-500 mb-4">' . $message . '</p>';
        }
        ?>
        <form action="../includes/register.php" method="POST">
            <div class="mb-4">
                <label for="name" class="block text-gray-700 text-sm font-bold mb-2">Name:</label>
                <input type="text" id="name" name="name" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            <div class="mb-4">
                <label for="email" class="block text-gray-700 text-sm font-bold mb-2">Email:</label>
                <input type="email" id="email" name="email" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            <div class="mb-4">
                <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Password:</label>
                <input type="password" id="password" name="password" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            <div class="mb-6">
                <label for="confirm_password" class="block text-gray-700 text-sm font-bold mb-2">Confirm Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            <div class="mb-6 flex justify-center">
                <div class="g-recaptcha" data-sitekey="<?php require_once __DIR__ . '/../includes/config.php'; echo RECAPTCHA_SITE_KEY; ?>"></div>
            </div>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full">
                Register
            </button>
        </form>
        <p class="mt-6 text-gray-600">Already have an account? <a href="login.php" class="text-blue-500 hover:text-blue-800">Login here</a></p>
    </div>
</body>
</html> 