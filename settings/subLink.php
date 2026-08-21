<?php
require __DIR__ . "/../config.php";
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private');

$token = trim((string)($_GET['token'] ?? ''));
if(!preg_match('/\A[A-Za-z0-9]{30}\z/D', $token)){
    http_response_code(400);
    exit('Wrong token');
}

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `token` = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$info){
            http_response_code(404);
            exit('Wrong token');
        }
        
        $remark = $info['remark'];
        $uuid = $info['uuid']??"0";
        $server_id = $info['server_id'];
        $inbound_id = $info['inbound_id'];
        $protocol = $info['protocol'];
        $rahgozar = $info['rahgozar'];
        
        $file_id = $info['fileid'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $file_id);
        $stmt->execute();
        $file_detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$file_detail){
            http_response_code(404);
            exit('Plan not found');
        }
        $customPath = $file_detail['custom_path'] ?? null;
        $customPort = $file_detail['custom_port'] ?? 0;
        $customSni = $file_detail['custom_sni'] ?? null;
        $customDomain = $file_detail['custom_domain'] ?? null;


        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE id=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$server_info){
            http_response_code(404);
            exit('Server not found');
        }
        $serverType = $server_info['type'];

        $panelSubLink = v2raystore_makeCustomerSubLink($server_id, $token, $uuid, $inbound_id, $remark);
        if($panelSubLink != '' && strpos($panelSubLink, 'settings/subLink.php') === false){
            header('Location: ' . $panelSubLink, true, 302);
            exit();
        }
        // For latest 3x-ui, if the bot-local subscription endpoint is used, serve the exact protocol links
        // returned by the panel API instead of regenerating them locally.
        if($serverType == 'sanaei_new' && function_exists('v2raystore_sanaeiNewSubLinksFromPanel')){
            $realSubId = v2raystore_findPanelSubId($server_id, $token, $uuid, $inbound_id, $remark);
            $panelApiLinks = v2raystore_sanaeiNewSubLinksFromPanel($server_id, $realSubId);
            if(!empty($panelApiLinks)){
                echo base64_encode(implode("
", $panelApiLinks));
                exit();
            }
        }

        $storedLinks = json_decode((string)($info['link'] ?? ''), true);
        $serveStoredLinks = static function() use ($storedLinks){
            if(!is_array($storedLinks) || empty($storedLinks)){
                http_response_code(502);
                exit('Subscription is temporarily unavailable');
            }
            echo base64_encode(implode("\n", array_map('strval', $storedLinks)));
            exit();
        };
        $panelResponse = getJson($server_id);
        if(!is_object($panelResponse) || !isset($panelResponse->obj) || !is_iterable($panelResponse->obj)){
            $serveStoredLinks();
        }
        $response = $panelResponse->obj;
        $clientInbound = intval($inbound_id);
        $up = 0;
        $down = 0;
        $total = 0;
        $port = 0;
        $netType = '';
        $statsFound = false;
        if($inbound_id == 0) {
            foreach($response as $row){
                $clientInbound = $row->id;
                $clients = json_decode($row->settings)->clients;
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $total = $row->total;
                    $port = $row->port;
                    $up = $row->up;
                    $down = $row->down; 
                    $netType = json_decode($row->streamSettings)->network;
                    $security = json_decode($row->streamSettings)->security;
                    $statsFound = true;
                    break;
                }
            }
        }else {
            foreach($response as $row){
                if($row->id == $inbound_id) {
                    $clientInbound = $row->id;
                    $port = $row->port;
                    $netType = json_decode($row->streamSettings)->network;
                    $security = json_decode($row->streamSettings)->security;
                    
                    $clientsStates = $row->clientStats;
                    $clients = json_decode($row->settings)->clients;
                    foreach($clients as $key => $client){
                        if($client->id == $uuid || $client->password == $uuid){
                            $email = $client->email;
                            $emails = array_column($clientsStates,'email');
                            $emailKey = array_search($email,$emails);
                            
                            if($emailKey === false || !isset($clientsStates[$emailKey])) continue;
                            $total = $clientsStates[$emailKey]->total;
                            $up = $clientsStates[$emailKey]->up;
                            $enable = $clientsStates[$emailKey]->enable;
                            $down = $clientsStates[$emailKey]->down;
                            $statsFound = true;
                            break;
                        }
                    }
                }
            }
        }
        if(!$statsFound) $serveStoredLinks();
        $totalUsed = round( ($up + $down) / 1073741824, 2) . " GB";
        $total = round ($total / 1073741824, 2) . " GB";
        $daysLeft = round(($info['expire_date'] - time())/86400,1);
    	$link = json_decode($info['link'])[0];

        if(preg_match('/vmess/',$link)){
            $link_info = json_decode(base64_decode(str_replace('vmess://','',$link)));
            $uniqid = $link_info->id;
            $port = $link_info->port;
            $netType = $link_info->net;
        }else{
            $link_info = parse_url($link);
            $panel_ip = $link_info['host'];
            $uniqid = $link_info['user'];
            $protocol = $link_info['scheme'];
        }


        
        $newRemark = preg_replace("/\(📊.+-.+\|📆.+\)/","", $remark) . "(📊" . $totalUsed . " - " . $total . "|📆" .  $daysLeft . ")";
        if($inbound_id == 0) $res = editInboundRemark($server_id, $uuid, $newRemark);
        else $res = editClientRemark($server_id, $clientInbound, $uuid, $newRemark);

        if($res->success){
            $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $newRemark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni, $customDomain);
            $stmt = $connection->prepare("UPDATE `orders_list` SET `link` = ?, `remark` = ? WHERE `token` = ?");
            $newLink = json_encode($vraylink);
            $stmt->bind_param("sss", $newLink, $newRemark, $token);
            $stmt->execute();
            $stmt->close();
            
            echo base64_encode(implode("\n", $vraylink));
            exit();
        }else exit("Error occured");
?>
