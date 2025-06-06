<?php

class TraderInstance {
    public $instance_id = 0;
    private $trader_id = 0;
    private $trader_type = 0;
    public $user_id = 0;
        
    public $account_id = 0;
    public $account_name = '';
    
    public $exchange_id = 0;
    public $exchange_name = '';
    public $market = '';
    public $pair_id = 0;
    public $pair_name = '';
    public $base_currency_id = 0;
    private $base_currency_name = '';
    public $quote_currency_id = 0;
    private $quote_currency_name = 0;
    
    public $start_amount_base = "0";
    public $start_amount_quote = "0";
    public $current_amount_base = "0";
    public $current_amount_quote = "0";
    
    public $taker_fee = "0";
    public $maker_fee = "0";
    public $min_order_amount = "0";
    public $min_order_quote_amount = "0";
    public $step_price = "0";
    public $step_volume = "0";
    public $min_delta_profit_sell = "0";
    public $chain_send_out = false;
    
    public $orderbook = array();
    public $bbo = array();
    private $orderbook_address = '';
    
    private $worker_address = '';
    
    public $deposit = array();
    public $withdrawal = array();
            
    function __construct($trader_id, $account_id, $market, $pair_id) {
        global $DB;
        $this->trader_id = $trader_id;

        $sql = "SELECT 
                    ea.ID AS ACCOUNT_ID,
                    ea.ACCOUNT_NAME AS ACCOUNT_NAME,
                    ea.UID AS USER_ID,
                    ea.EXID AS EXCHANGE_ID,
                    e.NAME AS EXCHANGE_NAME,
                    tsa.PAIR_ID AS PAIR_ID,
                    tsa.START_AMOUNT_BASE AS START_AMOUNT_BASE,
                    tsa.START_AMOUNT_QUOTE AS START_AMOUNT_QUOTE,
                    tsa.MIN_DELTA_PROFIT_SELL AS MIN_DELTA_PROFIT_SELL,
                    tsa.CHAIN_SEND_OUT AS CHAIN_SEND_OUT,
                    'spot' AS MARKET,
                    stpf.TAKER_FEE AS TAKER_FEE,
                    stpf.MAKER_FEE AS MAKER_FEE,
                    stp.MIN_ORDER_AMOUNT,
                    stp.MIN_ORDER_QUOTE_AMOUNT,
                    stp.STEP_PRICE,
                    stp.STEP_VOLUME,
                    tr.TYPE
                FROM 
                    TRADE_SPOT_ARRAYS tsa
                INNER JOIN
                    TRADE tr ON tr.ID = tsa.TRADE_ID
                LEFT JOIN 
                    EXCHANGE_ACCOUNTS ea ON ea.ID = tsa.EAID
                LEFT JOIN 
                    EXCHANGE e ON e.ID = ea.EXID 
                LEFT JOIN 
                    SPOT_TRADE_PAIR stp ON stp.ID = tsa.PAIR_ID
                LEFT JOIN 
                    SPOT_TRADE_PAIR_FEE stpf ON stpf.TRADE_PAIR_ID = stp.ID AND stpf.EAID = ea.ID
                WHERE 
                    ea.`ID` = ? AND  tsa.`PAIR_ID` = ? AND tr.ID = ?";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $account_id;
        $bind[1]['type'] = 'i';
        $bind[1]['value'] = $pair_id;
        $bind[2]['type'] = 'i';
        $bind[2]['value'] = $trader_id;
        $tri = $DB->select($sql, $bind); 
        if(!$tri && !empty($DB->getLastError())) {
            $message = "ERROR select data in Trader Instanse from DB. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        if(!empty($tri)) {
            $tr = $tri[0];
            $this->user_id = (int)$tr['USER_ID'];
            $this->account_id = (int)$tr['ACCOUNT_ID'];
            $this->account_name = $tr['ACCOUNT_NAME'];
            $this->exchange_id = (int)$tr['EXCHANGE_ID'];
            $this->exchange_name = $tr['EXCHANGE_NAME'];
            $this->market = $tr['MARKET'];
            $this->trader_type = $tr['TYPE'];
            $this->pair_id = (int)$tr['PAIR_ID'];
            $this->pair_name = Exchange::detectNamesPair($this->pair_id);
            $pair_arr = explode("/", $this->pair_name);
            $this->base_currency_name = $pair_arr[0];
            $this->quote_currency_name = $pair_arr[1];
            $this->base_currency_id = Exchange::detectCoinIdByName($this->base_currency_name, $this->exchange_id);
            $this->quote_currency_id = Exchange::detectCoinIdByName($this->quote_currency_name, $this->exchange_id);
            
            $this->taker_fee = (float)$tr['TAKER_FEE'];
            $this->maker_fee = (float)$tr['MAKER_FEE'];
            $this->min_order_amount = (float)$tr['MIN_ORDER_AMOUNT'];
            $this->min_order_quote_amount = (float)$tr['MIN_ORDER_QUOTE_AMOUNT'];
            $this->step_price = (float)$tr['STEP_PRICE'];
            $this->step_volume = (float)$tr['STEP_VOLUME'];
            $this->start_amount_base = (float)$tr['START_AMOUNT_BASE'];
            $this->start_amount_quote = (float)$tr['START_AMOUNT_QUOTE'];
            $this->current_amount_base = $this->start_amount_base;
            $this->current_amount_quote = $this->start_amount_quote;
            $this->min_delta_profit_sell = (float)$tr['MIN_DELTA_PROFIT_SELL'];
            $this->chain_send_out = (bool)$tr['CHAIN_SEND_OUT'];

            $this->instance_id = hash('xxh3',$this->account_id.'|'.$this->market.'|'.$this->pair_id);
            $this->orderbook_address = hash('xxh3',$this->exchange_id.'|'.$this->market.'|'.$this->pair_id);
            
            if($this->trader_type == 1 || $this->trader_type == 2) {
                $this->worker_address = hash('xxh3','WORKER|'.$this->account_id.'|'.$this->market.'|0');
            }
            elseif($this->trader_type == 3) {
                $this->worker_address = hash('xxh3','WORKER|'.$this->account_id.'|'.$this->market.'|'.$this->pair_id);
            }
            //get data by chains for deposit and withdrawal
            $sql = "SELECT
                        d.COIN_ID,
                        d.CHAIN_ID AS CHAIN_ID,
                        c.NAME AS CHAIN_NAME
                    FROM
                        DEPOSIT d
                    INNER JOIN 
                        `CHAIN` c ON d.CHAIN_ID = c.ID 
                    WHERE
                        (d.COIN_ID = ? OR d.COIN_ID = ?)
                        AND d.EXID = ?
                        AND d.ACTIVE = 1
                        AND c.ACTIVE = 1";
            $bind = array();
            $bind[0]['type'] = 'i';
            $bind[0]['value'] = $this->base_currency_id;
            $bind[1]['type'] = 'i';
            $bind[1]['value'] = $this->quote_currency_id;
            $bind[2]['type'] = 'i';
            $bind[2]['value'] = $this->exchange_id;
            $deposit = $DB->select($sql, $bind); 
            if(!$deposit && !empty($DB->getLastError())) {
                $message = "ERROR select data deposit in Trader Instanse from DB. ".$DB->getLastError();
                Log::systemLog('error', $message, "Trader");
                return false;
            }
            if(!empty($deposit)) {
                foreach ($deposit as $dep) {
                    $tmp = array();
                    $tmp['coin_id'] = $dep['COIN_ID'];
                    $tmp['chain_id'] = $dep['CHAIN_ID'];
                    $tmp['chain_name'] = $dep['CHAIN_NAME'];
                    $this->deposit[] = $tmp;
                }
            }
            
            $sql = "SELECT 
                        w.COIN_ID,
                        w.CHAIN_ID AS CHAIN_ID,
                        c.NAME AS CHAIN_NAME,
                        w.FEE AS FEE,
                        w.FEE_PERCENT AS FEE_PERCENT
                    FROM 
                        WITHDRAWAL w 
                    INNER JOIN 
                        `CHAIN` c ON w.CHAIN_ID = c.ID	
                    WHERE 
                        (w.COIN_ID = ? OR w.COIN_ID = ?)
                        AND w.EXID = ?
                        AND w.ACTIVE = 1
                        AND c.ACTIVE = 1";
            $bind = array();
            $bind[0]['type'] = 'i';
            $bind[0]['value'] = $this->base_currency_id;
            $bind[1]['type'] = 'i';
            $bind[1]['value'] = $this->quote_currency_id;
            $bind[2]['type'] = 'i';
            $bind[2]['value'] = $this->exchange_id;
            $withdrawal = $DB->select($sql, $bind); 
            if(!$withdrawal && !empty($DB->getLastError())) {
                $message = "ERROR select data withdrawal deposit in Trader Instanse from DB. ".$DB->getLastError();
                Log::systemLog('error', $message, "Trader");
                return false;
            }
            if(!empty($withdrawal)) {
                foreach ($withdrawal as $w) {
                    $tmp = array();
                    $tmp['coin_id'] = $w['COIN_ID'];
                    $tmp['chain_id'] = $w['CHAIN_ID'];
                    $tmp['chain_name'] = $w['CHAIN_NAME'];
                    $tmp['chain_fee'] = $w['FEE'];
                    $tmp['chain_fee_percent'] = $w['FEE_PERCENT'];
                    $this->withdrawal[] = $tmp;
                }
            }
        }
    }
    
    public function updateData() {
        global $DB;
        $sql = "SELECT 
                    tsa.MIN_DELTA_PROFIT_SELL AS MIN_DELTA_PROFIT_SELL,
                    tsa.CHAIN_SEND_OUT AS CHAIN_SEND_OUT,
                    stpf.TAKER_FEE AS TAKER_FEE,
                    stpf.MAKER_FEE AS MAKER_FEE
                FROM 
                    TRADE_SPOT_ARRAYS tsa
                INNER JOIN
                    TRADE tr ON tr.ID = tsa.TRADE_ID
                LEFT JOIN 
                    EXCHANGE_ACCOUNTS ea ON ea.ID = tsa.EAID
                LEFT JOIN 
                    EXCHANGE e ON e.ID = ea.EXID 
                LEFT JOIN 
                    SPOT_TRADE_PAIR stp ON stp.ID = tsa.PAIR_ID
                LEFT JOIN 
                    SPOT_TRADE_PAIR_FEE stpf ON stpf.TRADE_PAIR_ID = stp.ID AND stpf.EAID = ea.ID
                WHERE 
                    ea.`ID` = ? AND  tsa.`PAIR_ID` = ? AND tr.ID = ?";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $this->account_id;
        $bind[1]['type'] = 'i';
        $bind[1]['value'] = $this->pair_id;
        $bind[2]['type'] = 'i';
        $bind[2]['value'] = $this->trader_id;
        $tri = $DB->select($sql, $bind); 
        if(!$tri && !empty($DB->getLastError())) {
            $message = "ERROR select update data in Trader Instanse from DB. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        if(!empty($tri)) {
            $tr = $tri[0];
            $this->taker_fee = $tr['TAKER_FEE'];
            $this->maker_fee = $tr['MAKER_FEE'];
            $this->min_delta_profit_sell = $tr['MIN_DELTA_PROFIT_SELL'];
            $this->chain_send_out = $tr['CHAIN_SEND_OUT'];
        }
    }  
    public function readOrderBook() {
        $ob = OrderBook::readDepthRAM($this->orderbook_address);
        $bbo = OrderBook::readBBORAM($this->orderbook_address);
        $this->orderbook = $ob;
        $this->bbo = $bbo;
        $this->mergeOBvsBBO();
        return true;
    }
    public function readBBO() {
        $bbo = OrderBook::readBBORAM($this->orderbook_address);
        $this->bbo = $bbo;
        return true;
    }
    private function mergeOBvsBBO() {
        
        return true;
    }
    public function requestMarketSell($arb_id, $volume) {
        $write = array();
        $write['arbitrage_id'] = $arb_id;
        $write['type'] = 'market_sell';
        $write['volume'] = $volume;
        //Log::systemLog('error', 'MARKET SELL arb_id='.$arb_id.' vol='.$volume.' to_ram='.$this->worker_address, "Trader");
        $this->writeInputWorkerRAM($write);
        return true;
    }
    public function requestMarketBuy($arb_id, $volume) {
        $write = array();
        $write['arbitrage_id'] = $arb_id;
        $write['type'] = 'market_buy';
        $write['volume'] = $volume;
        //Log::systemLog('error', 'MARKET BUY arb_id='.$arb_id.' vol='.$volume.' to_ram='.$this->worker_address, "Trader");
        $this->writeInputWorkerRAM($write);
        return true;
    }
    
    private function readOutputWorkerRAM () {
        $element = false;
        $path = __DIR__."/ftok/".$this->worker_address.'.ftok';
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id = ftok($path, 'O');
        $semId = sem_get($id);
        sem_acquire($semId);
        //read segment
        $shmId = shm_attach($id);
        $var = 1;
        $data = '';
        $data_arr = array();
        if(shm_has_var($shmId, $var)) {
            //get data
            $data = shm_get_var($shmId, $var);
        } 
        shm_detach($shmId);
        //
        if(!empty($data)) {
            $data_arr = json_decode($data,JSON_OBJECT_AS_ARRAY);
        }
        if(is_array($data_arr) && !empty($data_arr)) {
            $element = array_shift($data_arr);
        }
        $data_json = json_encode($data_arr);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        return $element;
    }
    
    private function writeInputWorkerRAM ($input_data) {
        $path = __DIR__."/ftok/".$this->worker_address.'.ftok';
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id = ftok($path, 'I');
        $semId = sem_get($id);
        sem_acquire($semId);
        //read segment
        $shmId = shm_attach($id);
        $var = 1;
        $data = '';
        $data_arr = array();
        if(shm_has_var($shmId, $var)) {
            //get data
            $data = shm_get_var($shmId, $var);
        } 
        shm_detach($shmId);
        //
        if(!empty($data)) {
            $data_arr = json_decode($data,JSON_OBJECT_AS_ARRAY);
        }
        if(!is_array($data_arr)) {
            $data_arr = array();
        }
        if(is_array($data_arr)) {
            array_push($data_arr, $input_data);
        }
        $data_json = json_encode($data_arr);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        return true;
    }
}