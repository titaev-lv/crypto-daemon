<?php

abstract class AbstractProc {
    private string $proc_name;
    
    public ?int $timestamp = NULL;
    
    //Timers
    protected int $timer_update_tree = 200000; // 0.2sec
    protected int $timer_update_tree_ts = 0;
    protected int $timer_zombie = 5000000; //5 sec
    protected int $timer_zombie_ts = 0;
        
    abstract protected function processing();
    
       
    public function run() {
        global $Daemon;
        while(true) {
            $this->timestamp = microtime(true)*1E6;
            $Daemon->timestamp = $this->timestamp;
            $this->updateProcTree();
            $this->huntToZombie();
            $this->processing();
        }
    }
    
    private function updateProcTree() {
        global $Daemon;
        if($this->probeTimer("timer_update_tree") === true) {
            //Log::systemLog('error',"PROC ". json_encode($Daemon->proc)." pid=".getmypid(),$this->getProcName()); 
            //read child proc info from RAM
            if(!empty($Daemon->proc)) {
                $ch = $Daemon->receiveProcTree();
                //Log::systemLog('debug', 'Recevive PROC TREE '. json_encode($ch).' proc='. getmypid(),$this->getProcName());
                if(is_array($ch)) {
                    foreach ($ch as $c) {
                        foreach ($c['data'] as $kpt=>$pt) {
                            foreach ($Daemon->proc as $kproc=>$proc) {
                                if($proc['pid'] == $kpt) { 
                                   //Founded in proc process from child
                                   //update timestamp process response
                                   //Log::systemLog('debug', 'Recevive PROC TREE KPROC '. json_encode($kproc).' proc='. getmypid());
                                   $Daemon->proc[$kproc]['timestamp'] = $c['timestamp'];
                                   //Fill proc_tree
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
                                   if(empty($Daemon->proc_tree)) {
                                       $Daemon->proc_tree[] = $tmp;
                                   }
                                   else {
                                       $found = false;
                                       foreach ($Daemon->proc_tree as $kpp=>$pp) {
                                           if($pp['pid'] == $kpt) {
                                               $Daemon->proc_tree[$kpp] = $tmp;
                                               $found = true;
                                           }
                                       }
                                       if(!$found) {
                                            $Daemon->proc_tree[] = $tmp;
                                       }
                                   }
                                   continue;
                               }
                            }
                        }
                    }
                }
            }
            if($Daemon->getProcName() != 'ctd_main') {
                $Daemon->sendProcTree();
            }
            //Log::systemLog('debug', 'Timer update proc tree'. json_encode($this).' proc='. getmypid(), $this->getProcName());
        }
    }
    
    public static function checkTimer($time, $start_time) {
        if((microtime(true)*1E6 - $start_time) > $time){
            return true;
        }
        return false;
    }    
    
    protected function probeTimer($timer) {
        $tic = $timer.'_ts';
        if((microtime(true)*1E6 - $this->$tic) > $this->$timer){
            $this->$tic = microtime(true)*1E6;
            return true;
        }
        return false;
    }
    
 /*   public function getDBEngine() {
        return $this->db_engine;
    }
    public function setDBEngine($var) {
        $this->db_engine = $var;
    }
    public function getDBCredentials() {
        return $this->db_credentials;
    }
    public function setDBCredentials($ar) {
        $this->db_credentials = $ar;
    }
    */
    public function getProcName() {
        return $this->proc_name;
    }
    public function setProcName($name) {
        $this->proc_name = $name;
    }
       
    public function initChildProc() {
        global $Daemon;
        $Daemon->start = microtime(true)*1E6;
        $Daemon->timestamp = microtime(true)*1E6;
 
        //Read data from parent
        do {
            $ram = ServiceRAM::read('create_proc'); //return array
        }
        while($ram === false);
        Log::systemLog('debug',"Read from RAM TYPE PROCESS process pid=".getmypid(). ' '.json_encode($ram), "New Process");
        if(count($ram) !== 1) {
            Log::systemLog('error',"ServiceRAM data for create process pid=".getmypid(). ' failed', "New Process");
            exit();
        }
        
        $Daemon->setParentProc((int)$ram[0]['data']['parent_proc']);
        $Daemon->setProcName('ctd_'.strtolower($ram[0]['data']['type']).'_'.strtolower($ram[0]['data']['type2']));

        cli_set_process_title($Daemon->getProcName()); 
        $this->setProcName($Daemon->getProcName());
        $this->proc_type = $ram[0]['data']['type'];
        $this->proc_type2 = $ram[0]['data']['type2'];
        
        Log::systemLog('info',"Process type ".$ram[0]['data']['type']." ". ucfirst($ram[0]['data']['type2'])." STARTED pid=".getmypid(), $this->getProcName());          
    }
    
    public function huntToZombie() {
        global $Daemon, $DB;
        if($this->probeTimer("timer_zombie") === true) { 
            $this->timer_zombie_ts = microtime(true)*1E6;
            //Log::systemLog('debug', 'Zombie search pid='. getmypid(),$this->proc_name);
            //Kill zombie and reborn Child Process
            if(is_iterable($Daemon->proc) && !empty($Daemon->proc)) {
                foreach ($Daemon->proc as $i=>$ch_proc) {
                    $delta = microtime(true)*1E6 - $ch_proc['timestamp'];
                    //Log::systemLog('debug', 'Zombie search d='.$delta." timeout=".$this->timeout_child, $this->proc_name);
                    if($delta > $Daemon->timeout_child) { 
                        Log::systemLog('warn', 'Process pid='.$ch_proc['pid']. ' '.$ch_proc['name'] . ' have expire timestamp = '.$delta. '. Init restart process', $this->getProcName());
                        $kill = posix_kill($ch_proc['pid'], SIGTERM);  
                        if($kill) {
                            if(!empty($Daemon->proc_tree)) {   
                                foreach($Daemon->proc_tree as $kt=>$proct) {
                                    if($ch_proc['pid'] == $proct['pid']) {
                                        if(!empty($proct['child']) && is_array($proct['child'])) {
                                            foreach ($proct['child'] as $chp) {
                                                $k = posix_kill($chp['pid'], SIGTERM); 
                                                if($k) {
                                                    Log::systemLog('warn', 'Zombie child process pid='.$chp['pid']. ' '.$chp['name'] . ' killed',$this->getProcName());
                                                }
                                                else {
                                                    Log::systemLog('error', 'ERROR kill Zombie\'s child process ='.$ch_proc['pid'].', perhaps it is died', $this->getProcName());
                                                }
                                            }
                                        }
                                        unset($Daemon->proc_tree[$kt]);
                                    }
                                }
                            }
                            Log::systemLog('warn', 'Process pid='.$ch_proc['pid'].' is killed.', $this->getProcName());
                            if($DB) {
                                $DB->close();
                                $new_pid = $Daemon->newProcess($ch_proc['type'],$ch_proc['type2']);
                                $DB = DB::init($Daemon->getDBEngine(),$Daemon->getDBCredentials());
                            }
                            else {
                                $new_pid = $Daemon->newProcess($ch_proc['type'],$ch_proc['type2']);
                            }
                            //COPY property old process to new
                            foreach ($Daemon->proc as $j=>$n_proc) {
                                if($n_proc['pid'] == $new_pid) {
                                    /*For Order Book workers*/
                                    if($Daemon->proc[$i]['type'] === 'OrderBook' && $Daemon->proc[$i]['type2'] === 'worker') {
                                        if(isset($Daemon->proc[$i]['market'])) {
                                            $Daemon->proc[$j]['market'] = $Daemon->proc[$i]['market'];
                                        }
                                        if(isset($Daemon->proc[$i]['exchange_name'])) {
                                            $Daemon->proc[$j]['exchange_name'] = $Daemon->proc[$i]['exchange_name'];
                                        }
                                        if(isset($Daemon->proc[$i]['exchange_id'])) {
                                            $Daemon->proc[$j]['exchange_id'] = $Daemon->proc[$i]['exchange_id'];
                                        }
                                        if(isset($Daemon->proc[$j]['market']) && isset($Daemon->proc[$j]['exchange_id'])) {
                                            //Send new process info about exchange and market type
                                            Log::systemLog('debug', 'SEND to ServiceRAM command "create_exchange_orderbook" to order book process = '. $new_pid.' '. json_encode(array('exchange_id'=>$Daemon->proc[$j]['exchange_id'],'market'=>$Daemon->proc[$j]['market'])), $this->proc_name);
                                            ServiceRAM::write($new_pid,'create_exchange_orderbook', array('exchange_id'=>$Daemon->proc[$j]['exchange_id'],'market'=>$Daemon->proc[$j]['market'])); 
                                        }

                                        if(isset($Daemon->proc[$i]['subscribe'])) {
                                            $Daemon->proc[$j]['subscribe'] = $Daemon->proc[$i]['subscribe'];
                                            ServiceRAM::write($new_pid,'active_exchange_pair_orderbook',$Daemon->proc[$j]['subscribe']);
                                            Log::systemLog('debug', 'SEND to ServiceRAM command "active_exchange_pair_orderbook" to order book process = '. $new_pid.' '. json_encode($Daemon->proc[$j]['subscribe']), $this->proc_name);
                                        }
                                        if(isset($Daemon->proc[$i]['subscribe_crc'])) {
                                            $Daemon->proc[$j]['subscribe_crc'] = $Daemon->proc[$i]['subscribe_crc'];
                                        }
                                    }
                                }
                            }
                            /*****/
                            unset($Daemon->proc[$i]);
                        }
                        else {
                            Log::systemLog('error', 'ERROR kill process ='.$ch_proc['pid'].', perhaps it is died', $this->proc_name);
                        }
                    }
                }
            }
        }
    }
}
