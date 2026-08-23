-- ============================================================================
-- Restaurant POS + KDS Database Schema (v1.1)
-- ============================================================================
-- MySQL 8.0+
-- Updated: username-based auth, passcode, category visibility, item status
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. USERS TABLE
-- ============================================================================
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(255) NOT NULL UNIQUE,
    `name` VARCHAR(255) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    -- 4-digit PIN hashed with bcrypt; used to authorize sensitive POS actions
    -- (void item from open ticket, approve refund). Verified via Hash::check().
    `passcode` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'manager', 'cashier') NOT NULL DEFAULT 'cashier',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_username` (`username`),
    INDEX `idx_role` (`role`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. SHIFTS TABLE
-- ============================================================================
CREATE TABLE `shifts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `opened_by` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    `starting_cash` DECIMAL(10, 2) NOT NULL,
    `opened_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_at` TIMESTAMP NULL,
    `total_revenue` DECIMAL(12, 2) DEFAULT 0.00,
    `total_cash` DECIMAL(12, 2) DEFAULT 0.00,
    `total_gcash` DECIMAL(12, 2) DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_shifts_opened_by` (`opened_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    -- Enforces only one open shift at a time at the DB level
    UNIQUE INDEX `idx_one_active_shift` (`status`(4)),
    INDEX `idx_status` (`status`),
    INDEX `idx_opened_at` (`opened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. CATEGORIES TABLE
-- ============================================================================
CREATE TABLE `categories` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    -- Controls whether this category (and its items) appears on the POS menu
    `is_visible_to_pos` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_status` (`status`),
    INDEX `idx_visible` (`is_visible_to_pos`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. ITEMS TABLE (Menu Items with Inventory)
-- ============================================================================
CREATE TABLE `items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `base_price` DECIMAL(10, 2) NOT NULL,
    `cost_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `quantity` INT NOT NULL DEFAULT 0,
    `reserved_quantity` INT NOT NULL DEFAULT 0,
    `image_url` VARCHAR(255) NULL,
    -- available: orderable on POS
    -- unavailable: visible on POS but cannot be ordered (greyed out)
    -- hidden: not shown on POS at all (back office only)
    `status` ENUM('available', 'unavailable', 'hidden') NOT NULL DEFAULT 'available',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_items_category_id` (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
    INDEX `idx_category_id` (`category_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. MODIFIERS TABLE (Item add-ons / variants)
-- ============================================================================
CREATE TABLE `modifiers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `item_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_modifiers_item_id` (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
    UNIQUE INDEX `idx_item_modifier_name` (`item_id`, `name`),
    INDEX `idx_item_id` (`item_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. TICKETS TABLE (Open/Paid Orders)
-- ============================================================================
CREATE TABLE `tickets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `shift_id` BIGINT UNSIGNED NOT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `terminal_id` VARCHAR(50) NOT NULL,
    `customer_name` VARCHAR(255) NOT NULL,
    `order_number` VARCHAR(50) NOT NULL UNIQUE,
    `order_type` ENUM('dine_in', 'takeout') NOT NULL,
    `status` ENUM('open', 'paid', 'merged') NOT NULL DEFAULT 'open',
    `discount_amount` DECIMAL(10, 2) DEFAULT 0.00,
    `discount_percent` DECIMAL(5, 2) DEFAULT 0.00,
    `subtotal` DECIMAL(12, 2) DEFAULT 0.00,
    `total` DECIMAL(12, 2) DEFAULT 0.00,
    `merged_into_ticket_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_tickets_shift_id` (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY `fk_tickets_created_by` (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY `fk_tickets_merged_into` (`merged_into_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
    INDEX `idx_shift_id` (`shift_id`),
    INDEX `idx_terminal_id` (`terminal_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_order_number` (`order_number`),
    INDEX `idx_tickets_terminal_status` (`terminal_id`, `status`),
    INDEX `idx_tickets_shift_status_created` (`shift_id`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. TICKET_ITEMS TABLE (Line Items in Ticket)
-- ============================================================================
CREATE TABLE `ticket_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `item_id` BIGINT UNSIGNED NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10, 2) NOT NULL,
    `modifier_id` BIGINT UNSIGNED NULL,
    `modifier_price` DECIMAL(10, 2) DEFAULT 0.00,
    -- KDS-only notes: visible in kitchen and back office, NOT on customer receipt
    `notes` TEXT NULL,
    `line_total` DECIMAL(12, 2) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_ticket_items_ticket_id` (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    FOREIGN KEY `fk_ticket_items_item_id` (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY `fk_ticket_items_modifier_id` (`modifier_id`) REFERENCES `modifiers` (`id`) ON DELETE SET NULL,
    INDEX `idx_ticket_id` (`ticket_id`),
    INDEX `idx_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. CHARGES TABLE (Payment Records - supports Split Payment)
-- ============================================================================
CREATE TABLE `charges` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `payment_method` ENUM('cash', 'gcash') NOT NULL,
    `amount` DECIMAL(12, 2) NOT NULL,
    `status` ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
    `payment_reference` VARCHAR(255) NULL,
    `paid_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_charges_ticket_id` (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    INDEX `idx_ticket_id` (`ticket_id`),
    INDEX `idx_payment_method` (`payment_method`),
    INDEX `idx_status` (`status`),
    INDEX `idx_charges_ticket_status` (`ticket_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. CHARGE_ITEMS TABLE (Which ticket items belong to which charge/receipt)
-- ============================================================================
CREATE TABLE `charge_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `charge_id` BIGINT UNSIGNED NOT NULL,
    `ticket_item_id` BIGINT UNSIGNED NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_charge_items_charge_id` (`charge_id`) REFERENCES `charges` (`id`) ON DELETE CASCADE,
    FOREIGN KEY `fk_charge_items_ticket_item_id` (`ticket_item_id`) REFERENCES `ticket_items` (`id`) ON DELETE CASCADE,
    INDEX `idx_charge_id` (`charge_id`),
    INDEX `idx_ticket_item_id` (`ticket_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. REFUNDS TABLE
-- ============================================================================
CREATE TABLE `refunds` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `charge_id` BIGINT UNSIGNED NULL,
    `requested_by` BIGINT UNSIGNED NOT NULL,
    -- approved_by references users.id; admin/manager passcode verified before approval
    `approved_by` BIGINT UNSIGNED NULL,
    `amount` DECIMAL(12, 2) NOT NULL,
    `reason` TEXT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `approved_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_refunds_ticket_id` (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY `fk_refunds_charge_id` (`charge_id`) REFERENCES `charges` (`id`) ON DELETE SET NULL,
    FOREIGN KEY `fk_refunds_requested_by` (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY `fk_refunds_approved_by` (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    INDEX `idx_ticket_id` (`ticket_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_requested_at` (`requested_at`),
    INDEX `idx_refunds_status_requested` (`status`, `requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. RECEIPT_HISTORY TABLE
-- ============================================================================
CREATE TABLE `receipt_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `charge_id` BIGINT UNSIGNED NOT NULL,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `terminal_id` VARCHAR(50) NULL,
    `receipt_number` VARCHAR(100) NOT NULL UNIQUE,
    `printed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_reprint` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_receipt_history_charge_id` (`charge_id`) REFERENCES `charges` (`id`) ON DELETE CASCADE,
    FOREIGN KEY `fk_receipt_history_ticket_id` (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
    INDEX `idx_charge_id` (`charge_id`),
    INDEX `idx_ticket_id` (`ticket_id`),
    INDEX `idx_terminal_id` (`terminal_id`),
    INDEX `idx_printed_at` (`printed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12. SHIFT_TRANSACTIONS TABLE (Expenses & Cash Additions)
-- ============================================================================
CREATE TABLE `shift_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `shift_id` BIGINT UNSIGNED NOT NULL,
    `type` ENUM('expense', 'addition') NOT NULL,
    `amount` DECIMAL(12, 2) NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY `fk_shift_transactions_shift_id` (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
    FOREIGN KEY `fk_shift_transactions_created_by` (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    INDEX `idx_shift_id` (`shift_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- VIEWS
-- ============================================================================

CREATE VIEW `ticket_summary` AS
SELECT
    t.id,
    t.order_number,
    t.customer_name,
    t.terminal_id,
    t.order_type,
    t.status,
    COUNT(ti.id) AS item_count,
    t.total,
    t.created_at
FROM tickets t
LEFT JOIN ticket_items ti ON t.id = ti.ticket_id
WHERE t.status = 'open'
GROUP BY t.id, t.order_number, t.customer_name, t.terminal_id,
         t.order_type, t.status, t.total, t.created_at;

CREATE VIEW `inventory_available` AS
SELECT
    i.id,
    i.name,
    i.quantity,
    i.reserved_quantity,
    (i.quantity - i.reserved_quantity) AS available,
    c.name AS category,
    i.base_price,
    i.cost_price,
    i.status
FROM items i
LEFT JOIN categories c ON i.category_id = c.id;

CREATE VIEW `shift_daily_summary` AS
SELECT
    s.id,
    s.opened_at,
    s.closed_at,
    s.starting_cash,
    s.total_revenue,
    s.total_cash,
    s.total_gcash,
    COALESCE(SUM(CASE WHEN st.type = 'addition' AND st.deleted_at IS NULL THEN st.amount ELSE 0 END), 0) AS total_additions,
    COALESCE(SUM(CASE WHEN st.type = 'expense' AND st.deleted_at IS NULL THEN st.amount ELSE 0 END), 0) AS total_expenses,
    COUNT(DISTINCT t.id) AS ticket_count,
    (s.starting_cash + s.total_cash
        + COALESCE(SUM(CASE WHEN st.type = 'addition' AND st.deleted_at IS NULL THEN st.amount ELSE 0 END), 0)
        - COALESCE(SUM(CASE WHEN st.type = 'expense' AND st.deleted_at IS NULL THEN st.amount ELSE 0 END), 0)
    ) AS expected_cash
FROM shifts s
LEFT JOIN tickets t ON s.id = t.shift_id AND t.status = 'paid'
LEFT JOIN shift_transactions st ON s.id = st.shift_id
GROUP BY s.id;

SET FOREIGN_KEY_CHECKS = 1;
