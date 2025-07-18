<?php

class CoinEx extends AbstractExchange {
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
        $method = strtoupper($method);
        $url = '/v2'.$url;
        $request_str = $method.$url;
        if(!empty($param)) {
            switch($method) {
                case 'GET':
                    $param_str = '?';
                    $count_param = count($param);
                    $i=0;
                    foreach($param as $k=>$v) {
                        $param_str .= $k.'='.$v;
                        $i++;
                        if($i<$count_param) {
                            $param_str .= '&';
                        }
                    }
                    $request_str .= $param_str;
                    break;
                case 'POST':
                case 'DELETE':
                default:
                    break;            
            }
        }
        $this->timestamp = $this->getTonce();
        $request_str .= $this->timestamp;
        $request_str .= $this->secret_key;
        
        //signed_str = sha256(prepared_str).hexdigest().lower()
        $hash = hash("sha256", $request_str);
        $signature = strtolower($hash);
        return $signature;
    }
    
    private function request($url,$param=false,$method = 'GET', $header = array()) {
        global $Daemon;
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
            Log::systemLog('error', 'Error request from '.$this->name.' exchange. Error code. '.$http_code. ' url='.$url.' param='.$param,$Daemon->getProcName());
            return false;
        }
    }
    
    private function getTonce() {
        $time = new DateTime("now", new DateTimeZone('UTC'));
        return $time->format('U')*1000;
    }
    private function getTonceU() {
        $time = new DateTime("now", new DateTimeZone('UTC'));
        return $time->format('Uu');
    }
    
    public function requestSpotTradeFee($pair_id) {
        $pair = Exchange::detectNamesPair($pair_id);
        //Prepare parameters for request
        $arr_param = array(
            "market_type"=>'SPOT',
            "market"=> str_replace("/",'',$pair),
        );
        //Log::systemLog('error', 'PARAM ='. json_encode($arr_param));
        $sign = $this->sign("GET", "/account/trade-fee-rate", $arr_param);
        $str = '';
        $i=0;
        foreach ($arr_param as $k=>$v) {
            if($i>0) {
                $str .= '&';
            }
            $str .= $k.'='.$v;
            $i++;
        }
        $header['X-COINEX-KEY'] = $this->api_key;
        $header['X-COINEX-SIGN'] = $sign;
        $header['X-COINEX-TIMESTAMP'] = $this->timestamp;
            
        //Request
        $json_fee = $this->request($this->base_url.'/v2/account/trade-fee-rate', $str, 'GET', $header);
        
        if($json_fee) {
            $fee = json_decode($json_fee,true);
            if($fee['code'] == "0") {
                $taker_fee = (float)$fee['data']['taker_rate'];
                $maker_fee = (float)$fee['data']['maker_rate'];   
                return array(
                    "taker_fee" => $taker_fee,
                    "maker_fee" => $maker_fee
                );
            }
            else {
                Log::systemLog('error', 'Error request market fee CoinEx for '.$pair.' '.$json_fee, 'Service');
                $this->lastError = 'Error request market fee CoinEx for '.$pair.' '.$json_fee;
            }
        }
        else {
             Log::systemLog('error', 'Error request market fee CoinEx for '.$pair, 'Service');
             $this->lastError = 'Error request market fee CoinEx for '.$pair;
        }

        return false;
    }
    public function updateCoinsInfoData() {
        global $DB;
        
        $json_coins = $this->request($this->base_url.'/v1/common/asset/config');
        if(empty($json_coins)) {
            Log::systemLog('error', 'Error request Coin info for CoinEx is failed','Service');
            $this->lastError = 'Error request Coin info for CoinEx is failed';
            return false;
        }
        $coins = json_decode($json_coins,true);
        if(isset($coins['code'])) {
            if($coins['code'] == '0' && $coins['message'] == "Success") {
                $data = array();
                foreach($coins['data'] as $cd) {
                    $tmp = array();
                    $coin_id = Exchange::detectCoinIdByName($cd['asset'],1);
                    $chain_id = Exchange::detectChainByName($cd['chain']); 
                    if($coin_id > 0 && $chain_id > 0) {
                        $tmp['coin'] = $cd['asset'];
                        $tmp['coin_id'] = intval($coin_id); 
                        $tmp['chain_id'] = intval($chain_id); 
                        $tmp['deposit_active'] = (bool)$cd['can_deposit'];
                        $tmp['withdrawal_active'] = (bool)$cd['can_withdraw'];
                        $tmp['precission'] = intval($cd['withdrawal_precision']);
                        $tmp['deposit_min'] = floatval($cd['deposit_least_amount']);
                        $tmp['withdrawal_min'] = floatval($cd['withdraw_least_amount']);
                        $tmp['withdrawal_fee'] = floatval($cd['withdraw_tx_fee']);
                        $data[] = $tmp;
                        //Log::systemLog('debug', 'Exchange CoinEx '.$cd['asset'].' '.$coin_id);
                    }
                    else {
                        if($coin_id == false) {
                            if($cd['asset'] !== 'BTT_OLD') {
                                Log::systemLog('warn', 'Exchange CoinEx failed detect coin '.$cd['asset'],'Service');
                                $this->lastError = 'Exchange CoinEx failed detect coin '.$cd['asset'];
                            }
                        }
                        if($chain_id == false) {
                            Log::systemLog('warn', 'Exchange CoinEx failed detect chain by name '.$cd['chain'],'Service');
                            $this->lastError = 'Exchange CoinEx failed detect chain by name '.$cd['chain'];
                        }

                    }
                }
                //Write data into deposit table
                $DB->startTransaction();
                foreach ($data as $d) {
                    $sql = "INSERT INTO `DEPOSIT` (`EXID`,`COIN_ID`, `CHAIN_ID`, `ACTIVE`, `DEPOSIT_MIN_SUM`, `PRECISION`) VALUES (?,?,?,?,?,?) "
                            . "ON DUPLICATE KEY UPDATE `ACTIVE`=?, `DEPOSIT_MIN_SUM`=?, `PRECISION`=?, `DATE_MODIFY`=NOW()";
                    $bind = array();
                    $bind[0]['type'] = 'i';
                    $bind[0]['value'] = $this->exchange_id;
                    $bind[1]['type'] = 'i';
                    $bind[1]['value'] = $d['coin_id'];
                    $bind[2]['type'] = 'i';
                    $bind[2]['value'] = $d['chain_id'];
                    $bind[3]['type'] = 'i';
                    $bind[3]['value'] = $d['deposit_active'];
                    $bind[4]['type'] = 'd';
                    $bind[4]['value'] = $d['deposit_min'];
                    $bind[5]['type'] = 'i';
                    $bind[5]['value'] = $d['precission'];
                    $bind[6]['type'] = 'i';
                    $bind[6]['value'] = $d['deposit_active'];
                    $bind[7]['type'] = 'd';
                    $bind[7]['value'] = $d['deposit_min'];
                    $bind[8]['type'] = 'i';
                    $bind[8]['value'] = $d['precission'];
                    $ins = $DB->insert($sql,$bind);
                    if(!$ins && !empty($DB->getLastError())) {
                        $DB->rollbackTransaction();
                        Log::systemLog('error', $DB->getLastError());
                    }
                    //Write data into withdrawal table
                    $sql2 = "INSERT INTO `WITHDRAWAL` (`EXID`,`COIN_ID`, `CHAIN_ID`, `ACTIVE`, `WITHDRAWAL_MIN_SUM`, `PRECISION`, `FEE`) VALUES (?,?,?,?,?,?,?) "
                            . "ON DUPLICATE KEY UPDATE `ACTIVE`=?, `WITHDRAWAL_MIN_SUM`=?, `PRECISION`=?, `FEE`=?, `DATE_MODIFY`=NOW()";
                    $bind2 = array();
                    $bind2[0]['type'] = 'i';
                    $bind2[0]['value'] = $this->exchange_id;
                    $bind2[1]['type'] = 'i';
                    $bind2[1]['value'] = $d['coin_id'];
                    $bind2[2]['type'] = 'i';
                    $bind2[2]['value'] = $d['chain_id'];
                    $bind2[3]['type'] = 'i';
                    $bind2[3]['value'] = $d['withdrawal_active'];
                    $bind2[4]['type'] = 'd';
                    $bind2[4]['value'] = $d['withdrawal_min'];
                    $bind2[5]['type'] = 'i';
                    $bind2[5]['value'] = $d['precission'];
                    $bind2[6]['type'] = 'd';
                    $bind2[6]['value'] = $d['withdrawal_fee'];
                    $bind2[7]['type'] = 'i';
                    $bind2[7]['value'] = $d['withdrawal_active'];
                    $bind2[8]['type'] = 'd';
                    $bind2[8]['value'] = $d['withdrawal_min'];
                    $bind2[9]['type'] = 'i';
                    $bind2[9]['value'] = $d['precission'];
                    $bind2[10]['type'] = 'd';
                    $bind2[10]['value'] = $d['withdrawal_fee'];
                    $ins2 = $DB->insert($sql2,$bind2);
                    if(!$ins2 && !empty($DB->getLastError())) {
                        $DB->rollbackTransaction();
                        Log::systemLog('error', $DB->getLastError());
                    }
                }
                $DB->commitTransaction();
                return true;
            }
            else {
                Log::systemLog('error', 'Exchange CoinEx for request by coins return code '.$coins['code'],'Service');
                $this->lastError = 'Exchange CoinEx for request by coins return code '.$coins['code'];
            }
        }
        else {
            Log::systemLog('error', 'Exchange CoinEx for request by coins return failed message','Service');
            $this->lastError = 'Exchange CoinEx for request by coins return failed message';
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
        $json_kline = $this->request($this->base_url.'/v1/market/kline', $str, 'GET');
        if(empty($json_kline)) {
            Log::systemLog('error', 'Error request K-Line Kukoin for '.$pair);
            $this->lastError = 'Error request K-Line Kukoin for '.$pair;
            return false;
        }
        $kline = json_decode($json_kline,true);
        if($kline['code'] != '0') {
            Log::systemLog('error', 'Error request K-Line CoinEx for '.$pair.'. Return code '.$kline['code']);
            $this->lastError = 'Error request K-Line CoinEx for '.$pair.'. Return code '.$kline['code'];
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
                            CONCAT(bc.SYMBOL,qc.SYMBOL) AS NAME,
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
            $this->lastError = 'Error select trade pair name CoinEx';
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
    public function isNeedPingWebsocket() {
        return true;
    }
    public function isEnableWebsocket() {
        return false;
    }
    public function webSocketConnect($type=false) {
        $options = array_merge([
            'uri'           => $this->websocket_url,
            'timeout'       => 4,
            'fragment_size' => 1024,
            'filter'        => array('text','binary','ping','pong','close'),
            'persistent'    => true,
        ], getopt('', ['uri:', 'timeout:', 'fragment_size:', 'debug', 'filter:','persistent:']));

        $client = new \WebSocket\Client($options['uri'], $options);
        if($client) {
            return $client;
        }
        $src = '';
        switch($type) {
            case 'orderbook': 
                $src = 'Order Book';
                break;
            default:
                $src = '';
        }
        Log::systemLog('error', 'ERROR CONNECT to CoinEx exchange proc='. getmypid().'',$src);
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
          "params":{},
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
        //Log::systemLog('debug', 'Echange order book process = '. getmypid().' webSoket receive NATIVE from CoinEx = '. $unzip_receive, "Order Book");
        $r = json_decode($unzip_receive, JSON_OBJECT_AS_ARRAY);
        
        $ret = array();
        
        if(isset($r['message']) && isset($r['code'])) {
            //Success result
            if($r['code'] == "0" && $r['message'] == "OK") {
                //PONG
                if(isset($r['data']['result'])) {
                    if($r['data']['result'] == 'pong') {
                        $ret['method'] = 'pong';
                        $ret['status'] = 1;
                        $ret['id'] = (int)$r['id'];
                        return $ret;
                    }
                }
                else {
                    $ret['method'] = 'queryResponse';
                    $ret['id'] = (int)$r['id'];
                    $ret['status'] = 1;
                    return $ret;
                }
            }
        }

        if(isset($r['method'])) {
            switch ($r['method']) {
                //Order book data
                case 'bbo.update':
                    $ret['method'] = 'bbo';
                    $tmp['pair'] = $r['data']['market'];
                    $tmp['ask_price'] = $r['data']['best_ask_price']; 
                    $tmp['ask_volume'] = $r['data']['best_ask_size'];
                    $tmp['bid_price'] = $r['data']['best_bid_price']; 
                    $tmp['bid_volume'] = $r['data']["best_bid_size"];
                    $tmp['price_timestamp'] = $r['data']['updated_at']*1E3;
                    $tmp['timestamp'] = $this->getTonceU();
                    $ret['data'][] = $tmp;                    
                    $ret['id'] = (int)$r['id'];
                    break;
                case 'depth.update':
                default:
                    $ret['method'] = 'depth';
                    //parse data
                    $tmp = array();
                    $tmp['diff'] = !(bool)$r['data']['is_full'];
                    $tmp['pair'] = $r['data']['market'];
                    if(isset($r['data']['depth']['asks'])) {
                        $tmp['asks'] = $r['data']['depth']['asks'];
                    }
                    else {
                        $tmp['asks'] = array();
                    }
                    if(isset($r['data']['depth']['bids'])) {
                        $tmp['bids'] = $r['data']['depth']['bids'];
                    }
                    else {
                        $tmp['bids'] = array();
                    }
                    if(isset($r['data']['depth']['last'])) {
                        $tmp['last_price'] = $r['data']['depth']['last'];
                    }
                    if(isset($r['data']['depth']['updated_at'])) {
                        $tmp['price_timestamp'] = $r['data']['depth']['updated_at']*1E3;
                    }
                    $tmp['timestamp'] = $this->getTonceU();
                    $ret['data'][] = $tmp;                    
                    $ret['id'] = (int)$r['id'];
            }
            return $ret;
        }
        return false;
    }
}