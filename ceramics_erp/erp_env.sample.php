<?php
// erp_env.sample.php  ->  copy kore nam din  erp_env.php  (project root e, ba web root er baire)
// Eta WEB application read kore. Tai ekhane shudhu SELECT-only master user dao, root na.

define('ERP_BASE_DOMAIN', 'tomardomain.com');        // local test e: 'erp.test'
define('ERP_APP_KEY',     'base64:PASTE_GEN_KEY_OUTPUT_HERE');   // php tools/tenants.php gen-key  (HARIYE FELLE shob customer er DB password nosto)
define('ERP_MASTER_HOST', 'localhost');
define('ERP_MASTER_DB',   'erp_master');
define('ERP_MASTER_USER', 'erp_master');
define('ERP_MASTER_PASS', 'CHANGE_ME');

define('ERP_DEV_TENANT', '');                        // localhost / 127.0.0.1 e kon customer dekhabe (khali = dekhabe na)
define('ERP_SUPPORT_TEXT', 'সাপোর্ট: 01XXXXXXXXX');   // block page e dekhabe