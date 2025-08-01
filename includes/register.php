<?php
require_once 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        // Handle empty fields
        header("Location: ../public/register.php?error=empty_fields");
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Handle invalid email format
        header("Location: ../public/register.php?error=invalid_email");
        exit();
    }

    if ($password !== $confirm_password) {
        // Handle password mismatch
        header("Location: ../public/register.php?error=password_mismatch");
        exit();
    }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // Handle email already taken
        header("Location: ../public/register.php?error=email_taken");
        exit();
    }
    $stmt->close();

    // Hash password and insert user
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $is_admin = 0; // Default to non-admin

    $stmt = $conn->prepare("INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", $name, $email, $hashed_password, $is_admin);

    if ($stmt->execute()) {
        // Registration successful
        header("Location: ../public/login.php?success=registration_successful");
        exit();
    } else {
        // Handle database error
        header("Location: ../public/register.php?error=db_error");
        exit();
    }
    $stmt->close();
}

$conn->close();
?> 