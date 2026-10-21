-- template_schema.sql  (source db: ceramics_erp, 2026-10-17 14:22)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `erp_branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_code` varchar(50) NOT NULL,
  `branch_name` varchar(150) NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `branch_type` varchar(20) NOT NULL DEFAULT 'Branch',
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_code` (`branch_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_chart_of_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(150) NOT NULL,
  `account_type` enum('Asset','Liability','Equity','Income','Expense') NOT NULL,
  `parent_id` int(11) NOT NULL DEFAULT 0,
  `is_group` tinyint(1) NOT NULL DEFAULT 0,
  `fund_type` enum('Cash','Bank','MFS') DEFAULT NULL,
  `opening_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_code` (`account_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_company_profile` (
  `id` int(11) NOT NULL,
  `company_name` varchar(150) NOT NULL DEFAULT '',
  `tagline` varchar(200) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `mobile` varchar(60) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `website` varchar(120) DEFAULT NULL,
  `bin_no` varchar(60) DEFAULT NULL,
  `trade_license` varchar(60) DEFAULT NULL,
  `invoice_footer` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `left_img2` varchar(255) DEFAULT NULL,
  `right_img1` varchar(255) DEFAULT NULL,
  `right_img2` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(20) NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `address` text DEFAULT NULL,
  `opening_due` decimal(14,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_code` (`customer_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_delivery_challan_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `challan_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `box_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_delivery_challan_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `challan_no` varchar(50) NOT NULL,
  `challan_date` date NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `warehouse_id` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `invoice_no` (`invoice_no`),
  KEY `idx_inv` (`invoice_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_designations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `designation_name` varchar(100) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_designation_name` (`designation_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_code` varchar(20) NOT NULL,
  `employee_name` varchar(120) NOT NULL,
  `father_name` varchar(120) DEFAULT NULL,
  `designation_id` int(11) DEFAULT NULL,
  `mobile_no` varchar(30) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `nid_no` varchar(40) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `basic_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(10) NOT NULL DEFAULT 'Active',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_code` (`employee_code`),
  KEY `idx_employee_designation` (`designation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_fund_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(100) NOT NULL,
  `account_type` enum('CASH','BANK','MFS') NOT NULL,
  `opening_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_fund_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `txn_date` date NOT NULL,
  `account_id` int(11) NOT NULL,
  `txn_type` enum('IN','OUT') NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `ref_type` varchar(30) NOT NULL,
  `ref_no` varchar(50) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_date` (`txn_date`),
  KEY `idx_acc` (`account_id`),
  KEY `idx_ref` (`ref_type`,`ref_no`),
  CONSTRAINT `erp_fund_ledger_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `erp_fund_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_journal_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `journal_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `party_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_j` (`journal_id`),
  KEY `idx_a` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_journal_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_no` varchar(60) NOT NULL,
  `voucher_date` date NOT NULL,
  `voucher_type` varchar(30) NOT NULL,
  `ref_no` varchar(60) NOT NULL,
  `narration` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ref` (`voucher_type`,`ref_no`),
  KEY `idx_date` (`voucher_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_menus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_name` varchar(100) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `route_link` varchar(255) DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `order_no` int(11) DEFAULT 0,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `form_path` varchar(255) DEFAULT NULL,
  `icon_class` varchar(50) DEFAULT NULL,
  `sequence` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_physical_count` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `count_no` varchar(30) NOT NULL,
  `count_date` date NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'Draft',
  `remarks` varchar(255) DEFAULT NULL,
  `adj_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `count_no` (`count_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_physical_count_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `count_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `system_sft` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `phys_box` decimal(14,2) DEFAULT NULL,
  `phys_pcs` decimal(14,2) DEFAULT NULL,
  `phys_sft` decimal(14,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `count_id` (`count_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_product_brands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `brand_code` varchar(50) NOT NULL,
  `brand_name` varchar(150) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `brand_code` (`brand_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_product_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_code` varchar(50) NOT NULL,
  `category_name` varchar(150) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_code` (`category_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_product_colors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `color_code` varchar(50) NOT NULL,
  `color_name` varchar(150) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `color_code` (`color_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_product_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_code` varchar(50) NOT NULL,
  `grade_name` varchar(150) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `grade_code` (`grade_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_product_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_code` varchar(50) NOT NULL,
  `group_name` varchar(150) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_code` (`group_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_product_sizes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `size_code` varchar(50) NOT NULL,
  `size_name` varchar(150) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `size_code` (`size_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_product_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_code` varchar(50) NOT NULL,
  `unit_name` varchar(150) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unit_code` (`unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_code` varchar(50) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `group_name` varchar(150) DEFAULT NULL,
  `category_name` varchar(150) DEFAULT NULL,
  `unit_name` varchar(150) DEFAULT NULL,
  `size_name` varchar(150) DEFAULT NULL,
  `color_name` varchar(150) DEFAULT NULL,
  `grade_name` varchar(150) DEFAULT NULL,
  `brand_name` varchar(150) DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT 0.00,
  `sale_rate` decimal(12,2) DEFAULT 0.00,
  `sale_price` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_compound` tinyint(1) DEFAULT 0,
  `negative_sales` tinyint(1) NOT NULL DEFAULT 0,
  `negative_delivery` tinyint(1) NOT NULL DEFAULT 0,
  `box_to_pcs` decimal(10,2) DEFAULT 10.00,
  `ratio_pcs` decimal(10,2) DEFAULT 9.00,
  `ratio_output` decimal(10,2) DEFAULT 16.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_code` (`item_code`),
  KEY `idx_name` (`item_name`),
  KEY `idx_code` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_purchase_grn` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grn_no` varchar(50) NOT NULL,
  `grn_date` date NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `challan_no` varchar(50) DEFAULT NULL,
  `branch_name` varchar(150) DEFAULT NULL,
  `net_balance` decimal(12,2) DEFAULT 0.00,
  `labor_charge` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `prev_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_account_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grn_no` (`grn_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_purchase_grn_bill` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grn_no` varchar(50) NOT NULL,
  `line_no` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `bill_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `bill_rate` decimal(18,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_grn` (`grn_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_purchase_return` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_no` varchar(50) NOT NULL,
  `return_date` date NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `grn_id` int(11) NOT NULL,
  `grn_no` varchar(50) NOT NULL,
  `branch_name` varchar(100) DEFAULT NULL,
  `total_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `prev_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `refund_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `net_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_no` (`return_no`),
  KEY `idx_grn` (`grn_no`),
  KEY `idx_supplier` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_purchase_return_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `box_qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `return_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `rate` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_return` (`return_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_role_permissions` (
  `role_name` varchar(20) NOT NULL,
  `menu_id` int(11) NOT NULL,
  PRIMARY KEY (`role_name`,`menu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(20) NOT NULL,
  `description` varchar(150) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_sales_return` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_no` varchar(50) NOT NULL,
  `return_date` date NOT NULL,
  `customer_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `branch_name` varchar(100) DEFAULT NULL,
  `total_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `prev_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `refund_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `net_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `warehouse_id` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_no` (`return_no`),
  KEY `idx_invoice` (`invoice_no`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_sales_return_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `box_qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `return_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `rate` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `item_condition` varchar(10) NOT NULL DEFAULT 'Good',
  PRIMARY KEY (`id`),
  KEY `idx_return` (`return_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_stock_adjustment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `adj_no` varchar(30) NOT NULL,
  `adj_date` date NOT NULL,
  `adj_type` varchar(12) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `reason` varchar(60) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `ref_no` varchar(30) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `adj_no` (`adj_no`),
  KEY `adj_type` (`adj_type`),
  KEY `adj_date` (`adj_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_stock_adjustment_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `adj_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `direction` varchar(3) NOT NULL,
  `box_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sqft_qty` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `rate` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `adj_id` (`adj_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_stock_ledger` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL DEFAULT 1,
  `voucher_type` varchar(30) NOT NULL,
  `voucher_no` varchar(50) NOT NULL,
  `box_in` decimal(12,2) DEFAULT 0.00,
  `box_out` decimal(12,2) DEFAULT 0.00,
  `pcs_in` decimal(12,2) DEFAULT 0.00,
  `pcs_out` decimal(12,2) DEFAULT 0.00,
  `sqft_in` decimal(12,2) DEFAULT 0.00,
  `sqft_out` decimal(12,2) DEFAULT 0.00,
  `rate` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `total_value` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prod` (`product_id`),
  KEY `idx_wh` (`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_stock_transfer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_no` varchar(30) NOT NULL,
  `transfer_date` date NOT NULL,
  `from_warehouse_id` int(11) NOT NULL,
  `to_warehouse_id` int(11) NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_no` (`transfer_no`),
  KEY `transfer_date` (`transfer_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_stock_transfer_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `box_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `pcs_qty` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sqft_qty` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `rate` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `transfer_id` (`transfer_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(30) NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `mobile_no` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_code` (`supplier_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` varchar(20) DEFAULT 'admin',
  `employee_id` int(11) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_voucher_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_id` int(11) NOT NULL,
  `head_kind` varchar(10) NOT NULL,
  `head_id` int(11) NOT NULL,
  `head_label` varchar(255) DEFAULT NULL,
  `party_type` varchar(20) DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `dr_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cr_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voucher_id` (`voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_no` varchar(30) NOT NULL,
  `voucher_type` varchar(20) NOT NULL,
  `voucher_date` date NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `to_account_id` int(11) DEFAULT NULL,
  `party_type` varchar(20) DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `entry_side` varchar(2) DEFAULT NULL,
  `head_name` varchar(100) DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cr_party_type` varchar(20) DEFAULT NULL,
  `cr_party_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_no` (`voucher_no`),
  KEY `idx_type` (`voucher_type`),
  KEY `idx_party` (`party_type`,`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `erp_warehouses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `warehouse_code` varchar(30) DEFAULT NULL,
  `warehouse_name` varchar(120) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `sales_invoice_details` (
  `Invoice_Detail_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Invoice_ID` int(11) NOT NULL,
  `Product_ID` int(11) NOT NULL,
  `Size` varchar(50) DEFAULT NULL,
  `Color` varchar(50) DEFAULT NULL,
  `Unit` varchar(20) DEFAULT NULL,
  `Box_Qty` int(11) DEFAULT 0,
  `Pcs_Qty` int(11) DEFAULT 0,
  `Total_Qty` decimal(18,2) NOT NULL,
  `Rate` decimal(18,2) NOT NULL,
  `Amount` decimal(18,2) NOT NULL,
  PRIMARY KEY (`Invoice_Detail_ID`),
  KEY `idx_inv_prod` (`Invoice_ID`,`Product_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `sales_invoice_master` (
  `Invoice_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Invoice_No` varchar(50) NOT NULL,
  `Invoice_Date` date NOT NULL,
  `Customer_ID` int(11) NOT NULL,
  `branch_name` varchar(100) NOT NULL DEFAULT 'Main Factory / Godown',
  `Warehouse_ID` int(11) NOT NULL,
  `Challan_No` varchar(50) DEFAULT NULL,
  `Total_Amount` decimal(18,2) DEFAULT 0.00,
  `Labor_Charge` decimal(18,2) DEFAULT 0.00,
  `Discount_Percent` decimal(5,2) DEFAULT 0.00,
  `Discount_Amount` decimal(18,2) DEFAULT 0.00,
  `Gross_Total` decimal(18,2) DEFAULT 0.00,
  `Previous_Balance` decimal(18,2) DEFAULT 0.00,
  `Payment_Amount` decimal(18,2) DEFAULT 0.00,
  `payment_account_id` int(11) DEFAULT NULL,
  `Net_Balance` decimal(18,2) DEFAULT 0.00,
  `Remarks` text DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Undelivered',
  `Created_By` int(11) NOT NULL,
  `Created_At` timestamp NOT NULL DEFAULT current_timestamp(),
  `sales_person_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`Invoice_ID`),
  UNIQUE KEY `Invoice_No` (`Invoice_No`),
  KEY `Invoice_No_2` (`Invoice_No`),
  KEY `idx_date` (`Invoice_Date`),
  KEY `idx_date_status` (`Invoice_Date`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS=1;
