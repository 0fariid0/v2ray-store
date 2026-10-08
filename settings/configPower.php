<?php
// Explicit, per-agent permission. No global on/default inheritance.
function v2cfg_isAdmin($id,$user=null){
    return (int)$id>0 && ((int)$id===(int)($GLOBALS['admin']??0) || (is_array($user) && (int)($user['isAdmin']??0)===1));
}
function v2cfg_agentAllowed($user){
    return is_array($user) && (int)($user['is_agent']??0)===1 && (int)($user['userid']??0)>0
        && v2raystore_getSettingValue('AGENT_CONFIG_POWER_'.(int)$user['userid'],'0')==='1';
}
function v2cfg_authorized($order,$actorId){
    if(!is_array($order) || (int)($order['status']??0)!==1)return false;
    $actor=v2raystore_getUserRowFresh((int)$actorId);
    if(v2cfg_isAdmin($actorId,$actor))return true;
    return (int)$actorId>0 && (int)($order['userid']??0)===(int)$actorId && v2cfg_agentAllowed($actor);
}
function v2cfg_one($sql,$id){
    global $connection;
    $s=$connection->prepare($sql);$id=(int)$id;$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
}
function v2cfg_bool($value){return filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);}
function v2cfg_live($order,$server){
    $sid=(int)$order['server_id'];$uuid=trim((string)($order['uuid']??''));$email=trim((string)($order['remark']??''));$type=$server['type']??'';
    if($type==='sanaei_new'){
        $identity=v2id_resolve($sid,$uuid,$email,null,$server);
        if(!$identity)return null;
        $email=$identity['email'];
        if($email==='')return null;
        $r=null;
        foreach([false,true] as $refresh){
            try{$r=v2raystore_sanaeiRequestJson($server,'/panel/api/clients/get/'.rawurlencode($email),'GET',null,$refresh);}
            catch(Throwable $e){$r=null;}
            if(is_array($r) && ($r['success']??false)===true)break;
        }
        if(!is_array($r) || ($r['success']??false)!==true)return null;
        $obj=v2raystore_decodeMaybeJson($r['obj']??null,true);
        $client=v2raystore_decodeMaybeJson($obj['client']??null,true);
        if(!is_array($client) || ($client['email']??'')!==$email)return null;
        // The central record ID is numeric; its credential is uuid/password.
        $credentials=array_filter([(string)($client['uuid']??''),(string)($client['password']??'')],function($v){return $v!=='';});
        if(!v2id_select([['client'=>$client,'central'=>true]],$identity,''))return null;
        $enabled=array_key_exists('enable',$client)?v2cfg_bool($client['enable']):null;
        return $enabled===null?null:['enabled'=>$enabled,'kind'=>'central','email'=>$email];
    }
    if($type==='marzban'){
        $client=getMarzbanUser($sid,$email);
        if(!is_object($client)||($client->username??'')!==$email)return null;
        if(!in_array($client->status??'', ['active','disabled','limited','expired','on_hold'],true))return null;
        return ['enabled'=>$client->status!=='disabled','kind'=>'marzban','email'=>$email];
    }
    if($uuid==='' || $uuid==='0')return null;
    $matches=[];$inboundId=(int)($order['inbound_id']??0);
    foreach(v2raystore_panelListFromGetJson(getJson($sid)) as $row){
        $row=(array)$row;
        if($inboundId>0 && (int)($row['id']??0)!==$inboundId)continue;
        $settings=v2raystore_decodeMaybeJson($row['settings']??null,true);
        if(!is_array($settings)||!is_array($settings['clients']??null))continue;
        foreach($settings['clients'] as $key=>$client){
            if(!is_array($client) || !in_array($uuid,[(string)($client['id']??''),(string)($client['password']??'')],true))continue;
            // A dedicated inbound must contain only this customer. Never stop siblings.
            if($inboundId===0 && count($settings['clients'])!==1)return null;
            $kind=$inboundId===0?'inbound':'client';
            $enabled=v2cfg_bool($kind==='inbound'?($row['enable']??null):($client['enable']??null));
            if(!array_key_exists('enable',$kind==='inbound'?$row:$client)||$enabled===null)return null;
            if($kind==='inbound' && array_key_exists('enable',$client)){
                $clientEnabled=v2cfg_bool($client['enable']);if($clientEnabled===null)return null;
                $enabled=$enabled && $clientEnabled;
            }
            $matches[]=['enabled'=>$enabled,'kind'=>$kind,'row'=>$row,'settings'=>$settings,'key'=>$key,'client'=>$client];
        }
    }
    return count($matches)===1?$matches[0]:null;
}
function v2cfg_formRequest($server,$endpoint,$payload){
    [$curl,$session]=v2raystore_panelLoginSession($server);
    if(!$curl||!$session){if($curl)curl_close($curl);return false;}
    try{
        curl_setopt_array($curl,[CURLOPT_URL=>rtrim($server['panel_url'],'/').$endpoint,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_HEADER=>false,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Cookie: '.$session,'Content-Type: application/x-www-form-urlencoded','Accept: application/json','X-Requested-With: XMLHttpRequest']]);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$r=json_decode((string)$raw,true);
        return $status>=200 && $status<300 && ($r['success']??false)===true;
    }finally{curl_close($curl);}
}
function v2cfg_write($order,$server,$live,$target){
    if($live['kind']==='central'){
        // Panel API changes enable only; it preserves traffic, credentials, HWIDs,
        // quotas, expiry, links and every attached inbound.
        $r=v2raystore_sanaeiRequestJson($server,'/panel/api/clients/'.($target?'bulkEnable':'bulkDisable'),'POST',['emails'=>[$live['email']]]);
        return ($r['success']??false)===true;
    }
    if($live['kind']==='marzban'){
        $token=getMarzbanToken((int)$order['server_id']);if(empty($token->access_token))return false;
        $ch=curl_init();
        try{
            curl_setopt_array($ch,[CURLOPT_URL=>rtrim($server['panel_url'],'/').'/api/user/'.rawurlencode($live['email']),CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_POSTFIELDS=>json_encode(['status'=>$target?'active':'disabled']),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token->access_token,'Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15]);
            $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$r=json_decode((string)$raw,true);
            return $code>=200 && $code<300 && ($r['username']??'')===$live['email'];
        }finally{curl_close($ch);}
    }
    $row=$live['row'];$settings=$live['settings'];$prefix=($server['type']??'')==='sanaei'?'/panel/inbound':'/xui/inbound';
    if($live['kind']==='client' && in_array($server['type']??'', ['sanaei','alireza'],true)){
        $client=$live['client'];$client['enable']=$target;
        return v2cfg_formRequest($server,$prefix.'/updateClient/'.rawurlencode((string)$order['uuid']),['id'=>(int)$row['id'],'settings'=>json_encode(['clients'=>[$client]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
    if($live['kind']==='inbound'){
        $row['enable']=$target;
        if(array_key_exists('enable',$settings['clients'][$live['key']]))$settings['clients'][$live['key']]['enable']=$target;
    }
    else $settings['clients'][$live['key']]['enable']=$target;
    // Preserve all panel-returned editable fields, including listen and allocations.
    unset($row['clientStats']);$row['enable']=v2cfg_bool($row['enable'])?'true':'false';
    $row['settings']=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return v2cfg_formRequest($server,$prefix.'/update/'.(int)$row['id'],$row);
}
function v2cfg_setPower($orderId,$actorId,$target){
    global $connection,$dbName;
    $locked=false;$lock='v2cfg:'.substr(hash('sha256',(string)($dbName??'')),0,12).':'.(int)$orderId;
    try{
        $order=v2cfg_one('SELECT * FROM orders_list WHERE id=? LIMIT 1',$orderId);
        if(!v2cfg_authorized($order,$actorId))return ['ok'=>false,'message'=>'اجازهٔ تغییر وضعیت این کانفیگ را ندارید.'];
        $s=$connection->prepare('SELECT GET_LOCK(?,3) AS locked');$s->bind_param('s',$lock);$s->execute();$locked=!empty($s->get_result()->fetch_assoc()['locked']);$s->close();
        if(!$locked)return ['ok'=>false,'message'=>'این کانفیگ در حال تغییر است؛ دوباره تلاش کنید.'];
        $order=v2cfg_one('SELECT * FROM orders_list WHERE id=? LIMIT 1',$orderId);
        if(!v2cfg_authorized($order,$actorId))return ['ok'=>false,'message'=>'دسترسی تغییر کرده است؛ صفحه را دوباره باز کنید.'];
        $server=v2cfg_one('SELECT * FROM server_config WHERE id=? LIMIT 1',$order['server_id']);
        $live=$server?v2cfg_live($order,$server):null;
        if($live===null)return ['ok'=>false,'message'=>'وضعیت معتبر از پنل دریافت نشد؛ تغییری انجام نشد.'];
        if($live['enabled']===$target)return ['ok'=>true,'message'=>'کانفیگ از قبل در همین وضعیت است.'];
        // A permission or ownership change during the panel read must take effect now.
        $fresh=v2cfg_one('SELECT * FROM orders_list WHERE id=? LIMIT 1',$orderId);
        if(!v2cfg_authorized($fresh,$actorId))return ['ok'=>false,'message'=>'دسترسی تغییر کرده است؛ تغییری انجام نشد.'];
        foreach(['userid','server_id','inbound_id','uuid','remark'] as $field){
            if((string)($fresh[$field]??'')!==(string)($order[$field]??''))return ['ok'=>false,'message'=>'اطلاعات کانفیگ تغییر کرده؛ صفحه را دوباره باز کنید.'];
        }
        if(!v2cfg_write($order,$server,$live,$target))return ['ok'=>false,'message'=>'پنل تغییر را تأیید نکرد؛ وضعیت را دوباره بررسی کنید.'];
        $after=v2cfg_live($order,$server);
        if($after===null || $after['enabled']!==$target)return ['ok'=>false,'message'=>'درخواست ارسال شد ولی وضعیت نهایی تأیید نشد؛ صفحه را تازه کنید.'];
        return ['ok'=>true,'message'=>$target?'✅ کانفیگ فعال شد. حجم و زمان آن تغییر نکرد.':'⛔ کانفیگ غیرفعال شد.'];
    }catch(Throwable $e){error_log('Config power operation failed');return ['ok'=>false,'message'=>'ارتباط با پنل یا دیتابیس ناموفق بود؛ وضعیت را دوباره بررسی کنید.'];}
    finally{if($locked){try{$s=$connection->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();$s->close();}catch(Throwable $e){}}}
}
function v2cfg_attachButton($keyboard,$order,$actorId,$view='m',$offset=0){
    foreach($keyboard as &$row)$row=array_values(array_filter($row,function($button){return !preg_match('/^(changeUserConfigState|cfgPower_|cfgRefresh_)/',(string)($button['callback_data']??''));}));unset($row);
    $keyboard=array_values(array_filter($keyboard));
    $allowed=false;$live=null;
    try{
        if(!v2cfg_authorized($order,$actorId))return $keyboard;
        $allowed=true;
        $server=v2cfg_one('SELECT * FROM server_config WHERE id=? LIMIT 1',$order['server_id']);
        $live=$server?v2cfg_live($order,$server):null;
    }catch(Throwable $e){error_log('Config power button unavailable');}
    if(!$allowed)return $keyboard;
    $suffix=(int)$order['id'].'_'.$view.'_'.max(0,(int)$offset);
    $row=[];
    if($live!==null)$row[]=['text'=>$live['enabled']?'⛔ غیرفعال کردن کانفیگ':'✅ فعال کردن کانفیگ','callback_data'=>'cfgPower_'.(int)$order['id'].'_'.($live['enabled']?'0':'1').'_'.$view.'_'.max(0,(int)$offset)];
    $row[]=['text'=>$live===null?'🔄 وضعیت نامشخص؛ تلاش مجدد':'🔄 تازه‌سازی وضعیت','callback_data'=>'cfgRefresh_'.$suffix];
    array_splice($keyboard,max(0,count($keyboard)-1),0,[$row]);
    return $keyboard;
}
function v2cfg_refreshDetails($orderId,$actorId,$messageId,$adminView=false,$offset=0){
    $keys=$adminView?getUserOrderDetailKeys($orderId,$offset):getOrderDetailKeys($actorId,$orderId,$offset);
    if(!$keys)return false;
    if(function_exists('farid_attachUpdateConfigButton'))$keys['keyboard']=farid_attachUpdateConfigButton($keys['keyboard'],$orderId);
    editText($messageId,$keys['msg'],$keys['keyboard'],'HTML');
    return true;
}
function v2cfg_handle(){
    global $data,$from_id,$message_id;
    $cb=(string)($data??'');
    if(preg_match('/^agentPower_(\d+)_([01])$/D',$cb,$m)){
        $actor=v2raystore_getUserRowFresh($from_id);
        if(!v2cfg_isAdmin($from_id,$actor)){alert('دسترسی ندارید.',true);exit;}
        $agent=v2raystore_getUserRowFresh((int)$m[1]);
        if((int)($agent['is_agent']??0)!==1){alert('نماینده پیدا نشد.',true);exit;}
        $ok=v2raystore_setSettingValue('AGENT_CONFIG_POWER_'.(int)$m[1],$m[2]);
        if(!$ok){alert('ذخیرهٔ دسترسی انجام نشد.',true);exit;}
        editKeys(getAgentDiscounts((int)$m[1]));alert($m[2]==='1'?'دسترسی روشن/خاموش کردن کانفیگ فعال شد.':'دسترسی روشن/خاموش کردن کانفیگ غیرفعال شد.');exit;
    }
    if(preg_match('/^cfgRefresh_(\d+)_([am])_(\d+)$/D',$cb,$refresh)){
        $oid=(int)$refresh[1];$order=v2cfg_one('SELECT * FROM orders_list WHERE id=? LIMIT 1',$oid);
        if(!v2cfg_authorized($order,$from_id)){alert('اجازهٔ تغییر وضعیت این کانفیگ را ندارید.',true);exit;}
        $actor=v2raystore_getUserRowFresh($from_id);
        alert('در حال دریافت وضعیت جدید…');
        if(!v2cfg_refreshDetails($oid,$from_id,$message_id,v2cfg_isAdmin($from_id,$actor)&&$refresh[2]==='a',(int)$refresh[3]))alert('کانفیگ پیدا نشد؛ فهرست سرویس‌ها را دوباره باز کنید.',true);
        exit;
    }
    $legacy=preg_match('/^changeUserConfigState(\d+)$/D',$cb,$old);
    if(!$legacy && !preg_match('/^cfgPower_(\d+)_([01])_([am])_(\d+)$/D',$cb,$m))return;
    $oid=(int)($legacy?$old[1]:$m[1]);$order=v2cfg_one('SELECT * FROM orders_list WHERE id=? LIMIT 1',$oid);
    if(!v2cfg_authorized($order,$from_id)){alert('اجازهٔ تغییر وضعیت این کانفیگ را ندارید.',true);exit;}
    $actor=v2raystore_getUserRowFresh($from_id);$adminView=v2cfg_isAdmin($from_id,$actor)&&($legacy||$m[3]==='a');$offset=$legacy?0:(int)$m[4];
    $result=$legacy?['ok'=>true,'message'=>'دکمه به‌روز شد؛ وضعیت موردنظر را انتخاب کنید.']:v2cfg_setPower($oid,$from_id,$m[2]==='1');
    alert($result['message'],!$result['ok']);
    v2cfg_refreshDetails($oid,$from_id,$message_id,$adminView,$offset);
    exit;
}
