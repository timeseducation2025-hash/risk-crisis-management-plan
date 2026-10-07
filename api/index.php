<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');
$pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$in=json_decode(file_get_contents('php://input'),true)?:$_POST;
$a=$in['action']??'';
function out($d,$ok=true){echo json_encode(['ok'=>$ok]+$d,JSON_UNESCAPED_UNICODE);exit;}
function token($n=24){return rtrim(strtr(base64_encode(random_bytes($n)),'+/','-_'),'=');}
function adminAuth($pdo,$in){ /* تحقق من توكن الإدارة */
  $t=$in['token']??'';$st=$pdo->prepare("SELECT * FROM users WHERE role IN('super','sub') AND status='active'");
  $st->execute();foreach($st as $u){/* توكن بسيط مؤقت مخزن في meta الكود — نستخدم جلسة خادمية */ }
  if(empty($_SESSION))session_start();
  return (!empty($_SESSION['admin'])&&$_SESSION['admin_token']===$t)?$_SESSION['admin']:null;
}
if(session_status()===PHP_SESSION_NONE)session_start();

switch($a){
case 'device_status':
  $st=$pdo->prepare("SELECT * FROM devices WHERE hwid=?");$st->execute([$in['hwid']??'']);
  $d=$st->fetch();
  if(!$d)out(['active'=>false,'wa'=>WA_ADMIN]);
  $exp=$d['expiry']?strtotime($d['expiry']):null;
  out(['active'=>($exp===null||$exp>time()),'expiry'=>$d['expiry'],'label'=>$d['label'],'wa'=>WA_ADMIN]);
case 'activate_device':
  $hwid=strtoupper(preg_replace('/[^A-Z0-9]/','',$in['hwid']??''));
  $c=strtoupper(trim($in['code']??''));
  $st=$pdo->prepare("SELECT * FROM codes WHERE code=? AND type='device' AND used=0");$st->execute([$c]);$row=$st->fetch();
  if(!$row||$row['target']!==$hwid)out(['msg'=>'كود التفعيل غير صحيح أو مستخدم أو غير مخصص لهذا الجهاز'],false);
  $exp=$row['days']>=9999?null:date('Y-m-d H:i:s',time()+$row['days']*86400);
  $pdo->prepare("INSERT INTO devices(hwid,label,expiry,created_at,created_by) VALUES(?,?,?,NOW(),?)
    ON DUPLICATE KEY UPDATE label=VALUES(label),expiry=VALUES(expiry)")->execute([$hwid,$in['label']??'',$exp,$row['created_by']]);
  $pdo->prepare("UPDATE codes SET used=1 WHERE id=?")->execute([$row['id']]);
  out(['msg'=>'تم تفعيل الجهاز بنجاح','expiry'=>$exp]);
case 'register':
  $n=trim($in['name']??'');$e=strtolower(trim($in['email']??''));$p=$in['pass']??'';
  if(!$n||!filter_var($e,FILTER_VALIDATE_EMAIL)||strlen($p)<6)out(['msg'=>'تحققي من البيانات (كلمة مرور 6+ أحرف)'],false);
  try{$pdo->prepare("INSERT INTO users(name,email,pass_hash,status) VALUES(?,?,?,'pending')")
      ->execute([$n,$e,password_hash($p,PASSWORD_DEFAULT)]);}
  catch(Exception $ex){out(['msg'=>'هذا البريد مسجل من قبل'],false);}
  out(['msg'=>'تم إنشاء الحساب — اطلبي من الإدارة كود تفعيل الحساب']);
case 'activate_account':
  $e=strtolower(trim($in['email']??''));$c=strtoupper(trim($in['code']??''));
  $st=$pdo->prepare("SELECT * FROM codes WHERE code=? AND type='account' AND used=0");$st->execute([$c]);$row=$st->fetch();
  if(!$row||$row['target']!==$e)out(['msg'=>'كود التفعيل غير صحيح أو مستخدم'],false);
  $pdo->prepare("UPDATE users SET status='active' WHERE email=?")->execute([$e]);
  $pdo->prepare("UPDATE codes SET used=1 WHERE id=?")->execute([$row['id']]);
  out(['msg'=>'تم تفعيل حسابك بنجاح']);
case 'login':
  $e=strtolower(trim($in['email']??''));$p=$in['pass']??'';$hwid=$in['hwid']??'';
  $st=$pdo->prepare("SELECT * FROM users WHERE email=? AND role='user'");$st->execute([$e]);$u=$st->fetch();
  if(!$u||!password_verify($p,$u['pass_hash']))out(['msg'=>'بيانات الدخول غير صحيحة'],false);
  if($u['status']!=='active')out(['msg'=>'حسابك غير مفعل — اطلبي كود التفعيل من الإدارة'],false);
  $st=$pdo->prepare("SELECT * FROM devices WHERE hwid=?");$st->execute([$hwid]);$d=$st->fetch();
  if(!$d)out(['msg'=>'هذا الجهاز غير مفعل','device'=>true],false);
  if($d['expiry']&&strtotime($d['expiry'])<time())out(['msg'=>'انتهت صلاحية تفعيل هذا الجهاز','device'=>true],false);
  $tk=token();
  $_SESSION['user_'.$tk]=['email'=>$e,'name'=>$u['name']];
  $pdo->prepare("INSERT INTO logs(user_email,user_name,hwid,at) VALUES(?,?,?,NOW())")->execute([$e,$u['name'],$hwid]);
  out(['token'=>$tk,'name'=>$u['name'],'email'=>$e]);
case 'admin_login':
  $e=strtolower(trim($in['email']??''));$p=$in['pass']??'';
  $st=$pdo->prepare("SELECT * FROM users WHERE email=? AND role IN('super','sub') AND status='active'");
  $st->execute([$e]);$u=$st->fetch();
  if(!$u||!password_verify($p,$u['pass_hash']))out(['msg'=>'بيانات الإدارة غير صحيحة'],false);
  $tk=token();
  $_SESSION['admin']=['email'=>$e,'name'=>$u['name'],'role'=>$u['role']];
  $_SESSION['admin_token']=$tk;
  $pdo->prepare("INSERT INTO logs(user_email,user_name,hwid,at) VALUES(?,?,'ADMIN',NOW())")->execute([$e,$u['name']]);
  out(['token'=>$tk,'name'=>$u['name'],'role'=>$u['role']]);
case 'admin_users':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  if($adm['role']!=='super')out(['msg'=>'هذه الصلاحية للأدمن العام فقط'],false);
  out(['users'=>$pdo->query("SELECT id,name,email,status,created_at FROM users WHERE role='user' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC)]);
case 'admin_gen_account_code':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  $e=strtolower(trim($in['email']??''));
  $c=token(6);$c=substr(preg_replace('/[^A-Z0-9]/','',strtoupper(base64_encode(hex2bin(md5($c))))),0,4).'-'.token(2);
  $pdo->prepare("INSERT INTO codes(code,type,target,days,created_by) VALUES(?,'account',?,0,?)")->execute([$c,$e,$adm['email']]);
  out(['code'=>$c]);
case 'admin_gen_device_code':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  $hwid=strtoupper(preg_replace('/[^A-Z0-9]/','',$in['hwid']??''));$days=intval($in['days']??30);
  if(strlen($hwid)!==16)out(['msg'=>'رقم الجهاز يجب أن يكون 16 خانة'],false);
  $c=strtoupper(substr(preg_replace('/[^A-Z0-9]/','',base64_encode(random_bytes(9))),0,12));
  $c=substr($c,0,4).'-'.substr($c,4,4).'-'.substr($c,8,4);
  $pdo->prepare("INSERT INTO codes(code,type,target,meta,days,created_by) VALUES(?,'device',?,?,?,?)")
      ->execute([$c,$hwid,$in['label']??'',$days,$adm['email']]);
  out(['code'=>$c]);
case 'admin_codes':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  out(['codes'=>$pdo->query("SELECT * FROM codes ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC)]);
case 'admin_add_sub':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  if($adm['role']!=='super')out(['msg'=>'إضافة أدمن فرعي للعامة فقط'],false);
  $n=trim($in['name']??'');$e=strtolower(trim($in['email']??''));$p=$in['pass']??'';
  if(!$n||!filter_var($e,FILTER_VALIDATE_EMAIL)||strlen($p)<6)out(['msg'=>'تحققي من البيانات'],false);
  try{$pdo->prepare("INSERT INTO users(name,email,pass_hash,role,status) VALUES(?,?,?,'sub','active')")
      ->execute([$n,$e,password_hash($p,PASSWORD_DEFAULT)]);}
  catch(Exception $ex){out(['msg'=>'البريد مسجل من قبل'],false);}
  out(['msg'=>'تمت الإضافة']);
case 'admin_subs':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  if($adm['role']!=='super')out(['msg'=>'للعامة فقط'],false);
  out(['subs'=>$pdo->query("SELECT id,name,email FROM users WHERE role='sub'")->fetchAll(PDO::FETCH_ASSOC)]);
case 'admin_del_sub':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  if($adm['role']!=='super')out(['msg'=>'للعامة فقط'],false);
  $pdo->prepare("DELETE FROM users WHERE id=? AND role='sub'")->execute([intval($in['id'])]);
  out(['msg'=>'تم الحذف']);
case 'admin_logs':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  if($adm['role']!=='super')out(['msg'=>'سجل الدخول للعامة فقط'],false);
  out(['logs'=>$pdo->query("SELECT * FROM logs ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC)]);
case 'admin_devices':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  out(['devices'=>$pdo->query("SELECT * FROM devices ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC)]);
case 'admin_stats':
  $adm=adminAuth($pdo,$in);if(!$adm)out(['msg'=>'غير مصرح'],false);
  $c=fn($q)=>$pdo->query($q)->fetch(PDO::FETCH_NUM)[0];
  out(['users'=>$c("SELECT COUNT(*) FROM users WHERE role='user'"),
       'active'=>$c("SELECT COUNT(*) FROM users WHERE role='user' AND status='active'"),
       'devices'=>$c("SELECT COUNT(*) FROM devices"),
       'codes'=>$c("SELECT COUNT(*) FROM codes WHERE used=0")]);
default: out(['msg'=>'إجراء غير معروف'],false);
}
