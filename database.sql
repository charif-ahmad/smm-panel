-- Database: `smm_reseller`
--
CREATE DATABASE IF NOT EXISTS `smm_reseller` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `smm_reseller`;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `category` varchar(255) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `min` int(11) NOT NULL,
  `max` int(11) NOT NULL,
  `refill` tinyint(1) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `secsers_order` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `user_price` decimal(10,2) NOT NULL,
  `real_price` decimal(10,2) NOT NULL,
  `profit` decimal(10,2) NOT NULL,
  `status` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `admin_settings`
--

CREATE TABLE `admin_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `api_key` varchar(255) NOT NULL,
  `markup_amount` decimal(10,2) NOT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL, -- Link to orders table if transaction is an order
  `type` varchar(50) NOT NULL, -- e.g., 'deposit', 'debit', 'profit', 'transfer'
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Initial data for `admin_settings`
--
INSERT INTO `admin_settings` (`id`, `api_key`, `markup_amount`) VALUES
(1, 'YOUR_SECSERS_API_KEY', 0.25); 