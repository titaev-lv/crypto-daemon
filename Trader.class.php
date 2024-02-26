<?php

class Trader {
    public $trader_id = 0;
    public $trader_status = 0;
    /* 0 - process started
     * 1 - ready to trade
     * 
     * 20 - Error
     * 30 - High loss
    */
    private $trader_user_id = 0;
    public $trader_type = 0;
    private $min_delta_profit = 0;          //Minimum profit
    private $max_amount_trade = 0;
    private $fin_protection = false;
    
    public $arbitrage_id = 0;
    private $pool = array();
    
    public $timer_update_data = 60*1E6;
    public $timer_update_data_ts = 0;
    private $limit_count_negative_trans = 3;
    
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
                $this->pushObjectPoolTraderInstace($key, $obj);
            }
        }
        //Log::systemLog('debug', 'POOL proc='. getmypid().' '.json_encode($this->pool));
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
            $message = "ERROR select Trader update data from DB. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        if(is_array($tr)) {
            $this->min_delta_profit = (isset($tr[0]['MIN_DELTA_PROFIT'])) ? $tr[0]['MIN_DELTA_PROFIT'] : 0;            
            $this->max_amount_trade = (isset($tr[0]['MAX_AMOUNT_TRADE']) && !empty($tr[0]['MAX_AMOUNT_TRADE'])) ? $tr[0]['MAX_AMOUNT_TRADE'] : NULL;
            $this->fin_protection = (isset($tr[0]['FIN_PROTECTION'])) ? (bool)$tr[0]['FIN_PROTECTION'] : false;
        }
        
        //Select data for update TraderInstances
        if(!empty($this->pool)) {
            //update
            foreach(array_keys($this->pool) as $key) {
                $tr_ins = $this->fetchObjectPoolTraderInstace($key);
                $tr_ins->updateData();
                $this->pushObjectPoolTraderInstace($key, $tr_ins);
            }  
        }

        //Log::systemLog('debug', 'POOL UPDATE proc='. getmypid().' '.json_encode($this->pool));
        return true;
    }
    public function fetchObjectPoolTraderInstace($id) {
        return (isset($this->pool[$id])) ? $this->pool[$id] : false;
    }
    public function pushObjectPoolTraderInstace($id,$obj) {
        $this->pool[$id] = $obj;
        return true;
    }
    public function getLastArbTransStatus() {
        global $DB;
        $status = 0;
        
        $sql = "SELECT 
                    atr2.STATUS
                FROM 
                    ARBITRAGE_TRANS atr2
                WHERE 
                    atr2.ID = (
                        SELECT
                            max(atr.id) AS ID
                        FROM
                            ARBITRAGE_TRANS atr
                        WHERE
                            atr.TRADE_ID = ?
                    )";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $this->trader_id;
        $trans = $DB->select($sql, $bind); 
        if(!$trans && !empty($DB->getLastError())) {
            $message = "ERROR select Arbitrage transaction from DB. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        if(empty($trans)) {
            $status = 0;
        }
        else {
            $status = (int)$trans[0]['STATUS'];
        }
        return $status;
    }
    public function checkOverflowCountLossArbTrans() {
        global $DB;
        $sql = "SELECT 
                    COUNT(*) AS COUNT
                FROM 
                    ARBITRAGE_TRANS atr
                WHERE 
                    atr.STATUS = 6 
                    AND atr.TRADE_ID = ?";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $this->trader_id;
        $count = $DB->select($sql, $bind); 
        if(!$count && !empty($DB->getLastError())) {
            $message = "ERROR select count loss Arbitrage transaction from DB. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        $c = (int) $count[0]['COUNT'];
        if($c >= $this->limit_count_negative_trans) {
            return true;
        }
        return false;
    }
    public function prepareArbitrageTrans() {
        global $DB;
        $DB->startTransaction();
        if(!empty($DB->getLastError())) {
            $message = "ERROR start Arbitrage transaction. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        $sql = "INSERT INTO ARBITRAGE_TRANS (TRADE_ID, STATUS) VALUES(?, 1)";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $this->trader_id;
        $ins = $DB->insert($sql,$bind);
        if($ins === false || $ins == 0 || $DB->getLastError()) {
            $DB->rollbackTransaction();
            $message = "ERROR insert Arbitrage transaction in DB. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        $arb_id = $DB->getLastID();
        if(!$arb_id) {
            $DB->rollbackTransaction();
            $message = "ERROR get ID Arbitrage transaction from DB. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        $DB->commitTransaction();
        if(!empty($DB->getLastError())) {
            $message = "ERROR commit transaction of Arbitrage transaction. ".$DB->getLastError();
            Log::systemLog('error', $message, "Trader");
            return false;
        }
        $this->arbitrage_id = $arb_id;
        return $arb_id;
    }
    public function readOrderBooks() {
        if(!empty($this->pool)) {
            foreach ($this->pool as $k=>$v) {
                $inst_obj = $this->fetchObjectPoolTraderInstace($k);
                $ob_read = $inst_obj->readOrderBook();
                if($ob_read) {
                    $this->pushObjectPoolTraderInstace($k, $inst_obj);
                }
            }
        }
        Log::systemLog('debug', 'POOL proc='. getmypid().' '.json_encode($this->pool), "Trader");
        return true;
    }
}
