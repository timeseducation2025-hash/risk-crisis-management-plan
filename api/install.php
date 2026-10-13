<?php
/* ============================================================
   معالج التثبيت — شغّله مرة واحدة فقط عبر المتصفح:
   https://yourdomain.com/api/install.php  ثم احذفه فورًا
   ============================================================ */
require __DIR__ . '/config.php';

$OWNER_EMAIL = 'admin@egga-egypt.org';
$OWNER_PASS = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');$createdOwner = false;

try {
  $pdo = new PDO('mysql:host='.DB_HOST.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("USE `".DB_NAME."`");
  $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

  $installed =$pdo->prepare("SELECT meta_value FROM settings WHERE meta_key='install_complete'");
  $installed->execute();
  if ($installed->fetchColumn()) {
    // تم السماح بإعادة التثبيت أو تحديث المالك إذا كان الملف يعمل حالياً، يمكنك حذف شرط الحظر مؤقتاً أو تركه
  }

  /* حساب المالك (فوق الجميع — كافة الصلاحيات) */
  $st =$pdo->prepare("SELECT id FROM users WHERE email=?"); $st->execute([$OWNER_EMAIL]);
  if (!$st->fetch()){$pdo->prepare("INSERT INTO users(name,email,pass_hash,role,status) VALUES('مالك المنصة',?,?,'owner','active')")
        ->execute([$OWNER_EMAIL, password_hash($OWNER_PASS, PASSWORD_DEFAULT)]);$createdOwner = true;
  } else {
    // إذا كان البريد موجوداً مسبقاً، نقوم بتحديث كلمة المرور والصلاحية لتجنب أي مشاكل
    $pdo->prepare("UPDATE users SET pass_hash=?, role='owner', status='active' WHERE email=?")
        ->execute([password_hash($OWNER_PASS, PASSWORD_DEFAULT), $OWNER_EMAIL]);$createdOwner = true;
  }
  
  $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES('install_complete','1') ON DUPLICATE KEY UPDATE meta_value='1'")->execute();

  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

  echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1"><title>تم التثبيت</title></head>
  <body style="background:#0b241a;font-family:Tahoma;padding:20px;">
  <div style="max-width:640px;margin:40px auto;padding:28px;border:2px solid #2ee08a;border-radius:16px;text-align:center;background:#fff;color:#222;">
  <h2 style="color:#1b5e3b">✔ تم تحديث وتثبيت البيانات بنجاح</h2>
  <p>تم ضبط حساب المالك بالبريد المطلوب وتشفير كلمة المرور بنجاح.</p>
  <div style="background:#eaf4ee;border:1px solid #9bb8a6;border-radius:10px;padding:14px;margin:14px 0;direction:ltr;">
    <b>بيانات دخول المالك الجديدة (admin.html):</b><br><br>
    📧 '.$OWNER_EMAIL.'<br>
    🔑 '.$OWNER_PASS.'
  </div>
  <p style="color:#b3261e;font-weight:bold">احذف ملف install.php فورًا من الاستضافة لأسباب أمنية!</p>
  <p><a href="../admin.html" style="color:#1b5e3b;font-weight:bold">← الانتقال إلى لوحة الإدارة</a></p>
  </div></body></html>';
} catch (Exception $e) {
  http_response_code(500);
  echo '<div dir="rtl" style="font-family:Tahoma;max-width:640px;margin:40px auto;padding:24px;border:2px solid #b3261e;border-radius:14px;background:#fff;">';
  echo '<h2 style="color:#b3261e">✖ فشل التنفيذ</h2><p>'.htmlspecialchars($e->getMessage()).'</p>';
  echo '<p>تحقق من بيانات القاعدة في <b>api/config.php</b> ثم أعد المحاولة.</p></div>';
}