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
    public $arb_trans_count = 0;
    private $trader_user_id = 0;
    public $trader_type = 0;
    private $max_amount_trade = "0";
    public $fin_protection = false;
    public $bbo_only = true;
    
    public $arbitrage_id = 0;
    public $pool = array();
    
    public $timer_update_data = 20*1E6;
    public $timer_update_data_ts = 0;
    private $limit_count_negative_trans = 3;
    
    function __construct($trader_id) {
        global $DB;
        $this->timer_update_data_ts = microtime(true)*1E6;
        
        $this->trader_id = (int)$trader_id;
        $sql = "SELECT 
                    `UID`, 
                    `TYPE`,
                    `MAX_AMOUNT_TRADE`,
                    `FIN_PROTECTION`,
                    `BBO_ONLY`
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
            $this->max_amount_trade = (isset($tr[0]['MAX_AMOUNT_TRADE']) && !empty($tr[0]['MAX_AMOUNT_TRADE'])) ? (float)$tr[0]['MAX_AMOUNT_TRADE'] : 0.00;
            $this->fin_protection = (isset($tr[0]['FIN_PROTECTION'])) ? (bool)$tr[0]['FIN_PROTECTION'] : false;
            $this->bbo_only = (isset($tr[0]['BBO_ONLY'])) ? (bool)$tr[0]['BBO_ONLY'] : true;
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
                    `MAX_AMOUNT_TRADE`,
                    `FIN_PROTECTION`,
                    `BBO_ONLY`
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
            $this->max_amount_trade = (isset($tr[0]['MAX_AMOUNT_TRADE']) && !empty($tr[0]['MAX_AMOUNT_TRADE'])) ? (float)$tr[0]['MAX_AMOUNT_TRADE'] : 0.00;
            $this->fin_protection = (isset($tr[0]['FIN_PROTECTION'])) ? (bool)$tr[0]['FIN_PROTECTION'] : false;
            $this->bbo_only = (isset($tr[0]['BBO_ONLY'])) ? (bool)$tr[0]['BBO_ONLY'] : true;
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
        //Log::systemLog('debug', 'POOL proc='. getmypid().' '.json_encode($this->pool), "Trader");
        return true;
    }
    public function readBBOs() {
        if(!empty($this->pool)) {
            foreach ($this->pool as $k=>$v) {
                $inst_obj = $this->fetchObjectPoolTraderInstace($k);
                $ob_read = $inst_obj->readBBO();
                if($ob_read) {
                    $this->pushObjectPoolTraderInstace($k, $inst_obj);
                }
            }
        }
        //Log::systemLog('debug', 'POOL proc='. getmypid().' '.json_encode($this->pool), "Trader");
        return true;
    }
    
    public function getProfitablePair_Type1() {
        $arb_avail_pairs = false;
        $arb_positive_pairs = false;
        
        //All available pairs to sell/buy in array
        $arb_avail_pairs = $this->getAvailablePairs_type_1_and_2();
        if($arb_avail_pairs !== false && !empty($arb_avail_pairs)) {
            //Calculate profit for all alailable pairs
            $arb_calc_profit = $this->calc($arb_avail_pairs);

            Log::systemLog('warn', 'PROFIT PAIRS '.json_encode($arb_calc_profit), "Trader");
        }
        return false;
    }
    
    public function calculateType_1() {
        $pairs = array();
        $deltas = array();
        //Detect all pairs
        if(!empty($this->pool) && count($this->pool) > 1) {
            //Get it in array 
            $tmp = array();
            foreach ($this->pool as $k=>$v) {
                $tmp[] = $k;
                foreach ($this->pool as $n=>$m) {
                    if(in_array($n, $tmp) && $n !== $k) {
                        $t = array();
                        $t[] = $k;
                        $t[] = $n;
                        $pairs[] = $t;
                        unset($t);
                    }
                }
            }
            unset($tmp);
        }   
        // Log::systemLog('warn', 'ALANYZE PAIRS'. json_encode($pairs), "Trader");      
        if(!empty($pairs)) {
            foreach ($pairs as $p) {
                $sell = $this->fetchObjectPoolTraderInstace($p[0]);
                $buy = $this->fetchObjectPoolTraderInstace($p[1]);
                
                if(isset($sell->orderbook['bids']) && isset($sell->orderbook['asks']) && isset($buy->orderbook['bids']) && isset($buy->orderbook['asks'])) {
                    //Check timestamp
                    if(isset($sell->orderbook['timestamp']) && isset($buy->orderbook['timestamp']) && $sell->orderbook['timestamp'] && $buy->orderbook['timestamp']) {
                        if(microtime(true) - (float)$sell->orderbook['timestamp']*1E-6 < 6 && microtime(true) - (float)$buy->orderbook['timestamp']*1E-6 < 6) {
                            //1 variants  
                            if((float)$sell->orderbook['bids'][0][0] > (float)$buy->orderbook['asks'][0][0]) {
                                //calculate profit
                                $res_calc = $this->additionCalcType_1($sell, $buy);
                                if($res_calc) {
                                    $deltas[] = $res_calc;
                                   // Log::systemLog('warn', 'AFTERCALC'. json_encode($res_calc), "Trader"); 
                                }
                            }
                            //2 variant
                            $t = $buy;
                            $buy = $sell;
                            $sell = $t;
                            if((float)$sell->orderbook['bids'][0][0] > (float)$buy->orderbook['asks'][0][0]) {
                                $res_calc = $this->additionCalcType_1($sell, $buy);               
                                if($res_calc) {
                                    $deltas[] = $res_calc;
                                   // Log::systemLog('warn', 'AFTERCALC'. json_encode($res_calc), "Trader"); 
                                }
                            }
                        }
                        else {
                            $a = microtime(true) - (float)$sell->orderbook['timestamp']*1E-6;
                            $b = microtime(true) - (float)$buy->orderbook['timestamp']*1E-6;
                            Log::systemLog('warn',"Spot DATA price timestamp very old a=".$a. '  b='.$b, "Trader");
                        }
                    }
                    else {
                        //Log::systemLog('warn',"ddd". json_encode($sell->orderbook), "Trader");
                       // Log::systemLog('warn',"ddd". json_encode($sell->orderbook), "Trader");
                    }
                }
            }
        }
        if(!empty($deltas)) {
            //Log::systemLog('warn', 'RESULT_DELTAS'. json_encode($deltas), "Trader"); 
            $out = array();
            $profit_last = 0;
            //Select profitble
            foreach ($deltas as $kd=>$d) {
                if((float)$d['profit'] > $profit_last) {
                    $out = $deltas[$kd];
                    //$out['volume'] = rtrim($out['volume'],"0");
                    //$out['profit'] = rtrim($out['profit'],"0");
                    $profit_last = (float)$d['profit'];
                }
            }
            //Log::systemLog('warn', 'RESULT_OUT'. json_encode($out), "Trader"); 
            return $out;
        }
        return false;
    }
    private function additionCalcType_1 ($sell, $buy) {
        $sell_price =  number_format($sell->orderbook['bids'][0][0], 12, '.','');
        $buy_price = number_format($buy->orderbook['asks'][0][0], 12, '.','');
        $fee_sell = bcmul($sell_price, (string)$sell->taker_fee, 12);
        $fee_buy = bcmul($buy_price, (string)$buy->taker_fee, 12);
        //Delta price with commission
        $delta = bcsub(bcsub($sell_price, $fee_sell,12), bcadd($buy_price,$fee_buy,12),12);
        //Max volume
        $volume = ((float)$sell->orderbook['bids'][0][1] < (float)$buy->orderbook['asks'][0][1]) ? $sell->orderbook['bids'][0][1] : $buy->orderbook['asks'][0][1];
        $volume = number_format($volume, 12, '.','');
        
        //Min Volume define exchanges
        $min_volume = 0;
        //Min profit, default 0
        $min_profit = 0;

        if($sell->min_delta_profit_sell > 0) {
            if($sell->current_amount_base <= $sell->start_amount_base) {
                $min_profit = $sell->min_delta_profit_sell;
            }
        }
        $min_profit = number_format($min_profit, 12, '.','');
        
        //Calculate for 1,000 USDT send over chain
        if($sell->chain_send_out) {
            $base_chains = array();
            $quote_chains = array();
            foreach($sell->withdrawal as $withd) {
                if($withd['coin_id'] == $sell->quote_currency_id) {
                    foreach ($buy->deposit as $dep) {
                        if($dep['coin_id'] == $sell->quote_currency_id) {
                            if($withd['chain_id'] === $dep['chain_id']) {
                                $tmp = array();
                                $tmp['chain_id'] = $dep['chain_id'];
                                $tmp['chain_name'] = $dep['chain_name'];
                                $tmp['chain_fee'] = $withd['chain_fee'];
                                $tmp['chain_fee_percent'] = $withd['chain_fee_percent'];
                                $quote_chains[] = $tmp;
                            }
                        }
                    }
                }
            }
            foreach($buy->withdrawal as $withd) {
                if($withd['coin_id'] == $buy->base_currency_id) {
                    foreach ($sell->deposit as $dep) {
                        if($dep['coin_id'] == $buy->base_currency_id) {
                            if($withd['chain_id'] === $dep['chain_id']) {
                                $tmp = array();
                                $tmp['chain_id'] = $dep['chain_id'];
                                $tmp['chain_name'] = $dep['chain_name'];
                                $tmp['chain_fee'] = $withd['chain_fee'];
                                $tmp['chain_fee_percent'] = $withd['chain_fee_percent'];
                                $base_chains[] = $tmp;
                            }
                        }
                    }
                }
            }

            if(!empty($quote_chains)) {
                $array_chain_fee = [];
                $array_chain_fee_percent = [];
                foreach ($quote_chains as $key => $row) {
                    $array_chain_fee[] = $row['chain_fee'];
                    $array_chain_fee_percent[] = $row['chain_fee_percent'];
                }
                array_multisort($array_chain_fee, SORT_ASC,  $array_chain_fee_percent, SORT_ASC, $quote_chains);
            }
            if(!empty($base_chains)) {
                $array_chain_fee = [];
                $array_chain_fee_percent = [];
                foreach ($base_chains as $key => $row) {
                    $array_chain_fee[] = $row['chain_fee'];
                    $array_chain_fee_percent[] = $row['chain_fee_percent'];
                }
                array_multisort($array_chain_fee, SORT_ASC,  $array_chain_fee_percent, SORT_ASC, $base_chains);
            }

            if(!empty($base_chains) && !empty($quote_chains)) {
                $q = $quote_chains[0]['chain_fee'];
                $b = bcmul($buy->orderbook['bids'][0][0],$base_chains[0]['chain_fee'],12);
                $fee = bcadd($q, $b, 12);
                $fee_per_one = bcdiv($fee,"1000",12);
                $min_profit = bcadd($min_profit, $fee_per_one,12);
                //Log::systemLog('warn', 'COMM fee_per_one='.$fee_per_one.' min_profit='.$min_profit.' '.$sell->exchange_name.'->'.$buy->exchange_name, "Trader"); 
            }
            else {
                $delta = "0";
            }
        }


        if((float)$delta > (float)$min_profit) {
            //Correct volume 
            if(!empty($sell->step_volume) && !empty($buy->step_volume)) {
                $sv = ((float)$sell->step_volume >= (float)$buy->step_volume) ?  $buy->step_volume : $sell->step_volume;
            }
            elseif(!empty($sell->step_volume)) {
                $sv = $sell->step_volume;
            }
            else {
                $sv = $buy->step_volume;  
            }
            $sv = number_format($sv, 12, '.','');
            $rnd = strlen(rtrim(explode('.', $sv)[1],"0"));
            $volume = floor((float)$volume*pow(10,$rnd))/pow(10,$rnd);
            $volume = number_format($volume, 12, '.','');
            
            //
            if(isset($sell->min_order_amount) && (float)$sell->min_order_amount > 0) {
                $min_volume = $sell->min_order_amount;
            }
            if(isset($buy->min_order_amount) && (float)$buy->min_order_amount > 0) {
                $min_volume = ($buy->min_order_amount < $min_volume) ? $buy->min_order_amount : $min_volume;
            }
            //Check min quote volume
            if(isset($sell->min_order_quote_amount) && (float)$sell->min_order_quote_amount > 0) {
                $min_volume = ((float)$sell->min_order_quote_amount / (float)$sell_price < $min_volume) ? $sell->min_order_quote_amount / (float)$sell_price : $min_volume;
            }
            if(isset($buy->min_order_quote_amount) && (float) $buy->min_order_quote_amount > 0) {
                $min_volume = ((float)$buy->min_order_quote_amount / (float)$buy_price < $min_volume) ? $buy->min_order_quote_amount / (float)$buy_price : $min_volume;
            }
            if((float)$this->max_amount_trade > 0) {
                $volume = ((float)$volume > (float)$this->max_amount_trade) ? $this->max_amount_trade : $volume;
            }
            $volume = number_format($volume, 12, '.','');
            
            if($volume > $min_volume) {
                //Log::systemLog('warn', 'CALC '.$sell->exchange_name.' sell='.$sell->orderbook['bids'][0][0].' '.$buy->exchange_name.' buy='.$buy->orderbook['asks'][0][0]." delta=".$delta.' volume='.$volume, "Trader"); 
                $ret = array();
                $ret['sell_instance_id'] = $sell->instance_id;
                $ret['buy_instance_id'] = $buy->instance_id;
                $ret['volume'] = $volume;
                $ret['profit'] = $delta;
                return $ret;
            }
        }
        return false;
    }
    
    private function getAvailablePairs_type_1_and_2() {
        $pairs = array();
        $pairs_out = array();
        //Detect all pairs
        if(!empty($this->pool) && count($this->pool) > 1) {
            //Get it in array 
            $tmp = array();
            foreach ($this->pool as $k=>$v) {
                $tmp[] = $k;
                foreach ($this->pool as $n=>$m) {
                    if(in_array($n, $tmp) && $n !== $k) {
                        $t = array();
                        $t[] = $k;
                        $t[] = $n;
                        $pairs[] = $t;
                        unset($t);
                    }
                }
            }
            unset($tmp);
            
            foreach ($pairs as $p) {
                $t = array();
                $t['sell'] = $p[0];
                $t['buy'] = $p[1];
                $pairs_out[] = $t;
                $t = array();
                $t['sell'] = $p[1];
                $t['buy'] = $p[0];
                $pairs_out[] = $t;
                unset($t);
            }
            // Log::systemLog('warn', 'ALANYZE PAIRS'. json_encode($pairs_out), "Trader"); 
            //Example for 3 exchanges   
            /*  [
                    {
                        "sell":"9f359ccde7def693",
                        "buy":"6e58a6ae933bac4e"
                    },
                    {
                        "sell":"6e58a6ae933bac4e",
                        "buy":"9f359ccde7def693"
                    },
                ]*/
            return $pairs_out;
        }
        return false;
    }
    private function calc($arb_pairs) {
        if(!empty($arb_pairs)) {
            foreach ($arb_pairs as $ins=>$p) {
                $sell = $this->fetchObjectPoolTraderInstace($p['sell']);
                $buy = $this->fetchObjectPoolTraderInstace($p['buy']);
                
                //if(isset($sell->orderbook['bids']) && isset($sell->orderbook['asks']) && isset($buy->orderbook['bids']) && isset($buy->orderbook['asks'])) {
                    //Check timestamp
                    //if(isset($sell->orderbook['timestamp']) && isset($buy->orderbook['timestamp']) && $sell->orderbook['timestamp'] && $buy->orderbook['timestamp']) {
                        //if(microtime(true) - (float)$sell->orderbook['timestamp']*1E-6 < 6 && microtime(true) - (float)$buy->orderbook['timestamp']*1E-6 < 6) {
                            //BBO is true. Use only best price
                            $sell_price =  number_format($sell->bbo['bid_price'], 12, '.','');
                            $buy_price = number_format($buy->bbo['ask_price'], 12, '.','');
                            $fee_sell = bcmul($sell_price, (string)$sell->taker_fee, 12);
                            $fee_buy = bcmul($buy_price, (string)$buy->taker_fee, 12);
                            //Delta price with commission
                            $delta = number_format(bcsub(bcsub($sell_price, $fee_sell,12), bcadd($buy_price,$fee_buy,12),12), 12, '.','');
                            //Max volume
                            $volume = ((float)$sell->bbo['bid_volume'] < (float)$buy->bbo['ask_volume']) ? $sell->bbo['bid_volume'] : $buy->bbo['ask_volume'];
                            $volume = number_format($volume, 12, '.','');
                            $arb_pairs[$ins]['bbo']['profit'] = $delta;
                            $arb_pairs[$ins]['bbo']['volume'] = $volume;
                            //normal mode
                            if($this->bbo_only === false) {
                                
                            }
                            
                            //finance protection
                            if($this->bbo_only === false) {
                                
                            }
                            
                        //}
                    //}
              //  }
                

                Log::systemLog('warn', 'CALC ALANYZE PAIRS'. json_encode($p).' sell='.$sell_price.' ('.$sell->exchange_name.')   buy='.$buy_price .'('.$buy->exchange_name.') profit='.$delta.' volume='.$volume, "Trader"); 


                /*if($this->fin_protection) {
                    $sell_price = number_format($sell->orderbook['bids'][0][0], 12, '.','');
                    $buy_price = number_format($buy->orderbook['asks'][0][0], 12, '.','');    
                }
                else {
                    $sell_price = number_format($sell->bbo['bid_price'][0][0], 12, '.','');
                    $buy_price = number_format($buy->bbo['ask_price'][0][0], 12, '.','');
                }
                if((float)$sell_price > (float)$buy_price) {
                    $arb_positive[] = $p;
                    Log::systemLog('warn', 'POSITIVE ALANYZE PAIRS'. json_encode($p).' sell='.$sell_price.' ('.$sell->exchange_name.')   buy='.$buy_price .'('.$buy->exchange_name.')', "Trader"); 
                }*/
            }
            return $arb_pairs;
        }
        return false;
    }
}
