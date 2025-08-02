<?php
session_start();
require_once 'db.php';
require_once 'config.php'; // Include config for reCAPTCHA keys

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        // Handle empty fields
        header("Location: ../public/login.php?error=empty_fields");
        exit();
    }

    $stmt = $conn->prepare("SELECT id, name, password, is_admin, balance FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows == 1) {
        $stmt->bind_result($id, $name, $hashed_password, $is_admin, $balance);
        $stmt->fetch();

        if (password_verify($password, $hashed_password)) {
            // Password is correct, start a new session
            $_SESSION["loggedin"] = true;
            $_SESSION["id"] = $id;
            $_SESSION["name"] = $name;
            $_SESSION["is_admin"] = $is_admin;
            $_SESSION["balance"] = $balance;

            // Redirect user to appropriate dashboard
            if ($is_admin) {
                header("Location: ../public/admin_dashboard.php"); // Will create this later
            } else {
                header("Location: ../public/index.php"); // Will create this later
            }
            exit();
        } else {
            // Invalid password
            header("Location: ../public/login.php?error=invalid_password");
            exit();
        }
    } else {
        // No user found with that email
        header("Location: ../public/login.php?error=email_not_found");
        exit();
    }
    $stmt->close();
}

$conn->close();
?> 