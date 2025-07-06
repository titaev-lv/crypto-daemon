<?php
/* 
 * Class for manage Exchange Order Book's prosesses
 * 
 */
class OrderBookMonitor extends AbstractMonitor {
    
    protected int $timer_update_ob_trade_subscribes = 5000000; // 5sec
    protected int $timer_update_ob_trade_subscribes_ts = 0;
    
    protected int $timeout_child = 8000000;
    
    public function processing() {
        global $Daemon, $DB;           
        //Available merkets
        $markets = array("spot","features");
        //Log::systemLog('debug', 'Order Book Monitor processing'.json_encode($this), $this->getProcName());      
        //run every 5 seconds, search in DB new tasks
        //$sb = self::checkTimer($this->timer_update_ob_trade_subscribes, $this->timer_update_ob_trade_subscribes_ts);
        if($this->probeTimer("timer_update_ob_trade_subscribes") === true) {
            
            $tasks_arr = $this->getActivePairs();
            //Log::systemLog('debug', 'Tasks SUBSCRIBE List '. json_encode($tasks_arr),$this->getProcName());
            /**Tasks array from DB. Include SPOT and FEATURES active trade pairs
             * array(
             *     [EXCHANGE_ID]
             *         ['spot']
             *             [PAIR_ID]
             *             [PAIR_ID]
             *         ['features']
             *             [PAIR_ID]
             *             [PAIR_ID]
             *     ................
             * )
             */
            
            /*  For every exchange will can have 2 processes (spot and feature market)
             *  Step 1 - Create new process, if not exists
             *  Step 2 - Send array pairs to Exchange's process for receive order book if CRC is different
             *  Step 3 - Kill excess processes
            */
             
            //Step 1
            //Create new process, if not exists for exchange market
            if($tasks_arr !== false && !empty($tasks_arr)) {
                ///------ For every exchange  
                foreach ($tasks_arr as $key=>$tr) {
                    //---- For every market
                    foreach ($markets as $market) {
                        if(isset($tr[$market])) {
                            $exchange_market_exist_flag = false;
                            if(!empty($Daemon->proc)) {
                                foreach ($Daemon->proc as $k=>$proc) {
                                    if($proc['exchange_id'] === $key && $proc['pid'] > 0 &&  $proc['market'] == $market) {
                                        $exchange_market_exist_flag = true;
                                    }
                                }
                            }
                            //if process is absent
                            if($exchange_market_exist_flag === false) {
                                $DB->close();
                                $msg = array('exchange_id'=>$key,'market'=>$market);
                                $epid = $Daemon->newProcess('OrderBook', 'worker');
                                $DB = DB::init($Daemon->getDBEngine(),$Daemon->getDBCredentials()); 
                                Log::systemLog('debug', 'Order Book Monitor init start OrderBook Worker child proc = '. $epid.' Exchange = '.$tr['name'].' Market = '.strtoupper($market), $this->getProcName());
                                //Add information to proccess
                                foreach ($Daemon->proc as $kproc=>$proc) {
                                    if($proc['pid'] == $epid) {
                                        $Daemon->proc[$kproc]['market'] = $market;
                                        $Daemon->proc[$kproc]['exchange_id'] = $key;
                                        $Daemon->proc[$kproc]['exchange_name'] = $tr['name'];
                                    }
                                }
                                //Send new process info about exchange and market type
                                ServiceRAM::write($epid,'create_exchange_orderbook',$msg);
                                Log::systemLog('debug', 'SEND to ServiceRAM command "create_exchange_orderbook" to process = '. $epid.' from pid = '.getmypid().' '. json_encode($msg), $this->getProcName());
                            }
                        }
                    }   
                }
            }
            
            //Step 2
            //Send array pairs to Exchange's process for receive order book if CRC is different
            if($tasks_arr !== false && !empty($tasks_arr)) {
                ///------ For every exchange  
                foreach ($tasks_arr as $key=>$tr) {
                    //---- For every market
                    foreach ($markets as $market) {
                        foreach ($Daemon->proc as $kproc=>$proc) {
                            if($proc['exchange_id'] === $key && $proc['market'] == $market) {
                                if(isset($tr[$market])) {
                                    //Calculate CRC summ for 
                                    $subscribe_crc = crc32(json_encode($tr[$market]));
                                    //Log::systemLog('debug', 'Subscripe CRC '.$subscribe_crc.' proc_crc'.$proc['subscribe_crc'], $this->getProcName());
                                    if(!isset($proc['subscribe_crc']) || $proc['subscribe_crc'] !== $subscribe_crc) {
                                        $Daemon->proc[$kproc]['subscribe'] = $tr[$market];
                                        $Daemon->proc[$kproc]['subscribe_crc'] = $subscribe_crc;
                                        ServiceRAM::write($proc['pid'],'active_exchange_pair_orderbook',$tr[$market]);
                                        Log::systemLog('debug', 'SEND to ServiceRAM command "active_exchange_pair_orderbook" to order book process = '. $proc['pid'].' '. json_encode($tr[$market]), $this->getProcName());
                                    }
                                }
                            }
                        }
                    }
                }
            }
            //Step 3
            //Kill excess processes
            if(!empty($Daemon->proc)) {   
                //Destroy excess processes
                foreach($Daemon->proc as $k=>$proc) {
                    $exchange_remove_flag = true;
                    if(!empty($tasks_arr)) {
                        foreach ($tasks_arr as $key=>$tr) {
                            if($proc['exchange_id'] == $key && isset($tr[$proc['market']])) {
                                $exchange_remove_flag = false;
                            }
                        }
                    }
                    //kill process
                    if($exchange_remove_flag === true) {
                        Log::systemLog('debug', 'Kill excess Echange order book process = '.$proc['pid'].' Exchange name: '.$proc['exchange_name'].' market '. strtoupper($proc['market']), $this->getProcName());
                        $kill = posix_kill($proc['pid'], SIGTERM);  
                        if($kill) {
                            unset($Daemon->proc[$k]);
                            if(!empty($Daemon->proc_tree)) {   
                                foreach($Daemon->proc_tree as $kt=>$proct) {
                                    if($proc['pid'] == $proct['pid']) {
                                         unset($Daemon->proc_tree[$kt]);
                                    }
                                }
                            }
                        }
                        else {
                            Log::systemLog('error', 'ERROR kill excess Echange order book process ='.$proc['pid'].' Exchange name: '.$proc['exchange_name'].' market '. strtoupper($proc['market']), $this->getProcName());
                        }
                    }
                }
            }
        }
        //Log::systemLog('debug', 'Daemon Order Book proc processing '.json_encode($Daemon), $this->getProcName()); 
        usleep(20000);
    }

    private function getActivePairs() {
        global $DB;
        $ret = array();
        
        /**SPOT Market
         * SELECT 1 - Select active trade pairs
         * SELECT 2 - Select from monitorng table
         */
        
        //SELECT 1
        $sql = 'SELECT 
                    stp.EXCHANGE_ID,
                    t.PAIR_ID 
                FROM
                    (
                        (
                            SELECT
                                 DISTINCT(tsa.PAIR_ID) AS PAIR_ID
                            FROM
                                TRADE t
                            INNER JOIN 
                                TRADE_SPOT_ARRAYS tsa ON t.ID = tsa.TRADE_ID 
                            INNER JOIN 
                                EXCHANGE_ACCOUNTS ea ON ea.ID = tsa.EAID
                            INNER JOIN 
                                EXCHANGE e ON e.ID = ea.EXID
                            WHERE
                                t.ACTIVE = 1
                                AND e.ACTIVE = 1
                                AND ea.ACTIVE = 1
                        )
                        UNION 
                        (
                            SELECT
                                DISTINCT(msa.PAIR_ID) AS PAIR_ID
                            FROM
                                MONITORING m
                            INNER JOIN 
                                MONITORING_SPOT_ARRAYS msa 
                                       ON m.ID = msa.MONITOR_ID 
                            WHERE
                                m.ACTIVE = 1
                        )
                    ) t
                INNER JOIN 
                    SPOT_TRADE_PAIR stp 
                        ON t.PAIR_ID = stp.ID 
                LEFT JOIN 
                    EXCHANGE e2 
                        ON e2.ID = stp.EXCHANGE_ID 
                WHERE
                    stp.ACTIVE = 1
                    AND e2.ACTIVE = 1
                ORDER BY 
                    EXCHANGE_ID ASC';
        $ex_list = $DB->select($sql);
        if(!empty($DB->getLastError())) {
            $message = "ERROR select Active ExchangePairDepth. ".$DB->getLastError();
            Log::systemLog('error', $message, $this->proc_name);
            return false;
        }

        foreach ($ex_list as $l) {
            $tmp = array();
            $tmp['id'] = $l['PAIR_ID'];
            $ret[$l['EXCHANGE_ID']]['spot'][] = $tmp;
        }
        
        //test features
        /*$tmptmp['id'] = "1";
        $ret[1]['features'][] = $tmptmp;*/

        //Detect Name Exchange and PairName for API request       
        foreach ($ret as $key=>$val) {
            $exchange = Exchange::init($key);
            $ret[$key]['name'] = $exchange->getName();
            if(isset($val['spot'])) {
                foreach ($val['spot'] as $key2=>$val2) {
                    $nm = $exchange->getTradePairName($val2['id']);
                    $ret[$key]['spot'][$key2]['name'] = $nm['NAME'];
                    $ret[$key]['spot'][$key2]['sys_name'] = $nm['SYS_NAME'];
                }
            }
            unset($exchange);
        }
        return $ret;
    }
}