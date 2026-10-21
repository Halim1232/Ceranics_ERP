<?php
// erp_env.sample.php  ->  copy kore nam din  erp_env.php  (project root e, ba web root er baire)
// Eta WEB application read kore. Tai ekhane shudhu SELECT-only master user dao, root na.

define('ERP_BASE_DOMAIN', 'localhost');
define('ERP_APP_KEY',     'base64:dFfvhBcdDTPdWm77tKjs6BRykuyC1Z+OiWNvPRv/UlM=');   // php tools/tenants.php gen-key  (HARIYE FELLE shob customer er DB password nosto)
define('ERP_MASTER_HOST', 'localhost');
define('ERP_MASTER_DB',   'erp_master');
define('ERP_MASTER_USER', 'erp_master');
define('ERP_MASTER_PASS', 'Admin123');

define('ERP_DEV_TENANT', 'uttara');
define('ERP_SUPPORT_TEXT', 'সাপোর্ট: 01XXXXXXXXX');   // block page e dekhabe