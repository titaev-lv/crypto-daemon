<?php

/* 
 * Class for manage Exchange Order Book's prosesses
 * 
 */

class OrderBookWorker extends AbstractWorker {
    protected $timer_update_ob_read_ram_subscribes = 500000;
    protected $timer_update_ob_read_ram_subscribes_ts = 0;
    protected $timer_update_ob_ping = 3500000;
    protected $timer_update_ob_ping_ts = 0;
    protected $timer_update_ob_timeout = 5000;
    protected $timer_update_ob_timeout_ts = 0;
    protected $timer_update_ob_rest = 1000000;
    protected $timer_update_ob_rest_ts = 0;
    
    protected int $exchange_id;
    protected string $exchange_name;
    protected string $market;
    
    private $exchangeObj;
    private $subscribe = array();
    private $subscribe_crc = '';
    private $ws; //Websocket object
    private $ws_time_count_timeout = 0;
    
    public function processing() {
        global $Daemon, $DB;
        
        if(!isset($this->exchange_id)) {
            $this->initExchange();
        }
        
        //Connect or reconnect to websocket
        if($this->exchangeObj->isEnableWebsocket()) {
            if(!isset($this->ws) || !is_object($this->ws)) {
                sleep(1);
                $ws = $this->exchangeObj->webSocketConnect('orderbook');
                Log::systemLog('warn', 'Order Book proc='. getmypid().' Connect to websoket', $this->getProcName());
                if(!$ws) {
                    Log::systemLog('error', 'Child Order Book proc='. getmypid().' Connecting websoket FAILED', $this->getProcName());
                    $cnt = 10;
                    $i = 0;
                    do {
                        sleep(3);
                        $ws = $this->exchangeObj->webSocketConnect('orderbook');
                        if(!$ws) {
                            Log::systemLog('error', 'Error create websocket connect', $this->getProcName());
                        }
                        $i++;
                    }
                    while(!$ws || $i<$cnt);
                    if(!$ws) {
                        Log::systemLog('fatal', 'Process KILLED. Error create websocket connection', $this->getProcName());
                        exit();
                    }
                }
                if($ws) {
                    $this->ws = $ws;
                    $this->subscribe = '';
                    $this->subscribe_crc = '';
                }
            }
        }
         
        if($this->probeTimer("timer_update_ob_read_ram_subscribes") === true) {
            $pairs_arr = ServiceRAM::read('active_exchange_pair_orderbook');
            if(!empty($pairs_arr)) {
                //Need only newest data
                $pairs = $pairs_arr[0];
                Log::systemLog('debug', 'Order Book Worker pid='. getmypid().' '.$this->exchange_name.' received = '.json_encode($pairs),$this->getProcName());

                $tasks = $pairs['data'];
                $subscribe_crc32 = crc32(json_encode($tasks));
                
                $previous_subscribe = false;
                $new_subscribe = false;
                
                if(!empty($this->subscribe) && $this->subscribe_crc !== $subscribe_crc32) {
                    $previous_subscribe = $this->subscribe;
                }
                if(empty($this->subscribe) || $this->subscribe_crc !== $subscribe_crc32) {
                    $this->subscribe = $tasks;
                    $this->subscribe_crc = $subscribe_crc32;
                    $new_subscribe = true;
                }

                if($new_subscribe) {
                    //Create ftok crc hash for segment RAM
                    if(is_iterable($this->subscribe)) {
                        foreach($this->subscribe as $k=>$s) {
                            if(!isset($s['ftok_crc']) || empty($s['ftok_crc'])) {
                                // Exchange ID | Market | PAIR ID
                                $this->subscribe[$k]['ftok_crc'] = hash('xxh3',$this->exchange_id.'|'.$this->market.'|'.$s['id']);
                                //Log::systemLog('debug', 'STRING CRC '.$ob->exchange_id.'|'.$ob->market.'|'.$s['id'].' '. $ob->subscribe[$k]['ftok_crc'], $this->getProcName());
                            }
                        }
                    }
                    //Log::systemLog('debug', 'PROC SUBSCRIBE '. getmypid().' '. json_encode($ob->subscribe), $this->getProcName());
                    OrderBookRAM::eraseDepthRAM($this->subscribe);
                    Log::systemLog('debug', 'Echange order book process = '. getmypid().' New subscribe data='. json_encode($tasks), $this->getProcName());
                    if($this->exchangeObj->isEnableWebsocket()) {
                        $scb = $this->exchangeObj->webSocketMultiSubsribeDepth($this->ws, $tasks, $previous_subscribe);
                        $scbbo = $this->exchangeObj->webSocketMultiSubsribeBBO($this->ws,$tasks, $previous_subscribe);
                    }
                }
            }
        }
        if($this->exchangeObj->isEnableWebsocket()) {
            //PING Exchange.
            // Every 3.5 seconds send ping to server. Only over websocket if need
            if($this->exchangeObj->isNeedPingWebsocket()) {
                if($this->probeTimer("timer_update_ob_ping") === true) {
                    $this->exchangeObj->webSocketPing($this->ws);
                    Log::systemLog('debug', 'Order Book Worker proc='. getmypid().' '.$this->exchange_name.' '. strtoupper($this->market).' send PING', $this->getProcName());
                }
            }
            //control timeout and false receive
            $tik = $this->probeTimer("timer_update_ob_timeout");
            if($tik || $this->ws_time_count_timeout > 20) {
                if($this->ws_time_count_timeout > 20) {
                    $need_reconnect = true;
                    OrderBookRAM::eraseDepthRAM();
                    unset($this->ws);
                    Log::systemLog('error', 'Order Book Worker proc='. getmypid().' '.$this->exchange_name.' '. strtoupper($this->market).' LOST CONNECTION websocket',  $this->getProcName());
                    $this->ws_time_count_timeout = 0;
                }
                else {
                    $this->ws_time_count_timeout = 0;
                }
            }

            try {
                try {
                    $received = $this->ws->receive();
                    //Log::systemLog('debug', 'Echange order book process webSoket receive NATIVE from '.$this->exchange_name.' ='. $received, $this->getProcName());
                    $return = $this->exchangeObj->webSocketParse($received);
                    if($return) {
                        switch($return['method']) {
                            case 'depth':
                                //search in subscribe array
                                $found_sunscribe = false;
                                if(is_array($this->subscribe)) {
                                    foreach ($this->subscribe as $s) {
                                        foreach ($return['data'] as $d) {
                                            if($s['name'] == $d['pair']) {
                                                $found_sunscribe = true;
                                            }
                                        }
                                    }
                                }
                                //Write data into RAM
                                if($found_sunscribe === true) {
                                    $return_merge = $this->exchangeObj->mergeTradePairData($return,$this->subscribe);
                                    OrderBookRAM::writeDepthRAM($return_merge, $this->subscribe);
                                    Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$this->exchange_name.' '. strtoupper($this->market).' webSoket Receive parse DEPTH '. json_encode($return_merge), $this->getProcName());                                                     
                                }
                                break;
                            case 'bbo':
                                //search in subscribe array
                                $found_sunscribe = false;
                                if(is_array($this->subscribe)) {
                                    foreach ($this->subscribe as $s) {
                                        foreach ($return['data'] as $d) {
                                            if($s['name'] == $d['pair']) {
                                                $found_sunscribe = true;
                                            }
                                        }
                                    }
                                }
                                if($found_sunscribe === true) {
                                    $return_merge = $this->exchangeObj->mergeTradePairData($return, $this->subscribe);
                                    OrderBookRAM::writeBBORAM($return_merge);
                                    Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$this->exchange_name.' '. strtoupper($this->market).' webSoket Receive parse BBO '. json_encode($return_merge), $this->getProcName());  
                                }
                                break;
                            case 'pong':
                                //update all pair timestamp
                                OrderBookRAM::writeDepthRAMupdatePong($this->subscribe);
                                Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$this->exchange_name.' '. strtoupper($this->market).' webSoket Receive parse PONG '. json_encode($return), $this->getProcName());
                                break;
                            case 'ping':
                                OrderBookRAM::writeDepthRAMupdatePing($this->subscribe);
                                Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$this->exchange_name.' '. strtoupper($this->market).' webSoket Receive parse PING '. json_encode($return), $this->getProcName());
                                $msg = array();
                                $msg['pong'] = $return['timestamp'];  
                                $msg_json = json_encode($msg);
                                $this->ws->text($msg_json);
                                Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$this->exchange_name.' '. strtoupper($this->market).' webSoket Response PONG '. $msg_json, $this->getProcName());
                                break;
                            case 'error':
                                $need_reconnect = true;
                                OrderBookRAM::eraseDepthRAM();
                                unset($this->ws);
                                $this->ws_time_count_timeout = 0;
                                break;
                            default:
                                Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$this->exchange_name.' '. strtoupper($this->market).' webSoket Receive parse '. json_encode($return), $this->getProcName());
                        }
                    }
                    else {
                        Log::systemLog('warn', 'Echange order book process = '. getmypid().' webSoket receive UNKNOW NATIVE from '.$this->exchange_name.' receive:'. $received, $this->getProcName());
                    }
                }
                catch (\WebSocket\TimeoutException $e) {
                    //timeout read
                    //Send PING
                    //$ping = $exchange->webSocketPing($ws);   
                    /*if($ping === true) {
                        Log::systemLog('debug', 'Echange order book process = '. getmypid().' Exchange '.' '.$this->proc_data['exchange_name'].' ping', "Order Book");
                    }*/
                    $this->ws_time_count_timeout++;
                    //Log::systemLog('debug', 'Echange order book process = '. getmypid().' Exchange '.' '.$ob->exchange_name.' timeout wait response', "Order Book");
                }
            } catch (\WebSocket\ConnectionException $e) {
                $er = "ERROR: {$e->getMessage()} [{$e->getCode()}]\n";
                Log::systemLog('error', 'Echange order book process = '. getmypid().' FAILED '.$er, $this->getProcName());
                //After failed connection daemon lost subscribes
                $this->subscribe_crc = 'reset';           
            }  
        }
        else {
            //REST API
            $req = $this->probeTimer("timer_update_ob_rest");
            if($req) {
                //$exchange->
                if(is_iterable($this->subscribe)) {
                    foreach ($this->subscribe as $s) {
                        $symbol = $s['name'];
                        $received = $this->exchangeObj->restMarketDepth($symbol); 
                        //Log::systemLog('debug', 'Echange order book process = '. getmypid().' REST API response NATIVE '. $received, "Order Book");
                        $return = $this->exchangeObj->restMarketDepthParse($received);
                        if(isset($return['data'])) {
                            $return['data'][0]['pair'] = $symbol;
                        }
                        //Log::systemLog('debug', 'Echange order book process = '. getmypid().' REST API response parse '. json_encode($return), "Order Book");
                        if($return['method'] == 'depth') {
                            //search in subscribe array
                            $found_sunscribe = false;
                            if(is_array($this->subscribe)) {
                                foreach ($this->subscribe as $s) {
                                    foreach ($return['data'] as $d) {
                                        if($s['name'] == $d['pair']) {
                                            $found_sunscribe = true;
                                        }
                                    }
                                }
                            }
                            //Write data into RAM
                            if($found_sunscribe === true) {
                                $return_merge = $this->exchangeObj->mergeTradePairData($return, $this->subscribe);
                                //Log::systemLog('debug', 'Echange order book process = '. getmypid().' '.$ob->exchange_name.' '. strtoupper($ob->market).' REST API Receive parse '. json_encode($return_merge), "Order Book");                                                     
                                OrderBookRAM::writeDepthRAM($return_merge);
                            }
                        }
                    } 
                }
            }
            usleep(1000);
        }
    }
    
    private function initExchange() {
        do {
            $task_create_exchange = ServiceRAM::read('create_exchange_orderbook');
        }
        while($task_create_exchange === false || empty($task_create_exchange));
        $task = $task_create_exchange[0];
        Log::systemLog('debug', 'Order Book Worker pid='. getmypid().' received = '.json_encode($task), $this->getProcName());

        $this->exchangeObj = Exchange::init($task['data']['exchange_id'], false, $task['data']['market']);

        $this->exchange_id = $this->exchangeObj->getId();
        $this->exchange_name = $this->exchangeObj->getName();
        $this->market = $task['data']['market'];
    }
}