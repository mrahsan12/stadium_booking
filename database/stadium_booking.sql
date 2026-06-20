SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS `stadium_booking`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `stadium_booking`;

DROP EVENT IF EXISTS `ev_expire_advance_bookings`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `payment_followups`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `time_slots`;
DROP TABLE IF EXISTS `sports`;
DROP TABLE IF EXISTS `branches`;
DROP TABLE IF EXISTS `owner_expenses`;
DROP TABLE IF EXISTS `owner_slot_blocks`;
DROP TABLE IF EXISTS `owner_cancellation_policy`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `remember_tokens`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('customer', 'admin') NOT NULL DEFAULT 'customer',
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `remember_tokens` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `selector` CHAR(24) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  CONSTRAINT `fk_remember_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `branches` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `location` VARCHAR(150) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sports` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `branch_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `hourly_rate` DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sports_branch` (`branch_id`),
  CONSTRAINT `fk_sports_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `time_slots` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `sport_id` INT NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slots_sport` (`sport_id`),
  CONSTRAINT `fk_slots_sport`
    FOREIGN KEY (`sport_id`) REFERENCES `sports` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bookings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `branch_id` INT NOT NULL,
  `sport_id` INT NOT NULL,
  `slot_id` INT NOT NULL,
  `booking_date` DATE NOT NULL,
  `payment_due_at` DATETIME DEFAULT NULL,
  `advance_expires_at` DATETIME DEFAULT NULL,
  `status` ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
  `cancelled_at` DATETIME DEFAULT NULL,
  `payment_status` ENUM('unpaid', 'partial', 'paid', 'expired') NOT NULL DEFAULT 'unpaid',
  `payment_plan` ENUM('full', 'advance') NOT NULL DEFAULT 'full',
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bookings_user` (`user_id`),
  KEY `idx_bookings_branch` (`branch_id`),
  KEY `idx_bookings_sport_slot_date` (`sport_id`, `slot_id`, `booking_date`),
  KEY `idx_bookings_status_due` (`status`, `payment_status`, `payment_due_at`),
  CONSTRAINT `fk_bookings_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_bookings_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_bookings_sport`
    FOREIGN KEY (`sport_id`) REFERENCES `sports` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_bookings_slot`
    FOREIGN KEY (`slot_id`) REFERENCES `time_slots` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `booking_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('card', 'bank_transfer') NOT NULL DEFAULT 'card',
  `payment_type` ENUM('full', 'advance', 'balance', 'refund') NOT NULL DEFAULT 'full',
  `approval_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `approved_by` INT DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `payment_note` VARCHAR(255) DEFAULT NULL,
  `transaction_reference` VARCHAR(120) DEFAULT NULL,
  `transfer_slip_path` VARCHAR(255) DEFAULT NULL,
  `card_holder_name` VARCHAR(120) DEFAULT NULL,
  `card_last4` CHAR(4) DEFAULT NULL,
  `payment_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_booking` (`booking_id`),
  KEY `idx_payments_approval_status` (`approval_status`),
  CONSTRAINT `fk_payments_booking`
    FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_followups` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `booking_id` INT NOT NULL,
  `followup_type` ENUM('payment_due_2h', 'advance_expiry', 'auto_cancel_notice') NOT NULL DEFAULT 'payment_due_2h',
  `scheduled_at` DATETIME NOT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `status` ENUM('pending', 'sent', 'cancelled') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_followup_booking` (`booking_id`),
  KEY `idx_followup_status` (`status`),
  CONSTRAINT `fk_followup_booking`
    FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `owner_expenses` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `expense_date` DATE NOT NULL,
  `category` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_owner_expenses_date` (`expense_date`),
  KEY `idx_owner_expenses_creator` (`created_by`),
  CONSTRAINT `fk_owner_expenses_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `owner_slot_blocks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `block_date` DATE NOT NULL,
  `branch_id` INT NOT NULL,
  `sport_id` INT DEFAULT NULL,
  `slot_id` INT DEFAULT NULL,
  `block_scope` ENUM('branch_day', 'slot') NOT NULL DEFAULT 'slot',
  `reason` VARCHAR(150) NOT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_owner_slot_block_slot_date` (`slot_id`, `block_date`),
  KEY `idx_owner_slot_block_date_branch` (`block_date`, `branch_id`),
  KEY `idx_owner_slot_block_sport` (`sport_id`),
  CONSTRAINT `fk_owner_slot_blocks_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_owner_slot_blocks_sport`
    FOREIGN KEY (`sport_id`) REFERENCES `sports` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_owner_slot_blocks_slot`
    FOREIGN KEY (`slot_id`) REFERENCES `time_slots` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_owner_slot_blocks_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `owner_cancellation_policy` (
  `id` TINYINT NOT NULL,
  `full_refund_before_hours` INT NOT NULL DEFAULT 24,
  `partial_refund_before_hours` INT NOT NULL DEFAULT 6,
  `partial_refund_percent` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `status` ENUM('sent', 'pending') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`),
  CONSTRAINT `fk_notifications_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT DEFAULT NULL,
  `email` VARCHAR(100) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_user` (`user_id`),
  KEY `idx_password_resets_email` (`email`),
  CONSTRAINT `fk_password_resets_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`) VALUES
  (1, 'ArenaHub Customer', 'abc@gmail.com', '$2y$10$x1fwhDbR5NC/kQkPYa1.8uuHbsYqQ8kas4RjEr934b0YP.p6/Q8li', 'customer'),
  (2, 'ArenaHub Admin', 'admin@arenahub.com', '$2y$10$x1fwhDbR5NC/kQkPYa1.8uuHbsYqQ8kas4RjEr934b0YP.p6/Q8li', 'admin');

INSERT INTO `owner_cancellation_policy` (`id`, `full_refund_before_hours`, `partial_refund_before_hours`, `partial_refund_percent`) VALUES
  (1, 24, 6, 50.00);

INSERT INTO `branches` (`id`, `name`, `location`) VALUES
  (1, 'Colombo Branch', 'Colombo 05'),
  (2, 'Kandy Branch', 'Kandy City');

INSERT INTO `sports` (`id`, `branch_id`, `name`, `hourly_rate`) VALUES
  (1, 1, 'Futsal', 4500.00),
  (2, 1, 'Cricket Nets', 5500.00),
  (3, 1, 'Badminton', 3000.00),
  (4, 2, 'Futsal', 4300.00),
  (5, 2, 'Cricket Nets', 5200.00),
  (6, 2, 'Badminton', 2800.00);

INSERT INTO `time_slots` (`sport_id`, `start_time`, `end_time`) VALUES
  (1, '06:00:00', '08:00:00'),
  (1, '08:00:00', '10:00:00'),
  (1, '10:00:00', '12:00:00'),
  (1, '16:00:00', '18:00:00'),
  (1, '18:00:00', '20:00:00'),
  (2, '06:00:00', '08:00:00'),
  (2, '08:00:00', '10:00:00'),
  (2, '10:00:00', '12:00:00'),
  (2, '16:00:00', '18:00:00'),
  (2, '18:00:00', '20:00:00'),
  (3, '06:00:00', '07:00:00'),
  (3, '07:00:00', '08:00:00'),
  (3, '08:00:00', '09:00:00'),
  (3, '09:00:00', '10:00:00'),
  (3, '18:00:00', '19:00:00'),
  (4, '06:00:00', '08:00:00'),
  (4, '08:00:00', '10:00:00'),
  (4, '10:00:00', '12:00:00'),
  (4, '16:00:00', '18:00:00'),
  (4, '18:00:00', '20:00:00'),
  (5, '06:00:00', '08:00:00'),
  (5, '08:00:00', '10:00:00'),
  (5, '10:00:00', '12:00:00'),
  (5, '16:00:00', '18:00:00'),
  (5, '18:00:00', '20:00:00'),
  (6, '06:00:00', '07:00:00'),
  (6, '07:00:00', '08:00:00'),
  (6, '08:00:00', '09:00:00'),
  (6, '09:00:00', '10:00:00'),
  (6, '18:00:00', '19:00:00');

-- Make sure event scheduler is ON in MariaDB/MySQL:
-- SET GLOBAL event_scheduler = ON;

DELIMITER $$
CREATE EVENT `ev_expire_advance_bookings`
ON SCHEDULE EVERY 5 MINUTE
DO
BEGIN
  UPDATE bookings
  SET status = 'cancelled',
      payment_status = 'expired',
      cancelled_at = NOW()
  WHERE status = 'pending'
    AND payment_status = 'partial'
    AND payment_due_at IS NOT NULL
    AND payment_due_at <= NOW();

  UPDATE payment_followups pf
  JOIN bookings b ON b.id = pf.booking_id
  SET pf.status = 'cancelled',
      pf.sent_at = IFNULL(pf.sent_at, NOW())
  WHERE pf.status = 'pending'
    AND b.status = 'cancelled'
    AND b.payment_status = 'expired';
END$$
DELIMITER ;
