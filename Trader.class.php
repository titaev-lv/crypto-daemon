<?php

class Trader {
    
    public $trader_id = 0;
    private $trader_user_id = 0;
    public $trader_type = 0;
    private $min_delta_profit = 0;          //Minimum profit
    private $max_amount_trade = 0;
    private $fin_protection = false;
    
    public $pool = array();
    
    public $timer_update_data = 30*1E6;
    public $timer_update_data_ts = 0;
    
    function __construct($trader_id) {
        global $DB;
        
        $this->timer_update_data_ts = microtime(true)*1E6;
        
        $this->trader_id = (int)$trader_id;
        $sql = "SELECT 
                    `UID`, 
                    `TYPE`,
                    `MIN_DELTA_PROFIT`, 
                    `MAX_AMOUNT_TRADE`,
                    `FIN_PROTECTION`
                FROM 
                    `TRADE` 
                WHERE 
                    `ID` = ?";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $trader_id;
        $tr = $DB->select($sql, $bind); 
        if(!$tr && !empty($DB->getLastError())) {
            $message = "ERROR select Trader data from DB. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        if(is_array($tr)) {
            $this->trader_user_id = (isset($tr[0]['UID'])) ? intval($tr[0]['UID']) : 0;
            $this->trader_type = (isset($tr[0]['TYPE'])) ? intval($tr[0]['TYPE']) : 0;
            $this->min_delta_profit = (isset($tr[0]['MIN_DELTA_PROFIT'])) ? $tr[0]['MIN_DELTA_PROFIT'] : 0;            
            $this->max_amount_trade = (isset($tr[0]['MAX_AMOUNT_TRADE']) && !empty($tr[0]['MAX_AMOUNT_TRADE'])) ? $tr[0]['MAX_AMOUNT_TRADE'] : NULL;
            $this->fin_protection = (isset($tr[0]['FIN_PROTECTION'])) ? (bool)$tr[0]['FIN_PROTECTION'] : false;
        }
        //Select data for create TraderInstances
        $sql_tri = "SELECT 
                    tsa.EAID AS ACCOUNT_ID,
                    'spot' AS MARKET,
                    tsa.PAIR_ID 
                FROM 
                    TRADE_SPOT_ARRAYS tsa
                WHERE 
                    tsa.`TRADE_ID` = ?";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $trader_id;
        $tri = $DB->select($sql_tri, $bind); 
        if(!$tri && !empty($DB->getLastError())) {
            $message = "ERROR select data for Trader Instanse from DB. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        if(!empty($tri)) {
            foreach ($tri as $t) {
                $key = hash('xxh3',$t['ACCOUNT_ID'].'|'.$t['MARKET'].'|'.$t['PAIR_ID']);
                $obj = new TraderInstance($this->trader_id, $t['ACCOUNT_ID'], $t['MARKET'], $t['PAIR_ID']);
                $this->pool[$key] = $obj;
            }
        }
        Log::systemLog('debug', 'POOL proc='. getmypid().' '.json_encode($this->pool));
        //
    }
    public function updateTrader() {
        global $DB;
        $sql = "SELECT 
                    `MIN_DELTA_PROFIT`, 
                    `MAX_AMOUNT_TRADE`,
                    `FIN_PROTECTION`
                FROM 
                    `TRADE` 
                WHERE 
                    `ID` = ?";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $this->trader_id;
        $tr = $DB->select($sql, $bind); 
        if(!$tr && !empty($DB->getLastError())) {
            $message = "ERROR select Trader data from DB step2. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        if(is_array($tr)) {
            $this->min_delta_profit = (isset($tr[0]['MIN_DELTA_PROFIT'])) ? $tr[0]['MIN_DELTA_PROFIT'] : 0;            
            $this->max_amount_trade = (isset($tr[0]['MAX_AMOUNT_TRADE']) && !empty($tr[0]['MAX_AMOUNT_TRADE'])) ? $tr[0]['MAX_AMOUNT_TRADE'] : NULL;
            $this->fin_protection = (isset($tr[0]['FIN_PROTECTION'])) ? (bool)$tr[0]['FIN_PROTECTION'] : false;
        }
        
        return true;
    }
}
