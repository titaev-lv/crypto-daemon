<?php
class ctdaemon {
    public $proc_name = '';
    public $parent_proc = false;

    //Активные дочерние процессы. Вся информация хранится в массиве
    public $proc = array(); // name - имя сервиса; pid - pid; 2 - время старт
    public $proc_tree = array(); // child tree processes
       
    //DB connection
    private $db_engine = false;
    private $db_credentials = array();

    //info time
    public $start = 0; 
    public $timestamp = NULL;
    
    //Timers
    //Processes tree
    public $timer_update_tree = 200000;
    private $timer_update_tree_ts = 0;
    //Active trade pair's order book subscribe
    public $timer_update_ob_trade_subscribes = 5000000;
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
    public $timer_update_trade_workers_ts = 0;
    
    public function isDaemonActive($pid_file) {
        if(is_file($pid_file)) {
            $pid = file_get_contents($pid_file);
            //проверяем на наличие процесса
            if(posix_kill(intval($pid),0)) {
                //демон уже запущен
                return true;
            } else {
                //pid-файл есть, но процесса нет 
                if(!unlink($pid_file)) {
                    //не могу уничтожить pid-файл. ошибка
                    exit(-1);
                }
            }
        }
        return false;
    }
        
    public function newProcess($type) {
        $new_pid = pcntl_fork();
        if ($new_pid == -1) {
            //Error fork 
            Log::systemLog('error',"Error forked process ".$type);
            exit('FATAL ERROR. Error forked process '.$type.PHP_EOL);
        } 
        else if ($new_pid) {
            //Parent process 
            $tmp['name'] = $type;
            $tmp['pid'] = $new_pid;
            $tmp['timestamp'] = microtime(true)*1E6;
            $this->proc[] = $tmp;
            $data['type'] =  $type;
            $data['parent_proc'] = getmypid();
            ServiceRAM::write($new_pid, 'create_proc', $data);
            //Log::systemLog('debug', 'SEND to ServiceRAM command "create_proc" to process = '. $new_pid.' from pid = '.getmypid().' '. json_encode($data));
            return $new_pid;
        } 
        else {
            Log::systemLog('debug',"Create new process pid=".getmypid(), "New Process");
            $this->proc = array();
            $this->proc_tree = array();
            $this->start = microtime(true)*1E6;
            $this->timestamp = microtime(true)*1E6;
            do {
                $ram = ServiceRAM::read('create_proc'); //return array
            }
            while($ram === false);
            Log::systemLog('debug',"Read from RAM TYPE PROCESS process pid=".getmypid(). ' '.json_encode($ram), "New Process");
            if(count($ram) !== 1) {
                 Log::systemLog('error',"ServiceRAM data for create process pid=".getmypid(). ' failed', "New Process");
                 exit();
            }          
            $this->parent_proc = (int)$ram[0]['data']['parent_proc'];
            $type = $ram[0]['data']['type'];
            $this->proc_name = $type;
            cli_set_process_title($type);
            switch ($type) {
                case 'ctd_orderbook_monitor':
                    $this->runProcOrderBookMonitor();
                    break;
                case 'ctd_exchange_orderbook':
                    $this->runProcExchangeOrderBook();
                    break;
                case 'ctd_price_monitor':
                    $this->runProcPriceMonitor();
                    break;
                case 'ctd_trade_monitor':
                    $this->runProcTradeMonitor();
                    break;
                case 'ctd_trader':
                    $this->runProcTrader();
                    break;
                case 'ctd_service':
                    $this->runProcService();
                    break;
                case 'ctd_trade_worker_monitor':
                    $this->runProcTradeWorkerMonitor();
                    break;
                case 'ctd_order_trans_monitor':
                    $this->runProcOrderTransMonitor();
                    break;
                case 'ctd_trade_worker':
                    $this->runProcTradeWorker();
                    break;
                default:
                   Log::systemLog('error',"Filed create process pid=".getmypid(). ' Error run method for '.$type, "New Process"); 
            }
        }
    }
    
    public function runProcOrderBookMonitor() {
        global $DB;
        //Create DB connection

        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 

        Log::systemLog('info',"Process type \"Order Book Monitor\" STARTED pid=".getmypid(), "Order Book Monitor");    
        
        while(1) {
            $this->timestamp = microtime(true)*1E6;
            
            //For every process need update ProcTree for main process Every 1 second
            $this->updateProcTree();
            //Log::systemLog('debug', 'PROC TREE '. json_encode($this->proc_tree).' proc='. getmypid());
            
            //Main taks for this process - manage exchanges connections
            //Periodic read DB for tasks and create exchange's process
            $this->manageOrderBookExchanges();                        
            usleep(50000);
        }
    }
    
    private function manageOrderBookExchanges() {
        global $DB;
        
        //run every 5 seconds, search in DB new tasks
        $sb = self::checkTimer($this->timer_update_ob_trade_subscribes, $this->timer_update_ob_trade_subscribes_ts);
        if($sb) {
            //reset time for timer
            $this->timer_update_ob_trade_subscribes_ts = microtime(true)*1E6;

            //Available merkets
            $markets = array("spot","features");
            
            $tasks_arr = array();
            $proc_not_response_arr = array();
            
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
            $tasks_arr = $this->getActiveExchangePairOrderBook();
            //Log::systemLog('debug', 'TASKS SUBSCRIBE LIST='. json_encode($tasks_arr));
            
            /** For every exchange will can have 2 processes (spot and feature market)
             *  Step 1 - Find active process for every exchange and every market.   
             *           Check activity process. If process is not active, add to array $proc_not_response_arr, mark it.
             *  Step 2 - Create new process, if not exists or not respond
             *  Step 3 - Send array pairs to Exchange's process for receive order book if CRC is different
             *  Step 4 - Kill excess processes
             *  Step 5 - Destroy processes not response
             */
            if($tasks_arr !== false && !empty($tasks_arr)) {
                ///------ For every exchange  
                foreach ($tasks_arr as $key=>$tr) {
                    //---- For every market
                    foreach ($markets as $market) {
                        //search active children process
                        //Step 1
                        $exchange_market_exist_flag = false;
                        $proc_not_response = false;
                        if(!empty($this->proc)) {
                            foreach ($this->proc as $k=>$proc) {
                                if($proc['exchange_id'] === $key && $proc['pid'] > 0 &&  $proc['market'] == $market) {
                                    $exchange_market_exist_flag = true;
                                    //Check response
                                    if((microtime(true)*1E6 - $proc['timestamp'])*1E-6 > 9) {
                                        $proc_not_response = true;
                                        $proc_not_response_arr[] = $proc['pid'];
                                        Log::systemLog('error', 'Proc='.$proc['pid'].' Order Book Exchange NOT RESPONSE more 9 seconds.', "Order Book Monitor");
                                    }
                                }
                            }
                        }
                        //Create new child process if he is not exist
                        //Step 2
                        if(!$exchange_market_exist_flag && isset($tr[$market])) {
                            $DB->close();
                            $msg = array('exchange_id'=>$key,'market'=>$market);
                            $epid = $this->newProcess('ctd_exchange_orderbook');
                            $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 
                            Log::systemLog('debug', 'Order Book Monitor init start Exchange child proc ='. $epid.' Exchange '.$tr['name'].' Market '.strtoupper($market), "Order Book Monitor");
                            //Add information to proccess
                            foreach ($this->proc as $kproc=>$proc) {
                                if($proc['pid'] == $epid) {
                                    $this->proc[$kproc]['market'] = $market;
                                    $this->proc[$kproc]['exchange_id'] = $key;
                                    $this->proc[$kproc]['exchange_name'] = $tr['name'];
                                    //$this->proc[$kproc]['websoket_timeout'] = 30; //real need??
                                }
                            }
                            //Send new process info about exchange and market type
                            ServiceRAM::write($epid,'create_exchange_orderbook',$msg);
                            //Log::systemLog('debug', 'SEND to ServiceRAM command "create_exchange_orderbook" to process = '. $epid.' from pid = '.getmypid().' '. json_encode($msg));
                        }
                        //Log::systemLog('debug', 'OB PROC ='. json_encode($this->proc));
                        //Log::systemLog('debug', 'OB TREE PROC ='. json_encode($this->proc_tree));
                        
                        //Send array pairs to Exchange's process for receive order book
                        //Step 3
                        foreach ($this->proc as $kproc=>$proc) {
                            if($proc['exchange_id'] === $key && $proc['market'] == $market) {
                                if(isset($tr[$market])) {
                                    //Calculate CRC summ for 
                                    $subscribe_crc = crc32(json_encode($tr[$market]));
                                    if(!isset($proc['subscribe_crc']) || $proc['subscribe_crc'] !== $subscribe_crc) {
                                        $this->proc[$kproc]['subscribe'] = $tr[$market];
                                        if($proc_not_response === false) {
                                            ServiceRAM::write($proc['pid'],'active_exchange_pair_orderbook',$tr[$market]);
                                            //Log::systemLog('debug', 'SEND to ServiceRAM command "active_exchange_pair_orderbook" to order book process = '. $proc['pid'].' '. json_encode($tr[$market]));
                                        }
                                        else {
                                            Log::systemLog('warn', 'NOT SEND to order book process = '. $proc['pid'].' '. json_encode($tr[$market]).' Process not response.', "Order Book Monitor");
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            //Step 4. Kill excess processes
            if(!empty($this->proc)) {   
                //Destroy excess processes
                foreach($this->proc as $k=>$proc) {
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
                        Log::systemLog('debug', 'Kill excess Echange order book process = '.$proc['pid'].' Exchange name: '.$proc['exchange_name'].' market '. strtoupper($proc['market']), "Order Book Monitor");
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
                            Log::systemLog('error', 'ERROR kill excess Echange order book process ='.$proc['pid'].' Exchange name: '.$proc['exchange_name'].' market '. strtoupper($proc['market']), "Order Book Monitor");
                        }
                    }
                }
            }  
            //Step 5
            //Destroy processes not response
            if(!empty($proc_not_response_arr)) {
                foreach ($proc_not_response_arr as $pkill) {
                    $kill = posix_kill($pkill, SIGTERM);
                    if(!empty($this->proc)) {   
                        foreach($this->proc as $k=>$proc) {
                            if($proc['pid'] == $pkill) {
                                 unset($this->proc[$k]);
                            }
                        }
                    }
                    if(!empty($this->proc_tree)) {   
                        foreach($this->proc_tree as $k=>$proc) {
                            if($proc['pid'] == $pkill) {
                                 unset($this->proc_tree[$k]);
                            }
                        }
                    }
                    if($kill) {
                        Log::systemLog('error', 'Proc Echange order book process ='.$pkill.' is killed.', "Order Book Monitor" );
                    }
                    else {
                        Log::systemLog('error', 'ERROR kill Echange order book process ='.$pkill, "Order Book Monitor");
                    }
                }
            }
        }
    }
    
    private function runProcExchangeOrderBook() {
        global $DB;
        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials()); 
        usleep(20000);
        //$this->ping_timeout = 30;
        Log::systemLog('info',"Process type \"Exchange Order Book\" STARTED pid=".getmypid(), "Order Book");
        $this->proc = array();
        
        $ob = new OrderBook();
        
        do {
            $task_create_exchange = ServiceRAM::read('create_exchange_orderbook');
        }
        while($task_create_exchange === false || empty($task_create_exchange));
        Log::systemLog('debug', 'Process "Exchange Order Book" pid='. getmypid().' received = '.json_encode($task_create_exchange), "Order Book");
        //Use only newer messege 
        $task = $task_create_exchange[0];
        
        $exchange = Exchange::init($task['data']['exchange_id'], false, $task['data']['market']);
        
        $ob->exchange_id = $exchange->getId();
        $ob->exchange_name = $exchange->getName();
        $ob->market = $task['data']['market'];

        $need_reconnect = false;
        
        //Websoket connect if enable
        if($exchange->isEnableWebsocket()) {
            $ws = $exchange->webSocketConnect('orderbook');
            if(!$ws) {
                do {
                    sleep(3);
                    $ws = $exchange->webSocketConnect('orderbook');
                    if(!$ws) {
                        Log::systemLog('error', 'Child Order Book proc='. getmypid().' Error create websocket connect', "Order Book");
                    }
                }
                while(!$ws);
            }
        }
        //
        while(1) {
            $this->timestamp = microtime(true)*1E6;           
            //Update tree
            $this->updateProcTree();
           
            //Every 0.5 seconds read RAM for trade pair
            $sbr = self::checkTimer($this->timer_update_ob_read_ram_subscribes, $ob->timer_update_ob_read_ram_subscribes_ts);
            if($sbr) {
                $this->timer_update_ob_read_ram_subscribes_ts = microtime(true)*1E6;
                $pairs_arr = ServiceRAM::read('active_exchange_pair_orderbook');
                if(!empty($pairs_arr)) {
                    //Need only newest data
                    $pairs = $pairs_arr[0];
                    //Log::systemLog('debug', '"Exchange Order Book" pid='. getmypid().' '.$ob->exchange_name.' received = '.json_encode($pairs));

                    $previous_subscribe = false;
                    $new_subscribe = false;

                    $tasks = $pairs['data'];
                    //Log::systemLog('debug', 'Exchange order book proc='. getmypid().' '.$ob->exchange_name.' '.json_encode($tasks));
                    $subscribe_crc32 = crc32(json_encode($tasks));
                    if(!empty($ob->subscribe) && $ob->subscribe_crc !== $subscribe_crc32) {
                        $previous_subscribe = $ob->subscribe;
                    }
                    if(empty($ob->subscribe) || $ob->subscribe_crc !== $subscribe_crc32) {
                        $ob->subscribe = $tasks;
                        $ob->subscribe_crc = $subscribe_crc32;
                        $new_subscribe = true;
                    }
                    if($new_subscribe) {
                        //Create ftok crc hash for segment RAM
                        foreach($ob->subscribe as $k=>$s) {
                            if(!isset($s['ftok_crc']) || empty($s['ftok_crc'])) {
                                // Exchange ID | Market (spot) | PAIR ID
                                $ob->subscribe[$k]['ftok_crc'] = hash('xxh3',$ob->exchange_id.'|'.$ob->market.'|'.$s['id']);
                                //Log::systemLog('debug', 'STRING CRC '.$ob->exchange_id.'|'.$ob->market.'|'.$s['id'].' '. $ob->subscribe[$k]['ftok_crc'], "Order Book");
                            }
                        }
                        //Log::systemLog('debug', 'PROC SUBSCRIBE '. getmypid().' '. json_encode($ob->subscribe), "Order Book");
                        $ob->eraseDepthRAM();
                        Log::systemLog('debug', 'Echange order book process = '. getmypid().' New subscribe data='. json_encode($tasks), "Order Book");
                        if($exchange->isEnableWebsocket()) {
                            $scb = $exchange->webSocketMultiSubsribeDepth($ws,$tasks,$previous_subscribe);
                        }
                    }
                }
            }
            
            //For enable websocket on Exchange
            if($exchange->isEnableWebsocket()) {
                //PING Exchange.
                // Every 3.5 seconds send ping to server. Only over websocket if need
                if($exchange->isNeedPingWebsocket()) {
                    $ping = self::checkTimer(3500000, $ob->timer_update_ob_ping_ts);
                    if($ping) {
                        $ob->timer_update_ob_ping_ts = microtime(true)*1E6;
                        $ping = $exchange->webSocketPing($ws);
                        Log::systemLog('debug', 'Exchange order book proc='. getmypid().' '.$ob->exchange_name.' '. strtoupper($ob->market).' send PING');
                    }
                }
                //control timeout and false receive. Only websocket
                $tik = self::checkTimer(5000, $ob->timer_update_ob_timeout_ts);
                if($tik || $ob->time_count_timeout > 20) {
                    $ob->timer_update_ob_timeout_ts = microtime(true)*1E6;
                    if($ob->time_count_timeout > 20) {
                        $need_reconnect = true;
                        $ob->eraseDepthRAM();
                        unset($ws);
                        Log::systemLog('error', 'Exchange order book proc='. getmypid().' '.$ob->exchange_name.' '. strtoupper($ob->market).' LOST CONNECTION websocket', "Order Book");
                        $ob->time_count_timeout = 0;
                    }
                    else {
                        $ob->time_count_timeout = 0;
                    }
                }
                
                //Reconnect if lost.  Only websocket
                if(!isset($ws) || !is_object($ws)) {
                    sleep(2);
                    $ws = $exchange->webSocketConnect('orderbook');
                    Log::systemLog('warn', 'Child Order Book proc='. getmypid().' Reconnecting to websoket', "Order Book");
                    if($ws) {
                        $time_count_timeout = 0;
                        $scb = $exchange->webSocketMultiSubsribeDepth($ws, $ob->subscribe);
                        Log::systemLog('info', 'Echange order book process = '. getmypid().' New subscribe data='. json_encode($ob->subscribe), "Order Book");
                    }
                    else {
                        Log::systemLog('error', 'Child Order Book proc='. getmypid().' Reconnecting websoket FAILED', "Order Book");
                    }
                }
                //Read websocket
                try {
                    try {
                        $received = $ws->receive();
                        //Log::systemLog('debug', 'Echange order book process = '. getmypid().' webSoket receive NATIVE from '.$ob->exchange_name.' ='. $received, "Order Book");
                        $return = $exchange->webSocketParse($received);
                        //agregate addition data -> add ID and sys name trade pair
                        if($return) {
                            switch($return['method']) {
                                case 'depth':
                                    //search in subscribe array
                                    $found_sunscribe = false;
                                    if(is_array($ob->subscribe)) {
                                        foreach ($ob->subscribe as $s) {
                                            foreach ($return['data'] as $d) {
                                                if($s['name'] == $d['pair']) {
                                                    $found_sunscribe = true;
                                                }
                                            }
                                        }
                                    }
                                    //Write data into RAM
                                    if($found_sunscribe === true) {
                                        $return_merge = $exchange->mergeTradePairData($return,$ob->subscribe);
                                        Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$ob->exchange_name.' '. strtoupper($ob->market).' webSoket Receive parse '. json_encode($return_merge), "Order Book");                                                     
                                        $ob->writeDepthRAM($return_merge);
                                    }
                                    break;
                                case 'pong':
                                    //update all pair timestamp
                                    $ob->writeDepthRAMupdatePong();
                                    Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$ob->exchange_name.' '. strtoupper($ob->market).' webSoket Receive parse '. json_encode($return), "Order Book");
                                    break;
                                case 'ping':
                                    $ob->writeDepthRAMupdatePing();
                                    Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$ob->exchange_name.' '. strtoupper($ob->market).' webSoket Receive parse '. json_encode($return), "Order Book");
                                    $msg = array();
                                    $msg['pong'] = $return['timestamp'];  
                                    $msg_json = json_encode($msg);
                                    $ws->text($msg_json);
                                    Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$ob->exchange_name.' '. strtoupper($ob->market).' webSoket Response PONG '. $msg_json, "Order Book");
                                    break;
                                case 'error':
                                    $need_reconnect = true;
                                    $ob->eraseDepthRAM();
                                    unset($ws);
                                    $ob->time_count_timeout = 0;
                                    break;
                                default:
                                    Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$ob->exchange_name.' '. strtoupper($ob->market).' webSoket Receive parse '. json_encode($return), "Order Book");
                            }
                        }
                        else {
                            Log::systemLog('warn', 'Echange order book process = '. getmypid().' webSoket receive UNKNOW NATIVE from '.$ob->exchange_name.' receive:'. $received, "Order Book");
                        }
                    } catch (\WebSocket\TimeoutException $e) {
                        //timeout read
                        //Send PING
                        //$ping = $exchange->webSocketPing($ws);   
                        /*if($ping === true) {
                            Log::systemLog('debug', 'Echange order book process = '. getmypid().' Exchange '.' '.$this->proc_data['exchange_name'].' ping', "Order Book");
                        }*/
                        $ob->time_count_timeout++;
                        //Log::systemLog('debug', 'Echange order book process = '. getmypid().' Exchange '.' '.$ob->exchange_name.' timeout wait response', "Order Book");
                    }
                 } catch (\WebSocket\ConnectionException $e) {
                    $er = "ERROR: {$e->getMessage()} [{$e->getCode()}]\n";
                    Log::systemLog('error', 'Echange order book process = '. getmypid().' FAILED '.$er, "Order Book");
                    //After failed connection daemon lost subscribes
                    $ob->subscribe_crc = 'reset';           
                }  
            }
            else {
                //REST API
                $request = self::checkTimer((1/$exchange->rest_request_freq)*1E6, $ob->timer_rest_requests_ts);
                if($request) {
                    $ob->timer_rest_requests_ts = microtime(true)*1E6;
                    //$exchange->
                    foreach ($ob->subscribe as $s) {
                        $symbol = $s['name'];
                        $received = $exchange->restMarketDepth($symbol); 
                        //Log::systemLog('debug', 'Echange order book process = '. getmypid().' REST API response NATIVE '. $received);
                        $return = $exchange->restMarketDepthParse($received);
                        if(isset($return['data'])) {
                            $return['data'][0]['pair'] = $symbol;
                        }
                        Log::systemLog('debug', 'Echange order book process = '. getmypid().' REST API response parse '. json_encode($return), "Order Book");
                        if($return['method'] == 'depth') {
                            //search in subscribe array
                            $found_sunscribe = false;
                            if(is_array($ob->subscribe)) {
                                foreach ($ob->subscribe as $s) {
                                    foreach ($return['data'] as $d) {
                                        if($s['name'] == $d['pair']) {
                                            $found_sunscribe = true;
                                        }
                                    }
                                }
                            }
                            //Write data into RAM
                            if($found_sunscribe === true) {
                                $return_merge = $exchange->mergeTradePairData($return,$ob->subscribe);
                                Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$ob->exchange_name.' '. strtoupper($ob->market).' REST API Receive parse '. json_encode($return_merge), "Order Book");                                                     
                                $ob->writeDepthRAM($return_merge);
                            }
                        }
                    }                  
                }
                usleep(1000);
            }
        }
    }
    
    public function runProcPriceMonitor() {
        global $DB;
        sleep(3);
        //Create DB connection
        $DB = DB::init($this->getDBEngine(),$this->getDBCredentials());
        Log::systemLog('info',"Process type \"Price Monitor\" STARTED pid=".getmypid(), "Price Monitor");
        $price = new PriceLog();
        $this->start_time = microtime(true);
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
                                    if((microtime(true) - $tmp) < 4.5) {
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
                                        Log::systemLog('warn',"Process type \"Price Monitor\" spot DATA price timestamp very old ". json_encode($q));
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
                                if((microtime(true)*1E6 - $proc['timestamp'])*1E-6 > 9) {
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
        sleep(2);
        
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
                case 4:
                    $trader->trader_status = 20;
                    sleep(1);
                    break;
                case 6:
                    if ($trader->checkOverflowCountLossArbTrans()) {
                        $trader->trader_status = 30;
                        sleep(1);
                    }
                    else {
                        $continue = true;
                    }
                   break;
                default:
                    $continue = true;
            }
            
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
                    
                    //Read Order Book data for all TradeInstance. Read 20-65us at 6 trade proc
                    //$start = microtime(true);
                    $trader->readOrderBooks();                   
                    //$stop = microtime(true) - $start;
                    //Log::systemLog('debug', 'OBREAD '. $stop, "Trader");
                    
                    
                    usleep(1000);
                }
                while($trade_allow !== true);
            }

            //reset arbitrage transaction
            //$trader->arbitrage_id = 0;
            
            sleep(5);
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
                        Log::systemLog('debug', 'SEND to ServiceRAM command "create_trade_worker" to process = '. $tpid.' from pid = '.getmypid().' '. json_encode($msg));
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
         
        Log::systemLog('info',"Process type \"Trade Worker\" STARTED pid=".getmypid(), "Trade Worker");  
        do {
            $task_create_worker = ServiceRAM::read('create_trade_worker');
        }
        while($task_create_worker === false || empty($task_create_worker));
        Log::systemLog('debug', 'Process "Trade Worker" pid='. getmypid().' received = '.json_encode($task_create_worker), "Trade Worker");
        while(1) {
            $this->timestamp = microtime(true)*1E6;
            $this->updateProcTree();
            
            
            usleep(100000);
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
       
    //Get from database Exchange pair list to open websocket and read subscribed depth
    public function getActiveExchangePairOrderBook() {
        global $DB;
        
        /**SPOT Market
         * SELECT 1 - Select active trade pairs
         * SELECT 2 - Select from monitorng table
         */
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
            Log::systemLog('error', $message);
            return false;
        }
        $ret = array();

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
    public function updateProcTree() {
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
    }
    
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
    
    public function getDBEngine() {
        return $this->db_engine;
    }
    
    public function getDBCredentials() {
        return $this->db_credentials;
    }
    
    function __construct() {   
        if ($this->isDaemonActive(__DIR__.'/run/ctdaemon.pid')) {
            echo 'ctdaemon already active'.PHP_EOL;
            exit;
        }
        if (is_resource(STDIN)) {
           fclose(STDIN);
           $STDIN = fopen('/dev/null', 'r');
        }

        //Read config.
        if (file_exists(__DIR__."/ctdaemon.ini")) {
            $file_conf = __DIR__."/ctdaemon.ini";
        }
        else {
            echo "FATAL ERROR read file configuration ctdaemon.ini. File not found.".PHP_EOL;
            echo "Queue Daemon terminated".PHP_EOL;
            exit();
        }
        $conf = parse_ini_file($file_conf,true);
      
        //Set system log level
        if(!isset($conf['log_error']) || empty($conf['log_error'])) {
            echo "FATAL ERROR Not set system log in ctdaemon.ini.".PHP_EOL;
            echo "Queue Daemon terminated".PHP_EOL;
            exit();
        }
        else {
            if($f = @fopen($conf['log_error'], "a")) {
                fclose($f);
                Log::defineSystemLogFIile($conf['log_error']);
                chmod($conf['log_error'], 0644);
                set_error_handler(array('Log', 'systemLog'));
            }
            else {
                echo "FATAL ERROR Access deny write system log file.".PHP_EOL;
                echo "Queue Daemon terminated".PHP_EOL;
                exit();
            }
        }
        
        Log::systemLog('system',"INIT START ctdaemon", "Main");
                
        //Set system log level
        if(!isset($conf['log_level']) || empty($conf['log_level'])) {
            Log::systemLog('warn',"System log level is not define. Use default log level 'error'", "Main");
        }
        else {
            Log::defineSystemLogLevel($conf['log_level']);
            Log::systemLog('debug',"System log level set ".$conf['log_level'], "Main");
        }
        //Set Trade log
        if(!isset($conf['log_trade']) || empty($conf['log_trade'])) {
            $msg = "FATAL ERROR Not set trade log in ctdaemon.ini.";
            echo $msg.PHP_EOL;
            Log::systemLog('error', $msg, "Main");
            echo "Queue Daemon terminated".PHP_EOL;
            exit();
        }
        else {
            if($f = @fopen($conf['log_trade'], "a")) {
                fclose($f);
                Log::defineTradeLogFIile($conf['log_trade']);
                Log::systemLog('debug',"Set Trade log ".$conf['log_trade'], "Main");
            }
            else {
                $msg = "FATAL ERROR Access deny write trade log file.";
                echo $msg.PHP_EOL;
                Log::systemLog('error', $msg, "Main");
                echo "Queue Daemon terminated".PHP_EOL;
                exit();
            }
        }
        
        //Timers
        if(isset($conf['timer_update_tree']) && !empty($conf['timer_update_tree'])) {
            $this->timer_update_tree = floatval($conf['timer_update_tree'])*1E6;
        }
        if(isset($conf['timer_update_ob_trade_subscribes']) && !empty($conf['timer_update_ob_trade_subscribes'])) {
            $this->timer_update_ob_trade_subscribes = floatval($conf['timer_update_ob_trade_subscribes'])*1E6;
        }
        if(isset($conf['timer_update_ob_read_ram_subscribes']) && !empty($conf['timer_update_ob_read_ram_subscribes'])) {
            $this->timer_update_ob_read_ram_subscribes = floatval($conf['timer_update_ob_read_ram_subscribes'])*1E6;
            if($this->timer_update_ob_read_ram_subscribes > $this->timer_update_ob_trade_subscribes) {
                $this->timer_update_ob_read_ram_subscribes = $this->timer_update_ob_trade_subscribes;
            }
        }
        if(isset($conf['timer_update_price_monitor']) && !empty($conf['timer_update_price_monitor'])) {
            $this->timer_update_price_monitor = floatval($conf['timer_update_price_monitor'])*1E6;
        }
        if(isset($conf['timer_update_price_monitor_subscribes']) && !empty($conf['timer_update_price_monitor_subscribes'])) {
            $this->timer_update_price_monitor_subscribes = floatval($conf['timer_update_price_monitor_subscribes'])*1E6;
        }
        
        //Test Database
        //check param
        if(!isset($conf['db_engine']) || empty($conf['db_engine'])){
            $conf['db_engine'] = 'mysql';
            $message = "Error read database engine from config ctdaemon.ini. Use default mysql.";
            Log::systemLog('warn',$message, "Main");
        }
        $credentials = array();
        if(!isset($conf['db_host']) || empty($conf['db_host'])){
            $conf['db_host'] = 'localhost';
            $message = "Error read database host from config ctdaemon.ini. Use default localhost.";
            Log::systemLog('warn',$message, "Main");
        }
        $credentials['host'] = $conf['db_host'];
        if(!isset($conf['db_user']) || empty($conf['db_user'])){
            $message = "FATAL ERROR read databse DB_USER from config ctdaemon.ini. Parameter is not set or empty";
            echo "ctdaemon terminated".PHP_EOL;
            Log::systemLog('error', $message, "Main");
            exit($message.PHP_EOL);
        }
        $credentials['user'] = $conf['db_user'];
        if(!isset($conf['db_pass'])){
            $message = "FATAL ERROR read databse DB_PASS from config ctdaemon.ini. Parameter is not set";
            echo "ctdaemon terminated".PHP_EOL;
            Log::systemLog('error', $message, "Main");
            exit($message.PHP_EOL);
        }
        $credentials['pass'] = $conf['db_pass'];
        if(!isset($conf['db_base'])|| empty($conf['db_base'])){
            $message = "FATAL ERROR read databse DB_BASE from config ctdaemon.ini. Parameter is not set";
            echo "ctdaemon terminated".PHP_EOL;
            Log::systemLog('error', $message, "Main");
            exit($message.PHP_EOL);
        }
        $credentials['base'] = $conf['db_base'];
        $DB = Db::init($conf['db_engine'], $credentials);
        if(!$DB || !empty($DB->getLastError())) {
            $message = "FATAL ERROR Connected to database. ".$DB->getLastError();
            Log::systemLog('error', $message, "Main");
            exit($message.PHP_EOL);
        }
        //set database 
        $sql = 'USE '.$conf['db_base'];
        $DB->sql_not_need_prepared($sql);
        if(!empty($DB->getLastError())) {
            $message = "FATAL ERROR select database. ".$DB->getLastError();
            Log::systemLog('error', $message, "Main");
            exit($message.PHP_EOL);
        }
        //Set ARBITRAGE TRANSACTION new status to suspend
        $sql = "UPDATE `ARBITRAGE_TRANS` SET `STATUS`=3 WHERE `STATUS`=1";
        $DB->sql_not_need_prepared($sql);
        unset($DB);
        $this->db_engine = $conf['db_engine'];
        $this->db_credentials = $credentials;
        
        Log::systemLog("debug","Connection to database verified ok", "Main");
        $this->start = time();
        $this->timestamp = $this->start;
    }
}
?>