<?php

class Exbitron implements ExchangeInterface {
    private $exchange_id = 0;
    private $market = 'spot';
    private $name = '';
    
    private $account_id = 0;
    private $api_key = '';
    private $secret_key = '';
    private $passphrase = '';
    
    
    public $rest_request_freq = 1; //requests per second
    
    public function __construct($id,  $account_id, $market='spot') {
        global $DB, $User;
        $this->exchange_id = $id;
        $this->market = $market;
        $sql = "SELECT `NAME`, `BASE_URL` FROM `EXCHANGE` WHERE `ID`=? AND `ACTIVE`=1";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $id;
        $ret = $DB->select($sql,$bind);
        if(count($ret)>0) {
             $this->base_url = $ret[0]['BASE_URL'];
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
    private function sign() {
        
    }
    private function request($url,$param=false,$method = 'GET') {
        $ch = curl_init();
        curl_setopt ($ch,  CURLOPT_SSLVERSION, 6);
        curl_setopt ($ch, CURLOPT_URL,$url);
        //curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt ($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt ($ch, CURLOPT_FAILONERROR, false);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 2);  

        $headers = array(
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X x.y; rv:42.0) Gecko/20100101 Firefox/42.0',
            'Content-Type: application/json'
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1); 
        $result = curl_exec ($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
               
        if($http_code == 200){
            return $result;
        }
        else {
            Log::writeLog('error', 'Error get Acquire All Market Info from '.$this->name.' exchange. Error code. '.$http_code);
            $this->lastError = 'Error get Acquire All Market Info from '.$this->name.' exchange. Error code. '.$http_code;
            return false;
        }
    }
    public function syncSpotAllTradePair() {
        global $DB;
        //Check if need update
        $upd = Exchange::checkNeedUpdateSpotTradePair(3); 
        if($upd === false) {
            return true;
        }

        //1. Get Request
        $json_data = $this->request($this->base_url.'/api/v2/peatio/public/markets?limit=1000');
        if(!$json_data) {
            return false;
        }
        $data = json_decode($json_data,true);
        if(count($data)>0) {
            $ins_data = array();
            foreach ($data as $d) {
                $tmp['base_currency_id'] = Exchange::detectCoinIdByName($d['base_unit'],$this->exchange_id);
                $tmp['quote_currency_id'] = Exchange::detectCoinIdByName($d['quote_unit'],$this->exchange_id);
                switch ($d['state']) {
                    case 'enabled':
                        $tmp['active'] = 1;
                        break;
                    default :
                        $tmp['active'] = 0;
                }
                $ins_data[] = $tmp;
                   /* if(empty($tmp['base_currency_id']) || empty($tmp['quote_currency_id'])) {
                        echo $d['base_unit'].'/'.$d['quote_unit'].' -- '.$tmp['base_currency_id'].'-'.$tmp['quote_currency_id'];
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
                            Log::writeLog('error', $DB->getLastError());
                            $this->lastError = 'Error insert trade pair Exbitron';
                            return false;
                        }
                    }
                }
                $ok = $DB->commitTransaction(); 
                //Delist old pairs
                Exchange::delistSpotTradePair(3);
                return true;
            }
            else {
                Log::writeLog('error', 'Error start transaction');
                $this->lastError = 'Error start transaction';
                return false;
            }
        }
        else {
            Log::writeLog('error', 'Error syncSpotAllTradePair() Exbitron. Return data is empty');
            $this->lastError = 'Error syncSpotAllTradePair(). Return data is empty';
            return false;
        }
    }
    public function requestSpotTradeFee($pair) {
        return false;
    }
    public function updateCoinsInfoData() {
        
    }
    public function getKLine($pair,$timeframe) {
        
    }
    public function getTradePairName($pair,$market='spot') {
        
    }
    public function mergeTradePairData($src, $add_data) {
        
        return true;
    }
    public function isEnableWebsocket() {
        return false;
    }
    public function isNeedPingWebsocket() {
        return false;
    }
    public function webSocketConnect($type=false) {
        return false;
    }
    public function getWebSoketCount() {
        return false;
    }
    public function webSocketPing($client_ws) {
        return false;
    }
    public function webSocketParse($receive) {
        return false;
    }
    public function webSocketMultiSubsribeDepth($client_ws, $data) {
        
        return false;
    }
    public function restMarketDepth ($symbol, $merge="0", $limit= 5) {
        
        return false;
    }    
    public function restMarketDepthParse($receive) {
        
        return false;
    }
}