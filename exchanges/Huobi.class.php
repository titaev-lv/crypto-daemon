<?php

class Huobi  extends AbstractExchange {
    public $lastError = '';
    
    public function __construct($id, $account_id=false, $market=false) {
        global $DB;
        $this->exchange_id = $id;
        $sql = "SELECT `NAME` FROM `EXCHANGE` WHERE `ID`=? AND `ACTIVE`=1";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $id;
        $ret = $DB->select($sql,$bind);
        if(count($ret)>0) {
             $this->name = $ret[0]['NAME'];
        }
        if($account_id) {
            $sql = "SELECT `API_KEY`, `SECRET_KEY`, `ADD_KEY` FROM `EXCHANGE_ACCOUNTS` WHERE `ID`=?";
            $bind[0]['type'] = 'i';
            $bind[0]['value'] = $account_id;
            $ret = $DB->select($sql,$bind);
            if(count($ret)>0) {
                $this->api_key = $ret[0]['API_KEY'];
                $this->secret_key = $ret[0]['SECRET_KEY'];
                $this->passphrase = $ret[0]['ADD_KEY'];
                $this->account_id = $account_id;
            }
        }
    }
    
    private function sign($method,$url,$param=array()) {
        $request_str = $method."\n".preg_replace("/https:\/\//", '', $this->base_url)."\n".$url."\n";
        $param_str = '';
        
        switch($method) {
            case 'GET':
                $i=0;
                $count_param = count($param);
                foreach($param as $k=>$v) {
                    //echo mb_detect_encoding($v, "auto");echo $v."<br>";
                    $param_str .= $k.'='.urlencode(mb_convert_encoding($v, 'UTF-8', 'auto'));
                    $i++;
                    if($i<$count_param) {
                        $param_str .= '&';
                    }
                }
                break;
            case 'POST':
            case 'DELETE':
            default:
                break;            
        }
        $request_str .= $param_str;
        $hash = hash_hmac("sha256", $request_str, $this->secret_key, true);
        $signature = urlencode(base64_encode($hash));
        return $signature;
    }
    private function request($url,$param=false,$method = 'GET', $header = array()) {
        $ch = curl_init();
        curl_setopt ($ch,  CURLOPT_SSLVERSION, 6);
        if($method == 'GET') {
            curl_setopt ($ch, CURLOPT_URL,$url."?".$param);
        }
        else {
            curl_setopt ($ch, CURLOPT_URL,$url);
        }
        //curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt ($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt ($ch, CURLOPT_FAILONERROR, false);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 2);  

        $headers = array(
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X x.y; rv:42.0) Gecko/20100101 Firefox/42.0',
            'Content-Type: application/json'
        );
        if($header) {
            if(is_array($headers) && is_countable($headers)) {
                foreach ($header as $k=>$h) {
                    array_push($headers, $k.': '.$h);
                }
            }
        }
            
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1); 
        $result = curl_exec ($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
               
        if($http_code == 200){
            return $result;
        }
        else {
            Log::systemLog('error', 'Error request from '.$this->name.' exchange. Error code. '.$http_code);
            $this->lastError = 'Error request from '.$this->name.' exchange. Error code. '.$http_code;
            return false;
        }
    }
    private function getTonce() {
        $time = new DateTime("now",new DateTimeZone('UTC'));
        return $time->format('Y-m-d\TH:i:s');
    }
    private function getTonceU() {
        $time = new DateTime("now", new DateTimeZone('UTC'));
        return $time->format('Uu');
    }
   
    public function requestSpotTradeFee($pair_id) {
        $pair = Exchange::detectNamesPair($pair_id);
        //Prepare parameters for request
        $arr_param = array(
            "Timestamp" => $this->getTonce(),
            "AccessKeyId" => $this->api_key,
            "SignatureMethod" => 'HmacSHA256',
            "SignatureVersion" => 2,
            "symbols"=> strtolower(preg_replace("/\//", '', $pair)),
        );
        //Log::systemLog('error', 'PAIR='.$pair .' PARAM ='. json_encode($arr_param));
        ksort($arr_param,SORT_NATURAL);
        $param_str = '';
        $i=0;
        $count_param = count($arr_param);
        foreach($arr_param as $k=>$v) {
            $param_str .= $k.'='.urlencode(mb_convert_encoding($v, 'UTF-8', 'auto'));
            $i++;
            if($i<$count_param) {
                $param_str .= '&';
            }
        }
        $sign = $this->sign('GET','/v2/reference/transact-fee-rate', $arr_param);            
        $request_string = $param_str.'&Signature='.$sign;
        //Request    
        $json_fee = $this->request($this->base_url.'/v2/reference/transact-fee-rate', $request_string, 'GET');

        if($json_fee) {
            $fee = json_decode($json_fee,true);
            if($fee['code'] == "200" && $fee['success'] == "true") { 
                $taker_fee = (float)$fee['data'][0]['actualMakerRate'];
                $maker_fee = (float)$fee['data'][0]['actualTakerRate'];
                return array(
                    "taker_fee" => $taker_fee,
                    "maker_fee" => $maker_fee
                );
            }
            else {
                Log::systemLog('error', 'Error request market fee Huobi for '.$pair.' '.$json_fee, 'Service');
                $this->lastError = 'Error request market fee Huobi for '.$pair.' '.$json_fee;
            }
        }
        else {
             Log::systemLog('error', 'Error request market fee Huobi for '.$pair, 'Service');
             $this->lastError = 'Error request market fee Huobi for '.$pair;
        }

        return false;
    }
    public function updateCoinsInfoData() {
        global $DB;
        
        $json_coins = $this->request($this->base_url.'/v2/reference/currencies');
        if(empty($json_coins)) {
            Log::systemLog('error', 'Error request Coin info for Huobi is failed','Service');
            $this->lastError = 'Error request Coin info for Huobi is failed';
            return false;
        }
        $coins = json_decode($json_coins,true);
        
        if($coins['code'] == 200 && is_array($coins['data']) && !empty($coins['data'])) {
            $data = array();
            foreach ($coins['data'] as $dt) {
                $tmp = array();
                $flag_ignore = false;
                $dt['currency'] = mb_strtoupper($dt['currency']);
                
                //assetType = 2 is fiat
                if($dt['assetType'] == '1') {
                    $c_id = false;
                    $coin_id = false;
                    
                    switch($dt['currency']) {
                        case 'PROPY':
                            $c_id = 1974;
                            break;
                        case 'MONFTER':
                            $c_id = 16818;
                            break;
                        case 'AIDOC':
                            $c_id = 16818;
                            break;
                        case 'BXEN':
                            $c_id = 22118;
                            break;
                        case 'EUROC':
                            $c_id = 1038;
                            break;
                        case 'FCT2':
                            $c_id = 4953;
                            break;
                        case 'GEARBOX':
                            $c_id = 16360;
                            break;
                        case 'GSTERC':
                            $c_id = 21152;
                            break;
                        case 'MEDAMON':
                            $c_id = 19588;
                            break;
                        case 'POOLZ':
                            $c_id = 8271;
                            break;
                        case 'XPNT':
                            $c_id = 85794;
                            break;
                        /*case 'RLTM':
                            $c_id = 24106;
                            break;*/
                        case 'MGO':
                        case 'MZK':
                            $flag_ignore = true;
                            break;
                        default:
                    }
                    
                    if(preg_match("/(3L)|(3S)|(2L)|(2S)$/",$dt['currency'])) {
                        $flag_ignore = true;
                    }

                    if($flag_ignore == false) {
                        if(!$c_id) {
                            $coin_id = Exchange::detectCoinIdByName($dt['currency'],6);

                            if(!$coin_id) {
                                $active = false;
                                if(isset($dt['chains']) && is_array($dt['chains'])) {
                                    foreach ($dt['chains'] as $ch_pre) {
                                        if($ch_pre['depositStatus'] === 'allowed' || $ch_pre['withdrawStatus'] === 'allowed') {
                                            $active = true;
                                        }
                                    }
                                }
                                if($active === false) {
                                    $coin_id = -1;
                                }
                            }
                        }
                        elseif ($c_id > 0) {
                            $coin_id = $c_id;
                        }

                        if(is_array($dt['chains'])) {
                            foreach ($dt['chains'] as $ch) {
                                switch($ch['displayName']) {
                                    case 'ARBITRUMONE':
                                        $ch['displayName'] = 'ARBITRUM';
                                        break;
                                    case 'AVAXCCHAIN':
                                        $ch['displayName'] = 'AVAXC';
                                        break;
                                    case 'SOLANA':
                                        $ch['displayName'] = 'SOL';
                                        break;
                                    case 'TERRACLASSIC':
                                    case 'USTC':
                                        $ch['displayName'] = 'LUNC';
                                        break;
                                    case 'ONTOLOGY':
                                        $ch['displayName'] = 'ONT';
                                        break;
                                    case 'ETHFAIR':
                                        $ch['displayName'] = 'ETHF';
                                        break;
                                    case 'ETHW':
                                        $ch['displayName'] = 'ETHPOW';
                                        break;
                                    case 'BASE':
                                        $ch['displayName'] = 'ETHBASE';
                                        break;
                                    case 'NEON3':
                                        $ch['displayName'] = 'NEO3';
                                        break;
                                    case 'HRC20':
                                        $ch['displayName'] = 'HECO';
                                        break;
                                    case 'XEM':
                                        $ch['displayName'] = 'NEM'; 
                                        break;
                                    case 'BTM2':
                                        $ch['displayName'] = 'BTM'; 
                                        break;
                                    default:
                                }
                                if(empty($ch['displayName'])) {
                                    continue;
                                }
                                $chain_id = Exchange::detectChainByName($ch['displayName']); 
                                $tmp = array();
                                
                                if($coin_id > 0 && $chain_id > 0) {
                                    /*$tmp['coin_id'] = $coin_id;
                                    $tmp['chain_id'] = $chain_id;
                                    $tmp['deposit_active'] = (bool)$ch['isDepositEnabled'];
                                    $tmp['withdrawal_active'] = (bool)$ch['isWithdrawEnabled'];
                                    $tmp['precission'] = $precision;
                                    $tmp['deposit_min'] = 0;
                                    $tmp['withdrawal_min'] = floatval($ch['withdrawalMinSize']);
                                    $tmp['withdrawal_fee'] = floatval($ch['withdrawalMinFee']);
                                    /*if(isset($ch['depositFeeRate'])) {
                                        $tmp['fee_percent'] = floatval($ch['depositFeeRate']);
                                    }*/
                                    //$data[] = $tmp;
                                }
                                else {
                                    if($chain_id == false) {
                                        Log::systemLog('warn', 'Exchange Huobi failed detect chain by name '.$ch['displayName'],'Service');
                                        $this->lastError = 'Exchange Huobi failed detect chain by name '.$ch['displayName'];
                                    }
                                    if($coin_id == false) {
                                        Log::systemLog('warn', 'Exchange Huobi failed detect coin '.$dt['currency'],'Service');
                                        $this->lastError = 'Exchange Huobi failed detect coin '.$dt['currency'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return false;
    }
    public function getLastError(){
        return $this->last_erorr;
    }
    
    public function getKLine($pair,$timeframe) {
        $limit = 1000;
       // https://api.coinex.com/v1/market/kline?market=LUNCUSDT&type=1min&limit=1000
        $pair = preg_replace("/\//",'',$pair);
        $str = 'market='.$pair.'&type='.$timeframe.'&limit='.$limit;
        $json_kline = $this->request($this->base_url.'/market/kline', $str, 'GET');
        if(empty($json_kline)) {
            Log::systemLog('error', 'Error request K-Line Kukoin for '.$pair);
            $this->lastError = 'Error request K-Line Kukoin for '.$pair;
            return false;
        }
        $kline = json_decode($json_kline,true);
        if($kline['code'] != '0') {
            Log::systemLog('error', 'Error request K-Line Huobi for '.$pair.'. Return code '.$kline['code']);
            $this->lastError = 'Error request K-Line Huobi for '.$pair.'. Return code '.$kline['code'];
            return false;
        }
        return $kline['data'];
    }
    public function getTradePairName($pair,$market='spot') {
        global $DB;
        
        switch ($market) {
            case 'spot':
            default:
                $sql = 'SELECT 
                            LOWER(CONCAT(bc.SYMBOL,qc.SYMBOL)) AS NAME,
                            CONCAT(bc.SYMBOL,"/",qc.SYMBOL) AS SYS_NAME
                        FROM 
                            SPOT_TRADE_PAIR stp
                        INNER JOIN
                            COIN bc ON bc.ID = stp.BASE_CURRENCY_ID
                        INNER JOIN
                            COIN qc ON qc.ID = stp.QUOTE_CURRENCY_ID
                        WHERE
                            stp.ID = ?';
        }
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $pair;
        $sel = $DB->select($sql, $bind);
        if(empty($sel) || !empty($DB->getLastError())) {
            Log::systemLog('error', $DB->getLastError());
            $this->lastError = 'Error select trade pair name Huobi';
            return false;
        }
        return $sel[0];
    }
    public function mergeTradePairData($src, $add_data) {
        foreach ($src['data'] as $k=>$d) {
            if(!empty($add_data)) {
                foreach ($add_data as $adata) {
                    if($d['pair'] == $adata['name']) {
                        $src['data'][$k]['sys_pair'] = $adata['sys_name'];
                        $src['data'][$k]['pair_id'] = $adata['id'];
                        $src['data'][$k]['ftok_crc'] = $adata['ftok_crc'];
                        break;
                    }
                }
            }
        }
        return $src;
    }
    public function isEnableWebsocket() {
        return false;
    }
    public function isNeedPingWebsocket() {
        return false;
    }
    public function webSocketConnect($type=false) {
        $options = array_merge([
            'uri'           => $this->websocket_url,
            'timeout'       => 5,
            'fragment_size' => 1024,
            'filter'        => array('text','binary','ping','pong','close'),
            'persistent'    => true,
        ], getopt('', ['uri:', 'timeout:', 'fragment_size:', 'debug', 'filter:','persistent:']));

        $client = new \WebSocket\Client($options['uri'], $options);
        if($client) {
            try {
                $receive = $client->receive();
                $received = gzdecode($receive);
                $r = json_decode($received, JSON_OBJECT_AS_ARRAY);
                if(isset($r['ping'])) {
                    $this->websoket_conn_id = $r['ping'];
                }
            }
            catch (\WebSocket\TimeoutException $e) {
                $src = '';
                switch($type) {
                    case 'orderbook': 
                        $src = 'Order Book';
                        break;
                    default:
                        $src = '';
                }
                Log::systemLog('error', 'ERROR CONNECT to Huobi exchange proc='. getmypid().'',$src);
                return false;
            }
            return $client;
        }
        return false;
    }
    public function getWebSoketCount() {
        $c = $this->websoket_count;
        $this->websoket_count++;
        return $c;
    }
    public function webSocketPing($client_ws) {
        $c = $this->getWebSoketCount();
        $client_ws->text('{
          "method":"server.ping",
          "params":[],
          "id": '.$c.'
        }');
        return true;
    }
    public function webSocketParse($receive) {
        $is_gzip = 0 === mb_strpos($receive, "\x1f" . "\x8b" . "\x08", 0, "US-ASCII");
        if($is_gzip) {
            $unzip_receive = gzdecode($receive);
        }
        else {
            Log::systemLog('error', 'Error received message. Data not gzipped: '.$receive, 'Order Book');
            $ret['method'] = 'error';
            return $ret;
        }
        //$unzip_receive = gzdecode($receive);
        //Log::systemLog('debug', $unzip_receive);
        //Reseive json message
        $r = json_decode($unzip_receive, JSON_OBJECT_AS_ARRAY);
        $ret = array();
        //PING
        if(isset($r['ping'])) {
            $ret['method'] = 'ping';
            $ret['timestamp'] = $r['ping'];
            return $ret;
        }
        //Success result
        if(isset($r['id']) && isset($r['status'])) {
            $ret['method'] = 'queryResponse';
            if($r['status'] == 'ok') {
                $ret['status'] = 1;
            }
            else {
                $ret['status'] = 0;
            }
            $ret['error'] = '';
            $ret['id'] = (int)$r['id'];
            if(isset($r['subbed'])) {
                $ret['msg'] = "Subscribed ".$r['subbed'];
            }
            if(isset($r['unsubbed'])) {
                $ret['msg'] = "Unsubscribed ".$r['unsubbed'];
            }
            return $ret;
        }

        if(isset($r['ch'])) {
            $chdata = explode(".", $r['ch']);
            if($chdata[0] == 'market' && $chdata[2] == 'mbp' && $chdata[3] == 'refresh') {
                $ret['method'] = 'depth';
                $tmp = array();
                $tmp['diff'] = false;
                $tmp['pair'] = $chdata[1];
                $tmp['asks'] = $r['tick']['asks'];
                $tmp['bids'] = $r['tick']['bids'];
                $tmp['last_price'] = null;
                $tmp['price_timestamp'] = $r['ts']*1E3;
                $tmp['timestamp'] = $this->getTonceU();
                $ret['id'] = (int)$r['tick']['seqNum'];
                $ret['data'][] = $tmp;
            }
            if($chdata[0] == 'market' && $chdata[2] == 'bbo') {
                $ret['method'] = 'bbo';
                $tmp['pair'] = $chdata[1];
                $tmp['ask_price'] = $r['tick']['ask']; 
                $tmp['ask_volume'] = $r['tick']['askSize'];
                $tmp['bid_price'] = $r['tick']['bid']; 
                $tmp['bid_volume'] = $r['tick']["bidSize"];
                $tmp['price_timestamp'] = $r['ts']*1E3;
                $tmp['timestamp'] = $this->getTonceU();
                $ret['id'] = 0;
                $ret['data'][] = $tmp;                    
            }
            return $ret;
        }
        return false;
    }
}