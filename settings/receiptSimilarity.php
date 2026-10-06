<?php
/* Receipt visual signatures, v4. No OCR service, raw receipt, or bank data leaves
 * the server. Only compact line shapes are stored. Similarity is an ADMIN hint,
 * never proof that a transfer is fraudulent. */
function v2receipt_visualSignature($src){
    $w=imagesx($src); $h=imagesy($src);
    if($w<80 || $h<80) return [];
    $scale=min(1,1000/max($w,$h)); $w=max(1,(int)round($w*$scale)); $h=max(1,(int)round($h*$scale));
    $im=imagecreatetruecolor($w,$h); imagefill($im,0,0,0xffffff);
    imagecopyresampled($im,$src,0,0,0,0,$w,$h,imagesx($src),imagesy($src));
    $mask=imagecreatetruecolor($w,$h); imagefill($mask,0,0,0xffffff);
    $bands=[]; $start=null; $last=-1;
    // Row-local dominant background supports white/dark receipts. Ignore long
    // rules and margins, which otherwise join every text line into one shape.
    for($y=0;$y<$h;$y++){
        $gray=[]; $hist=array_fill(0,16,0);
        for($x=0;$x<$w;$x++){
            $c=imagecolorat($im,$x,$y); $g=(int)round((($c>>16)&255)*.299+(($c>>8)&255)*.587+($c&255)*.114);
            $gray[]=$g; $hist[min(15,(int)($g/16))]++;
        }
        $bg=array_search(max($hist),$hist,true)*16+8; $ink=[];
        for($x=2;$x<$w-2;$x++) if(abs($gray[$x]-$bg)>65) $ink[]=$x;
        $n=count($ink);
        if($n>=2 && $n<$w*.65){
            for($x=2;$x<$w-2;$x++){
                $v=255-min(255,(int)round(max(0,abs($gray[$x]-$bg)-10)*255/220));
                imagesetpixel($mask,$x,$y,($v<<16)|($v<<8)|$v);
            }
            if($start===null) $start=$y;
            $last=$y;
        }elseif($start!==null && $y-$last>3){
            $bands[]=[$start,$last]; $start=null;
        }
    }
    if($start!==null) $bands[]=[$start,$last];
    $rows=[];
    foreach($bands as [$top,$bottom]){
        $bh=$bottom-$top+1; if($bh<4 || $bh>$h*.15) continue;
        $left=$w; $right=-1; $count=0;
        for($y=$top;$y<=$bottom;$y++)for($x=2;$x<$w-2;$x++)if((imagecolorat($mask,$x,$y)&255)<180){$left=min($left,$x);$right=max($right,$x);$count++;}
        $bw=$right-$left+1;
        if($bw<30 || $count<35 || $count/($bw*$bh)>.65) continue;
        $tile=imagecreatetruecolor(256,16); imagefill($tile,0,0,0xffffff);
        imagecopyresampled($tile,$mask,0,0,$left,$top,256,16,$bw,$bh);
        $coarse=imagecreatetruecolor(17,4); imagecopyresampled($coarse,$tile,0,0,0,0,17,4,256,16);
        $keys=[];
        for($y=0;$y<4;$y++){
            $n=0;
            for($x=0;$x<16;$x++) $n=($n<<1)|((imagecolorat($coarse,$x,$y)&255)<(imagecolorat($coarse,$x+1,$y)&255)?1:0);
            $keys[]=sprintf('%d:%04x',$y,$n);
        }
        imagedestroy($tile);imagedestroy($coarse);
        $smooth=imagecreatetruecolor(64,4);
        imagecopyresampled($smooth,$mask,0,0,$left,$top,64,4,$bw,$bh);
        $grayBits='';
        for($x=0;$x<64;$x++)for($y=0;$y<4;$y++)$grayBits.=chr(255-(imagecolorat($smooth,$x,$y)&255));
        imagedestroy($smooth);
        $rows[]=['b'=>base64_encode($grayBits),'r'=>round($bw/$bh,3),'k'=>$keys];
        if(count($rows)>=64) break;
    }
    imagedestroy($im);imagedestroy($mask);
    return count($rows)>=5 ? ['v'=>4,'rows'=>$rows] : [];
}
function v2receipt_signatureKeys($signature){
    $keys=[];
    foreach(($signature['rows']??[]) as $row) foreach(($row['k']??[]) as $key) if(preg_match('/^[0-3]:[a-f0-9]{4}$/D',$key)) $keys[$key]=true;
    return array_keys($keys);
}
function v2receipt_lineSimilar($a,$b){
    if(abs($a['r']-$b['r'])/max(.1,$a['r'],$b['r'])>.10) return false;
    $a=base64_decode($a['b'],true);$b=base64_decode($b['b'],true);
    if($a===false || $b===false || strlen($a)!==256 || strlen($b)!==256) return false;
    $totalDiff=0;$totalInk=0;
    for($block=0;$block<8;$block++){
        $diff=0;$ink=0;
        for($j=$block*32;$j<($block+1)*32;$j++){
            $x=ord($a[$j]);$y=ord($b[$j]);$diff+=abs($x-$y);$ink+=max($x,$y);
        }
        if($ink>=300 && $diff/$ink>.42) return false;
        $totalDiff+=$diff;$totalInk+=$ink;
    }
    return $totalInk>=1000 && $totalDiff/max(1,$totalInk)<=.25;
}
function v2receipt_compareSignatures($a,$b){
    if(($a['v']??0)!==4 || ($b['v']??0)!==4) return false;
    $ar=$a['rows']??[];$br=$b['rows']??[];$an=count($ar);$bn=count($br);
    if(min($an,$bn)<5 || max($an,$bn)>64) return false;
    // A crop may remove the beginning/end, but changing a field in the middle
    // breaks the run. Do not accept a handful of matching bank labels scattered
    // across otherwise different receipts.
    $previous=array_fill(0,$bn+1,0);$best=0;
    for($i=0;$i<$an;$i++){
        $current=array_fill(0,$bn+1,0);
        for($j=0;$j<$bn;$j++)if(v2receipt_lineSimilar($ar[$i],$br[$j])){
            $current[$j+1]=$previous[$j]+1;$best=max($best,$current[$j+1]);
        }
        $previous=$current;
    }
    $short=min($an,$bn);
    // Five full lines and the smaller receipt except up to two boundary lines; very small scraps
    // and crops containing only a logo/header are intentionally inconclusive.
    return $best>=5 && $best>=max($short-2,(int)ceil($short*.75));
}
function v2receipt_ensureVisualTables(){
    global $connection; static $ready=null;
    if($ready!==null)return $ready;
    $ready=false;
    if(!$connection->query("CREATE TABLE IF NOT EXISTS receipt_visual_v4 (id BIGINT NOT NULL AUTO_INCREMENT, pay_hash VARCHAR(191) NOT NULL, user_id BIGINT NOT NULL, created_at INT NOT NULL, signature MEDIUMTEXT NOT NULL, PRIMARY KEY(id), KEY receipt_visual_date(created_at), KEY receipt_visual_pay(pay_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"))return false;
    if(!$connection->query("CREATE TABLE IF NOT EXISTS receipt_history_v4 (pay_hash VARCHAR(191) NOT NULL, file_hash CHAR(64) NOT NULL, sent_at INT NOT NULL, next_attempt INT NOT NULL, PRIMARY KEY(pay_hash), KEY receipt_history_date(sent_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"))return false;
    return $ready=(bool)$connection->query("CREATE TABLE IF NOT EXISTS receipt_visual_keys_v4 (visual_id BIGINT NOT NULL, bucket CHAR(6) NOT NULL, PRIMARY KEY(bucket,visual_id), KEY receipt_visual_id(visual_id), CONSTRAINT receipt_visual_keys_parent_v4 FOREIGN KEY(visual_id) REFERENCES receipt_visual_v4(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
function v2receipt_findVisualMatches($signature,$payHash,$cutoff,$now){
    global $connection;
    $keys=v2receipt_signatureKeys($signature);
    if(count($keys)<3 || !v2receipt_ensureVisualTables())return [];
    // Keys are validated hex above, never user-supplied SQL. Keyset pages cover
    // the whole retention window; there is no 'latest N receipts only' shortcut.
    $in="'".implode("','",$keys)."'";$cursor=0;$matches=[];
    do{
        $stmt=$connection->prepare("SELECT v.id,v.pay_hash,v.user_id,v.created_at,v.signature FROM receipt_visual_v4 v JOIN receipt_visual_keys_v4 k ON k.visual_id=v.id WHERE k.bucket IN ($in) AND v.created_at>=? AND v.created_at<=? AND v.pay_hash<>? AND v.id>? GROUP BY v.id,v.pay_hash,v.user_id,v.created_at,v.signature HAVING COUNT(*)>=3 ORDER BY v.id LIMIT 100");
        if(!$stmt)break;
        $stmt->bind_param('iisi',$cutoff,$now,$payHash,$cursor);$stmt->execute();$res=$stmt->get_result();$count=0;
        while($row=$res->fetch_assoc()){
            $count++;$cursor=(int)$row['id'];
            if(v2receipt_compareSignatures($signature,json_decode($row['signature'],true)??[])){
                unset($row['signature'],$row['id']);$row['match_type']='similar';$matches[$row['pay_hash']]=$row;
            }
        }
        $stmt->close();
    }while($count===100 && count($matches)<50);
    return $matches;
}
function v2receipt_storeVisual($signature,$payHash,$userId,$now){
    global $connection;
    $keys=v2receipt_signatureKeys($signature);
    if(count($keys)<3 || !v2receipt_ensureVisualTables())return;
    $json=json_encode($signature,JSON_UNESCAPED_SLASHES);
    $stmt=$connection->prepare('INSERT INTO receipt_visual_v4(pay_hash,user_id,created_at,signature) VALUES(?,?,?,?)');
    $stmt->bind_param('siis',$payHash,$userId,$now,$json);$stmt->execute();$id=$connection->insert_id;$stmt->close();
    $stmt=$connection->prepare('INSERT IGNORE INTO receipt_visual_keys_v4(visual_id,bucket) VALUES(?,?)');
    foreach($keys as $key){$stmt->bind_param('is',$id,$key);$stmt->execute();}$stmt->close();
}

// Rebuild visual signatures for retrievable historical receipt photos gradually.
// Called only by the existing CLI report cron: no downloads on customer browsing.
function v2receipt_markHistory($payHash,$fileId,$sentAt,$ok){
    global $connection;
    if(!v2receipt_ensureVisualTables())return;
    $fileHash=hash('sha256',$fileId);$next=$ok?2147483647:time()+86400;
    $stmt=$connection->prepare('INSERT INTO receipt_history_v4(pay_hash,file_hash,sent_at,next_attempt) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE file_hash=VALUES(file_hash),sent_at=VALUES(sent_at),next_attempt=VALUES(next_attempt)');
    $stmt->bind_param('ssii',$payHash,$fileHash,$sentAt,$next);$stmt->execute();$stmt->close();
}
function v2receipt_backfill($limit=3){
    global $connection,$dbName;
    if(PHP_SAPI!=='cli' || empty(v2raystore_receiptCheckSettings()['enabled']) || !function_exists('imagecreatefromstring'))return 0;
    $locked=false;$done=0;$lockName='v2receipt-history:'.substr(hash('sha256',(string)($dbName??'')),0,24);
    try{
        if(!v2receipt_ensureVisualTables() || !v2raystore_ensureReceiptFingerprintsTable())return 0;
        $stmt=$connection->prepare('SELECT GET_LOCK(?,0) AS locked');$stmt->bind_param('s',$lockName);$stmt->execute();$locked=!empty($stmt->get_result()->fetch_assoc()['locked']);$stmt->close();
        if(!$locked)return 0;
        $now=time();$cutoff=$now-90*86400;$limit=max(1,min(20,(int)$limit));
        $stmt=$connection->prepare("SELECT p.hash_id,p.user_id,p.receipt_file_id,COALESCE(NULLIF(p.sent_date,0),p.request_date) AS sent_at FROM pays p LEFT JOIN receipt_history_v4 h ON h.pay_hash=p.hash_id AND h.file_hash=SHA2(p.receipt_file_id,256) WHERE COALESCE(NULLIF(p.sent_date,0),p.request_date)>=? AND COALESCE(NULLIF(p.sent_date,0),p.request_date)<=? AND p.receipt_file_id IS NOT NULL AND p.receipt_file_id<>'' AND (h.pay_hash IS NULL OR h.next_attempt<=?) ORDER BY p.id DESC LIMIT $limit");
        $stmt->bind_param('iii',$cutoff,$now,$now);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        foreach($rows as $row){
            try{$fp=v2raystore_getReceiptFingerprints($row['receipt_file_id']);}catch(Throwable $e){$fp=[];}
            $ok=!empty($fp['signature']);
            if(!empty($fp['sha256'])){
                $s=$connection->prepare("INSERT INTO receipt_fingerprints(pay_hash,user_id,file_unique_id,content_hash,visual_hash,visual_version,created_at) SELECT ?,?,'',?,?,3,? WHERE NOT EXISTS(SELECT 1 FROM receipt_fingerprints WHERE pay_hash=? AND content_hash=?)");
                $s->bind_param('sississ',$row['hash_id'],$row['user_id'],$fp['sha256'],$fp['visual'],$row['sent_at'],$row['hash_id'],$fp['sha256']);$s->execute();$s->close();
            }
            if($ok)v2receipt_storeVisual($fp['signature'],$row['hash_id'],(int)$row['user_id'],(int)$row['sent_at']);
            v2receipt_markHistory($row['hash_id'],$row['receipt_file_id'],(int)$row['sent_at'],$ok);$done++;
        }
        v2raystore_cleanupReceiptFingerprints($now);
    }catch(Throwable $e){error_log('Receipt history backfill deferred');}
    finally{if($locked){try{$s=$connection->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lockName);$s->execute();$s->close();}catch(Throwable $e){}}}
    return $done;
}
