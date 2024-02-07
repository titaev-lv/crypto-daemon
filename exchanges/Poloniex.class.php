<?php

class Poloniex implements ExchangeInterface {
    private $exchange_id = 0;
    private $market = 'spot';
    private $name = '';
    private $base_url = '';
    private $websocket_url = '';
    private $websoket_count = 1;
    private $websoket_conn_id = '';
    
    private $account_id = 0;
    private $api_key = '';
    private $secret_key = '';
    private $passphrase = '';
    
    public $lastError = '';

    public $rest_request_freq = 0.5; //requests per second
    
    public function __construct($id, $account_id=false, $market='spot') {
        global $DB;
        $this->exchange_id = $id;
        $this->market = $market;
        $sql = "SELECT `NAME`, `BASE_URL`, `WEBSOCKET_URL` FROM `EXCHANGE` WHERE `ID`=? AND `ACTIVE`=1";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $id;
        $ret = $DB->select($sql,$bind);
        if(count($ret)>0) {
             $this->base_url = $ret[0]['BASE_URL'];
             $this->websocket_url = $ret[0]['WEBSOCKET_URL'];
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
    //Get Exchange ID
    public function getId() {
        return $this->exchange_id;
    }
    //Get Exchange Name
    public function getName() {
        return $this->name;
    }
    //Get Exchange Account ID
    public function getAccountId() {
        return $this->account_id;
    }
    public function getMarket() {
        return $this->market;
    }
    private function sign($method,$url,$param=array()) {
        $request_str = $method."\n".$url."\n";
        switch($method) {
            case 'GET':
                $param['signTimestamp'] = $this->getTonce();
                ksort($param,SORT_STRING);
                $param_str = '';
                $i=0;
                $count_param = count($param);
                foreach($param as $k=>$v) {
                    $param_str .= $k.'='.$v;
                    $i++;
                    if($i<$count_param) {
                        $param_str .= '&';
                    }
                }
                $request_str .= $param_str;
                $utf8_string = mb_convert_encoding($request_str, 'UTF-8', 'ISO-8859-1');
                
                break;
            case 'POST':
            case 'DELETE':
            default:
                
                break;            
        }

        $hash = hash_hmac("sha256", $utf8_string, $this->secret_key,true)."\n";
        $signature = base64_encode(trim($hash));
        
        $header = array(
            "key"=>"$this->api_key",
            'signTimestamp' => $param['signTimestamp'],
            "signature" => "$signature", 
        );  
        return $header;
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
            Log::systemLog('error', 'Error request from '.$this->name.' exchange. Error code '.$http_code.' '.$result);
            $this->lastError = 'Error request from '.$this->name.' exchange. Error code '.$http_code.' '.$result;
            return false;
        }
    }
    private function getTonce() {
        $time = new DateTime();
        return $time->format('U')*1000;
    }

    public function syncSpotAllTradePair() {
        global $DB;

        //1. Get Request
        $json_data = $this->request($this->base_url.'/markets');
        
        if(!$json_data) {
            return false;
        }
        $data = json_decode($json_data,true);
        
        
        if(is_array($data) && count($data) > 0) {
            $ins_data = array();
            foreach ($data as $d) {
                $tmp['base_currency_id'] = Exchange::detectCoinIdByName($d['baseCurrencyName'], $this->exchange_id);
                //Exceptions
                if($d['baseCurrencyName'] == "STR") {
                    $tmp['base_currency_id'] = 512;
                }
                $tmp['quote_currency_id'] = Exchange::detectCoinIdByName($d['quoteCurrencyName'], $this->exchange_id);
                switch($d['state']) {
                    case 'NORMAL':
                        $tmp['active'] = 1;
                        break;
                    default:
                        $tmp['active'] = 0;
                }
                $tmp['min_order_amount'] = $d['symbolTradeLimit']['minQuantity'];
                $tmp['min_order_quote_amount'] =  $d['symbolTradeLimit']['minAmount'];
                $exp = (int) $d['symbolTradeLimit']['priceScale'];
                $tmp['step_price'] = pow(10, (-1) * $exp);
                $exp2 = (int) $d['symbolTradeLimit']['amountScale'];
                $tmp['step_volume'] =  pow(10, (-1) * $exp2);
                $ins_data[] = $tmp;
                /*if(empty($tmp['base_currency_id'])) {
                    echo $d['trading_name'].'/'.$d['pricing_name'].' -- '.$tmp['base_currency_id'].'-'.$tmp['quote_currency_id'];
                    echo "<br>";                
                }*/
            }
                
                $st = $DB->startTransaction();
                if($st) {
                    foreach ($ins_data as $d2) {
                        if($d2['base_currency_id'] && $d2['quote_currency_id']) {
                            $base_currency_id = $d2['base_currency_id'];
                            $quote_currency_id = $d2['quote_currency_id'];
                            $status = $d2['active'];
                            $min_order_amount = $d2['min_order_amount'];
                            $min_order_quote_amount = $d2['min_order_quote_amount'];
                            $step_price = $d2['step_price'];
                            $step_volume = $d2['step_volume'];

                            $sql = 'INSERT INTO `SPOT_TRADE_PAIR` (`BASE_CURRENCY_ID`,`QUOTE_CURRENCY_ID`,`EXCHANGE_ID`,`ACTIVE`,`MIN_ORDER_AMOUNT`,`MIN_ORDER_QUOTE_AMOUNT`,`STEP_PRICE`,`STEP_VOLUME`) VALUES(?,?,?,?,?,?,?,?) '
                                    . 'ON DUPLICATE KEY UPDATE `BASE_CURRENCY_ID`=?,`QUOTE_CURRENCY_ID`=?,`EXCHANGE_ID`=?,`ACTIVE`=?, `MODIFY_DATE`=NOW(),`MIN_ORDER_AMOUNT`=?, `MIN_ORDER_QUOTE_AMOUNT`=?,`STEP_PRICE`=?,`STEP_VOLUME`=?';
                            $bind = array();
                            $bind[0]['type'] = 'i';
                            $bind[0]['value'] = $base_currency_id;
                            $bind[1]['type'] = 'i';
                            $bind[1]['value'] = $quote_currency_id;
                            $bind[2]['type'] = 'i';
                            $bind[2]['value'] = $this->exchange_id;
                            $bind[3]['type'] = 'i';
                            $bind[3]['value'] = $status;
                            $bind[4]['type'] = 'd';
                            $bind[4]['value'] = $min_order_amount;
                            $bind[5]['type'] = 'd';
                            $bind[5]['value'] = $min_order_quote_amount;
                            $bind[6]['type'] = 'd';
                            $bind[6]['value'] = $step_price;
                            $bind[7]['type'] = 'd';
                            $bind[7]['value'] = $step_volume;
                            $bind[8]['type'] = 'i';
                            $bind[8]['value'] = $base_currency_id;
                            $bind[9]['type'] = 'i';
                            $bind[9]['value'] = $quote_currency_id;
                            $bind[10]['type'] = 'i';
                            $bind[10]['value'] = $this->exchange_id;
                            $bind[11]['type'] = 'i';
                            $bind[11]['value'] = $status;
                            $bind[12]['type'] = 'd';
                            $bind[12]['value'] = $min_order_amount;
                            $bind[13]['type'] = 'd';
                            $bind[13]['value'] = $min_order_quote_amount;
                            $bind[14]['type'] = 'd';
                            $bind[14]['value'] = $step_price;
                            $bind[15]['type'] = 'd';
                            $bind[15]['value'] = $step_volume;

                            $ins = $DB->insert($sql,$bind);
                            if(!empty($DB->getLastError())) {
                                $DB->rollbackTransaction();
                                Log::systemLog('error', $DB->getLastError());
                                return false;
                            }
                        }
                    }
                    $ok = $DB->commitTransaction(); 
                    
                    //Delist old pairs
                    Exchange::delistSpotTradePair(5);
                    return true;
                }
                else {
                    Log::writeLog('error', 'Error start transaction');
                    return false;
                }
        }
        else {
            Log::systemLog('error', 'Error syncSpotAllTradePair() Poloniex. Return code='.$data['code']);
            return false;
        }
    }
    public function requestSpotTradeFee($pair_id) {
        $pair = Exchange::detectNamesPair($pair_id);
        //Prepare params
        $header = $this->sign('GET','/feeinfo');
        //Request
        $json_fee = $this->request($this->base_url.'/feeinfo', '', 'GET', $header);

        if($json_fee) {
            $fee = json_decode($json_fee,true);  
            if(isset($fee['makerRate']) && isset($fee['takerRate'])) {
                $taker_fee = (float)$fee['makerRate'];
                $maker_fee = (float)$fee['takerRate'];
                return array(
                    "taker_fee" => $taker_fee,
                    "maker_fee" => $maker_fee
                );
            }
            else {
                Log::systemLog('error', 'Error request market fee Poloniex for '.$pair.' '.$json_fee);
                $this->lastError = 'Error request market fee Poloniex for '.$pair.' '.$json_fee;
            }
        }
        else {
            Log::systemLog('error', 'Error request market fee Poloniex for '.$pair);
            $this->lastError = 'Error request market fee Poloniex for '.$pair;
        }
        
        return false;
    }
    public function updateCoinsInfoData() {
        global $DB;
        
        $json_coins = $this->request($this->base_url.'/v2/currencies');
        if(empty($json_coins)) {
            Log::systemLog('error', 'Error request Coin info for Poloniex is failed');
            $this->lastError = 'Error request Coin info for Poloniex is failed';
            return false;
        }
        
        $coins = json_decode($json_coins,true);
        
        if(is_array($coins)) {
            $data = array();
            foreach ($coins as $c) {
                $tmp = array();
                $flag_ignore = false;
                
                if($c['delisted'] == false) {
                    $c_id = false;
                    $coin_id = false;
                    
                    switch($c['coin']) {
                        case 'ACH1':
                            $c_id = 5465;
                            break;
                        case 'APX1':
                            $c_id = 28353;
                            break;
                        case 'BCHSV':
                            $c_id = 3602;
                            break;
                        case 'BNBDADDY':
                            $c_id = 23562;
                            break;
                        case 'GOLD1':
                            $c_id = 25;
                            break;
                        case 'HARRY':
                            $c_id = 15907;
                            break;
                        case 'LUK':
                            $c_id = 15786;
                            break;
                        case 'LUK':
                            $c_id = 27263;
                            break;
                        case 'ONEINCH':
                            $c_id = 8104;
                            break;
                        case 'PEPE2':
                            $c_id = 27276;
                            break;
                        case 'REPV2':
                            $c_id = 1104;
                            break;
                        case 'SHIB42069':
                            $c_id = 27930;
                            break;
                        case 'TISM':
                            $c_id = 25263;
                            break;
                        case 'TOKAMAK':
                            $c_id = 6731;
                            break;
                        case 'WLUNA':
                            $c_id = 11178;
                            break;
                        case 'WSTUSDT':
                            $c_id = 24755;
                            break;
                        case 'XRP8':
                            $c_id = 27809;
                            break;
                        case 'RLTM':
                            $c_id = 24106;
                            break;
                        case 'BRCBTCS':
                        case 'BRCSHIB':
                        case 'FTD':
                        case 'ETHOLD':
                        case 'ETHPEPE2':
                        case 'FCT2':                                
                        case 'FCD':                                
                        case 'OCISLY':                                
                        case 'OPOS':                                
                        case 'REKT2':                                
                        case 'USDTEARN1':                                
                        case 'XTOKEN':
                        case 'NATI':   
                        case 'PEPE20':   
                        case 'WSTREETBABY':   
                            $coin_id = -1;
                            $c_id = -1;
                            break;
                        default:
                    }

                    if(!$c_id) {
                        $coin_id = Exchange::detectCoinIdByName($c['coin'],5);
                    }
                    elseif ($c_id > 0) {
                        $coin_id = $c_id;
                    }
                    
                    if($coin_id && $coin_id > 0) {
                        if(is_array($c['networkList'])) {
                            foreach ($c['networkList'] as $n) {
                                switch($n['blockchain']) {
                                    case 'ETH':
                                        $n['blockchain'] = 'ERC20';
                                        break;
                                    case 'TRX':
                                        $n['blockchain'] = 'TRC20';
                                        break;
                                    case 'ETHARB':
                                        $n['blockchain'] = 'ARBITRUM';
                                        break;
                                    case 'BNB':
                                        $n['blockchain'] = 'BEP2';
                                        break;
                                    case 'APT':
                                        $n['blockchain'] = 'APTOS';
                                        break;
                                    case 'ETHOP':
                                        $n['blockchain'] = 'OPTIMISM';
                                        break;
                                    case 'MATICPOLY':
                                        $n['blockchain'] = 'MATIC';
                                        break;
                                    case 'ENJ':
                                        $n['blockchain'] = 'ENJIN';
                                        break;
                                    case 'ETHW':
                                        $n['blockchain'] = 'ETHPOW';
                                        break;
                                    case 'SBD':
                                        $n['blockchain'] = 'STEEM'; //??
                                        break;
                                    case 'ETHZKSYNC':
                                        $n['blockchain'] = 'ZKSYNC'; 
                                        break;
                                    case 'XEM':
                                        $n['blockchain'] = 'NEM'; 
                                        break;
                                    default:
                                }
                                $chain_id = Exchange::detectChainByName($n['blockchain']); 
                                $tmp = array();
                                if($coin_id > 0 && $chain_id > 0) {
                                    $tmp['coin_id'] = $coin_id;
                                    $tmp['chain_id'] = $chain_id;
                                    $tmp['deposit_active'] = (bool)$n['depositEnable'];
                                    $tmp['withdrawal_active'] = (bool)$n['withdrawalEnable'];
                                    $tmp['precission'] = $n['decimals'];
                                    $tmp['deposit_min'] = 0;
                                    $tmp['withdrawal_min'] = (float)(-1)?0:$n['withdrawMin'];
                                    $tmp['withdrawal_fee'] = floatval($n['withdrawFee']);
                                    $data[] = $tmp;
                                }
                            }
                        }
                    }
                    
                    if($coin_id == false) {
                        Log::systemLog('warn', 'Exchange Poloniex failed detect coin '.$c['coin']);
                        $this->lastError = 'Exchange Poloniex failed detect coin '.$c['coin'];
                    }
                    if($chain_id == false) {
                        Log::systemLog('warn', 'Exchange Poloniex failed detect chain by name '.$n['blockchain']);
                        $this->lastError = 'Exchange Poloniex failed detect chain by name '.$n['blockchain'];
                    }
                }   
            }
            if(count($data) > 0) {
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
        }
        else {
            Log::systemLog('error', 'Exchange Poloniex for request by coins return failed message');
            $this->lastError = 'Exchange Poloniex for request by coins return failed message';
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
            Log::systemLog('error', 'Error request K-Line Poloniex for '.$pair.'. Return code '.$kline['code']);
            $this->lastError = 'Error request K-Line Poloniex for '.$pair.'. Return code '.$kline['code'];
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
                            CONCAT(bc.SYMBOL,"_",qc.SYMBOL) AS NAME,
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
            $this->lastError = 'Error select trade pair name Poloniex';
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
                        break;
                    }
                }
            }
        }
        return $src;
    }
    public function isEnableWebsocket() {
        return true;
    }
    public function isNeedPingWebsocket() {
        return true;
    }
    public function webSocketConnect($type=false) {
        //$type - private or public
        $postfix_url = '';
        switch($type) {
            case 'orderbook':
            default:
                $postfix_url = 'public';
                break;
        }
        $options = array_merge([
            'uri'           => $this->websocket_url.$postfix_url,
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
        Log::systemLog('error', 'ERROR CONNECT to Poloniex exchange proc='. getmypid().'',$src);
        return false;
    }
    public function getWebSoketCount() {
        $c = $this->websoket_count;
        $this->websoket_count++;
        return $c;
    }
    public function webSocketPing($client_ws) {
        //$c = $this->getWebSoketCount();
        $client_ws->text('{
          "event":"ping"
        }');
        return true;
    }
    public function webSocketParse($receive) {
        $r = json_decode($receive, JSON_OBJECT_AS_ARRAY);
        $ret = array();
        //Success result
        if(isset($r['event'])) {
            if($r['event'] == 'subscribe') {
                $ret['method'] = 'queryResponse';
                $ret['status'] = 1;
            }
            if($r['event'] == 'pong') {
                $ret['method'] = 'pong';
                $ret['status'] = 1;
            }
            return $ret;
        }
        
        //stream
        if(!isset($r['event']) && isset($r['channel']) && isset($r['data'])) {
            switch ($r['channel']) {
                case 'book':
                default:
                    $ret['method'] = 'depth';
                    //parse data
                    $tmp = array();
                    $tmp['diff'] = false;
                    $tmp['pair'] = $r['data'][0]['symbol'];
                    $tmp['asks'] = $r['data'][0]['asks'];
                    $tmp['bids'] = $r['data'][0]['bids'];
                    $tmp['last_price'] = null;
                    $tmp['timestamp'] = $r['data'][0]['ts']*1E3;
                    $ret['data'][] = $tmp;                    
                    $ret['id'] = (int)$r['data'][0]['id'];
            }
            return $ret;
        }
        return false;
    }
    public function webSocketMultiSubsribeDepth($client_ws, $data, $previous=false) {
        //$previous not need for Poloniex
        //$c = $this->getWebSoketCount();
        if(!empty($data)) {
            $msg = array();
            $msg['event'] = "subscribe";           
            $msg['channel'] = array("book");
            $tmp = array();
            if(is_array($data)) {
                foreach ($data as $dd) {
                    $tmp[] = $dd['name'];
                }
            }    
            $msg['symbols'] = $tmp;
            $msg['depth'] = 5;
            $msg_json = json_encode($msg);
            if(empty($tmp)) {
                Log::systemLog('error', 'Echange order book process = '. getmypid().' Subscribe data is empty', "Order Book");
                return false;
            }
            
            $client_ws->text($msg_json);
            Log::systemLog('debug', 'Echange order book process = '. getmypid().' subscribe msg='.$msg_json, "Order Book");
            return true;
        }
        Log::systemLog('error', 'Echange order book process = '. getmypid().' Subscribe data error', "Order Book");
        return false;
    }
    public function restMarketDepth ($symbol, $merge="0", $limit= 5) {
        $str = "scale=".$merge."&limit=".$limit;
        $json_response = $this->request($this->base_url.'/markets/'.$symbol.'/orderBook', $str, 'GET');
        if(empty($json_response)) {
            Log::systemLog('error', 'Error request Poloniex Market Depth for '.$symbol);
            $this->lastError =  'Error request Poloniex Market Depth for '.$symbol;
            return false;
        }
        $r = json_decode($json_response,JSON_OBJECT_AS_ARRAY);
        if(!isset($r['time'])) {
            Log::systemLog('error', 'Error request Poloniex Market Depth for '.$symbol);
            $this->lastError = 'Error request Poloniex Market Depth for '.$symbol;
            return false;
        }
        return $json_response;
    }
    public function restMarketDepthParse($receive) {
        if(!empty($receive)) {
            $r = json_decode($receive, JSON_OBJECT_AS_ARRAY);
            $ret = array();
            if(is_array($r)) {
                if(isset($r['time']) && !empty($r['time'])) {
                    $ret['method'] = 'depth';
                    $tmp = array();
                    $a = count($r['asks']);
                    for($i=0;$i<($a-1);$i++) {
                        if(($i%2) == 0) {
                            $t = array();
                            $t[] = $r['asks'][$i];
                            $t[] = $r['asks'][$i+1];
                            $tmp['asks'][] = $t;
                        }
                    }
                    $b = count($r['bids']);
                    for($i=0;$i<($b-1);$i++) {
                        if(($i%2) == 0) {
                            $t = array();
                            $t[] = $r['bids'][$i];
                            $t[] = $r['bids'][$i+1];
                            $tmp['bids'][] = $t;
                        }
                    }                    
                    $tmp['diff'] = false;
                    $tmp['pair'] = false;
                    $tmp['last_price'] = false;
                    $tmp['timestamp'] = $r['ts']*1E3;
                    $ret['data'][] = $tmp;                    
                    $ret['id'] = 0;
                    return $ret;
                }
            }
        }
        Log::systemLog('error', 'Error parse Poloniex Market Depth '.$receive);
        $this->lastError = 'Error parse Poloniex Market Depth for '.$receive;
        return false;
    }
}