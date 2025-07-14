<?php

class KuCoinFeatures implements ExchangeInterface {
    private $market = 'spot';
    private $base_url = '';
    private $websocket_url = '';
    private $websoket_count = 1;
    private $websoket_conn_id = '';
    
    public $lastError = '';
        
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

    public function getMarket() {
        return $this->market;
    }
    private function sign($path, $time, $body = '', $method = 'GET') {
        $body = is_array($body) ? json_encode($body) : $body; // Body must be in json format
        $what = $time.$method.$path.$body;
        $sign = base64_encode(hash_hmac("sha256", $what, $this->secret_key, true));
        return $sign;
    }
    private function getPassphrase() {
        return base64_encode(hash_hmac("sha256", $this->passphrase, $this->secret_key, true));
    }
            
            
    private function request($url, $param=false, $method = 'GET', $header = array()) {
        global $Daemon;
        $ch = curl_init();
        curl_setopt ($ch,  CURLOPT_SSLVERSION, 6);
        if($method == 'GET') {
            curl_setopt ($ch, CURLOPT_URL,$url."?".$param);
        }
        else {
            curl_setopt ($ch, CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_POST, true);
        }
        curl_setopt ($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt ($ch, CURLOPT_FAILONERROR, false);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 2);  

        $headers = array(
           /* 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X x.y; rv:42.0) Gecko/20100101 Firefox/42.0',*/
            'Content-Type: application/json'
        );
        if($header) {
            if(is_array($headers) && is_countable($header) > 0) {
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
    
    public function syncSpotAllTradePair() {
        global $DB;

        //1. Get Request
        $json_data = $this->request($this->base_url.'/symbols');
        if(!$json_data) {
            return false;
        }
        $data = json_decode($json_data,true);
        
        if($data['code'] == 200000) {
            if(count($data['data'])>0) {
                $ins_data = array();
                foreach ($data['data'] as $d) {
                    $tmp['base_currency_id'] = Exchange::detectCoinIdByName($d['baseCurrency'], $this->exchange_id);
                    $tmp['quote_currency_id'] = Exchange::detectCoinIdByName($d['quoteCurrency'], $this->exchange_id);
                    switch ($d['enableTrading']) {
                        case 'true':
                            $tmp['active'] = 1;
                            break;
                        default :
                            $tmp['active'] = 0;
                    }
                    $ins_data[] = $tmp;
                    /*if(empty($tmp['base_currency_id'])) {
                        echo $d['baseCurrency'].'/'.$d['quoteCurrency'].' -- '.$tmp['base_currency_id'].'-'.$tmp['quote_currency_id'];
                        echo "<br>";                
                        echo PHP_EOL;
                    }*/
                }
                $st = $DB->startTransaction();
                if($st) {
                    foreach ($ins_data as $d2) {
                        if($d2['base_currency_id'] && $d2['quote_currency_id']) {
                            $base_currency_id = $d2['base_currency_id'];
                            $quote_currency_id = $d2['quote_currency_id'];

                            $sql = 'INSERT INTO `SPOT_TRADE_PAIR` (`BASE_CURRENCY_ID`,`QUOTE_CURRENCY_ID`,`EXCHANGE_ID`,`ACTIVE`) VALUES(?,?,?,?) '
                                    . 'ON DUPLICATE KEY UPDATE `BASE_CURRENCY_ID`=?,`QUOTE_CURRENCY_ID`=?,`EXCHANGE_ID`=?,`ACTIVE`=?, `MODIFY_DATE`=NOW()';
                            $bind = array();
                            $bind[0]['type'] = 'i';
                            $bind[0]['value'] = $base_currency_id;
                            $bind[1]['type'] = 'i';
                            $bind[1]['value'] = $quote_currency_id;
                            $bind[2]['type'] = 'i';
                            $bind[2]['value'] = $this->exchange_id;
                            $bind[3]['type'] = 'i';
                            $bind[3]['value'] = $d2['active'];
                            $bind[4]['type'] = 'i';
                            $bind[4]['value'] = $base_currency_id;
                            $bind[5]['type'] = 'i';
                            $bind[5]['value'] = $quote_currency_id;
                            $bind[6]['type'] = 'i';
                            $bind[6]['value'] = $this->exchange_id;
                            $bind[7]['type'] = 'i';
                            $bind[7]['value'] = $d2['active'];

                            $ins = $DB->insert($sql,$bind);
                            if(!empty($DB->getLastError())) {
                                $DB->rollbackTransaction();
                                Log::systemLog('error', $DB->getLastError());
                                return false;
                            }
                        }
                    }
                    $ok = $DB->commitTransaction(); 
                    Exchange::delistSpotTradePair(2);
                    return true;
                }
                else {
                    Log::systemLog('error', 'Error start transaction');
                    return false;
                }
            }
            else {
                Log::systemLog('error', 'Error syncSpotAllTradePair() KuCoin. Return data is empty');
                return false;
            }
        }
        else {
            Log::systemLog('error', 'Error syncSpotAllTradePair() KuCoin. Return code='.$data['code']);
            return false;
        }
    }
    
    public function requestSpotTradeFee($pair_id) {
        $pair = Exchange::detectNamesPair($pair_id);
        //Request to KuCoin exchange
        //GET /api/v1/trade-fees?symbols=BTC-USDT,KCS-USDT
        $str_pair = str_replace("/","-",$pair);
        $url = $this->base_url.'/trade-fees'.'?symbols='.$str_pair;
        $arr_url = parse_url($url);
        $query_string = $arr_url['path'].'?'.$arr_url['query'];

        $time = $this->getTimestamp();
        $header = array(
            "KC-API-KEY"=>$this->api_key,                        //"KC-API-KEY"=>$this->api_key
            "KC-API-SIGN"=>$this->sign($query_string,$time),     //The base64-encoded signature (see Signing a Message).
            "KC-API-TIMESTAMP"=>$time,                           //A timestamp for your request.
            "KC-API-PASSPHRASE"=>$this->getPassphrase(),         //The passphrase you specified when creating the API key.
            "KC-API-KEY-VERSION"=>'3'                            //You can check the version of API key on the page of API Management
        );
        //Log::systemLog('error', 'PAIR='.$pair .' PARAM ='. json_encode($header));
        
        $json_fee = $this->request($this->base_url.'/trade-fees', 'symbols='.$str_pair, 'GET', $header);
        
        if($json_fee) {
            $fee = json_decode($json_fee,true);
            if($fee['code'] == "200000") {
                $taker_fee = (float)$fee['data'][0]['takerFeeRate'];
                $maker_fee = (float)$fee['data'][0]['makerFeeRate'];
                return array(
                    "taker_fee" => $taker_fee,
                    "maker_fee" => $maker_fee
                );
            }
            else {
                Log::systemLog('error', 'Error request market fee KuCoin for '.$pair.' '.$json_fee, 'Service');
                $this->lastError = 'Error request market fee KuCoin for '.$pair.' '.$json_fee;
            }
        }
        else {
            Log::systemLog('error', 'Error request market fee Kucoin for '.$pair, 'Service');
            $this->lastError = 'Error request market fee Kukoin for '.$pair;
        }
        return false;
        
    }
    public function updateCoinsInfoData() {
        global $DB;
        
        $json_coins = $this->request(preg_replace("/v1/",'v3',$this->base_url).'/currencies');
        if(empty($json_coins)) {
            Log::systemLog('error', 'Error request Coin info for KuCoin is failed','Service');
            $this->lastError = 'Error request Coin info for KuCoin is failed';
            return false;
        }
        
        $coins = json_decode($json_coins,true);
        
        if(isset($coins['code'])) {
            if($coins['code'] == '200000' && !empty($coins['data'])) {
                $data = array();
                foreach($coins['data'] as $cd) {
                    $tmp = array();
                    $flag_ignore = false;
                    $c_id = false;
                    switch($cd['name']) {
                        case 'AMIO':
                            $cd['name'] = 'AMO';
                            break;
                        case 'WAXP0':
                            $cd['name'] = 'WAXP';
                            break;
                        case 'KTSt':
                            $cd['name'] = 'KTS';
                            break;  
                        case 'MODEFI':
                            $cd['name'] = 'MOD';
                            break;
                        case 'AXC':
                            $cd['name'] = 'AXIA';
                            break; 
                        case 'KDON':
                            $cd['name'] = 'DON';
                            break; 
                        case 'CHSB':
                            $cd['name'] = 'BORG';
                            break; 
                        case 'FLIP':
                            $c_id = 2707;
                            break;
                        case 'RLTM':
                            $c_id = 24106;
                            break;
                        /*case 'KPOL':
                        case 'VRAB': 
                        case 'VI':  
                        case 'KCANDY':
                        case 'TNC2':
                        case 'USDN':   
                        case 'PAZZI': 
                        case 'BCHA':
                        case 'ksETH':
                        case 'BTMX':
                        case 'SPI':
                        case 'GSPI':
                        case 'LNCHX':
                        case 'ROSN':
                        case 'ZORT':
                        case 'MUSH':
                        case 'ACAT':
                        case 'ARN':
                        case 'APH':
                        case 'ARY':
                        case 'BCHABC':
                        case 'CAG':
                        case 'CFD':
                        case 'ORDIDOWN': 
                        case 'ORDIUP': 
                        case 'LOOMDOWN': 
                        case 'LOOMUP': 
                        case 'STORJDOWN': 
                        case 'STORJUP': 
                        case 'VRADOWN': 
                        case 'VRAUP': 
                        case 'GLMRDOWN': 
                        case 'GLMRUP':
                        case 'FRONTDOWN': 
                        case 'FRONTUP': 
                        case 'TRBDOWN': 
                        case 'TRBUP': 
                        case 'UNFIDOWN':
                        case 'UNFIUP': 
                        case 'PERPDOWN': 
                        case 'PERPUP': 
                        case 'WLDDOWN': 
                        case 'WLDUP':
                        case 'MKRDOWN': 
                        case 'MKRUP': 
                        case 'COMPDOWN': 
                        case 'COMPUP': 
                        case 'ALGODOWN':
                        case 'ALGOUP': 
                        case 'OCEANDOWN': 
                        case 'OCEANUP':
                        case 'FETDOWN': 
                        case 'FETUP': 
                        case 'ROSEDOWN': 
                        case 'ROSEUP': 
                        case 'ARPADOWN':  
                        case 'ARPAUP': 
                        case 'LUNADOWN': 
                        case 'LUNAUP':  
                        case 'FILDOWN': 
                        case 'FILUP': 
                        case 'WOODOWN':  
                        case 'ZILDOWN': 
                        case 'WOOUP': 
                        case 'ZILUP': 
                        case 'LUNCDOWN': 
                        case 'LUNCUP': 
                        case 'KAVADOWN':  
                        case 'KAVAUP': 
                        case 'FLOKIDOWN': 
                        case 'FLOKIUP': 
                        case 'PEPEDOWN': 
                        case 'PEPEUP': 
                        case 'ICPDOWN':  
                        case 'ICPUP': 
                        case 'CTSIDOWN': 
                        case 'CTSIUP': 
                        case 'ETCDOWN': 
                        case 'ETCUP': 
                        case 'INJDOWN':  
                        case 'INJUP': 
                        case 'LINADOWN': 
                        case 'LINAUP': 
                        case 'STXDOWN': 
                        case 'STXUP': 
                        case 'RNDRDOWN': 
                        case 'RNDRUP': 
                        case 'DYDXDOWN': 
                        case 'DYDXUP':    
                        case 'MASKDOWN': 
                        case 'MASKUP': 
                        case 'SXPDOWN':   
                        case 'SXPUP': 
                        case 'DADI':*/
                        case 'SPRINT':
                        case 'AOS':
                        case 'P00LS':
                        case 'ASTROBOY':
                        case 'PIKASTER2':
                        case 'IDLENFT':   
                        case 'XRACER':  
                        case 'ELITEHERO':
                        case 'PIKASTER':   
                        case 'FORWARD':
                        case 'ksETH':
                            $flag_ignore = true;
                        default:
                    }
                    if(preg_match("/(3L)|(3S)|(2L)|(2S)$/",$cd['name'])) {
                        $flag_ignore = true;
                    }
                    
                    if($flag_ignore) {
                        $coin_id = -1;
                    }
                    else { 
                        if(!$c_id) {
                            $coin_id = Exchange::detectCoinIdByName($cd['name'],2);
                            if(!$coin_id) {
                                $active = false;
                                if(is_array($cd['chains'])) {
                                    foreach ($cd['chains'] as $ch_pre) {
                                        if((bool)$ch_pre['isDepositEnabled'] === true || (bool)$ch_pre['isWithdrawEnabled'] === true) {
                                            $active = true;
                                        }
                                    }
                                }
                                if($active === false) {
                                    $coin_id = -1;
                                }
                            }
                        }
                        else {
                            $coin_id = $c_id;
                        }
                    }
                    $precision = $cd['precision'];
                    if(is_array($cd['chains'])) {
                        foreach ($cd['chains'] as $ch) {
                            switch($ch['chainName']) {
                                case 'NIM':
                                    $ch['chainName'] = 'NIMIQ';
                                    break;
                                case 'WAXP0':
                                    $ch['chainName'] = 'WAXP';
                                    break;
                                case 'TERRA':
                                    $ch['chainName'] = 'LUNA';
                                    break;
                                case 'VET':
                                    $ch['chainName'] = 'VECHAIN';
                                    break;
                                case 'OASIS':
                                    $ch['chainName'] = 'OAS';
                                    break;
                                case 'DFI':
                                    $ch['chainName'] = 'DEFI';
                                    break;
                                case 'HRC20':
                                    $ch['chainName'] = 'HECO';
                                    break;
                                case 'AVAX C-Chain':
                                    $ch['chainName'] = 'AVAXC';
                                    break;
                                case 'KDA0':
                                    $ch['chainName'] = 'KDA';
                                    break;
                                case 'ERGO':
                                    $ch['chainName'] = 'ERG';
                                    break;
                                case 'BTC-Segwit':
                                    $ch['chainName'] = 'BECH32';
                                    break;
                                case 'NEON3':
                                    $ch['chainName'] = 'NEO3';
                                    break;
                                case 'APT':
                                    $ch['chainName'] = 'APTOS';
                                    break;
                                case 'bifrost':
                                    $ch['chainName'] = 'BNCDOT';
                                    break;
                                case 'Pastel':
                                    $ch['chainName'] = 'PSL';
                                    break;
                                case 'SYSNEVM':
                                    $ch['chainName'] = 'SYS';
                                    break;
                                case 'AKT':
                                    $ch['chainName'] = 'AKASH';
                                    break;
                                case 'ICON':
                                    $ch['chainName'] = 'ICX';
                                    break;
                                case 'Casper':
                                    $ch['chainName'] = 'CSPR';
                                    break;
                                case 'PIONEER':
                                    $ch['chainName'] = 'NEER'; 
                                    break;
                                case 'Ethereum Pow':
                                    $ch['chainName'] = 'ETHPOW'; 
                                    break;
                                case 'STRAX':
                                    $ch['chainName'] = 'STRATIS'; 
                                    break;
                                case 'ironfish':
                                    $ch['chainName'] = 'IRON'; 
                                    break;
                                case 'ZKS20':
                                    $ch['chainName'] = 'ZKSYNC'; 
                                    break;
                                case 'parallelchain':
                                    $ch['chainName'] = 'XPLL'; 
                                    break;
                                default:
                            }
                            if(empty($ch['chainName'])) {
                                continue;
                            }
                            $chain_id = Exchange::detectChainByName($ch['chainName']); 
                            $tmp = array();
                            if($coin_id > 0 && $chain_id > 0) {
                                $tmp['coin_id'] = $coin_id;
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
                                $data[] = $tmp;
                            }
                            else {
                                if($coin_id == false) {
                                    Log::systemLog('warn', 'Exchange KuCoin failed detect coin '.$cd['currency'], 'Service');
                                    $this->lastError = 'Exchange KuCoin failed detect coin '.$cd['currency'];
                                }
                                if($chain_id == false) {
                                    Log::systemLog('warn', 'Exchange KuCoin failed detect chain by name '.$ch['chainName'], 'Service');
                                    $this->lastError = 'Exchange KuCoin failed detect chain by name '.$ch['chainName'];
                                }
                            }
                        }
                    }
                }
                //
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
                        //update percent fee
                        /*if(isset($d['fee_percent']) && !empty($d['fee_percent'])) {
                            $sql3 = "UPDATE `WITHDRAWAL` SET `FEE_PERCENT` = ? WHERE `COIN_ID` = ?";
                            $bind3 = array();
                            $bind3[0]['type'] = 'd';
                            $bind3[0]['value'] = $d['fee_percent'];
                            $bind3[1]['type'] = 'i';
                            $bind3[1]['value'] = $d['coin_id'];
                            $upd = $DB->update($sql3,$bind3);
                            if(!$upd && !empty($DB->getLastError())) {
                                $DB->rollbackTransaction();
                                Log::systemLog('error', $DB->getLastError());
                            }
                        }*/
                    }
                    $DB->commitTransaction();
                    return true;
                }
            }
            else {
                Log::systemLog('error', 'Exchange KuCoin for request by coins return code '.$coins['code'], 'Service');
                $this->lastError = 'Exchange KuCoin for request by coins return code '.$coins['code'];
            }
        }
        else {
            Log::systemLog('error', 'Exchange KuCoin for request by coins return failed message', 'Service');
            $this->lastError = 'Exchange KuCoin for request by coins return failed message';
        }
        return false;
    }
    private function getTimestamp () {
        $time = new DateTime("now",new DateTimeZone('UTC'));
        return $time->format('U')*1000;
    }
    private function getTimestampU () {
        $time = new DateTime("now",new DateTimeZone('UTC'));
        return $time->format('Uu');
    }
    public function getLastError(){
        return $this->last_erorr;
    }
    
    public function getKLine($pair,$timeframe) {
        $limit = 1000;
        //https://api.kucoin.com/api/v1/market/candles?type=1min&symbol=BTC-USDT&startAt=1566703297&endAt=1566789757
        $pair = preg_replace("/\//",'-',$pair);
        $time_now = new DateTime(null);
        $end = (int)$time_now->format('U');
        $start = 0;
        switch ($timeframe) {
            case '1min':
                $start = $end - 60000;    
                break;
            case '3min':
                $start = $end - 180000;  
                break;
            case '5min':
                $start = $end - 300000;  
                break;
            case '15min':
                $start = $end - 900000;  
                break;
            case '30min':
                $start = $end - 1800000;  
                break;
            case '1hour':
                $start = $end - 3200000;  
                break;
            case '2hour':
                $start = $end - 6400000;  
                break;
            case '4hour':
                $start = $end - 12800000;  
                break;
        }
        $str = 'symbol='.$pair.'&type='.$timeframe.'&startAt='.$start.'&endAt='.$end;
        $json_kline = $this->request($this->base_url.'/market/candles', $str, 'GET');
        if(empty($json_kline)) {
            Log::systemLog('error', 'Error request K-Line Kukoin for '.$pair);
            $this->lastError = 'Error request K-Line Kukoin for '.$pair;
            return false;
        }
        $kline = json_decode($json_kline,true);
        if($kline['code'] != '200000') {
            Log::systemLog('error', 'Error request K-Line KuCoin for '.$pair.'. Return code '.$kline['code']);
            $this->lastError = 'Error request K-Line KuCoin for '.$pair.'. Return code '.$kline['code'];
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
                            CONCAT(bc.SYMBOL,"-",qc.SYMBOL) AS NAME,
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
    public function isEnableWebsocket() {
        return true;
    }
    public function isNeedPingWebsocket() {
        return true;
    }
    public function webSocketConnect($type=false) {
        switch($type) {
            case 'orderbook': 
                $src = 'Order Book';
                break;
            default:
                $src = '';
        }
        //First get token
        $json_data = $this->request($this->base_url.'/bullet-public', false, 'POST');
        if($json_data) {
            $data = json_decode($json_data,JSON_OBJECT_AS_ARRAY);
            if(isset($data['code'])){
                if($data['code'] == '200000') {
                    $this->websocket_url = $data['data']['instanceServers'][0]['endpoint'];
                    $token = $data['data']['token'];
                    //var socket = new WebSocket("wss://ws-api-spot.kucoin.com/?token==xxx&[connectId=xxxxx]");
                    $options = array_merge([
                        'uri'           => $this->websocket_url.'?token='.$token,
                        'timeout'       => 2,
                        'fragment_size' => 1024,
                        'filter'        => array('text','ping','pong','close'),
                        'persistent'    => true,
                    ], getopt('', ['uri:', 'timeout:', 'fragment_size:', 'debug', 'filter:','persistent:']));

                    $client = new \WebSocket\Client($options['uri'], $options);
                    if($client) {
                        try {
                            $receive = $client->receive();
                            $r = json_decode($receive, JSON_OBJECT_AS_ARRAY);
                            if(isset($r['id'])) {
                                $this->websoket_conn_id = $r['id'];
                            }
                            Log::systemLog('debug', 'Child Order Book proc='. getmypid().' Connect to exchange KuCoin is true',$src);
                            return $client;
                        }
                        catch (\WebSocket\TimeoutException $e) {
                            $src = '';
                            Log::systemLog('error', 'ERROR CONNECT to KuCoin exchange proc='. getmypid().'',$src);
                        }
                    }
                    return false;
                }
                else {
                    Log::systemLog('error', 'Child Order Book proc='. getmypid().' Error request connection token. Response code='.$json_data['code'],$src);
                    return false;
                }
            }
            else {
                Log::systemLog('error', 'Child Order Book proc='. getmypid().' Error request connection token. Faliled response json',$src);
                return false;
            }
        }
        else {
            Log::systemLog('error', 'Child Order Book proc='. getmypid().' Error request connection token',$src);
            return false;
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
          "id": '.$c.',
          "type":"ping"
        }');
        return true;
    }
    public function webSocketParse($receive) {
        $r = json_decode($receive, JSON_OBJECT_AS_ARRAY);
        $ret = array();
        //Welcome
        if(isset($r['type']) && $r['type'] == 'welcome') {
            $ret['method'] = 'queryResponse';
            $ret['status'] = 1;
            $ret['id'] = $this->getWebSoketCount();
            return $ret;
        }
        //ack - response good
        if(isset($r['type']) && $r['type'] == 'ack') {
            $ret['method'] = 'queryResponse';
            $ret['status'] = 1;
            $ret['id'] = (int)$r['id'];
            return $ret;
        }
        //PONG
        if(isset($r['type']) && $r['type'] == 'pong') {
            $ret['method'] = "pong";
            $ret['status'] = 1;
            $ret['id'] = (int)$r['id'];
            return $ret;
        }
        //responce depth5 - best price
        if(isset($r['type']) && $r['type'] == 'message') {
            if(isset($r['subject']) && isset($r['topic'])) {
                if($r['subject'] == 'level2' && strstr($r['topic'], '/spotMarket/level2Depth5')) {
                    $ret['method'] = 'depth';
                    $tmp = array();
                    $tmp['diff'] = false;
                    $topic_arr = explode(":", $r['topic']);
                    $tmp['pair'] = $topic_arr[1];
                    $tmp['asks'] = $r['data']['asks'];
                    $tmp['bids'] = $r['data']['bids'];
                    $tmp['last_price'] = null;
                    $tmp['price_timestamp'] = $r['data']['timestamp']*1E3;
                    $tmp['timestamp'] = $this->getTimestampU();
                    $ret['id'] = null;
                    $ret['data'][] = $tmp;   
                    return $ret;
                }
                if($r['subject'] == 'level1' && strstr($r['topic'], '/spotMarket/level1')) {
                    $ret['method'] = 'bbo';
                    $tmp = array();
                    $topic_arr = explode(":", $r['topic']);
                    $tmp['pair'] = $topic_arr[1];
                    $tmp['ask_price'] = $r['data']['asks'][0]; 
                    $tmp['ask_volume'] = $r['data']['asks'][1];
                    $tmp['bid_price'] = $r['data']['bids'][0]; 
                    $tmp['bid_volume'] = $r['data']["bids"][1];
                    $tmp['price_timestamp'] = $r['data']['timestamp']*1E3;
                    $tmp['timestamp'] = $this->getTimestampU();
                    $ret['data'][] = $tmp;                    
                    $ret['id'] = null;
                    return $ret;
                }
            }
        }
        return false;
    }
    public function webSocketMultiSubsribeDepth($client_ws, $data, $previous=false) {
        $c = $this->getWebSoketCount();
        $msg = array();
        if(!empty($data)) {
            //unsubscribe
            if($previous !== false) {
                $msg = array();
                $msg['id'] = $c;
                $msg['type'] = "unsubscribe";
                $tiker = '/spotMarket/level2Depth50:';
                $i=0;
                $found_somthing = false;
                foreach ($previous as $od) {
                    $found = false;
                    foreach ($data as $d) {
                        if($d['id'] == $od['id']) {
                            $found = true;
                        }
                    }
                    if($found === false) {
                        if($i > 0) {
                            $tiker .= ',';
                        }
                        $tiker .= $od['name'];
                        $found_somthing = true;
                        $i++;
                    }
                }
                $msg['topic'] = $tiker;
                $msg['privateChannel'] = false;
                $msg['response'] = true;
                if($found_somthing == true) {
                    $msg_json = json_encode($msg);
                    Log::systemLog('debug', 'Child Order Book proc='. getmypid().' unsubscribe MSG '.$msg_json, "Order Book");
                    $client_ws->text($msg_json);
                }
            }
            //subscribe
            $c = $this->getWebSoketCount();
            $msg = array();
            $msg['id'] = $c;
            $msg['type'] = "subscribe";
            //$tiker = '/market/level2:';
            $tiker = '/spotMarket/level2Depth5:';
            $i=0;
            foreach ($data as $d) {
                if($i > 0) {
                    $tiker .= ',';
                }
                $tiker .= $d['name'];
                $i++;
            }
            $msg['topic'] = $tiker;
            $msg['privateChannel'] = false;
            $msg['response'] = true;
            $msg_json = json_encode($msg);
            Log::systemLog('debug', 'Child Order Book proc='. getmypid().' subscribe MSG '.$msg_json, "Order Book");
            $client_ws->text($msg_json);
            return true;
        }
        Log::systemLog('error', 'Echange order book process = '. getmypid().' Subscribe data error', "Order Book");
        return false;
    }
    public function webSocketMultiSubsribeDepth5($client_ws, $data, $previous=false) {
        $c = $this->getWebSoketCount();
        $msg = array();
        if(!empty($data)) {
            //unsubscribe
            if($previous !== false) {
                $msg = array();
                $msg['id'] = $c;
                $msg['type'] = "unsubscribe";
                $tiker = '/spotMarket/level2Depth5:';
                $i=0;
                $found_somthing = false;
                foreach ($previous as $od) {
                    $found = false;
                    foreach ($data as $d) {
                        if($d['id'] == $od['id']) {
                            $found = true;
                        }
                    }
                    if($found === false) {
                        if($i > 0) {
                            $tiker .= ',';
                        }
                        $tiker .= $od['name'];
                        $found_somthing = true;
                        $i++;
                    }
                }
                $msg['topic'] = $tiker;
                $msg['privateChannel'] = false;
                $msg['response'] = true;
                if($found_somthing == true) {
                    $msg_json = json_encode($msg);
                    Log::systemLog('debug', 'Child Order Book proc='. getmypid().' unsubscribe MSG '.$msg_json, "Order Book");
                    $client_ws->text($msg_json);
                }
            }
            //subscribe
            $c = $this->getWebSoketCount();
            $msg = array();
            $msg['id'] = $c;
            $msg['type'] = "subscribe";
            //$tiker = '/market/level2:';
            $tiker = '/spotMarket/level2Depth5:';
            $i=0;
            foreach ($data as $d) {
                if($i > 0) {
                    $tiker .= ',';
                }
                $tiker .= $d['name'];
                $i++;
            }
            $msg['topic'] = $tiker;
            $msg['privateChannel'] = false;
            $msg['response'] = true;
            $msg_json = json_encode($msg);
            Log::systemLog('debug', 'Child Order Book proc='. getmypid().' subscribe MSG '.$msg_json, "Order Book");
            $client_ws->text($msg_json);
            return true;
        }
        Log::systemLog('error', 'Echange order book process = '. getmypid().' Subscribe data error', "Order Book");
        return false;
    }
    public function webSocketMultiSubsribeBBO($client_ws, $data, $previous=false) {
        $c = $this->getWebSoketCount();
        $msg = array();
        if(!empty($data)) {
            //unsubscribe
            if($previous !== false) {
                $msg = array();
                $msg['id'] = $c;
                $msg['type'] = "unsubscribe";
                $tiker = '/spotMarket/level1:';
                $i=0;
                $found_somthing = false;
                foreach ($previous as $od) {
                    $found = false;
                    foreach ($data as $d) {
                        if($d['id'] == $od['id']) {
                            $found = true;
                        }
                    }
                    if($found === false) {
                        if($i > 0) {
                            $tiker .= ',';
                        }
                        $tiker .= $od['name'];
                        $found_somthing = true;
                        $i++;
                    }
                }
                $msg['topic'] = $tiker;
                $msg['privateChannel'] = false;
                $msg['response'] = true;
                if($found_somthing == true) {
                    $msg_json = json_encode($msg);
                    Log::systemLog('debug', 'Child Order Book proc='. getmypid().' unsubscribe BBO MSG '.$msg_json, "Order Book");
                    $client_ws->text($msg_json);
                }
            }
            //subscribe
            $c = $this->getWebSoketCount();
            $msg = array();
            $msg['id'] = $c;
            $msg['type'] = "subscribe";
            //$tiker = '/market/level2:';
            $tiker = '/spotMarket/level1:';
            $i=0;
            foreach ($data as $d) {
                if($i > 0) {
                    $tiker .= ',';
                }
                $tiker .= $d['name'];
                $i++;
            }
            $msg['topic'] = $tiker;
            $msg['privateChannel'] = false;
            $msg['response'] = true;
            $msg_json = json_encode($msg);
            Log::systemLog('debug', 'Child Order Book proc='. getmypid().' subscribe BBO MSG '.$msg_json, "Order Book");
            $client_ws->text($msg_json);
            return true;
        }
        Log::systemLog('error', 'Echange order book process = '. getmypid().' Subscribe BBO data error', "Order Book");
        return false;
    }
    public function restMarketDepth ($symbol, $merge="0", $limit= 5) {
        $str = 'symbol='.$symbol;
        $json_response = $this->request($this->base_url.'/market/orderbook/level2_20', $str, 'GET');
        if(empty($json_response)) {
            Log::systemLog('error', 'Error request CoinEx Market Depth for '.$symbol);
            $this->lastError =  'Error request CoinEx Market Depth for '.$symbol;
            return false;
        }
        if(empty($json_response)) {
            Log::systemLog('error', 'Error request CoinEx Market Depth for '.$symbol.' Return false');
            $this->lastError = 'Error request CoinEx Market Depth for '.$symbol.' Return false ';
            return false;
        }
        return $json_response;
    }
    public function restMarketDepthParse($receive) {
        if(!empty($receive)) {
            $r = json_decode($receive, JSON_OBJECT_AS_ARRAY);
            $ret = array();
            if(is_array($r) && isset($r['code']) && $r['code'] == '200000') {
                $ret['method'] = 'depth';
                $tmp = array();
                $tmp['diff'] = false;
                $tmp['pair'] = false;
                $tmp['asks'] = array_slice($r['data']['asks'], 0, 5);
                $tmp['bids'] = array_slice($r['data']['bids'], 0, 5);
                $tmp['last_price'] = null;
                $tmp['timestamp'] = $r['data']['time']*1E3;
                $ret['id'] = null;
                $ret['data'][] = $tmp;   
                return $ret;
            }
        }
        Log::systemLog('error', 'Error parse KuCoin Market Depth '.$receive);
        $this->lastError = 'Error parse KuCoin Market Depth for '.$receive;
        return false;
    }
}