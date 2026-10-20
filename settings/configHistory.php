<?php
// History is read from confirmed payments; never infer a previous quota from today's plan.
function v2hist_allowed($actor){
    $u=v2raystore_getUserRowFresh((int)$actor);
    return v2cfg_isAdmin((int)$actor,$u);
}
function v2hist_attach($keys,$order,$actor){
    if(v2hist_allowed($actor))array_splice($keys,max(0,count($keys)-1),0,[[['text'=>'📜 تاریخچهٔ کانفیگ','callback_data'=>'cfgHist_open_'.(int)$order['id'].'_0']]]);
    return $keys;
}
function v2hist_snapshot($order){
    try{
        $s=v2raystore_renewSnapshotFromOrder($order);
        return array_intersect_key($s,array_flip(['created_at','volume_bytes','volume_source','expire_date','expire_source','remaining_gb']));
    }catch(Throwable $e){return ['created_at'=>time()];}
}
function v2hist_record($hash,$order,$before,$volume,$days,$mode){
    global $connection;
    // Audit failure must not turn a successful renewal into a retry and double credit.
    try{
        $after=v2hist_snapshot($order);
        $s=$connection->prepare('SELECT description FROM pays WHERE hash_id=? LIMIT 1');
        if(!$s)return;$s->bind_param('s',$hash);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();if(!$r)return;
        $m=json_decode((string)$r['description'],true);
        if(!is_array($m))$m=['previous_description'=>(string)$r['description']];
        if(isset($m['service_history']))return;
        $m['service_history']=['version'=>1,'order_id'=>(int)$order['id'],'completed_at'=>time(),'mode'=>$mode,'volume_gb'=>(float)$volume,'days'=>(int)$days,'before'=>array_intersect_key($before,array_flip(['created_at','volume_bytes','volume_source','expire_date','expire_source','remaining_gb'])),'after'=>$after];
        $json=json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $s=$connection->prepare('UPDATE pays SET description=? WHERE hash_id=? LIMIT 1');if(!$s)return;
        $s->bind_param('ss',$json,$hash);if(!$s->execute())error_log('Config history persistence failed');$s->close();
    }catch(Throwable $e){error_log('Config history persistence failed');}
}
function v2hist_matches($p,$id){
    $m=json_decode((string)($p['description']??''),true);if(!is_array($m))$m=[];
    if((int)($m['service_history']['order_id']??0)===$id)return true;
    if(($p['type']??'')==='RENEW_ACCOUNT')return v2raystore_getRenewOrderIdFromPay($p)===$id;
    if(preg_match('/^INCREASE_(?:VOLUME|DAY)_(\d+)_\d+$/D',(string)($p['type']??''),$a))return (int)$a[1]===$id;
    $ids=json_decode((string)($p['auto_approved_orders']??''),true);
    return is_array($ids)&&in_array($id,array_map('intval',$ids),true);
}
function v2hist_num($v){return rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.');}
function v2hist_date($t){return (int)$t>0?jdate('Y/m/d H:i',(int)$t,'','Asia/Tehran','en'):'ثبت نشده';}
function v2hist_volume($s){
    if(($s['volume_source']??'')!=='panel'||!array_key_exists('volume_bytes',$s))return 'ثبت نشده';
    return (int)$s['volume_bytes']===0?'نامحدود':v2hist_num($s['volume_bytes']/1073741824).' گیگ';
}
function v2hist_expiry($s){
    if(($s['expire_source']??'')!=='panel'||!array_key_exists('expire_date',$s))return 'ثبت نشده';
    $exp=(int)$s['expire_date'];if($exp===0)return 'بدون تاریخ انقضا';if($exp<0)return 'شروع با اولین اتصال';
    $txt=v2hist_date($exp);
    if(!empty($s['created_at']))$txt.=' ('.v2hist_num(max(0,$exp-(int)$s['created_at'])/86400).' روز مانده)';
    return $txt;
}
function v2hist_entry($p){
    $h=function($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};
    $m=json_decode((string)($p['description']??''),true);if(!is_array($m))$m=[];
    $a=$m['service_history']??null;$e=$m['renew_effect']??[];$before=$a['before']??($m['renew_snapshot']??[]);$after=$a['after']??[];
    $type=(string)($p['type']??'');$title=$type==='BUY_SUB'?'خرید سرویس':(str_starts_with($type,'INCREASE_VOLUME')?'افزایش حجم':(str_starts_with($type,'INCREASE_DAY')?'افزایش زمان':'تمدید سرویس'));
    $method=(string)($p['payment_method']??'');
    $method=($method==='wallet'||($p['state']??'')==='paid_with_wallet')?'کیف پول':(['card'=>'کارت‌به‌کارت','cart'=>'کارت‌به‌کارت','cart_to_cart'=>'کارت‌به‌کارت','gateway'=>'درگاه پرداخت','crypto'=>'ارز دیجیتال'][$method]??($method!==''?$method:'ثبت نشده'));
    $time=(int)($a['completed_at']??($p['auto_approved_date']??0));$timeLabel=$time>0?'انجام':'ثبت درخواست';if($time<=0)$time=(int)($p['request_date']??0);
    $text='<b>'.$title.'</b> · '.$timeLabel.' '.$h(v2hist_date($time))."\nپرداخت: ".$h($method).' · '.number_format((int)($p['price']??0))." تومان\nکد: <code>".$h($p['hash_id']??'')."</code>\n";
    if(in_array($p['state']??'', ['declined','auto_cancelled','cancelled_by_user'],true))$text.="⚠️ این پرداخت لغو شده؛ مقادیر زیر وضعیت زمان عملیات‌اند.\n";
    $mode=$a['mode']??($e['mode']??'');$volume=$a['volume_gb']??($e['volume_gb']??null);$days=$a['days']??($e['applied_days']??null);
    if($mode!=='')$text.='روش: '.($mode==='reset'?'ریست به پلن جدید':'افزایشی')."\n";
    $text.='سقف حجم قبل: '.v2hist_volume($before).' ← '.($mode==='reset'?'حجم پلن جدید: ':'حجم افزوده: ').($volume===null?'ثبت نشده':v2hist_num($volume).' گیگ').' ← سقف بعد: '.v2hist_volume($after)."\n";
    if(array_key_exists('remaining_gb',$before)||array_key_exists('remaining_gb',$after)){
        $remaining=function($s){if(!array_key_exists('remaining_gb',$s))return 'ثبت نشده';return $s['remaining_gb']===null?'نامحدود':v2hist_num($s['remaining_gb']).' گیگ';};
        $text.='باقی‌ماندهٔ حجم: '.$remaining($before).' ← '.$remaining($after)."\n";
    }
    $text.='انقضای قبل: '.v2hist_expiry($before)."\n".($mode==='reset'?'مدت پلن جدید: ':'روز افزوده: ').($days===null?'ثبت نشده':(int)$days.' روز')."\nانقضای بعد: ".v2hist_expiry($after);
    return $text;
}
function v2hist_handle(){
    global $data,$from_id,$message_id,$connection;
    if(!preg_match('/^cfgHist_(open|page)_(\d{1,12})_(\d{1,9})$/D',(string)($data??''),$m))return;
    if(!v2hist_allowed($from_id)){alert('دسترسی ندارید.',true);exit;}
    $id=(int)$m[2];$offset=(int)$m[3];
    $order=v2raystore_orderFetchById($id);if(!$order){alert('سرویس پیدا نشد.',true);exit;}
    $uid=(int)$order['userid'];
    $s=$connection->prepare("SELECT id,type,plan_id,description,hash_id,price,state,payment_method,auto_approved_orders,auto_approved_date,request_date FROM pays WHERE user_id=? AND (state IN ('approved','paid','paid_with_wallet') OR (state IN ('declined','auto_cancelled','cancelled_by_user') AND auto_approved_date>0)) ORDER BY id DESC");
    $s->bind_param('i',$uid);$s->execute();$result=$s->get_result();$payments=[];
    while($p=$result->fetch_assoc())if(v2hist_matches($p,$id))$payments[]=$p;$s->close();
    $offset=min($offset,max(0,intdiv(max(0,count($payments)-1),3)*3));
    $name=htmlspecialchars((string)$order['remark'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $text="📜 <b>تاریخچهٔ کانفیگ</b>\n$name\n🆔 $id · ".count($payments)." سابقه\n\n";
    foreach(array_slice($payments,$offset,3) as $p)$text.=v2hist_entry($p)."\n────────\n";
    if(!$payments)$text.="پرداخت مرتبط و قابل‌تأییدی برای این کانفیگ ثبت نشده است.\n";
    $text.="\n«ثبت نشده» یعنی مقدار تاریخی موجود نیست؛ از پلن فعلی حدس زده نمی‌شود. تغییر مستقیم در پنل در این دفتر ثبت نمی‌شود.";
    $nav=[];if($offset>0)$nav[]=['text'=>'◀️ جدیدتر','callback_data'=>'cfgHist_page_'.$id.'_'.max(0,$offset-3)];if($offset+3<count($payments))$nav[]=['text'=>'قدیمی‌تر ▶️','callback_data'=>'cfgHist_page_'.$id.'_'.($offset+3)];
    $keys=['inline_keyboard'=>$nav?[$nav]:[]];$keys['inline_keyboard'][]=[['text'=>'🔄 تازه‌سازی','callback_data'=>'cfgHist_page_'.$id.'_'.$offset]];
    alert('تاریخچه آماده شد.');
    if($m[1]==='open')sendMessage($text,json_encode($keys,JSON_UNESCAPED_UNICODE),'HTML');else editText($message_id,$text,json_encode($keys,JSON_UNESCAPED_UNICODE),'HTML');
    exit;
}
