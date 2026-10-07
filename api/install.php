<?php
/* شغّلي هذا الملف مرة واحدة فقط عبر المتصفح: yourdomain.com/api/install.php ثم احذفيه */
require 'config.php';
$pdo=new PDO('mysql:host='.DB_HOST.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `".DB_NAME."`");
$pdo->exec(file_get_contents('schema.sql'));
/* الأدمن العام الافتراضي — غيّري كلمة المرور فورًا */
$hash=password_hash('EgGa@Fayoum2026',PASSWORD_DEFAULT);
$st=$pdo->prepare("INSERT IGNORE INTO users(name,email,pass_hash,role,status) VALUES('الأدمن العام','admin@egga-egypt.org',?,'super','active')");
$st->execute([$hash]);
echo '<div dir="rtl" style="font-family:Tahoma;max-width:600px;margin:40px auto;padding:24px;border:2px solid #2ee08a;border-radius:14px;text-align:center">';
echo '<h2 style="color:#1b5e3b">✔ تم التثبيت بنجاح</h2>';
echo '<p>تم إنشاء القاعدة والجداول والأدمن العام.</p>';
echo '<p><b>بيانات الدخول:</b><br>admin@egga-egypt.org<br>EgGa@Fayoum2026</p>';
echo '<p style="color:#b3261e;font-weight:bold">⚠️ احذفي ملف install.php الآن من الاستضافة، وغيّري كلمة المرور من لوحة الإدارة.</p></div>';
