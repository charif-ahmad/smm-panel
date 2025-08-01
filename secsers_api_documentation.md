
# 🌐 Secsers.com API – Full Developer Guide (English)

## 🧾 Introduction
The `Secsers.com API` allows developers to programmatically interact with social media marketing services such as followers, comments, likes, etc. This enables automation, dashboard integration, and easier management of orders and account balances.

---

## 🔐 Authentication & Request Basics

- **API Method:** `POST`
- **API Endpoint:** `https://secsers.com/api/v2`
- **Authentication Parameter:** `key` (your personal API key)
- **Response Format:** `JSON`
- **Common Content-Type:** `application/x-www-form-urlencoded`

> **Important:** Always keep your API key confidential. Never expose it in client-side code.

---

## 📌 General Request Format
Each API call must include:
```json
{
  "key": "YOUR_API_KEY",
  "action": "ACTION_NAME",
  "other_parameters": "value"
}
```

---

## 📦 Available API Actions

### 1. ✅ Get List of Services
- **Parameters:**
```json
{
  "key": "YOUR_API_KEY",
  "action": "services"
}
```
- **Response Example:**
```json
[
  {
    "service": 1,
    "name": "Followers",
    "type": "Default",
    "category": "First Category",
    "rate": "0.90",
    "min": "50",
    "max": "10000",
    "refill": true
  },
  {
    "service": 2,
    "name": "Comments",
    "type": "Custom Comments",
    "category": "Second Category",
    "rate": "8",
    "min": "10",
    "max": "1500",
    "refill": false
  }
]
```

---

### 2. 🛒 Add a New Order
- **Parameters:**
```json
{
  "key": "YOUR_API_KEY",
  "action": "add",
  "service": 1,
  "link": "http://example.com/your_profile",
  "quantity": 100
}
```
- **Response:**
```json
{
  "order": 23501
}
```

---

### 3. 📊 Check Order Status
- **Parameters:**
```json
{
  "key": "YOUR_API_KEY",
  "action": "status",
  "order": 23501
}
```
- **Response:**
```json
{
  "charge": "0.27819",
  "start_count": "3572",
  "status": "Partial",
  "remains": "157",
  "currency": "USD"
}
```

---

### 4. 📋 Check Multiple Orders' Status
- **Parameters:**
```json
{
  "key": "YOUR_API_KEY",
  "action": "status",
  "orders": "1,10,100"
}
```
- **Response:**
```json
{
  "1": {
    "charge": "0.27819",
    "start_count": "3572",
    "status": "Partial",
    "remains": "157",
    "currency": "USD"
  },
  "10": {
    "error": "Incorrect order ID"
  },
  "100": {
    "charge": "1.44219",
    "start_count": "234",
    "status": "In progress",
    "remains": "10",
    "currency": "USD"
  }
}
```

---

### 5. 🔁 Create Refill Request
- **Parameters:**
```json
{
  "key": "YOUR_API_KEY",
  "action": "refill",
  "order": 23501
}
```
- **Response:**
```json
{
  "refill": "1"
}
```

---

### 6. 🔎 Check Refill Status
- **Parameters:**
```json
{
  "key": "YOUR_API_KEY",
  "action": "refill_status",
  "refill": 1
}
```
- **Response:**
```json
{
  "status": "Completed"
}
```

---

### 7. 💰 Check User Balance
- **Parameters:**
```json
{
  "key": "YOUR_API_KEY",
  "action": "balance"
}
```
- **Response:**
```json
{
  "balance": "100.84292",
  "currency": "USD"
}
```

---

## 💡 PHP Code Example (Get Services)
```php
<?php

$api_key = 'YOUR_API_KEY';
$api_url = 'https://secsers.com/api/v2';

$post_data = array(
    'key' => $api_key,
    'action' => 'services'
);

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo 'Error: ' . curl_error($ch);
} else {
    $services = json_decode($response, true);
    print_r($services);
}

curl_close($ch);

?>
```

---

## 🔒 Security Tips
- Never expose your API key in frontend code or public repositories.
- Store the key in environment variables or server-side configs.
- Rate-limit or validate IPs on your server if possible.

- my api key=fda14a84ed59996cc089a38c9fdbc48e