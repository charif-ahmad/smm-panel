# 🌐 Social Media Reseller Website – Project Blueprint

## 🎯 Project Objective
Build a web platform that resells social media services (followers, likes, comments, etc.) using the [Secsers.com API](https://secsers.com), with the ability to:

- Add a **global profit margin** on all services.
- Let users place orders via your site.
- Automatically forward orders to Secsers.
- Track and store the **profit** from each order.
- Manage everything from an **Admin Dashboard**.

---

## 🧱 Architecture Overview

### 📂 Frontend Pages (User Interface)
- `index.php`: Display available services and prices (with profit margin).
- `order.php`: Form to place an order.
- `status.php`: Check status of a placed order.
- `balance.php`: Show platform balance.
- `login.php` / `register.php`: User authentication.

### 🛠️ Admin Dashboard
- View and modify the **global markup** (e.g. +$0.25 on all services).
- View all orders with their real cost, final price, and profit.
- See total profit generated.
- (Optionally) Trigger manual **profit withdrawal**.

---

## 🗃️ Database Schema

### `users`
| Field       | Type         | Notes              |
|-------------|--------------|--------------------|
| id          | INT          | Primary key        |
| name        | VARCHAR      |                    |
| email       | VARCHAR      | Unique             |
| password    | VARCHAR      | Hashed             |
| is_admin    | BOOLEAN      | Admin = 1          |

---

### `services`
| Field       | Type         | Notes                              |
|-------------|--------------|------------------------------------|
| id          | INT          | Primary key                        |
| service_id  | INT          | ID from Secsers API                |
| name        | VARCHAR      | Service name                       |
| type        | VARCHAR      | From Secsers (e.g., Default)       |
| category    | VARCHAR      | Service category                   |
| rate        | DECIMAL      | Original rate from API             |
| min         | INT          | Minimum quantity                   |
| max         | INT          | Maximum quantity                   |
| refill      | BOOLEAN      | Refill option                      |
| created_at  | TIMESTAMP    |                                    |

> 💡 Final price = rate + global markup (not stored here)

---

### `orders`
| Field         | Type      | Notes                           |
|---------------|-----------|---------------------------------|
| id            | INT       | Primary key                     |
| user_id       | INT       | Foreign key from `users`        |
| service_id    | INT       | FK from `services`              |
| secsers_order | INT       | Returned order ID from API      |
| quantity      | INT       | Order quantity                  |
| user_price    | DECIMAL   | What the user paid              |
| real_price    | DECIMAL   | What you paid to Secsers        |
| profit        | DECIMAL   | user_price - real_price         |
| status        | VARCHAR   | From Secsers API                |
| created_at    | TIMESTAMP |                                 |

---

### `admin_settings`
| Field         | Type      | Notes                     |
|---------------|-----------|---------------------------|
| id            | INT       | Always 1                  |
| api_key       | VARCHAR   | Secsers API key           |
| markup_amount | DECIMAL   | e.g. 0.25 = +$0.25 margin |
| last_updated  | TIMESTAMP |                           |

---

## 🔁 Pricing Formula

```php
$final_price = $service['rate'] + $markup_amount;
$total_cost = $final_price * $quantity;
