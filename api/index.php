<?php
/* ============================================================
   خطة منظومة إدارة المخاطر والأزمات بالفروع والفرق الإرشادية
   risk-crisis-management-plan — واجهة البرمجة الرئيسية (API)
   ============================================================ */
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
  $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Exception $e) {
  echo json_encode(['ok'=>false,'msg'=>'تعذر الاتصال بقاعدة البيانات — راجع config.php أو شغّل install.php'], JSON_UNESCAPED_UNICODE); exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$a  = $in['action'] ?? '';

function out($d, $ok = true){ echo json_encode(['ok'=>$ok] + $d, JSON_UNESCAPED_UNICODE); exit; }

/* توليد رقم سيريال/كود مقروء بدون حروف متشابهة (0/O و 1/I) */
function serial($groups = 2, $len = 4){
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $parts = [];
  for ($g = 0; $g < $groups; $g++){
    $p = '';
    for ($i = 0; $i < $len; $i++) $p .= $chars[random_int(0, strlen($chars)-1)];
    $parts[] = $p;
  }
  return implode('-', $parts);
}

/* كود تفعيل حساب فريد مع إعادة المحاولة عند التكرار */
function genAccountCode($pdo){
  for ($i = 0; $i < 20; $i++){
    $c = 'RCMP-' . serial(2, 4);          // مثال: RCMP-K7D2-9QXF
    $st = $pdo->prepare("SELECT id FROM codes WHERE code=?"); $st->execute([$c]);
    if (!$st->fetch()) return $c;
  }
  return 'RCMP-' . strtoupper(bin2hex(random_bytes(5)));
}

/* سيريال التقارير: RPT-2026-0001 */
function reportSerial($id){ return 'RPT-' . date('Y') . '-' . str_pad($id, 4, '0', STR_PAD_LEFT); }

function makeToken(){ return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '='); }

/* توثيق المستخدم بالتوكن من جدول الجلسات */
function auth($pdo, $in, $roles = null){
  $t = $in['token'] ?? '';
  if (!$t) return null;
  $st = $pdo->prepare("SELECT s.token, s.expiry, u.name, u.email, u.role, u.status, u.gov
                       FROM sessions s JOIN users u ON u.email = s.email WHERE s.token=?");
  $st->execute([$t]); $u = $st->fetch();
  if (!$u) return null;
  if ($u['expiry'] && strtotime($u['expiry']) < time()) return null;
  if ($u['status'] !== 'active') return null;
  if ($roles && !in_array($u['role'], $roles)) return null;
  return $u;
}

function newSession($pdo, $email, $role){
  /* تنظيف دوري للجلسات المنتهية — يحافظ على خفة القاعدة مع كثرة المستخدمين */
  if (random_int(1, 10) === 1) $pdo->query("DELETE FROM sessions WHERE expiry IS NOT NULL AND expiry < NOW()");
  $tk = makeToken();
  $pdo->prepare("INSERT INTO sessions(token,email,role,expiry,created_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY),NOW())")
      ->execute([$tk, $email, $role]);
  return $tk;
}

function logAct($pdo, $email, $name, $action){
  $pdo->prepare("INSERT INTO logs(user_email,user_name,action,at) VALUES(?,?,?,NOW())")->execute([$email, $name, $action]);
}

function storeAccess($pdo, $u, $storeKey, $permission){
  if (!$u) return false;
  if (in_array($u['role'], ['owner','admin'], true)) return true;
  if (!in_array($storeKey, ['scout','bodycare'], true) || !in_array($permission, ['can_inventory','can_orders'], true)) return false;
  $st = $pdo->prepare("SELECT 1 FROM store_staff_permissions WHERE user_email=? AND store_key IN(?, 'both') AND $permission=1 LIMIT 1");
  $st->execute([$u['email'], $storeKey]);
  return (bool)$st->fetchColumn();
}

switch ($a){

/* ---------------- المصادقة العامة ---------------- */

case 'wa_info':
  out(['wa' => WA_ADMIN, 'site' => SITE_NAME]);

case 'register':
  $n = trim($in['name'] ?? ''); $e = strtolower(trim($in['email'] ?? ''));
  $p = $in['pass'] ?? ''; $g = trim($in['gov'] ?? '');
  $ph = preg_replace('/[^0-9+]/', '', $in['phone'] ?? '');
  if (!$n || !filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($p) < 6)
    out(['msg'=>'تحقق من البيانات (كلمة المرور 6 أحرف على الأقل)'], false);
  try {
    $pdo->prepare("INSERT INTO users(name,email,pass_hash,gov,phone,role,status) VALUES(?,?,?,?,?,'user','pending')")
        ->execute([$n, $e, password_hash($p, PASSWORD_DEFAULT), $g, mb_substr($ph,0,30)]);
  } catch (Exception $ex) { out(['msg'=>'هذا البريد مسجل من قبل'], false); }
  logAct($pdo, $e, $n, 'register');
  out(['msg'=>'تم إنشاء الحساب بنجاح — اطلب كود التفعيل من الإدارة عبر واتساب', 'wa'=>WA_ADMIN]);

case 'activate_account':
  $e = strtolower(trim($in['email'] ?? '')); $c = strtoupper(trim($in['code'] ?? ''));
  $st = $pdo->prepare("SELECT * FROM codes WHERE code=? AND type='account' AND used=0"); $st->execute([$c]);
  $row = $st->fetch();
  if (!$row || $row['target'] !== $e) out(['msg'=>'كود التفعيل غير صحيح أو مستخدم'], false);
  $pdo->prepare("UPDATE users SET status='active' WHERE email=?")->execute([$e]);
  $pdo->prepare("UPDATE codes SET used=1 WHERE id=?")->execute([$row['id']]);
  logAct($pdo, $e, '', 'activate');
  out(['msg'=>'تم تفعيل حسابك بنجاح — يمكنك الدخول الآن']);

case 'login':
  $e = strtolower(trim($in['email'] ?? '')); $p = $in['pass'] ?? '';
  $st = $pdo->prepare("SELECT * FROM users WHERE email=?"); $st->execute([$e]); $u = $st->fetch();
  if (!$u || !password_verify($p, $u['pass_hash'])) out(['msg'=>'بيانات الدخول غير صحيحة'], false);
  if ($u['status'] === 'blocked') out(['msg'=>'هذا الحساب موقوف — تواصل مع الإدارة'], false);
  if ($u['status'] !== 'active')   out(['msg'=>'حسابك غير مفعل — اطلب كود التفعيل من الإدارة', 'wa'=>WA_ADMIN], false);
  $tk = newSession($pdo, $e, $u['role']);
  logAct($pdo, $e, $u['name'], 'login');
  out(['token'=>$tk, 'name'=>$u['name'], 'email'=>$e, 'role'=>$u['role'], 'gov'=>$u['gov']]);

case 'logout':
  $u = auth($pdo, $in);
  $email = '';
  if ($u){
    $email = $u['email'];
    $pdo->prepare("DELETE FROM sessions WHERE email=?")->execute([$u['email']]);
    logAct($pdo, $u['email'], $u['name'], 'logout_all_sessions');
  } else {
    $pdo->prepare("DELETE FROM sessions WHERE token=?")->execute([$in['token'] ?? '']);
  }
  out(['msg'=>'تم تسجيل الخروج', 'email'=>$email]);

case 'me':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'الجلسة منتهية'], false);
  out(['name'=>$u['name'], 'email'=>$u['email'], 'role'=>$u['role'], 'gov'=>$u['gov']]);

case 'store_get':
  $owner = auth($pdo, $in, ['owner']);
  $st = $pdo->prepare("SELECT meta_value FROM settings WHERE meta_key=?");
  $st->execute(['store_enabled']); $enabled = $st->fetchColumn();
  $st->execute(['store_prices']); $prices = json_decode($st->fetchColumn() ?: '{}', true);
  $st->execute(['store_prices_configured']); $pricesConfigured = $st->fetchColumn() === '1';
    $isPublished = $enabled === '1';
    $canSeePrices = $isPublished || (bool)$owner;
    if(!is_array($prices))$prices=[];
    if($canSeePrices){$dbPrices=$pdo->query("SELECT product_key,base_price FROM store_products WHERE store_key='scout'")->fetchAll();foreach($dbPrices as $product)$prices[$product['product_key']]=$prices[$product['product_key']]??floatval($product['base_price']);}
    out(['enabled'=>$isPublished, 'prices_configured'=>$pricesConfigured && $canSeePrices,
      'is_owner'=>(bool)$owner, 'prices'=>$canSeePrices?$prices:new stdClass()]);

case 'store_products':
  $storeKey = in_array($in['store'] ?? '', ['scout','bodycare'], true) ? $in['store'] : '';
  if (!$storeKey) out(['msg'=>'متجر غير صالح'], false);
  $u = auth($pdo, $in);
  $canInventory = storeAccess($pdo, $u, $storeKey, 'can_inventory');
  $enabledKey=$storeKey==='scout'?'store_enabled':'bodycare_enabled';
  $enabledQuery=$pdo->prepare("SELECT meta_value FROM settings WHERE meta_key=?");$enabledQuery->execute([$enabledKey]);
  if($enabledQuery->fetchColumn()!=='1'&&!$canInventory)out(['msg'=>'المتجر غير متاح'],false);
  $st = $pdo->prepare("SELECT product_key,name,category,description,image_url,base_price,stock,active FROM store_products WHERE store_key=? AND (active=1 OR ?=1) ORDER BY id");
  $st->execute([$storeKey, $canInventory?1:0]);
  $products=$st->fetchAll();
  if($storeKey==='bodycare'){
    $settings=$pdo->prepare("SELECT meta_key,meta_value FROM settings WHERE meta_key IN('bodycare_base_prices','bodycare_markup','bodycare_discount')");$settings->execute();$values=[];
    foreach($settings->fetchAll() as $row)$values[$row['meta_key']]=$row['meta_value'];
    $base=json_decode($values['bodycare_base_prices']??'{}',true)?:[];$markup=floatval($values['bodycare_markup']??0);$discount=floatval($values['bodycare_discount']??0);
    foreach($products as &$product){$id=$product['product_key'];$basePrice=floatval($base[$id]??$product['base_price']);$product['base_price']=$basePrice;$product['retail_price']=round($basePrice*(1+$markup/100),2);$product['price']=round($product['retail_price']*(1-$discount/100),2);$product['discount']=$discount;}unset($product);
  }else{
    $pricesQuery=$pdo->prepare("SELECT meta_value FROM settings WHERE meta_key='store_prices'");$pricesQuery->execute();$prices=json_decode($pricesQuery->fetchColumn()?:'{}',true)?:[];
    foreach($products as &$product){$product['price']=floatval($prices[$product['product_key']]??$product['base_price']);}unset($product);
  }
  out(['products'=>$products, 'can_inventory'=>$canInventory]);

case 'store_products_export':
  $u = auth($pdo, $in); $storeKey = in_array($in['store'] ?? '', ['scout','bodycare'], true) ? $in['store'] : '';
  if (!$storeKey || !storeAccess($pdo, $u, $storeKey, 'can_inventory')) out(['msg'=>'غير مصرح'], false);
  $st = $pdo->prepare("SELECT product_key,name,category,description,image_url,base_price,stock,active FROM store_products WHERE store_key=? ORDER BY id");
  $st->execute([$storeKey]); $rows = $st->fetchAll();
  $stream = fopen('php://temp', 'r+');
  fputcsv($stream, ['product_key','name','category','description','image_url','base_price','stock','active']);
  foreach ($rows as $row){
    foreach ($row as &$value){
      $value = (string)$value;
      if (preg_match('/^[\s]*[=+@\-]/', $value)) $value = "'".$value;
    }
    unset($value); fputcsv($stream, array_values($row));
  }
  rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
  out(['filename'=>$storeKey.'-products.csv','csv'=>"\xEF\xBB\xBF".$csv]);

case 'store_products_import':
  $u = auth($pdo, $in); $storeKey = in_array($in['store'] ?? '', ['scout','bodycare'], true) ? $in['store'] : '';
  if (!$storeKey || !storeAccess($pdo, $u, $storeKey, 'can_inventory')) out(['msg'=>'غير مصرح'], false);
  $csv = $in['csv'] ?? '';
  if (!is_string($csv) || strlen($csv)>5242880) out(['msg'=>'ملف CSV غير صالح أو أكبر من 5 ميجابايت'], false);
  $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
  $stream = fopen('php://temp', 'r+'); fwrite($stream, $csv); rewind($stream);
  $header = fgetcsv($stream);
  $required = ['product_key','name','category','description','image_url','base_price','stock','active'];
  if (!$header || array_map('trim',$header)!==$required){ fclose($stream); out(['msg'=>'عناوين الأعمدة المطلوبة: '.implode(',',$required)], false); }
  $rows=[]; $seenKeys=[]; $line=1;
  while (($record=fgetcsv($stream))!==false){
    $line++; if ($record===[null] || $record===[]) continue;
    if (count($record)!==count($required) || count($rows)>=500){ fclose($stream); out(['msg'=>'صف CSV غير صالح عند السطر '.$line.' أو تجاوزت 500 منتج'], false); }
    $row=array_combine($required,$record);
    foreach(['name','category','description','image_url'] as $textColumn){
      $row[$textColumn]=(string)$row[$textColumn];
      if(preg_match("/^'([=+@-])/, $row[$textColumn]))$row[$textColumn]=substr($row[$textColumn],1);
    }
    $key=trim($row['product_key']); $name=trim(strip_tags($row['name']));
    $url=trim($row['image_url']); $price=$row['base_price']; $stock=filter_var($row['stock'],FILTER_VALIDATE_INT);
    $active=in_array(strtolower(trim($row['active'])),['1','true','yes','نعم'],true)?1:(in_array(strtolower(trim($row['active'])),['0','false','no','لا'],true)?0:null);
    $key=strtolower($key);
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,47}$/',$key) || isset($seenKeys[$key]) || !$name || strlen($name)>160 || strlen($url)>500 || !is_numeric($price) || floatval($price)<0 || floatval($price)>10000000 || $stock===false || $stock<0 || $stock>1000000 || $active===null || ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !preg_match('/^https:\/\//i',$url)))){
      fclose($stream); out(['msg'=>'بيانات غير صالحة عند السطر '.$line], false);
    }
    $seenKeys[$key]=true;
    $rows[]=['key'=>$key,'name'=>$name,'category'=>mb_substr(trim(strip_tags($row['category'])),0,80),'description'=>mb_substr(trim(strip_tags($row['description'])),0,500),'url'=>$url,'price'=>round(floatval($price),2),'stock'=>$stock,'active'=>$active];
  }
  fclose($stream); if (!$rows) out(['msg'=>'ملف CSV لا يحتوي منتجات'], false);
  try{
    $pdo->beginTransaction();
    $find=$pdo->prepare("SELECT stock FROM store_products WHERE store_key=? AND product_key=? FOR UPDATE");
    $save=$pdo->prepare("INSERT INTO store_products(store_key,product_key,name,category,description,image_url,base_price,stock,active) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),category=VALUES(category),description=VALUES(description),image_url=VALUES(image_url),base_price=VALUES(base_price),stock=VALUES(stock),active=VALUES(active)");
    $stockEvent=$pdo->prepare("INSERT INTO store_stock_events(store_key,product_key,actor_email,change_qty,stock_after,reason) VALUES(?,?,?,?,?,'csv_import')");
    $priceSettingKey=$storeKey==='bodycare'?'bodycare_base_prices':'store_prices';
    $priceSetting=$pdo->prepare("SELECT meta_value FROM settings WHERE meta_key=?");$priceSetting->execute([$priceSettingKey]);
    $catalogPrices=json_decode($priceSetting->fetchColumn()?:'{}',true)?:[];
    foreach($rows as $row){
      $find->execute([$storeKey,$row['key']]); $old=$find->fetchColumn();
      $save->execute([$storeKey,$row['key'],$row['name'],$row['category'],$row['description'],$row['url'],$row['price'],$row['stock'],$row['active']]);
      $oldStock=$old===false?0:intval($old);
      if ($old===false || $oldStock!==$row['stock']) $stockEvent->execute([$storeKey,$row['key'],$u['email'],$row['stock']-$oldStock,$row['stock']]);
      $catalogPrices[$row['key']]=$row['price'];
    }
    $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES(?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute([$priceSettingKey,json_encode($catalogPrices)]);
    $pdo->commit();
  }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();out(['msg'=>'تعذر استيراد الملف بالكامل'],false);}
  logAct($pdo,$u['email'],$u['name'],'store_products_import '.$storeKey.' '.count($rows));
  out(['msg'=>'تم استيراد المنتجات','count'=>count($rows)]);

case 'store_product_stock':
  $u=auth($pdo,$in);$storeKey=$in['store']??'';$key=trim($in['product_key']??'');$stock=filter_var($in['stock']??null,FILTER_VALIDATE_INT);
  if(!in_array($storeKey,['scout','bodycare'],true)||!preg_match('/^[a-z0-9_-]{1,48}$/i',$key)||$stock===false||$stock<0||$stock>1000000||!storeAccess($pdo,$u,$storeKey,'can_inventory'))out(['msg'=>'غير مصرح أو بيانات مخزون غير صالحة'],false);
  $reason=mb_substr(trim(strip_tags($in['reason']??'adjustment')),0,40);
  $pdo->beginTransaction();$st=$pdo->prepare("SELECT stock FROM store_products WHERE store_key=? AND product_key=? FOR UPDATE");$st->execute([$storeKey,$key]);$old=$st->fetchColumn();
  if($old===false){$pdo->rollBack();out(['msg'=>'المنتج غير موجود'],false);}
  $pdo->prepare("UPDATE store_products SET stock=? WHERE store_key=? AND product_key=?")->execute([$stock,$storeKey,$key]);
  $pdo->prepare("INSERT INTO store_stock_events(store_key,product_key,actor_email,change_qty,stock_after,reason) VALUES(?,?,?,?,?,?)")->execute([$storeKey,$key,$u['email'],$stock-intval($old),$stock,$reason]);
  $pdo->commit();logAct($pdo,$u['email'],$u['name'],'store_stock '.$storeKey.' '.$key);
  out(['msg'=>'تم تحديث المخزون','stock'=>$stock]);

case 'store_staff_list':
  $u = auth($pdo, $in, ['owner']); if (!$u) out(['msg'=>'للمالك فقط'], false);
  $rows=$pdo->query("SELECT p.user_email,p.store_key,p.can_inventory,p.can_orders,p.assigned_by,p.created_at,u.name,u.role FROM store_staff_permissions p LEFT JOIN users u ON u.email=p.user_email ORDER BY p.created_at DESC LIMIT 300")->fetchAll();
  out(['assignments'=>$rows]);

case 'store_staff_candidates':
  $u=auth($pdo,$in,['owner']);if(!$u)out(['msg'=>'للمالك فقط'],false);
  $rows=$pdo->query("SELECT name,email,role FROM users WHERE status='active' AND role IN('supervisor','user') ORDER BY name LIMIT 500")->fetchAll();
  out(['users'=>$rows]);

case 'store_my_permissions':
  $u=auth($pdo,$in);if(!$u)out(['msg'=>'غير مصرح'],false);
  if(in_array($u['role'],['owner','admin'],true))out(['all'=>true,'assignments'=>[]]);
  $st=$pdo->prepare("SELECT store_key,can_inventory,can_orders FROM store_staff_permissions WHERE user_email=?");$st->execute([$u['email']]);
  out(['all'=>false,'assignments'=>$st->fetchAll()]);

case 'store_staff_assign':
  $u = auth($pdo, $in, ['owner']); if (!$u) out(['msg'=>'للمالك فقط'], false);
  $email=strtolower(trim($in['email']??''));$storeKey=$in['store']??'';
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($storeKey,['scout','bodycare','both'],true))out(['msg'=>'بريد أو متجر غير صالح'],false);
  $st=$pdo->prepare("SELECT role,status FROM users WHERE email=?");$st->execute([$email]);$target=$st->fetch();
  if(!$target||$target['status']!=='active'||!in_array($target['role'],['supervisor','user'],true))out(['msg'=>'اختر حساب مستخدم أو مشرفًا نشطًا'],false);
  $inventory=!empty($in['can_inventory'])?1:0;$orders=!empty($in['can_orders'])?1:0;
  if(!$inventory&&!$orders){$pdo->prepare("DELETE FROM store_staff_permissions WHERE user_email=? AND store_key=?")->execute([$email,$storeKey]);out(['msg'=>'تم إلغاء صلاحيات المتجر']);}
  $pdo->prepare("INSERT INTO store_staff_permissions(user_email,store_key,can_inventory,can_orders,assigned_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE can_inventory=VALUES(can_inventory),can_orders=VALUES(can_orders),assigned_by=VALUES(assigned_by)")
    ->execute([$email,$storeKey,$inventory,$orders,$u['email']]);
  logAct($pdo,$u['email'],$u['name'],'store_staff_assign '.$email.' '.$storeKey);
  out(['msg'=>'تم حفظ صلاحيات المتجر']);

case 'store_order_status':
  $u=auth($pdo,$in);if(!$u)out(['msg'=>'غير مصرح'],false);
  $orderId=intval($in['id']??0);$newStatus=$in['status']??'';$note=mb_substr(trim(strip_tags($in['note']??'')),0,300);
  if(!in_array($newStatus,['confirmed','processing','shipped','fulfilled','cancelled'],true))out(['msg'=>'حالة طلب غير صالحة'],false);
  try{
    $pdo->beginTransaction();$st=$pdo->prepare("SELECT * FROM store_orders WHERE id=? FOR UPDATE");$st->execute([$orderId]);$order=$st->fetch();
    if(!$order){$pdo->rollBack();out(['msg'=>'الطلب غير موجود'],false);}
    if(!storeAccess($pdo,$u,$order['store_key'],'can_orders')){$pdo->rollBack();out(['msg'=>'ليس لديك صلاحية متابعة طلبات هذا المتجر'],false);}
    $transitions=['new'=>['confirmed','cancelled'],'confirmed'=>['processing','cancelled'],'processing'=>['shipped','cancelled'],'shipped'=>['fulfilled'],'fulfilled'=>[],'cancelled'=>[]];
    if(!in_array($newStatus,$transitions[$order['status']]??[],true)){$pdo->rollBack();out(['msg'=>'انتقال حالة الطلب غير مسموح'],false);}
    if($newStatus==='cancelled'&&$order['status']!=='cancelled'){
      $items=$pdo->prepare("SELECT product_id,quantity FROM store_order_items WHERE order_id=?");$items->execute([$orderId]);
      $restore=$pdo->prepare("UPDATE store_products SET stock=stock+? WHERE store_key=? AND product_key=?");
      $audit=$pdo->prepare("INSERT INTO store_stock_events(store_key,product_key,actor_email,change_qty,stock_after,reason,order_id) SELECT ?,?,? ,?,stock,'order_cancelled',? FROM store_products WHERE store_key=? AND product_key=?");
      foreach($items->fetchAll() as $item){$restore->execute([$item['quantity'],$order['store_key'],$item['product_id']]);$audit->execute([$order['store_key'],$item['product_id'],$u['email'],$item['quantity'],$orderId,$order['store_key'],$item['product_id']]);}
    }
    $pdo->prepare("UPDATE store_orders SET status=? WHERE id=?")->execute([$newStatus,$orderId]);
    $pdo->prepare("INSERT INTO store_order_events(order_id,actor_email,old_status,new_status,note) VALUES(?,?,?,?,?)")->execute([$orderId,$u['email'],$order['status'],$newStatus,$note]);
    $pdo->commit();
  }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();out(['msg'=>'تعذر تحديث حالة الطلب'],false);}
  out(['msg'=>'تم تحديث حالة الطلب']);

case 'store_order':
  $st = $pdo->prepare("SELECT meta_value FROM settings WHERE meta_key='store_enabled'"); $st->execute();
  if ($st->fetchColumn() === '0') out(['msg'=>'المتجر غير متاح حاليًا'], false);
  $st = $pdo->prepare("SELECT meta_value FROM settings WHERE meta_key='store_prices_configured'"); $st->execute();
  if ($st->fetchColumn() !== '1') out(['msg'=>'الأسعار غير معتمدة بعد'], false);
  if (trim($in['website'] ?? '') !== '') out(['msg'=>'تعذر قبول الطلب'], false);
  $name = mb_substr(trim(strip_tags($in['name'] ?? '')), 0, 120);
  $phone = preg_replace('/[^0-9+]/', '', $in['phone'] ?? '');
  $gov = mb_substr(trim(strip_tags($in['gov'] ?? '')), 0, 120);
  $address = mb_substr(trim(strip_tags($in['address'] ?? '')), 0, 300);
  $note = mb_substr(trim(strip_tags($in['note'] ?? '')), 0, 400);
  $items = $in['items'] ?? [];
  if (!$name || strlen($phone)<8 || strlen($phone)>18 || !$gov || !$address || !is_array($items) || !$items || count($items)>20)
    out(['msg'=>'أكمل الاسم والهاتف والمحافظة والعنوان واختر منتجات'], false);
  $st = $pdo->prepare("SELECT meta_value FROM settings WHERE meta_key='store_prices'"); $st->execute();
  $savedPrices = json_decode($st->fetchColumn() ?: '{}', true) ?: [];
  $orderItems = []; $total = 0;
  $productLookup=$pdo->prepare("SELECT name,base_price,stock,active FROM store_products WHERE store_key='scout' AND product_key=?");
  foreach ($items as $item){
    if (!is_array($item)) out(['msg'=>'يوجد منتج أو عدد غير صالح'], false);
    $id = is_string($item['id'] ?? null) ? $item['id'] : '';
    $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (!$quantity || $quantity<1 || $quantity>20) out(['msg'=>'يوجد منتج أو عدد غير صالح'], false);
    $productLookup->execute([$id]);$product=$productLookup->fetch();
    if (!$product || !$product['active']) out(['msg'=>'المنتج غير متاح'],false);
    if (intval($product['stock'])<$quantity) out(['msg'=>'الكمية المطلوبة غير متوفرة في المخزون'],false);
    $price = isset($savedPrices[$id]) ? floatval($savedPrices[$id]) : floatval($product['base_price']);
    $line = round($price*$quantity, 2); $total += $line;
    $orderItems[] = [$id, $product['name'], $price, $quantity, $line];
  }
  $u = auth($pdo, $in);
  try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO store_orders(store_key,user_email,customer_name,phone,gov,address,note,total,status,created_at) VALUES('scout',?,?,?,?,?,?,?,'new',NOW())")
        ->execute([$u?$u['email']:null, $name, $phone, $gov, $address, $note, $total]);
    $orderId = intval($pdo->lastInsertId());
    $code = 'NES-' . date('ymd') . '-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE store_orders SET order_code=? WHERE id=?")->execute([$code, $orderId]);
    $pdo->prepare("INSERT INTO store_order_events(order_id,actor_email,old_status,new_status,note) VALUES(?,?,?,'new','تم استلام الطلب')")->execute([$orderId,$u?$u['email']:'system','']);
    $insertItem = $pdo->prepare("INSERT INTO store_order_items(order_id,product_id,product_name,unit_price,quantity,line_total) VALUES(?,?,?,?,?,?)");
    $deduct=$pdo->prepare("UPDATE store_products SET stock=stock-? WHERE store_key='scout' AND product_key=? AND active=1 AND stock>=?");
    $stockAudit=$pdo->prepare("INSERT INTO store_stock_events(store_key,product_key,actor_email,change_qty,stock_after,reason,order_id) SELECT 'scout',product_key,?,-?,stock,'order_reserved',? FROM store_products WHERE store_key='scout' AND product_key=?");
    foreach ($orderItems as $item){
      $deduct->execute([$item[3],$item[0],$item[3]]);if($deduct->rowCount()!==1)throw new RuntimeException('stock_changed');
      $insertItem->execute([$orderId, ...$item]);$stockAudit->execute([$u?$u['email']:'guest',$item[3],$orderId,$item[0]]);
    }
    $pdo->commit();
  } catch (Exception $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    out(['msg'=>'تعذر حفظ الطلب الآن، حاول مرة أخرى'], false);
  }
  out(['msg'=>'تم تسجيل الطلب بنجاح','order_code'=>$code,'total'=>$total]);

case 'store_orders':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'غير مصرح'], false);
  if(in_array($u['role'],['owner','admin'],true)){
    $orders=$pdo->query("SELECT id,store_key,order_code,customer_name,phone,gov,address,note,total,status,created_at FROM store_orders ORDER BY id DESC LIMIT 100")->fetchAll();
  }else{
    $perm=$pdo->prepare("SELECT store_key FROM store_staff_permissions WHERE user_email=? AND can_orders=1");$perm->execute([$u['email']]);$allowedStores=[];
    foreach($perm->fetchAll(PDO::FETCH_COLUMN) as $store){if($store==='both')$allowedStores=['scout','bodycare'];elseif(in_array($store,['scout','bodycare'],true))$allowedStores[]=$store;}
    $allowedStores=array_values(array_unique($allowedStores));if(!$allowedStores)out(['orders'=>[]]);
    $holders=implode(',',array_fill(0,count($allowedStores),'?'));
    $query=$pdo->prepare("SELECT id,store_key,order_code,customer_name,phone,gov,address,note,total,status,created_at FROM store_orders WHERE store_key IN($holders) ORDER BY id DESC LIMIT 100");$query->execute($allowedStores);$orders=$query->fetchAll();
  }
  $itemQuery = $pdo->prepare("SELECT product_name,unit_price,quantity,line_total FROM store_order_items WHERE order_id=? ORDER BY id");
  $eventQuery=$pdo->prepare("SELECT actor_email,old_status,new_status,note,created_at FROM store_order_events WHERE order_id=? ORDER BY id");
  foreach ($orders as &$order){$itemQuery->execute([$order['id']]);$order['items']=$itemQuery->fetchAll();$eventQuery->execute([$order['id']]);$order['events']=$eventQuery->fetchAll();}unset($order);
  out(['orders'=>$orders]);

case 'store_order_track':
  $code=mb_substr(strtoupper(trim($in['order_code']??'')),0,24);$phone=preg_replace('/[^0-9]/','',$in['phone']??'');
  if(!$code||strlen($phone)<8)out(['msg'=>'أدخل رقم الطلب ورقم الهاتف'],false);
  $st=$pdo->prepare("SELECT id,store_key,order_code,total,status,created_at,phone FROM store_orders WHERE order_code=?");$st->execute([$code]);$order=$st->fetch();
  if(!$order||preg_replace('/[^0-9]/','',$order['phone'])!==$phone)out(['msg'=>'لم يتم العثور على طلب بهذه البيانات'],false);
  $events=$pdo->prepare("SELECT old_status,new_status,created_at FROM store_order_events WHERE order_id=? ORDER BY id");$events->execute([$order['id']]);
  $items=$pdo->prepare("SELECT product_name,quantity FROM store_order_items WHERE order_id=? ORDER BY id");$items->execute([$order['id']]);
  out(['order'=>['order_code'=>$order['order_code'],'store_key'=>$order['store_key'],'total'=>$order['total'],'status'=>$order['status'],'created_at'=>$order['created_at'],'items'=>$items->fetchAll(),'events'=>$events->fetchAll()]]);

case 'bodycare_store_get':
  $owner = auth($pdo, $in, ['owner']);
  $st = $pdo->prepare("SELECT meta_value FROM settings WHERE meta_key=?");
  $st->execute(['bodycare_enabled']); $enabled = $st->fetchColumn() === '1';
  $st->execute(['bodycare_base_prices']); $basePrices = json_decode($st->fetchColumn() ?: '{}', true);
  $st->execute(['bodycare_markup']); $markup = $st->fetchColumn();
  $st->execute(['bodycare_discount']); $discount = $st->fetchColumn();
  $st->execute(['bodycare_prices_configured']); $configured = $st->fetchColumn() === '1';
  $canSee = $enabled || (bool)$owner;
  out(['enabled'=>$enabled,'is_owner'=>(bool)$owner,'prices_configured'=>$configured && $canSee,
       'base_prices'=>$canSee&&is_array($basePrices)?$basePrices:new stdClass(),
       'markup'=>$canSee&&$markup!==false?floatval($markup):0,
       'discount'=>$canSee&&$discount!==false?floatval($discount):0]);

case 'bodycare_store_manage':
  $u = auth($pdo, $in, ['owner']); if (!$u) out(['msg'=>'إدارة متجر العناية صلاحية المالك فقط'], false);
  $allowedRows=$pdo->query("SELECT product_key FROM store_products WHERE store_key='bodycare'")->fetchAll(PDO::FETCH_COLUMN);
  $allowed=$allowedRows;
  $basePrices = $in['base_prices'] ?? [];
  $markup = $in['markup'] ?? null; $discount = $in['discount'] ?? null;
  if (!is_array($basePrices) || !is_numeric($markup) || floatval($markup)<0 || floatval($markup)>500 ||
      !is_numeric($discount) || floatval($discount)<0 || floatval($discount)>90)
    out(['msg'=>'تحقق من الأسعار والهامش والخصم'], false);
  $clean = [];
  foreach ($basePrices as $id=>$price){
    if (!in_array($id,$allowed,true) || !is_numeric($price) || floatval($price)<=0 || floatval($price)>10000000)
      out(['msg'=>'تحقق من أسعار المنتجات'], false);
    $clean[$id]=round(floatval($price),2);
  }
  foreach ($allowed as $id) if (!array_key_exists($id,$clean)) out(['msg'=>'أدخل سعر الأساس لكل المنتجات'], false);
  $syncPrice=$pdo->prepare("UPDATE store_products SET base_price=? WHERE store_key='bodycare' AND product_key=?");
  foreach($clean as $id=>$price)$syncPrice->execute([$price,$id]);
  $settings = ['bodycare_enabled'=>!empty($in['enabled'])?'1':'0','bodycare_base_prices'=>json_encode($clean),
               'bodycare_markup'=>(string)floatval($markup),'bodycare_discount'=>(string)floatval($discount),
               'bodycare_prices_configured'=>'1'];
  $save = $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES(?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
  foreach ($settings as $key=>$value) $save->execute([$key,$value]);
  logAct($pdo,$u['email'],$u['name'],'bodycare_store_manage '.($settings['bodycare_enabled']==='1'?'visible':'hidden'));
  out(['msg'=>'تم حفظ متجر فيرجينيا الشرق']);

case 'bodycare_store_order':
  $st=$pdo->prepare("SELECT meta_value FROM settings WHERE meta_key=?");
  $st->execute(['bodycare_enabled']); if ($st->fetchColumn()!=='1') out(['msg'=>'متجر العناية غير متاح حاليًا'],false);
  $st->execute(['bodycare_prices_configured']); if ($st->fetchColumn()!=='1') out(['msg'=>'الأسعار لم تعتمد بعد'],false);
  if (trim($in['website']??'')!=='') out(['msg'=>'تعذر قبول الطلب'],false);
  $name=mb_substr(trim(strip_tags($in['name']??'')),0,120); $phone=preg_replace('/[^0-9+]/','',$in['phone']??'');
  $gov=mb_substr(trim(strip_tags($in['gov']??'')),0,120); $address=mb_substr(trim(strip_tags($in['address']??'')),0,300);
  $note=mb_substr(trim(strip_tags($in['note']??'')),0,400); $items=$in['items']??[];
  if (!$name||strlen($phone)<8||strlen($phone)>18||!$gov||!$address||!is_array($items)||!$items||count($items)>20)
    out(['msg'=>'أكمل الاسم والهاتف والمحافظة والعنوان واختر المنتجات'],false);
  $st->execute(['bodycare_base_prices']); $base=json_decode($st->fetchColumn()?:'{}',true)?:[];
  $st->execute(['bodycare_markup']); $markup=floatval($st->fetchColumn());
  $st->execute(['bodycare_discount']); $discount=floatval($st->fetchColumn());
  $lines=[];$total=0;$productLookup=$pdo->prepare("SELECT name,stock,active FROM store_products WHERE store_key='bodycare' AND product_key=?");
  foreach($items as $item){
    if(!is_array($item))out(['msg'=>'يوجد منتج أو عدد غير صالح'],false);
    $id=is_string($item['id']??null)?$item['id']:'';$qty=filter_var($item['quantity']??null,FILTER_VALIDATE_INT);
    if(!preg_match('/^[a-z0-9_-]{1,48}$/i',$id)||!$qty||$qty<1||$qty>20)out(['msg'=>'يوجد منتج أو عدد غير صالح'],false);
    $productLookup->execute([$id]);$product=$productLookup->fetch();
    if(!$product||!$product['active'])out(['msg'=>'المنتج غير متاح'],false);
    if(intval($product['stock'])<$qty)out(['msg'=>'الكمية المطلوبة غير متوفرة في المخزون'],false);
    $basePrice=floatval($base[$id]??$product['base_price']);if($basePrice<=0)out(['msg'=>'سعر المنتج غير مضبوط'],false);
    $retail=$basePrice*(1+$markup/100);$price=round($retail*(1-$discount/100),2);$total+=$price*$qty;
    $lines[]=[$id,$product['name'],$price,$qty,round($price*$qty,2)];
  }
  $u=auth($pdo,$in);
  try{$pdo->beginTransaction();
    $pdo->prepare("INSERT INTO store_orders(store_key,user_email,customer_name,phone,gov,address,note,total,status,created_at) VALUES('bodycare',?,?,?,?,?,?,?,'new',NOW())")
      ->execute([$u?$u['email']:null,$name,$phone,$gov,$address,$note,$total]);
    $orderId=intval($pdo->lastInsertId());$code='VES-'.date('ymd').'-'.str_pad((string)$orderId,6,'0',STR_PAD_LEFT);
    $pdo->prepare("UPDATE store_orders SET order_code=? WHERE id=?")->execute([$code,$orderId]);
    $pdo->prepare("INSERT INTO store_order_events(order_id,actor_email,old_status,new_status,note) VALUES(?,?,?,'new','تم استلام الطلب')")->execute([$orderId,$u?$u['email']:'system','']);
    $save=$pdo->prepare("INSERT INTO store_order_items(order_id,product_id,product_name,unit_price,quantity,line_total) VALUES(?,?,?,?,?,?)");
    $deduct=$pdo->prepare("UPDATE store_products SET stock=stock-? WHERE store_key='bodycare' AND product_key=? AND active=1 AND stock>=?");
    $stockAudit=$pdo->prepare("INSERT INTO store_stock_events(store_key,product_key,actor_email,change_qty,stock_after,reason,order_id) SELECT 'bodycare',product_key,?,-?,stock,'order_reserved',? FROM store_products WHERE store_key='bodycare' AND product_key=?");
    foreach($lines as $line){$deduct->execute([$line[3],$line[0],$line[3]]);if($deduct->rowCount()!==1)throw new RuntimeException('stock_changed');$save->execute([$orderId,...$line]);$stockAudit->execute([$u?$u['email']:'guest',$line[3],$orderId,$line[0]]);}
    $pdo->commit();
  }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();out(['msg'=>'تعذر تسجيل الطلب حاليًا'],false);}
  out(['msg'=>'تم تسجيل الطلب','order_code'=>$code,'total'=>round($total,2)]);

case 'store_manage':
  $u = auth($pdo, $in, ['owner']); if (!$u) out(['msg'=>'إدارة المتجر صلاحية المالك فقط'], false);
  $enabled = !empty($in['enabled']) ? '1' : '0';
  $allowed = $pdo->query("SELECT product_key FROM store_products WHERE store_key='scout'")->fetchAll(PDO::FETCH_COLUMN);
  $prices = $in['prices'] ?? [];
  if (!is_array($prices)) out(['msg'=>'قائمة الأسعار غير صالحة'], false);
  $clean = [];
  foreach ($prices as $id=>$price){
    if (!in_array($id, $allowed, true) || !is_numeric($price) || floatval($price)<=0 || floatval($price)>10000000)
      out(['msg'=>'تحقق من أسعار المنتجات'], false);
    $clean[$id] = round(floatval($price), 2);
  }
  foreach ($allowed as $id){
    if (!array_key_exists($id, $clean)) out(['msg'=>'أدخل سعرًا لكل المنتجات'], false);
  }
  $syncPrice=$pdo->prepare("UPDATE store_products SET base_price=? WHERE store_key='scout' AND product_key=?");
  foreach($clean as $id=>$price)$syncPrice->execute([$price,$id]);
  $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES('store_enabled',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
      ->execute([$enabled]);
  $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES('store_prices',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
      ->execute([json_encode($clean)]);
  $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES('store_prices_configured','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute();
  logAct($pdo, $u['email'], $u['name'], 'store_manage '.($enabled==='1'?'visible':'hidden'));
  out(['msg'=>'تم حفظ حالة المتجر والأسعار']);

case 'change_pass':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'غير مصرح'], false);
  $st = $pdo->prepare("SELECT pass_hash FROM users WHERE email=?"); $st->execute([$u['email']]);
  if (!password_verify($in['old'] ?? '', $st->fetchColumn())) out(['msg'=>'كلمة المرور الحالية غير صحيحة'], false);
  if (strlen($in['new'] ?? '') < 12) out(['msg'=>'كلمة المرور الجديدة يجب أن تكون 12 حرفًا على الأقل'], false);
  $pdo->prepare("UPDATE users SET pass_hash=? WHERE email=?")
      ->execute([password_hash($in['new'], PASSWORD_DEFAULT), $u['email']]);
  $pdo->prepare("DELETE FROM sessions WHERE email=? AND token<>?")->execute([$u['email'], $in['token']]);
  out(['msg'=>'تم تغيير كلمة المرور بنجاح']);

/* ---------------- صور الأنشطة (حتى 15 صورة) ---------------- */

case 'upload_image':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'غير مصرح — سجّل الدخول أولًا'], false);
  $data = $in['data'] ?? ''; $cap = mb_substr(trim($in['caption'] ?? ''), 0, 200);
  if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/', $data, $m)) out(['msg'=>'صيغة الصورة غير مدعومة'], false);
  $bin = base64_decode($m[2], true);
  if ($bin === false || strlen($bin) < 100) out(['msg'=>'الصورة غير صالحة'], false);
  if (strlen($bin) > MAX_IMG_MB * 1024 * 1024) out(['msg'=>'حجم الصورة أكبر من '.MAX_IMG_MB.' ميجا'], false);
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM uploads WHERE user_email=?"); $cnt->execute([$u['email']]);
  if ($cnt->fetchColumn() >= 60) out(['msg'=>'وصلت للحد الأقصى المؤقت — احفظ التقرير أولًا'], false);
  $ext = ($m[1] === 'png') ? 'png' : (($m[1] === 'webp') ? 'webp' : 'jpg');
  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
  $fname = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
  if (file_put_contents(UPLOAD_DIR . $fname, $bin) === false) out(['msg'=>'تعذر حفظ الصورة على الخادم'], false);
  $path = 'api/uploads/' . $fname;
  $okStatus = in_array($u['role'], ['owner','admin','supervisor']) ? 'approved' : 'pending';
  $pdo->prepare("INSERT INTO uploads(user_email,file_path,caption,kind,cat,status,created_at) VALUES(?,?,?,'image','أنشطة',?,NOW())")
      ->execute([$u['email'], $path, $cap, $okStatus]);
  out(['path'=>$path]);

case 'upload_video':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'غير مصرح — سجّل الدخول أولًا'], false);
  $data = $in['data'] ?? ''; $cap = mb_substr(trim($in['caption'] ?? ''), 0, 200);
  if (!preg_match('/^data:video\/(mp4|webm|quicktime);base64,(.+)$/', $data, $m)) out(['msg'=>'صيغة الفيديو غير مدعومة (MP4 / WebM فقط)'], false);
  $bin = base64_decode($m[2], true);
  if ($bin === false || strlen($bin) < 200) out(['msg'=>'الفيديو غير صالح'], false);
  if (strlen($bin) > 40 * 1024 * 1024) out(['msg'=>'حجم الفيديو أكبر من 40 ميجا'], false);
  $ext = ($m[1] === 'webm') ? 'webm' : 'mp4';
  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
  $fname = 'vid_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
  if (file_put_contents(UPLOAD_DIR . $fname, $bin) === false) out(['msg'=>'تعذر حفظ الفيديو على الخادم'], false);
  $path = 'api/uploads/' . $fname;
  $okStatus = in_array($u['role'], ['owner','admin','supervisor']) ? 'approved' : 'pending';
  $pdo->prepare("INSERT INTO uploads(user_email,file_path,caption,kind,cat,status,created_at) VALUES(?,?,?,'video',?,?,NOW())")
      ->execute([$u['email'], $path, $cap, mb_substr(trim($in['cat'] ?? 'كشفية'),0,60), $okStatus]);
  out(['path'=>$path, 'status'=>$okStatus, 'msg'=>$okStatus==='pending'?'تم الرفع — سيظهر الفيديو بعد اعتماد الإدارة':'تم الرفع والنشر']);

/* شريط الإعلانات المتحرك */
case 'announce_get':
  $st = $pdo->query("SELECT meta_value FROM settings WHERE meta_key='announce'");
  $v = $st ? $st->fetchColumn() : false;
  out(['items' => $v ? json_decode($v, true) : []]);

case 'announce_set':
  $u = auth($pdo, $in, ['owner','admin']); if (!$u) out(['msg'=>'هذه الصلاحية للمالك وأدمن الموقع'], false);
  $items = $in['items'] ?? [];
  if (!is_array($items) || count($items) > 10) out(['msg'=>'الحد الأقصى 10 إعلانات'], false);
  $clean = [];
  foreach ($items as $it){
    $txt = mb_substr(trim(strip_tags($it['text'] ?? '')), 0, 300);
    if ($txt === '') continue;
    $url = trim($it['url'] ?? '');
    if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) $url = '';
    $clean[] = ['text'=>$txt, 'url'=>mb_substr($url,0,300)];
  }
  $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES('announce',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
      ->execute([json_encode($clean, JSON_UNESCAPED_UNICODE)]);
  out(['msg'=>'تم حفظ شريط الإعلانات']);

/* وسائل التواصل — يضبطها المالك/الأدمن وتظهر للجميع */
case 'social_get':
  $st = $pdo->query("SELECT meta_value FROM settings WHERE meta_key='social'");
  $v = $st ? $st->fetchColumn() : false;
  out(['social' => $v ? json_decode($v, true) : new stdClass()]);

case 'social_set':
  $u = auth($pdo, $in, ['owner','admin']); if (!$u) out(['msg'=>'هذه الصلاحية للمالك وأدمن الموقع'], false);
  $allowed = ['youtube','tiktok','instagram','facebook','whatsapp','website'];
  $clean = [];
  foreach ($allowed as $k){
    $val = trim($in[$k] ?? '');
    if ($val !== '' && !preg_match('/^https?:\/\//i', $val)) out(['msg'=>'الروابط يجب أن تبدأ بـ https://'], false);
    $clean[$k] = mb_substr($val, 0, 300);
  }
  $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
  $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES('social',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
      ->execute([$json]);
  out(['msg'=>'تم حفظ روابط التواصل']);

  case 'social_youtube_delete':
    $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
    $st = $pdo->query("SELECT meta_value FROM settings WHERE meta_key='social'");
    $social = json_decode($st->fetchColumn() ?: '{}', true);
    if (!is_array($social)) $social = [];
    $social['youtube'] = '';
    $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES('social',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
      ->execute([json_encode($social, JSON_UNESCAPED_UNICODE)]);
    logAct($pdo, $u['email'], $u['name'], 'social_youtube_delete');
    out(['msg'=>'تم حذف فيديو يوتيوب من روابط الميديا']);

/* إحصائيات المحافظات — رؤية شاملة لكل فروع مصر */
case 'gov_stats':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'سجّل الدخول لعرض لوحة المحافظات'], false);
  $rows = $pdo->query("SELECT gov,
      SUM(CASE WHEN role='user' THEN 1 ELSE 0 END) users,
      SUM(CASE WHEN role='user' AND status='active' THEN 1 ELSE 0 END) active
    FROM users WHERE gov<>'' GROUP BY gov")->fetchAll();
  $reps = $pdo->query("SELECT gov, COUNT(*) reports FROM reports WHERE gov<>'' GROUP BY gov")->fetchAll();
  $map = [];
  foreach ($rows as $r) $map[$r['gov']] = ['gov'=>$r['gov'],'users'=>intval($r['users']),'active'=>intval($r['active']),'reports'=>0];
  foreach ($reps as $r){ if(isset($map[$r['gov']])) $map[$r['gov']]['reports']=intval($r['reports']); else $map[$r['gov']]=['gov'=>$r['gov'],'users'=>0,'active'=>0,'reports'=>intval($r['reports'])]; }
  $out_rows = array_values($map);
  usort($out_rows, fn($x,$y)=>$y['reports']<=>$x['reports']);
  out(['govs'=>$out_rows]);

case 'quick_upload':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'غير مصرح — سجّل الدخول أولًا'], false);
  $kind = $in['kind'] ?? 'image';
  $data = $in['data'] ?? ''; $cap = mb_substr(trim($in['caption'] ?? ''), 0, 200);
  $pat = $kind === 'video' ? '/^data:video\/(mp4|webm|quicktime);base64,(.+)$/' : '/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/';
  if (!preg_match($pat, $data, $m)) out(['msg'=>'صيغة الملف غير مدعومة'], false);
  $bin = base64_decode($m[2], true);
  if ($bin === false || strlen($bin) < 100) out(['msg'=>'الملف غير صالح'], false);
  $maxMb = $kind === 'video' ? MAX_VID_MB : MAX_IMG_MB;
  if (strlen($bin) > $maxMb * 1024 * 1024) out(['msg'=>'حجم الملف أكبر من '.$maxMb.' ميجا'], false);
  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
  $ext = $kind === 'video' ? (($m[1]==='webm')?'webm':'mp4') : (($m[1]==='png')?'png':(($m[1]==='webp')?'webp':'jpg'));
  $fname = ($kind === 'video' ? 'vid_' : 'img_') . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
  if (file_put_contents(UPLOAD_DIR . $fname, $bin) === false) out(['msg'=>'تعذر الحفظ على الخادم'], false);
  $path = 'api/uploads/' . $fname;
  $okStatus = in_array($u['role'], ['owner','admin','supervisor']) ? 'approved' : 'pending';
  $pdo->prepare("INSERT INTO uploads(user_email,file_path,caption,kind,cat,status,created_at) VALUES(?,?,?,?,?,?,NOW())")
      ->execute([$u['email'], $path, $cap, $kind, mb_substr(trim($in['cat'] ?? 'أنشطة'),0,60), $okStatus]);
  logAct($pdo, $u['email'], $u['name'], 'quick_upload '.$kind);
  out(['msg'=> $okStatus==='pending' ? '✔ تم الرفع — سيظهر بعد اعتماد الإدارة' : '✔ تم الرفع والنشر', 'path'=>$path]);

case 'gallery_list':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'سجّل الدخول لعرض المعرض'], false);
  $isStaff = in_array($u['role'], ['owner','admin','supervisor']);
  $kind = in_array($in['kind'] ?? '', ['image','video','pdf']) ? $in['kind'] : '';
  $where = $isStaff ? "1=1" : "(status='approved' OR user_email=".$pdo->quote($u['email']).")";
  if ($kind) $where .= " AND kind=".$pdo->quote($kind);
  $rows = $pdo->query("SELECT id,user_email,file_path,caption,kind,cat,status,created_at FROM uploads WHERE $where ORDER BY id DESC LIMIT 300")->fetchAll();
  out(['items'=>$rows]);

case 'gallery_delete':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
  $id = intval($in['id'] ?? 0);
  $st = $pdo->prepare("SELECT file_path FROM uploads WHERE id=? AND kind='video'"); $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) out(['msg'=>'الفيديو غير موجود'], false);
  $path = basename($row['file_path']);
  if ($path !== '' && is_file(__DIR__ . '/uploads/' . $path)) @unlink(__DIR__ . '/uploads/' . $path);
  $pdo->prepare("DELETE FROM uploads WHERE id=? AND kind='video'")->execute([$id]);
  logAct($pdo, $u['email'], $u['name'], 'gallery_delete video '.$id);
  out(['msg'=>'تم حذف الفيديو']);

/* رفع كتاب PDF لمكتبة المعرفة — للمالك/الأدمن/المشرف فقط */
case 'library_upload':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'رفع الكتب صلاحية فريق الإدارة'], false);
  $data = $in['data'] ?? ''; $title = mb_substr(trim($in['title'] ?? ''), 0, 200);
  $cat  = mb_substr(trim($in['cat'] ?? 'إرشادية'), 0, 60);
  $lang = in_array($in['lang'] ?? '', ['عربي','إنجليزي']) ? $in['lang'] : 'عربي';
  if (!$title) out(['msg'=>'اكتب اسم الكتاب'], false);
  if (!preg_match('/^data:application\/pdf;base64,(.+)$/', $data, $m)) out(['msg'=>'الملف يجب أن يكون PDF'], false);
  $bin = base64_decode($m[1], true);
  if ($bin === false || strlen($bin) < 200) out(['msg'=>'الملف غير صالح'], false);
  if (strlen($bin) > 25 * 1024 * 1024) out(['msg'=>'حجم الكتاب أكبر من 25 ميجا'], false);
  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
  $fname = 'book_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.pdf';
  if (file_put_contents(UPLOAD_DIR . $fname, $bin) === false) out(['msg'=>'تعذر الحفظ على الخادم'], false);
  $pdo->prepare("INSERT INTO uploads(user_email,file_path,caption,kind,cat,lang,status,created_at) VALUES(?,?,?,'pdf',?,?,'approved',NOW())")
      ->execute([$u['email'], 'api/uploads/' . $fname, $title, $cat, $lang]);
  out(['msg'=>'✔ تمت إضافة الكتاب إلى مكتبة المعرفة']);

/* أقسام مكتبة المعرفة — يضيفها المالك/الأدمن */
case 'lib_cats_get':
  $st = $pdo->query("SELECT meta_value FROM settings WHERE meta_key='lib_cats'");
  $v = $st ? $st->fetchColumn() : false;
  $defs = ['كتب إرشادية','كتب ثقافية','كتب علمية','كتب قيادة','كتب أطفال'];
  out(['cats' => $v ? json_decode($v, true) : $defs]);

case 'lib_cats_set':
  $u = auth($pdo, $in, ['owner','admin']); if (!$u) out(['msg'=>'للمالك وأدمن الموقع'], false);
  $cats = $in['cats'] ?? [];
  if (!is_array($cats) || count($cats) > 30) out(['msg'=>'الحد الأقصى 30 قسمًا'], false);
  $clean = [];
  foreach ($cats as $c){ $c = mb_substr(trim(strip_tags($c)), 0, 60); if ($c !== '') $clean[] = $c; }
  if (!$clean) out(['msg'=>'أضف قسمًا واحدًا على الأقل'], false);
  $pdo->prepare("INSERT INTO settings(meta_key,meta_value) VALUES('lib_cats',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
      ->execute([json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_UNICODE)]);
  out(['msg'=>'تم حفظ الأقسام']);

case 'library_list':
  $stt = $pdo->query("SELECT id,file_path,caption,cat,lang,created_at FROM uploads WHERE kind='pdf' AND status='approved' ORDER BY id DESC LIMIT 500");
  out(['books'=>$stt->fetchAll()]);

case 'library_delete':
  $u = auth($pdo, $in, ['owner','admin']); if (!$u) out(['msg'=>'للمالك وأدمن الموقع'], false);
  $st = $pdo->prepare("SELECT file_path FROM uploads WHERE id=? AND kind='pdf'"); $st->execute([intval($in['id'] ?? 0)]);
  $row = $st->fetch();
  if ($row){ $fp = __DIR__ . '/uploads/' . basename($row['file_path']); if (is_file($fp)) @unlink($fp); }
  $pdo->prepare("DELETE FROM uploads WHERE id=? AND kind='pdf'")->execute([intval($in['id'] ?? 0)]);
  out(['msg'=>'تم حذف الكتاب']);

/* فيديوهات يوتيوب المصنفة — رابط فقط والعرض داخل الموقع */
case 'yt_add':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'سجّل الدخول أولًا'], false);
  $url = trim($in['url'] ?? ''); $title = mb_substr(trim($in['title'] ?? ''), 0, 200);
  $cat = mb_substr(trim($in['cat'] ?? 'كشفية'), 0, 60);
  if (!preg_match('/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\//i', $url)) out(['msg'=>'الرابط يجب أن يكون من يوتيوب'], false);
  if (!preg_match('/(?:youtu\.be\/|v=|shorts\/|live\/|embed\/|v\/)([\w-]{11})/', $url, $match)) out(['msg'=>'تعذر استخراج الفيديو من الرابط'], false);
  $url = 'https://www.youtube-nocookie.com/embed/' . $match[1];
  $okStatus = in_array($u['role'], ['owner','admin','supervisor']) ? 'approved' : 'pending';
  $pdo->prepare("INSERT INTO yt_videos(url,title,cat,status,added_by,created_at) VALUES(?,?,?,?,?,NOW())")
      ->execute([mb_substr($url,0,300), $title, $cat, $okStatus, $u['email']]);
  out(['msg'=> $okStatus==='pending' ? 'تمت الإضافة — يظهر الفيديو بعد اعتماد الإدارة' : '✔ تمت الإضافة والنشر']);

case 'yt_list':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'سجّل الدخول لعرض الفيديوهات'], false);
  $isStaff = in_array($u['role'], ['owner','admin','supervisor']);
  if ($isStaff){
    $rows = $pdo->query("SELECT * FROM yt_videos ORDER BY id DESC LIMIT 300")->fetchAll();
  } else {
    $st = $pdo->prepare("SELECT * FROM yt_videos WHERE status='approved' OR added_by=? ORDER BY id DESC LIMIT 200");
    $st->execute([$u['email']]); $rows = $st->fetchAll();
  }
  out(['videos'=>$rows]);

/* اعتماد/رفض الوسائط والفيديوهات — للمالك/الأدمن/المشرف */
case 'moderate_upload':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
  $stt = in_array($in['status'] ?? '', ['approved','rejected']) ? $in['status'] : 'approved';
  $pdo->prepare("UPDATE uploads SET status=? WHERE id=?")->execute([$stt, intval($in['id'] ?? 0)]);
  out(['msg'=> $stt==='approved' ? '✔ تم الاعتماد والنشر' : 'تم الرفض']);

case 'moderate_yt':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
  $stt = in_array($in['status'] ?? '', ['approved','rejected']) ? $in['status'] : 'approved';
  $pdo->prepare("UPDATE yt_videos SET status=? WHERE id=?")->execute([$stt, intval($in['id'] ?? 0)]);
  out(['msg'=> $stt==='approved' ? '✔ تم الاعتماد والنشر' : 'تم الرفض']);

case 'yt_delete':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
  $pdo->prepare("DELETE FROM yt_videos WHERE id=?")->execute([intval($in['id'] ?? 0)]);
  out(['msg'=>'تم الحذف']);

/* ---------------- التقارير (محفوظة على الخادم) ---------------- */

case 'save_report':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'غير مصرح — سجّل الدخول أولًا'], false);
  $payload = json_encode($in['payload'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);
  if (strlen($payload) > 3 * 1024 * 1024) out(['msg'=>'حجم التقرير كبير جدًا'], false);
  $pdo->prepare("INSERT INTO reports(serial,user_email,user_name,gov,period,month_label,payload,created_at)
                 VALUES('',?,?,?,?,?,?,NOW())")
      ->execute([$u['email'], $u['name'], mb_substr(trim($in['gov'] ?? ''),0,120),
                 mb_substr(trim($in['period'] ?? 'شهري'),0,40), mb_substr(trim($in['month_label'] ?? ''),0,60), $payload]);
  $id = $pdo->lastInsertId();
  $ser = reportSerial($id);
  $pdo->prepare("UPDATE reports SET serial=? WHERE id=?")->execute([$ser, $id]);
  logAct($pdo, $u['email'], $u['name'], 'save_report '.$ser);
  out(['msg'=>'تم حفظ التقرير على الخادم', 'serial'=>$ser, 'id'=>$id]);

case 'my_reports':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'غير مصرح'], false);
  $st = $pdo->prepare("SELECT id,serial,gov,period,month_label,user_name,created_at FROM reports WHERE user_email=? ORDER BY id DESC LIMIT 200");
  $st->execute([$u['email']]);
  out(['reports'=>$st->fetchAll()]);

case 'get_report':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'غير مصرح'], false);
  $st = $pdo->prepare("SELECT * FROM reports WHERE id=?"); $st->execute([intval($in['id'] ?? 0)]); $r = $st->fetch();
  if (!$r) out(['msg'=>'التقرير غير موجود'], false);
  $staff = in_array($u['role'], ['owner','admin','supervisor']);
  if (!$staff && $r['user_email'] !== $u['email']) out(['msg'=>'غير مصرح بعرض هذا التقرير'], false);
  $r['payload'] = json_decode($r['payload'], true);
  out(['report'=>$r]);

case 'delete_report':
  $u = auth($pdo, $in); if (!$u) out(['msg'=>'غير مصرح'], false);
  $st = $pdo->prepare("SELECT user_email FROM reports WHERE id=?"); $st->execute([intval($in['id'] ?? 0)]); $r = $st->fetch();
  if (!$r) out(['msg'=>'التقرير غير موجود'], false);
  $staff = in_array($u['role'], ['owner','admin']);
  if (!$staff && $r['user_email'] !== $u['email']) out(['msg'=>'غير مصرح بالحذف'], false);
  $pdo->prepare("DELETE FROM reports WHERE id=?")->execute([intval($in['id'])]);
  out(['msg'=>'تم حذف التقرير']);

/* ---------------- الإدارة (مالك / أدمن / مشرف) ---------------- */

case 'admin_users':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
  out(['users'=>$pdo->query("SELECT id,name,email,gov,phone,role,status,created_at FROM users WHERE role='user' ORDER BY id DESC LIMIT 500")->fetchAll()]);

case 'admin_gen_code':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
  $e = strtolower(trim($in['email'] ?? ''));
  $st = $pdo->prepare("SELECT id,status FROM users WHERE email=?"); $st->execute([$e]); $t = $st->fetch();
  if (!$t) out(['msg'=>'لا يوجد حساب بهذا البريد'], false);
  if ($t['status'] === 'active') out(['msg'=>'هذا الحساب مفعل بالفعل'], false);
  $c = genAccountCode($pdo);
  $pdo->prepare("INSERT INTO codes(code,type,target,created_by) VALUES(?,'account',?,?)")->execute([$c, $e, $u['email']]);
  logAct($pdo, $u['email'], $u['name'], 'gen_code '.$e);
  out(['code'=>$c, 'wa'=>WA_ADMIN]);

case 'admin_set_status':
  $u = auth($pdo, $in, ['owner','admin']); if (!$u) out(['msg'=>'هذه الصلاحية للمالك وأدمن الموقع'], false);
  $stt = in_array($in['status'] ?? '', ['active','pending','blocked']) ? $in['status'] : 'pending';
  $pdo->prepare("UPDATE users SET status=? WHERE id=? AND role='user'")->execute([$stt, intval($in['id'] ?? 0)]);
  out(['msg'=>'تم تحديث الحالة']);

case 'admin_del_user':
  $u = auth($pdo, $in, ['owner','admin']); if (!$u) out(['msg'=>'هذه الصلاحية للمالك وأدمن الموقع'], false);
  $pdo->prepare("DELETE FROM users WHERE id=? AND role='user'")->execute([intval($in['id'] ?? 0)]);
  out(['msg'=>'تم حذف المستخدم']);

case 'admin_add_staff':
  $u = auth($pdo, $in, ['owner']); if (!$u) out(['msg'=>'إضافة الإداريين صلاحية المالك فقط'], false);
  $n = trim($in['name'] ?? ''); $e = strtolower(trim($in['email'] ?? ''));
  $p = $in['pass'] ?? ''; $r = $in['role'] ?? 'supervisor';
  if (!in_array($r, ['admin','supervisor'])) $r = 'supervisor';
  if (!$n || !filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($p) < 12) out(['msg'=>'تحقق من البيانات — كلمة المرور 12 حرفًا على الأقل'], false);
  try {
    $pdo->prepare("INSERT INTO users(name,email,pass_hash,role,status) VALUES(?,?,?,?,'active')")
        ->execute([$n, $e, password_hash($p, PASSWORD_DEFAULT), $r]);
  } catch (Exception $ex) { out(['msg'=>'البريد مسجل من قبل'], false); }
  out(['msg'=>'تمت إضافة '.($r==='admin'?'أدمن الموقع':'المشرف').' بنجاح']);

case 'admin_staff':
  $u = auth($pdo, $in, ['owner']); if (!$u) out(['msg'=>'للمالك فقط'], false);
  out(['staff'=>$pdo->query("SELECT id,name,email,role,created_at FROM users WHERE role IN('admin','supervisor') ORDER BY role,id")->fetchAll()]);

case 'admin_set_role':
  $u = auth($pdo, $in, ['owner']); if (!$u) out(['msg'=>'للمالك فقط'], false);
  $r = $in['role'] ?? '';
  if (!in_array($r, ['user','supervisor','admin'])) out(['msg'=>'دور غير صالح'], false);
  $pdo->prepare("UPDATE users SET role=? WHERE id=? AND role!='owner'")->execute([$r, intval($in['id'] ?? 0)]);
  out(['msg'=>'تم تغيير الدور']);

case 'admin_del_staff':
  $u = auth($pdo, $in, ['owner']); if (!$u) out(['msg'=>'للمالك فقط'], false);
  $pdo->prepare("DELETE FROM users WHERE id=? AND role IN('admin','supervisor')")->execute([intval($in['id'] ?? 0)]);
  out(['msg'=>'تم الحذف']);

case 'admin_codes':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
  out(['codes'=>$pdo->query("SELECT * FROM codes ORDER BY id DESC LIMIT 300")->fetchAll()]);

case 'admin_reports':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
  out(['reports'=>$pdo->query("SELECT id,serial,user_name,user_email,gov,period,month_label,created_at FROM reports ORDER BY id DESC LIMIT 500")->fetchAll()]);

case 'admin_logs':
  $u = auth($pdo, $in, ['owner','admin']); if (!$u) out(['msg'=>'سجل الدخول للمالك وأدمن الموقع'], false);
  out(['logs'=>$pdo->query("SELECT * FROM logs ORDER BY id DESC LIMIT 300")->fetchAll()]);

case 'admin_stats':
  $u = auth($pdo, $in, ['owner','admin','supervisor']); if (!$u) out(['msg'=>'غير مصرح'], false);
  $c = fn($q) => $pdo->query($q)->fetch(PDO::FETCH_NUM)[0];
  out(['users'    =>$c("SELECT COUNT(*) FROM users WHERE role='user'"),
       'active'   =>$c("SELECT COUNT(*) FROM users WHERE role='user' AND status='active'"),
       'pending'  =>$c("SELECT COUNT(*) FROM users WHERE role='user' AND status='pending'"),
       'reports'  =>$c("SELECT COUNT(*) FROM reports"),
       'images'   =>$c("SELECT COUNT(*) FROM uploads"),
       'codes'    =>$c("SELECT COUNT(*) FROM codes WHERE used=0")]);

default: out(['msg'=>'إجراء غير معروف'], false);
}
