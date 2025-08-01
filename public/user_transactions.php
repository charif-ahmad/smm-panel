<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

// Redirect to login if not logged in or not an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit;
}

$target_user_id = null;
$user_info = null;
$transactions = [];
$error_message = '';

if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $target_user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);

    // Fetch user info
    $stmt_user = $conn->prepare("SELECT id, name, email, balance FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $target_user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows == 1) {
        $user_info = $result_user->fetch_assoc();
    } else {
        $error_message = "User not found.";
    }
    $stmt_user->close();

    // Fetch transactions for the user
    if ($user_info) {
        $stmt_transactions = $conn->prepare("SELECT id, type, amount, description, created_at, order_id FROM transactions WHERE user_id = ? ORDER BY created_at DESC");
        $stmt_transactions->bind_param("i", $target_user_id);
        $stmt_transactions->execute();
        $result_transactions = $stmt_transactions->get_result();

        if ($result_transactions->num_rows > 0) {
            while ($row = $result_transactions->fetch_assoc()) {
                $transactions[] = $row;
            }
        }
        $stmt_transactions->close();
    }

} else {
    $error_message = "No user ID provided.";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Transactions</title>
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
        <h2 class="text-3xl font-bold mb-4 text-gray-800" data-aos="fade-right">User Transaction History</h2>
        <?php if ($error_message): ?>
            <p class="text-red-500 mb-4" data-aos="fade-left"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?></p>
        <?php else: ?>
            <div class="bg-gray-100 p-4 rounded-lg shadow-sm mb-6" data-aos="zoom-in">
                <h3 class="text-xl font-semibold text-gray-800">User Details:</h3>
                <p><strong><i class="fas fa-user mr-2"></i>Name:</strong> <?php echo htmlspecialchars($user_info['name']); ?></p>
                <p><strong><i class="fas fa-envelope mr-2"></i>Email:</strong> <?php echo htmlspecialchars($user_info['email']); ?></p>
                <p><strong><i class="fas fa-wallet mr-2"></i>Current Balance:</strong> $<?php echo number_format($user_info['balance'], 2); ?></p>
            </div>

            <?php if (!empty($transactions)): ?>
                <div class="overflow-x-auto" data-aos="zoom-in">
                    <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">ID <i class="fas fa-hashtag ml-1"></i></th>
                                <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Type <i class="fas fa-exchange-alt ml-1"></i></th>
                                <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Amount <i class="fas fa-dollar-sign ml-1"></i></th>
                                <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Description <i class="fas fa-align-left ml-1"></i></th>
                                <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Order ID <i class="fas fa-file-invoice ml-1"></i></th>
                                <th class="py-3 px-4 border-b text-left text-gray-600 font-semibold">Date <i class="fas fa-calendar-alt ml-1"></i></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($transaction['id']); ?></td>
                                    <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars(ucfirst($transaction['type'])); ?></td>
                                    <td class="py-3 px-4 border-b text-gray-800 <?php echo ($transaction['type'] == 'deposit' || $transaction['type'] == 'profit' || $transaction['type'] == 'credit') ? 'text-green-600' : 'text-red-600'; ?>">$<?php echo number_format($transaction['amount'], 2); ?></td>
                                    <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($transaction['description'] ?? '-'); ?></td>
                                    <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($transaction['order_id'] ?? '-'); ?></td>
                                    <td class="py-3 px-4 border-b text-gray-800"><?php echo htmlspecialchars($transaction['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-gray-700">No transactions found for this user.</p>
            <?php endif; ?>
        <?php endif; ?>
        <p class="mt-6" data-aos="fade-right"><a href="admin_dashboard.php" class="text-blue-500 hover:underline"><i class="fas fa-arrow-left mr-1"></i>Back to Admin Dashboard</a></p>
    </div>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
    </script>
</body>
</html> 