<?php
// Customer membership is persisted once; purchases never promote a new customer.
function v2seg_query($sql, $types = '', $args = []){
    global $connection;
    $stmt = $connection->prepare($sql);
    if(!$stmt) throw new RuntimeException('Customer groups: prepare failed');
    if($types !== '') $stmt->bind_param($types, ...$args);
    if(!$stmt->execute()) throw new RuntimeException('Customer groups: query failed');
    return $stmt;
}
function v2seg_one($sql, $types = '', $args = []){
    $stmt = v2seg_query($sql, $types, $args);
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row;
}
function v2seg_bootstrap(){
    global $connection, $admin, $dbName;
    if(v2raystore_schemaPatchDone('CUSTOMER_GROUPS_V1')) return;
    $lock = 'v2seg_init_' . md5((string)$dbName);
    if((int)(v2seg_one('SELECT GET_LOCK(?, 10) AS ok','s',[$lock])['ok'] ?? 0) !== 1) throw new RuntimeException('Customer groups: initialization busy');
    try {
        $connection->query("CREATE TABLE IF NOT EXISTS `v2_customer_groups` (`userid` BIGINT NOT NULL PRIMARY KEY, `segment` VARCHAR(8) NOT NULL, `created_at` BIGINT NOT NULL, `source` VARCHAR(32) NOT NULL, KEY `segment_user` (`segment`,`userid`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $connection->query("CREATE TABLE IF NOT EXISTS `v2_customer_settings` (`id` INT NOT NULL PRIMARY KEY, `payload` LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $connection->query("CREATE TABLE IF NOT EXISTS `v2_plan_audience` (`plan_id` INT NOT NULL PRIMARY KEY, `legacy_on` TINYINT NOT NULL DEFAULT 1, `new_on` TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if(!v2seg_one('SELECT userid FROM v2_customer_groups WHERE userid=0')){
            $state = v2raystore_getBotStatesArray(true);
            $mode = v2raystore_getNewMemberAccessMode($state);
            $since = max(0,(int)($state['newMemberAccessStartedAt'] ?? 0));
            $buyer = "(EXISTS(SELECT 1 FROM orders_list o WHERE o.userid=u.userid) OR EXISTS(SELECT 1 FROM pays p WHERE p.user_id=u.userid AND p.state IN ('paid','approved')))";
            $eligible = "(u.userid=".(int)$admin." OR COALESCE(u.isAdmin,0)=1 OR COALESCE(u.is_agent,0)=1 OR COALESCE(u.access_exempt,0)=1 OR $buyer)";
            if($mode === 'open') $eligible = '1=1';
            elseif($mode === 'existing') $eligible .= $since ? " OR (u.date>0 AND u.date<=$since)" : ' OR 1=1';
            elseif($mode === 'approval') $eligible .= " OR COALESCE(u.approval_status,'')='approved'";
            $connection->begin_transaction();
            // Atomic snapshot, including denied existing users as new. No ongoing date/buyer inference.
            v2seg_query("INSERT IGNORE INTO v2_customer_groups (userid,segment,created_at,source) SELECT u.userid,IF($eligible,'legacy','new'),UNIX_TIMESTAMP(),'initial_snapshot' FROM users u WHERE u.userid>0")->close();
            v2seg_query("INSERT INTO v2_customer_groups VALUES (0,'legacy',UNIX_TIMESTAMP(),'initialized')")->close();
            $connection->commit();
        }
        v2raystore_markSchemaPatchDone('CUSTOMER_GROUPS_V1');
    } catch(Throwable $e){
        $connection->rollback(); throw $e;
    } finally { v2seg_query('SELECT RELEASE_LOCK(?)','s',[$lock])->close(); }
}
function v2seg_settings($fresh = false){
    if(!$fresh && isset($GLOBALS['v2seg_settings'])) return $GLOBALS['v2seg_settings'];
    $row = v2seg_one('SELECT payload FROM v2_customer_settings WHERE id=1');
    $s = json_decode($row['payload'] ?? '{}', true);
    return $GLOBALS['v2seg_settings'] = array_merge([
        'enabled'=>false, 'percent'=>10, 'round'=>5000,
        'card'=>'', 'holder'=>'', 'contact'=>'', 'welcome'=>'',
        'sell'=>true, 'wallet'=>true, 'test'=>true, 'custom'=>true,
    ], is_array($s) ? $s : []);
}
function v2seg_save($key, $value){
    global $connection;
    // Serialize concurrent administrators instead of overwriting other settings.
    $connection->begin_transaction();
    try {
        v2seg_query("INSERT IGNORE INTO v2_customer_settings VALUES(1,'{}')")->close();
        $row = v2seg_one('SELECT payload FROM v2_customer_settings WHERE id=1 FOR UPDATE');
        $s = json_decode($row['payload'],true) ?: []; $s[$key]=$value;
        v2seg_query('UPDATE v2_customer_settings SET payload=? WHERE id=1','s',[json_encode($s,JSON_UNESCAPED_UNICODE)])->close();
        $connection->commit(); unset($GLOBALS['v2seg_settings']);
    } catch(Throwable $e){ $connection->rollback(); throw $e; }
}
function v2seg_group($userId = null, $fresh = false){
    global $from_id;
    $uid = (int)($userId ?? $from_id ?? 0);
    if($uid <= 0) return 'new';
    if(!$fresh && isset($GLOBALS['v2seg_groups'][$uid])) return $GLOBALS['v2seg_groups'][$uid];
    $row = v2seg_one('SELECT segment FROM v2_customer_groups WHERE userid=?','i',[$uid]);
    // Absence always means new, even after buying, approval or receiving a representative role.
    return $GLOBALS['v2seg_groups'][$uid] = ($row['segment'] ?? '') === 'legacy' ? 'legacy' : 'new';
}
function v2seg_isAdmin(){
    global $from_id, $admin, $userInfo;
    return (int)($from_id ?? 0)===(int)$admin || !empty($userInfo['isAdmin']);
}
function v2seg_roundPrice($base, $percent, $round){
    $base = max(0,(int)round((float)$base));
    $bps = (int)round(max(0,(float)$percent)*100);
    if($base===0 || $bps===0) return $base;
    $unit = max(1,(int)$round);
    $denominator = 10000*$unit;
    return (int)(ceil(($base*(10000+$bps))/$denominator)*$unit);
}
function v2seg_price($base, $userId = null, $round = true){
    if(v2seg_group($userId)!=='new') return $base;
    $s=v2seg_settings();
    return v2seg_roundPrice($base,$s['percent'],$round ? $s['round'] : 1);
}
function v2seg_planFlags($id){
    return v2seg_one('SELECT legacy_on,new_on FROM v2_plan_audience WHERE plan_id=?','i',[(int)$id]) ?: ['legacy_on'=>1,'new_on'=>1];
}
function v2seg_planAllowed($id, $userId=null){
    $flags=v2seg_planFlags($id);
    return !empty($flags[v2seg_group($userId).'_on']);
}
function v2seg_planSql($alias='server_plans'){
    $alias=preg_replace('/[^A-Za-z0-9_]/','',$alias);
    $column=v2seg_group()==='legacy'?'legacy_on':'new_on';
    return " AND NOT EXISTS (SELECT 1 FROM v2_plan_audience va WHERE va.plan_id=$alias.id AND va.$column=0) ";
}
function v2seg_applyStates($state,$user){
    if(!is_array($user) || empty($user['userid']) || v2seg_group($user['userid'])!=='new') return $state;
    $s=v2seg_settings();
    foreach(['sell'=>'sellState','wallet'=>'walletState','test'=>'testAccount','custom'=>'plandelkhahState'] as $key=>$flag){
        if(empty($s[$key])) $state[$flag]='off';
    }
    // New customers have an independent card. Never silently use a legacy account.
    if(trim($s['card'])==='') $state['cartToCartState']='off';
    return $state;
}
function v2seg_account($userId){
    if(v2seg_group($userId)!=='new') return null;
    $s=v2seg_settings();
    return ['bank'=>$s['card'],'holder'=>$s['holder'],'type'=>'new','is_second'=>false,'has_active_paid_config'=>false];
}
function v2seg_customerLock(){
    global $from_id,$dbName;
    $lock='v2seg_user_'.md5($dbName.':'.$from_id);
    if((int)(v2seg_one('SELECT GET_LOCK(?, 5) AS ok','s',[$lock])['ok']??0)!==1){
        http_response_code(503); exit;
    }
    register_shutdown_function(function()use($lock){v2seg_query('SELECT RELEASE_LOCK(?)','s',[$lock])->close();});
}
function v2seg_promote($uid,$code){
    global $connection;
    $expected=v2raystore_getBuyersAccessCode();
    if($expected==='' || !hash_equals(strtolower($expected),strtolower(v2raystore_normalizeAccessCodeText($code)))) return false;
    // A card/price must not switch midway through an unpaid or submitted order.
    $pay=v2seg_one("SELECT hash_id,state FROM pays WHERE user_id=? AND state IN ('pending','sent','processing','auto_processing') ORDER BY id DESC LIMIT 1",'i',[(int)$uid]);
    if($pay){
        $keys=($pay['state']==='pending') ? json_encode(['inline_keyboard'=>[[['text'=>'❌ لغو سفارش در انتظار','callback_data'=>'cancelPendingPay'.$pay['hash_id']]]]],JSON_UNESCAPED_UNICODE) : null;
        sendMessage('ابتدا سفارش در انتظار را تکمیل یا لغو کنید؛ سپس کد ورود مشتریان قدیمی را دوباره بفرستید.',$keys); return 'pending';
    }
    $connection->begin_transaction();
    try {
        v2seg_query("INSERT INTO v2_customer_groups VALUES(?,'legacy',UNIX_TIMESTAMP(),'access_code') ON DUPLICATE KEY UPDATE segment='legacy',created_at=UNIX_TIMESTAMP(),source='access_code'",'i',[(int)$uid])->close();
        if(!v2raystore_setUserAccessExempt($uid,true,$expected)) throw new RuntimeException('Cannot save code access');
        $connection->commit(); unset($GLOBALS['v2seg_groups'][(int)$uid]);
    } catch(Throwable $e){$connection->rollback();throw $e;}
    return true;
}
function v2seg_gate(){
    global $from_id,$userInfo,$text,$data,$update,$first_name,$username,$botState,$buttonValues,$cancelKey;
    if(v2seg_isAdmin()) return;
    v2seg_customerLock();
    $group=v2seg_group($from_id,true);
    if($group==='legacy') return;
    if(!$userInfo){
        if(!v2raystore_ensureBasicUserRecord($from_id,$first_name??'',$username??'')) throw new RuntimeException('Cannot register customer');
        $GLOBALS['v2seg_created_user']=true;
        $userInfo=v2raystore_getUserByTelegramId($from_id);
    }
    v2seg_query("INSERT IGNORE INTO v2_customer_groups VALUES(?,'new',UNIX_TIMESTAMP(),'new_customer')",'i',[(int)$from_id])->close();
    if(isset($update->message->text)){
        $result=v2seg_promote($from_id,$text??'');
        if($result==='pending') exit;
        if($result===false && ($userInfo['step']??'')==='cgEnterLegacy' && !in_array(trim($text),[$buttonValues['cancel']??'لغو','/start'],true)){sendMessage('کد ورود صحیح نیست؛ دوباره بفرستید یا لغو را بزنید.');exit;}
        if($result===true){
            $userInfo=v2raystore_getUserByTelegramId($from_id);
            $botState=v2raystore_applyRoleSpecificStates(v2raystore_getBotStatesArray(true),$userInfo);
            sendMessage('✅ کد تأیید شد. از این پس قیمت‌ها و پرداخت‌های مشتریان قدیمی برای شما فعال است.',getMainKeys()); exit;
        }
    }
    if(($data??'')==='cgEnterLegacy'){setUser('cgEnterLegacy');sendMessage('کد ورود مشتریان قدیمی را ارسال کنید.',$cancelKey);exit;}
    $s=v2seg_settings();
    // Cancellation stays available when new-customer access is temporarily disabled.
    if(empty($s['enabled']) && !preg_match('/^cancelPendingPay/',(string)($data??''))){
        $msg='🔒 پذیرش مشتریان جدید فعلاً غیرفعال است. اگر کد ورود مشتریان قدیمی دارید، همان کد را اینجا ارسال کنید.';
        if(isset($update->callback_query)) alert($msg,true); else sendMessage($msg);
        exit;
    }
    $botState=v2seg_applyStates($botState,$userInfo);
    if(trim($s['welcome'])!=='' && isset($update->message->text) && trim($text)==='/start') sendMessage($s['welcome']);
}
function v2seg_guardSelection(){
    global $data,$userInfo,$from_id,$update,$text,$buttonValues;
    if(v2seg_isAdmin()) return;
    if(isset($update->message->text) && ($text??'')===($buttonValues['cancel']??'لغو')) return;
    $input=isset($update->callback_query)?(string)($data??''):(string)($userInfo['step']??'');
    if(preg_match('/^(?:selectPlan|selectCustomePlan|selectCustomPlanGB|selectCustomPlanDay|enterCustomPlanName|enterAccountName|sConfigRenewPlan|freeTrial|freeCustomTrial)(\d+)/',$input,$m)){
        if(!v2seg_planAllowed((int)$m[1])){alert('این پلن برای گروه مشتری شما فعال نیست.',true);sendMessage('لطفاً دوباره از منوی خرید پلن انتخاب کنید.',getMainKeys());setUser();exit;}
    }
    $hash=v2raystore_extractPaymentHashFromAction($input);
    if(preg_match('/^(?:increaseWalletWithCartToCart|requestCartToCartCard)(.+)$/',$input,$hm)) $hash=$hm[1];
    if($hash!==''){
        $pay=v2raystore_getPayByHash($hash);
        if(!$pay || (int)$pay['user_id']!==(int)$from_id){sendMessage('این پرداخت متعلق به شما نیست.');exit;}
    }
    if(preg_match('/^freeTrial(\d+)_(\w+)$/',$input,$fm)){
        $plan=v2raystore_getPlanRow($fm[1]);
        if(!$plan){sendMessage('پلن پیدا نشد.');exit;}
        $cost=$plan['price'];
        if(!empty($userInfo['is_agent']) && in_array($fm[2],['one','much'],true)) $cost=v2raystore_applyAgentPricing($cost,$userInfo,$plan['id'],$plan['server_id'],$plan['volume']??0,1);
        if(v2seg_price($cost)>0){sendMessage('این پلن رایگان نیست؛ از منوی خرید ادامه دهید.');exit;}
    }
    if(v2seg_group()!=='new') return;
    $s=v2seg_settings();
    if(empty($s['wallet']) && (strpos($input,'WithWallet')!==false || strpos($input,'increaseMyWallet')===0 || strpos($input,'increaseWallet')===0)){sendMessage('کیف پول برای مشتریان جدید غیرفعال است.');exit;}
    if(empty($s['custom']) && preg_match('/^(?:selectCustom|selectCustome|enterCustom|freeCustom)/',$input)) {sendMessage('پلن دلخواه برای مشتریان جدید غیرفعال است.');exit;}
    if(empty($s['test']) && preg_match('/^(getTestAccount|freeTrial)/',$input)){sendMessage('اکانت تست برای مشتریان جدید غیرفعال است.');exit;}
    if(trim($s['card'])==='' && (strpos($input,'WithCartToCart')!==false || strpos($input,'requestCartToCartCard')===0)){sendMessage('پرداخت کارت‌به‌کارت مشتریان جدید هنوز تنظیم نشده است. لطفاً با پشتیبانی تماس بگیرید.');exit;}
}
function v2seg_menuKeys(){
    $s=v2seg_settings();
    $button=function($label,$cb){return ['text'=>$label,'callback_data'=>$cb];};
    $rows=[
        [$button((!empty($s['enabled'])?'🟢':'🔴').' پذیرش مشتری جدید','cgToggle_enabled'),$button('💰 افزایش قیمت: '.$s['percent'].'٪','cgEdit_percent')],
        [$button('🔢 گرد کردن: '.number_format($s['round']),'cgEdit_round'),$button('💳 شماره کارت مستقل','cgEdit_card')],
        [$button('👤 نام دارنده کارت','cgEdit_holder'),$button('📩 پشتیبانی پرداخت','cgEdit_contact')],
        [$button('🎟 کد انتقال به قدیمی‌ها','cgCode'),$button('📦 پلن‌های هر گروه','cgPlans_0')],
        [$button((!empty($s['sell'])?'✅':'❌').' فروش','cgToggle_sell'),$button((!empty($s['wallet'])?'✅':'❌').' کیف پول','cgToggle_wallet')],
        [$button((!empty($s['test'])?'✅':'❌').' اکانت تست','cgToggle_test'),$button((!empty($s['custom'])?'✅':'❌').' پلن دلخواه','cgToggle_custom')],
        [$button('📝 پیام خوش‌آمد جدیدها','cgEdit_welcome'),$button('🔎 گروه یک مشتری','cgEdit_lookup')],
        [$button('⬅️ بازگشت','adminUsersMenu'),$button('🏠 مدیریت','adminMainMenu')]
    ];return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
}
function v2seg_menuText(){
    $s=v2seg_settings(); $card=htmlspecialchars($s['card']?:'تنظیم نشده',ENT_QUOTES,'UTF-8');
    $holder=htmlspecialchars($s['holder']?:'تنظیم نشده',ENT_QUOTES,'UTF-8');
    return "👥 <b>تنظیمات مشتریان جدید</b>\n\nکارت مستقل: <code>$card</code>\nدارنده: $holder\n\nقیمت خرید، تمدید و افزایش حجم/زمان: +{$s['percent']}٪؛ گرد کردن رو به بالا تا ".number_format($s['round'])." تومان.\nدرصد صفر یعنی قیمت پایه بدون افزایش و گرد کردن. شارژ کیف پول افزایش قیمت ندارد.\n\nکارت اول و دوم فقط برای گروه قدیمی است. مشتری جدید با خرید، قدیمی نمی‌شود؛ انتقال فقط با کد است.\nپلن تازه به‌صورت پیش‌فرض برای هر دو گروه فعال است. غیرفعال کردن پلن برای یک گروه، سرویس‌های قبلی را حذف نمی‌کند.\nگزینه‌های امکانات، تابع روشن بودن همان امکان در تنظیمات اصلی هم هستند.";
}
function v2seg_codeKeys(){return json_encode(['inline_keyboard'=>[
    [['text'=>'🔄 ساخت کد جدید','callback_data'=>'cgCodeGenerate'],['text'=>'✏️ تنظیم کد','callback_data'=>'cgEdit_code']],
    [['text'=>'🧹 غیرفعال کردن کد','callback_data'=>'cgCodeClear']],
    [['text'=>'⬅️ بازگشت','callback_data'=>'customerGroupsMenu']]
]],JSON_UNESCAPED_UNICODE);}
function v2seg_showCode(){
    global $message_id;
    $code=htmlspecialchars(v2raystore_getBuyersAccessCode()?:'تنظیم نشده',ENT_QUOTES,'UTF-8');
    editText($message_id,"🎟 <b>کد ورود به گروه مشتریان قدیمی</b>\n\n<code>$code</code>\n\nاین همان کد ورود خریداران قبلی است. مشتری جدید با ارسال آن وارد گروه قدیمی می‌شود؛ حتی وقتی پذیرش جدید خاموش است. تغییر یا حذف کد، گروه افراد منتقل‌شده را برنمی‌گرداند. کد را فقط به افراد موردنظر بدهید.",v2seg_codeKeys(),'HTML');
}
function v2seg_planMenu($offset){
    global $message_id;
    $offset=max(0,(int)$offset);$rows=[];
    $stmt=v2seg_query('SELECT id,title,server_id FROM server_plans WHERE step=10 AND COALESCE(price,0)>0 ORDER BY id DESC LIMIT 9 OFFSET '.$offset);
    $plans=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    foreach(array_slice($plans,0,8) as $p){$id=(int)$p['id'];$f=v2seg_planFlags($id);$title=$p['title'].' · سرور '.$p['server_id'].' #'.$id;
        $rows[]=[['text'=>($f['legacy_on']?'✅':'❌').' قدیمی | '.$title,'callback_data'=>"cgPlan_{$id}_legacy_{$offset}"],['text'=>($f['new_on']?'✅':'❌').' جدید | '.$title,'callback_data'=>"cgPlan_{$id}_new_{$offset}"]];}
    $nav=[];if($offset>0)$nav[]=['text'=>'◀️ قبلی','callback_data'=>'cgPlans_'.max(0,$offset-8)];if(count($plans)>8)$nav[]=['text'=>'بعدی ▶️','callback_data'=>'cgPlans_'.($offset+8)];if($nav)$rows[]=$nav;
    $rows[]=[['text'=>'⬅️ بازگشت','callback_data'=>'customerGroupsMenu']];
    editText($message_id,'📦 نمایش و فروش پلن برای هر گروه؛ با لمس هر دکمه همان گروه فعال/غیرفعال می‌شود. حذف از یک گروه، پلن یا سرویس‌های قبلی را پاک نمی‌کند.',json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE));
}
function v2seg_admin(){
    global $data,$text,$userInfo,$message_id,$cancelKey,$removeKeyboard,$update;
    if(!v2seg_isAdmin()) return;
    $cb=(string)($data??'');
    if(preg_match('/^(?:cg|customerGroupsMenu)/',$cb)){setUser();$userInfo['step']='none';}
    if($cb==='customerGroupsMenu') {setUser();editText($message_id,v2seg_menuText(),v2seg_menuKeys(),'HTML');exit;}
    if(preg_match('/^cgToggle_(enabled|sell|wallet|test|custom)$/',$cb,$m)){
        $s=v2seg_settings(true);v2seg_save($m[1],empty($s[$m[1]]));editText($message_id,v2seg_menuText(),v2seg_menuKeys(),'HTML');exit;
    }
    if(in_array($cb,['cgCode','cgCodeGenerate','cgCodeClear'],true)){
        setUser();if($cb==='cgCodeGenerate')v2raystore_generateBuyersAccessCode();if($cb==='cgCodeClear')v2raystore_setBuyersAccessCode('');v2seg_showCode();exit;
    }
    if(preg_match('/^cgPlans_(\d+)$/',$cb,$m)){setUser();v2seg_planMenu($m[1]);exit;}
    if(preg_match('/^cgPlan_(\d+)_(legacy|new)_(\d+)$/',$cb,$m)){
        if(!v2raystore_getPlanRow($m[1])){alert('پلن پیدا نشد.',true);exit;}
        $col=$m[2].'_on';
        v2seg_query("INSERT INTO v2_plan_audience(plan_id,$col) VALUES(?,0) ON DUPLICATE KEY UPDATE $col=1-$col",'i',[(int)$m[1]])->close();
        if($m[3]==='999999') {editText($message_id,'📦 تنظیمات پلن',getPlanDetailsKeys($m[1]));}else v2seg_planMenu($m[3]);exit;
    }
    if(preg_match('/^cgEdit_(percent|round|card|holder|contact|welcome|code|lookup)$/',$cb,$m)){
        $prompts=['percent'=>'درصد افزایش قیمت را از ۰ تا ۱۰۰۰ بفرستید؛ مثلاً 10 یا 0. اعشار تا دو رقم مجاز است.','round'=>'مضرب گرد کردن رو به بالا را بفرستید؛ مثلاً 5000. صفر یعنی بدون گرد کردن.','card'=>'شماره کارت ۱۶ رقمی مخصوص مشتریان جدید را بفرستید. برای حذف بنویسید: حذف','holder'=>'نام دارنده کارت مخصوص مشتریان جدید را بفرستید.','contact'=>'آیدی پشتیبانی پرداخت جدیدها را بفرستید؛ مثلاً @support. برای حذف بنویسید: حذف','welcome'=>'متن خوش‌آمد مشتریان جدید را بفرستید. برای حذف بنویسید: حذف','code'=>'کد انتقال به گروه قدیمی را بفرستید؛ ۸ تا ۶۰ کاراکتر انگلیسی، عدد، خط تیره یا زیرخط.','lookup'=>'آیدی عددی مشتری را بفرستید.'];
        setUser('cgInput_'.$m[1]);sendMessage($prompts[$m[1]],$cancelKey);exit;
    }
    if(isset($update->message->text) && preg_match('/^cgInput_(percent|round|card|holder|contact|welcome|code|lookup)$/',(string)($userInfo['step']??''),$m)){
        $k=$m[1];$v=trim($text);$v=strtr($v,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
        $error='';
        if($k==='percent') {if(!preg_match('/^\d{1,4}(\.\d{1,2})?$/D',$v)||(float)$v>1000)$error='درصد معتبر بین صفر و ۱۰۰۰ بفرستید.';else $v=(float)$v;}
        elseif($k==='round'){if(!ctype_digit($v)||(int)$v>1000000)$error='عدد صحیح صفر تا یک میلیون بفرستید.';else $v=(int)$v;}
        elseif($k==='card'){$v=preg_replace('/[\s-]/u','',$v);if($v==='حذف')$v='';elseif(!preg_match('/^\d{16}$/D',$v))$error='شماره کارت باید ۱۶ رقم باشد.';}
        elseif($k==='code'&&!preg_match('/^[A-Za-z0-9_-]{8,60}$/D',$v))$error='کد باید ۸ تا ۶۰ کاراکتر مجاز داشته باشد.';
        elseif($k==='contact'){if($v==='حذف')$v='';elseif(!preg_match('/^(?:@[A-Za-z][A-Za-z0-9_]{4,31}|[1-9]\d{4,19})$/D',$v))$error='آیدی با @ یا آیدی عددی معتبر بفرستید.';}
        elseif($k==='lookup'&&(!ctype_digit($v)||(int)$v<=0))$error='آیدی عددی معتبر بفرستید.';
        elseif($k==='welcome'){if($v==='حذف')$v='';if(strlen($v)>3000)$error='متن کوتاه‌تری بفرستید.';}
        elseif($k==='holder'&&($v===''||strlen($v)>250))$error='نام دارنده کارت معتبر بفرستید.';
        if($error!==''){sendMessage($error);exit;}
        if($k==='lookup'){
            $u=v2raystore_getUserByTelegramId((int)$v);
            sendMessage($u?'گروه مشتری: '.(v2seg_group((int)$v)==='legacy'?'قدیمی':'جدید'):'کاربر پیدا نشد.');
        }elseif($k==='code')v2raystore_setBuyersAccessCode($v);
        else v2seg_save($k,$v);
        setUser();sendMessage($k==='lookup'?'بررسی انجام شد.':'✅ ذخیره شد.',$removeKeyboard);
        sendMessage(v2seg_menuText(),v2seg_menuKeys(),'HTML');exit;
    }
}
