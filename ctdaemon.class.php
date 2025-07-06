<?php
class ctdaemon extends AbstractProc {

    //Timers
    //Active trade pair's order book subscribe
 /*   public $timer_update_ob_trade_subscribes = 5000000;
    private $timer_update_ob_trade_subscribes_ts = 0;
    //Read RAM for new subscribe message
    public $timer_update_ob_read_ram_subscribes = 500000;
    private $timer_update_ob_read_ram_subscribes_ts = 0;
    //Price monitor saved price and volume into DB
    public $timer_update_price_monitor = 500000;
    public $timer_update_price_monitor_subscribes = 5000000;
    //Timer Check Sync all trade pairs from all active exchanges (1min)
    public $timer_sync_trade_pairs = 60*1E6;
    //Sync active trade pair's fee from all axchenges s(1min)
    public $timer_sync_fees_active_trade_pairs = 60*1E6;
    //Timer update Traders
    public $timer_update_traders = 5*1E6; // (5sec)
    public $timer_update_traders_ts = 0;
    //Timer update TradeWorkers
    public $timer_update_trade_workers = 5*1E6; // (5sec)
    public $timer_update_trade_workers_ts = 0;*/
    
    public function processing() {
        global $Daemon;
        //Log::systemLog('debug', 'Daemon Main proc processing '.json_encode($Daemon), $this->getProcName()); 
        //Write Proc Tree into external RAM (rewrite)
        $daemon_status['pid'] = getmypid();
        $daemon_status['name'] = $Daemon->getProcName();
        $daemon_status['timestamp'] = $Daemon->timestamp;
        $daemon_status['child'] = $Daemon->proc_tree;
        ExternalRAM::write('daemon_status', $daemon_status);
        usleep(2000000);
    }
              
    public function runProcPriceMonitor() {
        global $DB;
        sleep(6);
        //Create DB connection
        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials());
        Log::systemLog('info',"Process type \"Price Monitor\" STARTED pid=".getmypid(), "Price Monitor");
        $price = new PriceLog();
        $this->start = microtime(true);
        while(1) {
            $this->timestamp = microtime(true)*1E6;    
            //For every process need update ProcTree for main process Every 1 second
            $this->updateProcTree();
            //Log::systemLog('debug', 'PROC TREE '. json_encode($this->proc_tree).' proc='. getmypid());
            
            //Read tasks
            $task_t =  self::checkTimer($this->timer_update_price_monitor_subscribes, $price->timer_update_task_ts);
            if($task_t) {
                $price->timer_update_task_ts = microtime(true)*1E6;
                $tasks_arr = $price->getActiveExchangePairMonitoring();
                //Log::systemLog('debug',"Process type \"Price Monitor\" TASKS pid=". json_encode($tasks_arr));
            }
            
            //Price Logger
            $price_t = self::checkTimer($this->timer_update_price_monitor, $price->timer_update_price_ts);
            if($price_t) {
                $price->timer_update_price_ts = microtime(true)*1E6;
                //Log SPOT market
                //Read market data from RAM
                if(isset($tasks_arr) && is_array($tasks_arr)) {
                    $date_obj = new DateTime('now', new DateTimeZone('UTC'));
                    $spot_data = array();
                    foreach ($tasks_arr as $key=>$t) {
                        if(isset($t['spot'])) {
                            foreach ($t['spot'] as $key2=>$t2) {
                                // Exchange ID | Market (spot) | PAIR ID
                                $hash = hash('xxh3', $key.'|spot|'.$t2['id']);
                                $spot_data[$t2['id']] = OrderBook::readDepthRAM($hash);
                            }
                        }
                        if(isset($t['features'])) {
                            
                        }
                    }
                    //Log::systemLog('debug',"Process type \"Price Monitor\" DATA". json_encode($spot_data));
                    if(!empty($spot_data)) {
                        if(count($spot_data) > 0) {
                            $DB->startTransaction();
                            foreach ($spot_data as $p=>$q) {
                               // Log::systemLog('debug',"Process type \"Price Monitor\" QQQ ". json_encode($q));
                                if(isset($q['timestamp']) && isset($q['asks']) && isset($q['bids'])) {
                                    $tmp = $q['timestamp']*1E-6;
                                    //Log::systemLog('debug',"TIMESTAMP ". $tmp);
                                    //Log::systemLog('debug',"TIMESTAMP NOW ". microtime(true));
                                    //Log::systemLog('debug',"DELTA TIME ". (microtime(true) - $tmp));
                                    if((microtime(true) - $tmp) < 7) {
                                        $sql = 'INSERT INTO `PRICE_SPOT_LOG` (
                                                     `DATE`,
                                                     `PRICE_TIMESTAMP`,
                                                     `PAIR_ID`,
                                                     ASKS5_PRICE,ASKS5_VOLUME,ASKS4_PRICE,ASKS4_VOLUME,ASKS3_PRICE,ASKS3_VOLUME,ASKS2_PRICE,ASKS2_VOLUME,ASKS1_PRICE,ASKS1_VOLUME,
                                                     BIDS1_PRICE,BIDS1_VOLUME,BIDS2_PRICE,BIDS2_VOLUME,BIDS3_PRICE,BIDS3_VOLUME,BIDS4_PRICE,BIDS4_VOLUME,BIDS5_PRICE,BIDS5_VOLUME) 
                                                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                                        $bind = array();
                                        $bind[0]['type'] = 's';
                                        $bind[0]['value'] = $date_obj->format('Y-m-d H:i:s.u');

                                        $pair_time = DateTime::createFromFormat('U.u', number_format($tmp,3,".",""), new DateTimeZone('UTC'));
                                        $bind[1]['type'] = 's';
                                        $bind[1]['value'] = $pair_time->format('Y-m-d H:i:s.u');
                                        $bind[2]['type'] = 'i';
                                        $bind[2]['value'] = $p;
                                        $bind[3]['type'] = 'd';
                                        $bind[3]['value'] = $q['asks'][4][0];
                                        $bind[4]['type'] = 'd';
                                        $bind[4]['value'] = $q['asks'][4][1];
                                        $bind[5]['type'] = 'd';
                                        $bind[5]['value'] = $q['asks'][3][0];
                                        $bind[6]['type'] = 'd';
                                        $bind[6]['value'] = $q['asks'][3][1];
                                        $bind[7]['type'] = 'd';
                                        $bind[7]['value'] = $q['asks'][2][0];
                                        $bind[8]['type'] = 'd';
                                        $bind[8]['value'] = $q['asks'][2][1];
                                        $bind[9]['type'] = 'd';
                                        $bind[9]['value'] = $q['asks'][1][0];
                                        $bind[10]['type'] = 'd';
                                        $bind[10]['value'] = $q['asks'][1][1];
                                        $bind[11]['type'] = 'd';
                                        $bind[11]['value'] = $q['asks'][0][0];
                                        $bind[12]['type'] = 'd';
                                        $bind[12]['value'] = $q['asks'][0][1];
                                        $bind[13]['type'] = 'd';
                                        $bind[13]['value'] = $q['bids'][0][0];
                                        $bind[14]['type'] = 'd';
                                        $bind[14]['value'] = $q['bids'][0][1];
                                        $bind[15]['type'] = 'd';
                                        $bind[15]['value'] = $q['bids'][1][0];
                                        $bind[16]['type'] = 'd';
                                        $bind[16]['value'] = $q['bids'][1][1];
                                        $bind[17]['type'] = 'd';
                                        $bind[17]['value'] = $q['bids'][2][0];
                                        $bind[18]['type'] = 'd';
                                        $bind[18]['value'] = $q['bids'][2][1];
                                        $bind[19]['type'] = 'd';
                                        $bind[19]['value'] = $q['bids'][3][0];
                                        $bind[20]['type'] = 'd';
                                        $bind[20]['value'] = $q['bids'][3][1];
                                        $bind[21]['type'] = 'd';
                                        $bind[21]['value'] = $q['bids'][4][0];
                                        $bind[22]['type'] = 'd';
                                        $bind[22]['value'] = $q['bids'][4][1];
                                        $ins = $DB->insert($sql, $bind);                                        
                                        //Log::systemLog('debug',"Process type \"Price Monitor\" spot DATA BIND ". json_encode($bind));
                                    }
                                    else {
                                        $tmp = (int)$tmp;
                                        $do = new DateTime('now', new DateTimeZone('UTC'));
                                        $d = date_timestamp_set($do,$tmp);
                                        
                                        Log::systemLog('warn',"Process type \"Price Monitor\" spot DATA price timestamp very old ".$tmp. ' '.$d->format("Y-m-d H:i:s.u").' '. json_encode($q));
                                    }
                                }
                            }
                            $DB->commitTransaction();
                        }
                    }
                    //Log::systemLog('debug',"Process type \"Price Monitor\" WRITE INTO DB".(microtime(true)-$start_time));
                    //Log::systemLog('debug',"Process type \"Price Monitor\" DATA". json_encode($spot_data));
                }
            }
         
            usleep(100);
        }
    }
    private function manageAutoTraders() {
        global $DB;
        
        //run every 5 seconds, search in DB new tasks
        $sb = self::checkTimer($this->timer_update_traders, $this->timer_update_traders_ts);
        if($sb) {
            $this->timer_update_traders_ts = microtime(true)*1E6;
            
            //Get active Traders
            $sql = 'SELECT 
                        t.ID
                    FROM 
                        TRADE t
                    INNER JOIN 
                        `USER` u ON u.ID = t.UID  
                    INNER JOIN 
                        USERS_GROUP ug ON ug.UID = u.ID AND ug.GID = 2
                    INNER JOIN 
                        `GROUP` g ON g.ID = ug.GID 
                    WHERE 
                        t.ACTIVE = 1
                        AND u.ACTIVE = 1
                        AND g.ACTIVE = 1';
            $trade_list = $DB->select($sql);
            if(!empty($DB->getLastError())) {
                $message = "ERROR select Active Traders. ".$DB->getLastError();
                Log::systemLog('error', $message, "Trade Monitor");
                return false;
            }
            //Log::systemLog('debug', json_encode($trade_list));
            
            /** Manage Trader's processes
             *  Step 1 - Find active Trader's process
             *           Check activity process. If process is not active, kill it.
             *  Step 2 - Create new process, if not exists or not respond
             *  Step 3 - Send array pairs to Exchange's process for receive order book if CRC is different
             *  Step 4 - Kill excess processes
             */

            if($trade_list !== false && !empty($trade_list)) {
                foreach ($trade_list as $tr) {
                    // Step 1
                    $trader_exist_flag = false;
                    if(!empty($this->proc)) {
                        foreach ($this->proc as $k=>$proc) {
                            if($proc['trade_id'] === $tr['ID'] && $proc['pid'] > 0) {
                                $trader_exist_flag = true;
                                //Check response
                                if((microtime(true)*1E6 - $proc['timestamp'])*1E-6 > 15) {
                                    $trader_exist_flag = false;
                                    Log::systemLog('error', 'Proc='.$proc['pid'].' Traader NOT RESPONSE more 9 seconds.', "Trade Monitor");
                                    $kill = posix_kill($this->proc[$k]['pid'], SIGTERM);
                                    unset($this->proc[$k]);
                                    if(!empty($this->proc_tree)) {   
                                        foreach($this->proc_tree as $k2=>$proc2) {
                                            if($proc2['pid'] == $proc['pid']) {
                                                unset($this->proc_tree[$k]);
                                            }
                                        }
                                    }
                                    if($kill) {
                                        Log::systemLog('error', 'Proc Trader process ='.$proc['pid'].' is killed.' );
                                    }
                                    else {
                                        Log::systemLog('error', 'ERROR kill Trader process ='.$proc['pid']);
                                    }
                                }
                            }    
                        }
                    }
                    //Step 2
                    //Create new child process if he is not exist
                    if(!$trader_exist_flag) {
                        $DB->close();
                        $msg = array('trade_id'=>$tr['ID']);
                        $tpid = $this->newProcess('ctd_trader');
                        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 
                        Log::systemLog('debug', 'Init start Trader proc = '. $tpid.' TRADER_ID = '.$tr['ID'], "Trade Monitor");
                        //Add information to proccess
                        foreach ($this->proc as $kproc=>$proc) {
                            if($proc['pid'] == $tpid) {
                                $this->proc[$kproc]['trade_id'] = $tr['ID'];
                            }
                        }
                        //Log::systemLog('debug', 'TRADER PROC ='. json_encode($this->proc));
                        //
                        //Step 3
                        //Send new process info about exchange and market type
                        ServiceRAM::write($tpid,'create_trader',$msg);
                        //Log::systemLog('debug', 'SEND to ServiceRAM command "create_trader" to process = '. $tpid.' from pid = '.getmypid().' '. json_encode($msg));
                    }
                }
            }
            
            //Step 4
            if(!empty($this->proc)) {   
                //Destroy excess processes
                foreach($this->proc as $k=>$proc) {
                    $trader_remove_flag = true;
                    if(!empty($trade_list)) {
                        foreach ($trade_list as $key=>$tr) {
                            if($proc['trade_id'] == $tr['ID']) {
                                $trader_remove_flag = false;
                            }
                        }
                    }
                    //kill process
                    if($trader_remove_flag === true) {
                        Log::systemLog('debug', 'Kill excess Trader process = '.$proc['pid'].' TRADE_ID = '.$proc['trade_id'], "Trade Monitor");
                        $kill = posix_kill($proc['pid'], SIGTERM);  
                        if($kill) {
                            unset($this->proc[$k]);
                            if(!empty($this->proc_tree)) {   
                                foreach($this->proc_tree as $kt=>$proct) {
                                    if($proc['pid'] == $proct['pid']) {
                                        unset($this->proc_tree[$kt]);
                                    }
                                }
                            }
                        }
                        else {
                            Log::systemLog('error', 'ERROR kill excess Trader process ='.$proc['pid'].' TRADE_ID = '.$proc['trade_id'], "Trade Monitor");
                        }
                    }
                }
            }  
        }
    }

    private function runProcTradeMonitor() {
        global $DB;
        //Create DB connection
        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 
        
        Log::systemLog('info',"Process type \"Trade Monitor\" STARTED pid=".getmypid(), "Trade Monitor");    
        
        while(1) {
            $this->timestamp = microtime(true)*1E6;
            
            //For every process need update ProcTree for main process Every 1 second
            $this->updateProcTree();
            //Log::systemLog('debug', 'PROC TREE '. json_encode($this->proc_tree).' proc='. getmypid());
            
            //Main taks for this process - manage exchanges connections
            //Periodic read DB for tasks and create exchange's process
            
            $this->manageAutoTraders();
                        
            usleep(100);
        }
    }
    private function runProcTrader() {
        global $DB;
        //Trader must run after run all system's processes
        sleep(10);
        $this->proc_info['status'] = 'running';
        $this->proc_info['trade_count'] = 0;
        
        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 
        Log::systemLog('info',"Process type \"Trader\" STARTED pid=".getmypid(), "Trader");
        $this->proc = array();

        //Read RAM
        do {
            $task_create_trader = ServiceRAM::read('create_trader');
        }
        while($task_create_trader === false || empty($task_create_trader));        
        Log::systemLog('debug', 'Process "Trader" pid='. getmypid().' received = '.json_encode($task_create_trader), "Trader");
        $tr_id = $task_create_trader[0]['data']['trade_id'];
        
        //Create object and init all data
        $trader = new Trader($tr_id);
        //Log::systemLog('debug', 'TRADER pid='. getmypid().' CLASSDATA = '.$trader->chain_transfer, "Trader");
        $trader->trader_status = 1;
        
        while(1) {
            $this->timestamp = microtime(true)*1E6; 
            $continue = false; //algorithm flag
            //Update tree
            $this->updateProcTree();
            
            //Update trader's data 
            $update = self::checkTimer($trader->timer_update_data, $trader->timer_update_data_ts);
            if($update) {
                $trader->timer_update_data_ts = microtime(true)*1E6;
                //Log::systemLog('debug', 'TRADER pid='. getmypid().' RUN TIMER UPDATE ', "Trader");
                $trader->updateTrader();
            }
            
            //Check status last arbitrage transaction (return integer)
            $arb_last_status = $trader->getLastArbTransStatus();
            //Log::systemLog('debug', 'ARBSTATUS pid='. $arb_status.'', "Trader");
            switch($arb_last_status) {
                case 4: //error
                    $trader->trader_status = 20;
                    $this->proc_info['status'] = 'error';
                    break;
                case 6: //complete but loss
                    if ($trader->checkOverflowCountLossArbTrans()) {
                        $trader->trader_status = 30;
                        $this->proc_info['status'] = 'high_loss';
                    }
                    else {
                        $this->proc_info['status'] = 'running';
                        $continue = true;
                    }
                   break;
                default:
                    $continue = true;
            }
            
            if($continue == false) {
                sleep(2);
            }
            else {
                switch($trader->trader_type) {
                    case 1:
                        /*
                         * Type 1. Market Arbitrage
                        */

                        //Calculate position
                        if($continue) {

                        }

                        //Prepare arbitrage transaction
                        if($continue && $trader->arbitrage_id == 0) {
                            $arb_id = $trader->prepareArbitrageTrans();
                            if(!$arb_id) {
                                $continue = false; 
                            }
                        }

                        //Analyze
                        if($continue) {
                            $trade_allow = false;
                            do {
                                //This for keep alive process
                                $this->timestamp = microtime(true)*1E6;
                                $this->updateProcTree();
                                //Update trader's data 
                                $update = self::checkTimer($trader->timer_update_data, $trader->timer_update_data_ts);
                                if($update) {
                                    $trader->timer_update_data_ts = microtime(true)*1E6;
                                    //Log::systemLog('debug', 'TRADER pid='. getmypid().' RUN TIMER UPDATE ', "Trader");
                                    $trader->updateTrader();
                                }

                                //Read Order Book data for all TradeInstance. Read 20-65us at 6 trade proc
                                //$start = microtime(true);
                                $trader->readOrderBooks(); 
                                $trader->readBBOs();
                                //$stop = microtime(true) - $start;
                                //Log::systemLog('warn', 'ORDER BOOK ******** READ '. $stop, "Trader"); // ~ 200-500us
                                
                                //$start_2 = microtime(true);
                                $trade_arb_pairs = $trader->getProfitablePair_Type1();
                                //$trans_calc = $trader->calculateType_1();
                                $trans_calc = false;
                                //$stop_2 = microtime(true) - $start_2;
                                //Log::systemLog('warn', 'CALCULATE '. $stop_2, "Trader");
                                
                                if($trans_calc !== false) {
                                    $trade_allow = true;
                                }
                                else {
                                    usleep(10000); //pause 3ms
                                }
                            }
                            while($trade_allow !== true);
                            Log::systemLog('debug', 'READY TO TRADE '. json_encode($trans_calc), "Trader");
                        }

                        //Calculation Ok. Send Buy and Sell request to RAM
                        if($continue) {  
                            $start_3 = microtime(true);
                            $sell = $trader->fetchObjectPoolTraderInstace($trans_calc['sell_instance_id']);
                            $buy = $trader->fetchObjectPoolTraderInstace($trans_calc['buy_instance_id']);
                            $volume = $trans_calc['volume'];
                            $calc_profit = $trans_calc['profit'];
                            $stop_3 = microtime(true) - $start_3;
                            //Log::systemLog('debug', 'FETCH INSTANCE '. $stop_3, "Trader");
   
                            //Push request to Worker RAM
                            $start_4 = microtime(true);
                            $s = $sell->requestMarketSell($arb_id, $volume);
                            $b = $buy->requestMarketBuy($arb_id, $volume);
                            $stop_4 = microtime(true) - $start_4;
                            //Log::systemLog('debug', 'PUSH ARBITRAGE ACTION TO RAM '. $stop_4, "Trader");
                            
                            //Write arbitrage transaction detail into DB
                            //Log::systemLog('error', 'MARKET SELL arb_id='.$arb_id.' vol='.$volume.' to_ram='.$this->worker_address.' Profit='.$calc_profit.' '.$sell->exchange_name." readOB=".$stop.' calc='.$stop_2." getObj=".$stop_3." sendToTRAM=".$stop_4, "Trader");
                            //Log::systemLog('error', 'MARKET BUY arb_id='.$arb_id.' vol='.$volume.' to_ram='.$this->worker_address.' Profit='.$calc_profit.' '.$buy->exchange_name, "Trader");
                            if($s && $b) {

                            }
                        }

                        //reset arbitrage transaction
                        //$trader->arbitrage_id = 0;

                        sleep(2);
                        break;
                    default:
                        sleep(5);
                }
            }
        }
        
    }
    private function runProcService() {
        global $DB;
        Log::systemLog('info',"Process type \"Service\" STARTED pid=".getmypid(), "Service");    
        
        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials());
        
        $service = new Service();
        
        while(1) {
            $this->timestamp = microtime(true)*1E6;
            
            $this->updateProcTree();
            //Log::systemLog('debug', 'PROC TREE '. json_encode($this->proc_tree).' proc='. getmypid());
            
            //Sync trade pairs from all active exchanges
            $service->syncTradePair();
            
            //sync active pair's fee from all exchanges 
            $service->syncFeesActivePairs();
            
            //sync coins from all exchanges (deposit, withdrawal)
            $service->syncCoins();
            
            usleep(1000000);
        }
    }   
    private function runProcTradeWorkerMonitor() {
        global $DB;
        //Create DB connection
        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 
        
        Log::systemLog('info',"Process type \"Trade Worker Monitor\" STARTED pid=".getmypid(), "Trade Worker Monitor");    
        
        while(1) {
            $this->timestamp = microtime(true)*1E6;
            //For every process need update ProcTree for main process Every 1 second
            $this->updateProcTree();
            //Log::systemLog('debug', 'PROC TREE '. json_encode($this->proc_tree).' proc='. getmypid());
            
            //Main taks for this process - manage exchanges's workers
            //Periodic read DB for tasks and create trade worker processes
            
            $this->manageAutoTraderWorkers();
                        
            usleep(100);
        }
    }
    
    private function manageAutoTraderWorkers() {
        global $DB;
        
        //run every 5 seconds, search in DB new tasks
        $sb = self::checkTimer($this->timer_update_trade_workers, $this->timer_update_trade_workers_ts);
        if($sb) {
            $this->timer_update_trade_workers_ts = microtime(true)*1E6;
            
            //Define Trader Workers
            $sql = 'SELECT 
                        ACCOUNT_ID AS ACCOUNT_ID,
                        PAIR_ID AS WORKERS_PAIR_ID,
                        MARKET AS MARKET
                    FROM
                    (
                        (
                            SELECT 
                                ea.ID AS ACCOUNT_ID,
                                e.ID AS EXID,
                                0 AS PAIR_ID,
                                \'spot\' AS MARKET
                            FROM 
                                TRADE_SPOT_ARRAYS tsa
                            INNER JOIN
                                TRADE tr ON tr.ID = tsa.TRADE_ID
                            LEFT JOIN 
                                EXCHANGE_ACCOUNTS ea ON ea.ID = tsa.EAID
                            LEFT JOIN 
                                EXCHANGE e ON e.ID = ea.EXID 
                            INNER JOIN 
                                `USER` u ON u.ID = tr.UID 
                            INNER JOIN 
                                USERS_GROUP ug ON ug.UID = u.ID AND ug.GID = 2
                            INNER JOIN 
                                `GROUP` g ON g.ID = ug.GID
                            WHERE 
                                tr.`TYPE` IN (1,2,5)
                                AND tr.ACTIVE = 1
                                AND ea.ACTIVE = 1
                                AND e.ACTIVE = 1
                                AND u.ACTIVE = 1
                                AND g.ACTIVE = 1
                            GROUP BY 
                                e.ID,
                                ea.ID
                        )
                        UNION
                        (   
                            SELECT 
                                ea.ID AS ACCOUNT_ID,
                                e.ID AS EXID,
                                tsa.PAIR_ID AS PAIR_ID,
                                \'spot\' AS MARKET
                            FROM 
                                TRADE_SPOT_ARRAYS tsa
                            INNER JOIN
                                TRADE tr ON tr.ID = tsa.TRADE_ID
                            LEFT JOIN 
                                EXCHANGE_ACCOUNTS ea ON ea.ID = tsa.EAID
                            LEFT JOIN 
                                EXCHANGE e ON e.ID = ea.EXID 
                            INNER JOIN 
                                `USER` u ON u.ID = tr.UID 
                            INNER JOIN 
                                USERS_GROUP ug ON ug.UID = u.ID AND ug.GID = 2
                            INNER JOIN 
                                `GROUP` g ON g.ID = ug.GID
                            WHERE 
                                tr.`TYPE` IN (3,4)
                                AND tr.ACTIVE = 1
                                AND ea.ACTIVE = 1
                                AND e.ACTIVE = 1
                                AND u.ACTIVE = 1
                                AND g.ACTIVE = 1
                            GROUP BY 
                                e.ID,
                                ea.ID,
                                tsa.PAIR_ID
                        )
                    ) t;';
            $trade_worker_list = $DB->select($sql);
            if(!empty($DB->getLastError())) {
                $message = "ERROR define Trader Workers. ".$DB->getLastError();
                Log::systemLog('error', $message, "Trade Worker Monitor");
                return false;
            }
            
            if($trade_worker_list !== false && !empty($trade_worker_list)) {
                foreach ($trade_worker_list as $tr) {
                    // Step 1
                    $trade_worker_exist_flag = false;
                    if(!empty($this->proc)) {
                        foreach ($this->proc as $k=>$proc) {
                            if($proc['trade_worker_account_id'] === $tr['ACCOUNT_ID'] 
                                    && $proc['trade_worker_pair_id'] === $tr['WORKERS_PAIR_ID']
                                    && $proc['trade_worker_market'] === $tr['MARKET']
                                    && $proc['pid'] > 0) {
                                
                                $trade_worker_exist_flag = true;
                                //Check response
                                if((microtime(true)*1E6 - $proc['timestamp'])*1E-6 > 9) {
                                    $trade_worker_exist_flag = false;
                                    Log::systemLog('error', 'Proc='.$proc['pid'].' Traader Worker NOT RESPONSE more 9 seconds.', "Trade Worker Monitor");
                                    $kill = posix_kill($this->proc[$k], SIGTERM);
                                    unset($this->proc[$k]);
                                    if(!empty($this->proc_tree)) {   
                                        foreach($this->proc_tree as $k2=>$proc2) {
                                            if($proc2['pid'] == $proc['pid']) {
                                                unset($this->proc_tree[$k]);
                                            }
                                        }
                                    }
                                    if($kill) {
                                        Log::systemLog('error', 'Proc Worker Trader process ='.$proc['pid'].' is killed.', "Trade Worker Monitor" );
                                    }
                                    else {
                                        Log::systemLog('error', 'ERROR kill Worker Trader process ='.$proc['pid'], "Trade Worker Monitor");
                                    }
                                }
                            }
   
                        }
                    }
                    //Step 2
                    //Create new child process if he is not exist
                    if(!$trade_worker_exist_flag) {
                        $DB->close();
                        $msg = array('trade_worker_account_id'=>$tr['ACCOUNT_ID'],'trade_worker_pair_id'=>$tr['WORKERS_PAIR_ID'],'trade_worker_market'=>$tr['MARKET']);
                        $tpid = $this->newProcess('ctd_trade_worker');
                        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 
                        Log::systemLog('debug', 'Init start Trade Worker proc = '. $tpid.' ACCOUNT_ID = '.$tr['ACCOUNT_ID'].' WORKERS_PAIR_ID = '.$tr['WORKERS_PAIR_ID'].' MARKET = '.$tr['MARKET'], "Trade Worker Monitor");
                        //Add information to proccess
                        foreach ($this->proc as $kproc=>$proc) {
                            if($proc['pid'] == $tpid) {
                                $this->proc[$kproc]['trade_worker_account_id'] = $tr['ACCOUNT_ID'];
                                $this->proc[$kproc]['trade_worker_pair_id'] = $tr['WORKERS_PAIR_ID'];
                                $this->proc[$kproc]['trade_worker_pair_name'] = Exchange::detectNamesPair($tr['WORKERS_PAIR_ID']);
                                $this->proc[$kproc]['trade_worker_market'] = $tr['MARKET'];
                            }
                        }
                        //Log::systemLog('debug', 'WORKER PROC ='. json_encode($this->proc));
                        //
                        //Step 3
                        //Send new process info 
                        ServiceRAM::write($tpid,'create_trade_worker',$msg);
                        Log::systemLog('debug', 'SEND to ServiceRAM command "create_trade_worker" to process = '. $tpid.' from pid = '.getmypid().' '. json_encode($msg), "Trade Worker Monitor");
                    }
                }
            }

            //Step 4
            if(!empty($this->proc)) {   
                //Destroy excess processes
                foreach($this->proc as $k=>$proc) {
                    $trade_worker_remove_flag = true;
                    if(!empty($trade_worker_list)) {
                        foreach ($trade_worker_list as $key=>$tr) {
                            if($proc['trade_worker_account_id'] === $tr['ACCOUNT_ID'] 
                                                && $proc['trade_worker_pair_id'] === $tr['WORKERS_PAIR_ID']
                                                && $proc['trade_worker_market'] === $tr['MARKET']) {
                                $trade_worker_remove_flag = false;
                            }
                        }
                    }
                    //kill process
                    if($trade_worker_remove_flag === true) {
                        Log::systemLog('debug', 'Kill excess Trader Worker process = '.$proc['pid'].' ACCOUNT_ID = '.$tr['ACCOUNT_ID'].' WORKERS_PAIR_ID = '.$tr['WORKERS_PAIR_ID'].' MARKET = '.$tr['MARKET'], "Trade Worker Monitor");
                        $kill = posix_kill($proc['pid'], SIGTERM);  
                        if($kill) {
                            unset($this->proc[$k]);
                            if(!empty($this->proc_tree)) {   
                                foreach($this->proc_tree as $kt=>$proct) {
                                    if($proc['pid'] == $proct['pid']) {
                                        unset($this->proc_tree[$kt]);
                                    }
                                }
                            }
                        }
                        else {
                            Log::systemLog('error', 'ERROR kill excess Trader process ='.$proc['pid'].' ACCOUNT_ID = '.$tr['ACCOUNT_ID'].' WORKERS_PAIR_ID = '.$tr['WORKERS_PAIR_ID'].' MARKET = '.$tr['MARKET'], "Trade Worker Monitor");
                        }
                    }
                }
            }  
        }
    }
    
     private function runProcTradeWorker() {
        global $DB; 
        //Create DB connection
        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 
        
        Log::systemLog('info',"Process type \"Trade Worker\" STARTED pid=".getmypid(), "Trade Worker");  
        do {
            $task_create_worker = ServiceRAM::read('create_trade_worker');
        }
        while($task_create_worker === false || empty($task_create_worker));
        Log::systemLog('warn', 'Process "Trade Worker" pid='. getmypid().' received = '.json_encode($task_create_worker), "Trade Worker");

        //init
        $worker = new TradeWorker($task_create_worker[0]['data']);
        
        while(1) {
            $this->timestamp = microtime(true)*1E6;
            $this->updateProcTree();
            
            //read RAM
            $queue_el = $worker->readInputRAM();
            if($queue_el) {
                Log::systemLog('warn', 'Process "Trade Worker" pid='. getmypid().' read RAM '.json_encode($queue_el), "Trade Worker");
            }
            
            usleep(100);
        }
    }
    private function runProcOrderTransMonitor() {
        global $DB;
        //Create DB connection
        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 
        
        Log::systemLog('info',"Process type \"Order and Transaction Monitor\" STARTED pid=".getmypid(), "Order&Trans Monitor");    
        
        while(1) {
            $this->timestamp = microtime(true)*1E6;
            //For every process need update ProcTree for main process Every 1 second
            $this->updateProcTree();
            //Log::systemLog('debug', 'PROC TREE '. json_encode($this->proc_tree).' proc='. getmypid());
            
            //Main taks for this process - manage transacions and orders logger precesses
            //Periodic read DB for tasks and create processes
            
            $this->manageAutoOrderTrans();
                        
            usleep(100);
        }
    }
    
    private function manageAutoOrderTrans() {
        return false;
    }
       
   /*    
    public static function checkTimer($time, $start_time) {
        if((microtime(true)*1E6 - $start_time) > $time){
            return true;
        }
        return false;
    }    
    
    private function sendProcTree() {
        $send = array();
        $pid = getmypid();
        $send[$pid] = $this->proc_tree;
        ServiceRAM::write($this->parent_proc, "proc_tree", $send);
        //Log::systemLog('debug', 'SEND to ServiceRAM PROC TREE from proc='. getmypid().' to parent '.$this->parent_proc.' '. json_encode($send).' ');
    }
    private function receiveProcTree () {
        $pt_arr = ServiceRAM::read("proc_tree");
        if($pt_arr !== false && !empty($pt_arr)) {
           //Log::systemLog('debug', 'Recevive PROC TREE from RAM '. json_encode($pt_arr).' proc='. getmypid());
           return $pt_arr;
        } 
        return false;
    }
    /*public function updateProcTree() {
        $u = self::checkTimer($this->timer_update_tree, $this->timer_update_tree_ts);
        if($u === true) {
            $this->timer_update_tree_ts = microtime(true)*1E6;
            //read child proc info from RAM
            if(!empty($this->proc)) {
                $ch = $this->receiveProcTree();
                //Log::systemLog('debug', 'Recevive PROC TREE'. json_encode($ch).' proc='. getmypid());
                if(is_array($ch)) {
                    foreach ($ch as $c) {
                        foreach ($c['data'] as $kpt=>$pt) {
                            foreach ($this->proc as $kproc=>$proc) {
                               if($proc['pid'] == $kpt) {
                                   //Нашли в proc процесс, который к нам пришел от дочернего
                                   //обновим timestamp процесса, который ответил
                                   //Log::systemLog('debug', 'Recevive PROC TREE KPROC '. json_encode($kproc).' proc='. getmypid());
                                   $this->proc[$kproc]['timestamp'] = $c['timestamp'];
                                   //Заполним proc_tree
                                   $tmp = array();
                                   $tmp['pid'] = $kpt;
                                   $tmp['timestamp'] = $c['timestamp'];
                                   $tmp['name'] = $proc['name'];
                                   if(isset($proc['market'])) {
                                       $tmp['market'] = $proc['market'];
                                   }
                                   if(isset($proc['exchange_name'])) {
                                       $tmp['exchange_name'] = $proc['exchange_name'];
                                   }
                                   if(isset($proc['subscribe'])) {
                                       $tmp['subscribe'] = $proc['subscribe'];
                                   }
                                   if(isset($proc['trade_id'])) {
                                       $tmp['trade_id'] = $proc['trade_id'];
                                   }
                                   if(isset($proc['trade_worker_account_id'])) {
                                       $tmp['acc_id'] = $proc['trade_worker_account_id'];
                                   }
                                   if(isset($proc['trade_worker_pair_id'])){
                                       $tmp['trade_worker_pair_id'] = $proc['trade_worker_pair_id'];
                                   }
                                   if(isset($proc['trade_worker_pair_name'])){
                                       $tmp['trade_worker_pair_name'] = $proc['trade_worker_pair_name'];
                                   }
                                   if(isset($proc['trade_worker_market'])) {
                                       $tmp['trade_worker_market'] = $proc['trade_worker_market'];
                                   }
                                   $tmp['child'] = $pt;
                                   if(empty($this->proc_tree)) {
                                       $this->proc_tree[] = $tmp;
                                   }
                                   else {
                                       $found = false;
                                       foreach ($this->proc_tree as $kpp=>$pp) {
                                           if($pp['pid'] == $kpt) {
                                               $this->proc_tree[$kpp] = $tmp;
                                               $found = true;
                                           }
                                       }
                                       if(!$found) {
                                            $this->proc_tree[] = $tmp;
                                       }
                                   }
                                   continue;
                               }
                           }
                        }
                    }
                }
            }
            if($this->proc_name != 'ctd_main') {
                $this->sendProcTree();
            }
            //Log::systemLog('debug', 'Timer '. json_encode($u).' proc='. getmypid());
        }
    }*/
    
    /*private function objToArray($obj) {
        if (!is_object($obj) && !is_array($obj)) {
            return $obj;
        }
	$arr = array();
        foreach ($obj as $key => $value) {
            $arr[$key] = $this->objToArray($value);
        }
        return $arr;
    }*/
    
    private function unchunk($result) {
        return preg_replace_callback(
            '/(?:(?:\r\n|\n)|^)([0-9A-F]+)(?:\r\n|\n){1,2}(.*?)'.
            '((?:\r\n|\n)(?:[0-9A-F]+(?:\r\n|\n))|$)/si',
            function($matches) {
                return hexdec($matches[1]) == strlen($matches[2]) ? $matches[2] : $matches[0];
            },
            $result
        );
    }
    
    
}
?>