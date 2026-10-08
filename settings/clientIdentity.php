<?php
// Never change panel names or credentials. Resolve first, then use the exact API name.
function v2id_name($name){
    $s=preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u','',(string)$name);
    return $s===null ? preg_replace('/\s+/','',(string)$name) : $s;
}
function v2id_values($client,$central=false){
    $client=(array)$client;$out=['uuid'=>[], 'password'=>[], 'subId'=>[]];
    foreach($out as $field=>$_){
        $v=(string)($client[$field]??($field==='uuid'&&!$central?($client['id']??''):''));
        if($v!=='' && $v!=='0')$out[$field]=[$v];
    }
    return $out;
}
function v2id_key($sid,$uuid,$name){return 'CLIENT_ID_'.hash('sha256',(int)$sid.'|'.($uuid!==''&&$uuid!=='0'?$uuid:v2id_name($name)));}
function v2id_known($sid,$uuid,$name){
    $saved=json_decode(v2raystore_getSettingValue(v2id_key($sid,$uuid,$name),'{}'),true);
    $known=['uuid'=>[], 'password'=>[], 'subId'=>[]];
    foreach($known as $f=>$_)if(is_array($saved[$f]??null))$known[$f]=array_values(array_filter($saved[$f],'is_string'));
    // Legacy orders store either UUID or the protocol password in uuid.
    if($uuid!==''&&$uuid!=='0'){$known['uuid'][]=$uuid;$known['password'][]=$uuid;}
    // Existing subscription links can bootstrap recovery before aliases were saved.
    global $connection;
    if(isset($connection) && $uuid!=='' && $uuid!=='0'){
        $q=$connection->prepare('SELECT `link` FROM `orders_list` WHERE `server_id`=? AND `uuid`=? ORDER BY `id` DESC LIMIT 1');
        if($q){$q->bind_param('is',$sid,$uuid);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
            $links=json_decode((string)($row['link']??''),true);
            foreach(is_array($links)?$links:[] as $link){if(!is_string($link))continue;
                $path=parse_url($link,PHP_URL_PATH);if(is_string($path)&&preg_match('~/sub/([^/]+)/*$~',$path,$m))$known['subId'][]=rawurldecode($m[1]);
            }
        }
    }
    return $known;
}
function v2id_select($records,$known,$name){
    $groups=[];$strong=[];$names=[];
    foreach($records as $record){
        $c=(array)$record['client'];$email=(string)($c['email']??'');if($email==='')continue;
        $values=v2id_values($c,!empty($record['central']));
        if(!isset($groups[$email]))$groups[$email]=['email'=>$email,'uuid'=>[],'password'=>[],'subId'=>[]];
        foreach($values as $f=>$vv){
            $groups[$email][$f]=array_values(array_unique(array_merge($groups[$email][$f],$vv)));
            if(array_intersect($known[$f]??[],$vv))$strong[$email]=true;
        }
        if($name!=='' && v2id_name($email)===v2id_name($name))$names[$email]=true;
    }
    $hits=$strong?:$names;
    if(count($hits)!==1)return null;
    $found=$groups[array_key_first($hits)];
    if(!$strong && !empty($known['uuid']) && !empty($found['uuid']))return null;
    return $found;
}
function v2id_resolve($sid,$uuid='',$name='',$rows=null,$server=null){
    $sid=(int)$sid;$uuid=trim((string)$uuid);$name=trim((string)$name);
    if($sid<=0)return null;
    if($name==='')$name=v2raystore_orderRemarkByUuid($sid,$uuid);
    $known=v2id_known($sid,$uuid,$name);
    if(!is_array($server))$server=v2raystore_orderServerConfig($sid);
    $records=[];
    // First try the canonical saved name; only a credential match bypasses the full search.
    if(($server['type']??'')==='sanaei_new' && $name!==''){
        $saved=json_decode(v2raystore_getSettingValue(v2id_key($sid,$uuid,$name),'{}'),true);
        $candidate=(string)($saved['email']??$name);
        foreach([false,true] as $refresh){
            try{$direct=v2raystore_sanaeiRequestJson($server,'/panel/api/clients/get/'.rawurlencode($candidate),'GET',null,$refresh);}catch(Throwable $e){$direct=null;}
            if(($direct['success']??false)===true)break;
        }
        if(($direct['success']??false)===true && is_array($direct['obj']['client']??null)){
            $found=v2id_select([['client'=>$direct['obj']['client'],'central'=>true]],$known,'');
            if($found)return v2id_save($sid,$uuid,$name,$found);
        }
    }
    // The central list retains all three credentials even with detached/disabled inbounds.
    if(($server['type']??'')==='sanaei_new'){
        try{$r=v2raystore_sanaeiRequestJson($server,'/panel/api/clients/list','GET');}catch(Throwable $e){$r=null;}
        if(is_array($r)&&($r['success']??false)===true&&is_array($r['obj']??null)){
            $valid=true;
            foreach($r['obj'] as $c){if(!is_array($c)&&!is_object($c)){$valid=false;break;}$c=(array)$c;$c=(array)($c['client']??$c);if(empty($c['email'])){$valid=false;break;}$records[]=['client'=>$c,'central'=>true];}
            $found=v2id_select($records,$known,$name);
            // A complete central response is authoritative, including ambiguity/not found.
            if($valid){if(!$found)return null;return v2id_save($sid,$uuid,$name,$found);}
            $records=[];
        }
    }
    if($rows===null){$j=getJson($sid);$rows=($j&&!empty($j->success)&&is_array($j->obj??null))?$j->obj:null;}
    if(!is_array($rows))return null;
    foreach($rows as $row){
        $row=(array)$row;
        if(function_exists('v2raystore_isSupportedInboundProtocol') && isset($row['protocol']) && !v2raystore_isSupportedInboundProtocol(strtolower($row['protocol'])))continue;
        $settings=$row['settings']??null;
        if(is_string($settings))$settings=json_decode($settings,true);else $settings=(array)$settings;
        if(!is_array($settings)||!array_key_exists('clients',$settings)||($settings['clients']!==null&&!is_array($settings['clients'])))return null;
        foreach($settings['clients']??[] as $c)$records[]=['client'=>(array)$c,'central'=>false];
    }
    $found=v2id_select($records,$known,$name);
    return $found?v2id_save($sid,$uuid,$name,$found):null;
}
function v2id_save($sid,$uuid,$name,$found){
    // Preserve absent fields when a protocol is disabled, replace fields actually read.
    $key=v2id_key($sid,$uuid,$name);$old=json_decode(v2raystore_getSettingValue($key,'{}'),true);
    foreach(['uuid','password','subId'] as $f)if(!$found[$f]&&is_array($old[$f]??null))$found[$f]=$old[$f];
    $json=json_encode($found,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json!==v2raystore_getSettingValue($key,'')){
        if(!v2raystore_setSettingValue($key,$json))return null;
    }
    return $found;
}
