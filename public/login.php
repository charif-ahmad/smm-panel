<?php
include '../includes/login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-sm text-center">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">Login</h2>
        <?php
        if (isset($_GET['error'])) {
            $error_message = htmlspecialchars($_GET['error']);
            $message = "";
            switch ($error_message) {
                case 'empty_fields':
                    $message = "Please fill in all fields.";
                    break;
                case 'invalid_password':
                    $message = "Invalid password.";
                    break;
                case 'email_not_found':
                    $message = "No user found with that email.";
                    break;
                case 'registration_successful':
                    $message = "Registration successful! Please login.";
                    echo '<p class="text-green-500 mb-4">' . $message . '</p>';
                    break;
                default:
                    $message = "An unknown error occurred.";
            }
            echo '<p class="text-red-500 mb-4">' . $message . '</p>';
        }
        ?>
        <form action="../includes/login.php" method="POST">
            <div class="mb-4">
                <label for="email" class="block text-gray-700 text-sm font-bold mb-2">Email:</label>
                <input type="email" id="email" name="email" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            <div class="mb-6">
                <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Password:</label>
                <input type="password" id="password" name="password" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full">
                Login
            </button>
        </form>
        <p class="mt-6 text-gray-600">Don't have an account? <a href="register.php" class="text-blue-500 hover:text-blue-800">Register here</a></p>
    </div>
</body>
</html> 