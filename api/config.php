<?php
/* ============================================================
   خطة منظومة إدارة المخاطر والأزمات بالفروع والفرق الإرشادية
   risk-crisis-management-plan — ملف الإعدادات
   ============================================================ */
define('DB_HOST', 'fdb1029.awardspace.net');   // خادم قاعدة البيانات في AwardSpace
define('DB_NAME', '4795119_riskdb');           // اسم القاعدة
define('DB_USER', '4795119_riskdb');           // مستخدم القاعدة
define('DB_PASS', 'FeY4YA@!4LJe9qv');          // كلمة مرور القاعدة

define('SECRET',      'RCMP-2026-ServerSecret!-ChangeMe'); // مفتاح داخلي
define('WA_ADMIN',    '201000973608');  // واتساب الإدارة
define('SITE_NAME',   'خطة منظومة إدارة المخاطر والأزمات بالفروع والفرق الإرشادية');
define('SITE_SLUG',   'risk-crisis-management-plan');
define('UPLOAD_DIR',  __DIR__ . '/uploads/');  // مجلد المرفقات
define('MAX_IMG_MB',  8);                      // أقصى حجم للصورة
define('MAX_VID_MB',  40);                     // أقصى حجم للفيديو