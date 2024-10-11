<?php

class OrderBook {
    public $exchange_id = 0;
    public $exchange_name = '';
    public $market = '';

    public $subscribe = '';
    public $subscribe_crc = '';
    
    //Timers
    public $timer_update_ob_ping_ts = 0;
    public $timer_update_ob_timeout_ts = 0;
    public $timer_rest_requests_ts = 0;
    public $timer_update_ob_read_ram_subscribes_ts = 0;
    
    //Counts
    public $time_count_timeout = 0;
    
    private $need_reconnect_flag = false;
    
    function __construct() {
        $this->timer_update_ob_ping_ts = microtime(true)*1E6;
    }
        
    public function eraseDepthRAM () {
        if(!empty($this->subscribe)) {
            foreach ($this->subscribe as $key=>$p) {
                $path = __DIR__."/ftok/".$p['ftok_crc'].'.ftok';
                if(!is_file($path)) {
                    $file = fopen($path, 'w');
                    if($file){
                        fclose($file);
                    }
                }
                $id = ftok($path, 'A');
                //Semaphore
                $semId = sem_get($id);
                sem_acquire($semId);
                $data_arr = array();
                $data_json = json_encode($data_arr);
                $shmId = shm_attach($id, strlen($data_json)+4096);
                $var = 1;
                shm_put_var($shmId, $var, $data_json);
                shm_detach($shmId);
                sem_release($semId);
            }
        }
        return true;
    }
    
    public function writeDepthRAM($data_upd) {
        //$start = microtime(true);
        //Log::systemLog(4, 'ORDER BOOK DATA TO WRITE RAM '.json_encode($data_upd));
        $nu = array();
        foreach ($data_upd['data'] as $d) {
            //$start1 = microtime(true);
            $path = __DIR__."/ftok/".$d['ftok_crc'].'.ftok';
            if(!is_file($path)) {
                $file = fopen($path, 'w');
                if($file){
                    fclose($file);
                }
            }
            $nu[] = $d['ftok_crc'];
            $id = ftok($path, 'A');
            //Semaphore
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

            $element = array();
            if($d['diff'] == false || empty($data_arr)) {
                $element['sys_pair'] = $d['sys_pair'];
                $element['pair'] = $d['pair'];
                $element['price_timestamp'] = $d['price_timestamp'];
                $element['timestamp'] = $d['timestamp'];
                $element['last_price'] = (float) $d['last_price'];
                foreach ($d['asks'] as $b) {
                   $tmp = array();
                   $tmp[0] = (float) $b[0];
                   $tmp[1] = (float) $b[1];
                   $element['asks'][] = $tmp;
                   unset($tmp);
                }
                foreach ($d['bids'] as $b) {
                   $tmp = array();
                   $tmp[0] = (float) $b[0];
                   $tmp[1] = (float) $b[1];
                   $element['bids'][] = $tmp;
                   unset($tmp);
                }
                $data_arr = $element;    
            }
            elseif($d['diff'] === true){
                $src_mem = $data_arr;    
                $resort_bids = false;
                if(isset($d['bids'])) {
                    foreach ($d['bids'] as $b) {
                        $up = false; //insert values = false
                        foreach ($src_mem['bids'] as $k=>$v){
                            if($v[0] === (float) $b[0]){
                                if((float) $b[1] == 0) {
                                    unset($src_mem['bids'][$k]);
                                    $resort_bids = true;
                                    $up = true; //not insert
                                }
                                else {
                                    $src_mem['bids'][$k][1] = (float) $b[1];
                                    $up = true; //not insert
                                }
                            }
                        }
                        if($up === false) {
                            $tmp[0] = (float) $b[0];
                            $tmp[1] = (float) $b[1];
                            $src_mem['bids'][] = $tmp;
                            $resort_bids = true;
                        }
                    }
                }    

                $resort_asks = false;
                if(isset($d['asks'])) {
                  //  $resort = false;
                    foreach ($d['asks'] as $b) {
                        $up = false; //insert 
                        foreach ($src_mem['asks'] as $k=>$v) {
                            if($v[0] === (float) $b[0]){
                                if ((float) $b[1] == 0){
                                    unset($src_mem['asks'][$k]);
                                    $resort_asks = true;
                                    $up = true; //not insert
                                }
                                else {
                                    $src_mem['asks'][$k][1] = (float) $b[1];
                                    $up = true; //not insert
                                }
                            }
                        }
                        if($up === false) {
                            $tmp[0] = (float) $b[0];
                            $tmp[1] = (float) $b[1];
                            $src_mem['asks'][] = $tmp;
                            $resort_asks = true;
                        }
                    }
                }

                if($resort_bids === true) {
                    usort($src_mem['bids'], function($a,$b){return $b[0]<=>$a[0];});
                } 
                if($resort_asks === true) {
                    usort($src_mem['asks'], function($a,$b){return $a[0]<=>$b[0];});
                }
                $src_mem['price_timestamp'] = $d['price_timestamp'];
                $src_mem['timestamp'] = $d['timestamp'];
                $data_arr = $src_mem;
                //Log::systemLog(4, 'ORDER BOOK ARR FOR MERGE '.json_encode($src));                
            }
        }
        $data_json = json_encode($data_arr);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        
        //$term1 = microtime(true) - $start1;
        //Log::systemLog(4, 'NEW Write RAM STEP1 '.$term1. ' '.$d['ftok_crc']);
        //Update timestamp for all exchange's trade pair
        if(!empty($this->subscribe)) {
            foreach ($this->subscribe as $key=>$p) {
                if(!in_array($p['ftok_crc'], $nu)) {
                    //$start2 = microtime(true);
                    $path = __DIR__."/ftok/".$p['ftok_crc'].'.ftok';
                    if(!is_file($path)) {
                        $file = fopen($path, 'w');
                        if($file){
                            fclose($file);
                        }
                    }
                    $id = ftok($path, 'A');
                    //Semaphore
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

                    $data_arr['timestamp'] = microtime(true)*1E6;
                    $data_json = json_encode($data_arr);

                    $shmId = shm_attach($id, strlen($data_json)+4096);
                    $var = 1;
                    shm_put_var($shmId, $var, $data_json);
                    shm_detach($shmId);
                    sem_release($semId);
                    //$term2 = microtime(true) - $start2;
                    //Log::systemLog(4, 'NEW Write RAM STEP2 '.$term1. ' '.$p['ftok_crc']);
                }
            }
        }
        //Log::systemLog(4, 'ORDER BOOK WRITE RAM NEW '.json_encode($data_arr));
        //$term = microtime(true) - $start;
        //Log::systemLog(4, 'NEW Write RAM '.$term);
        return true;
    }
    
    public function writeDepthRAMupdatePong() {  
        //$start = microtime(true);
        if(!empty($this->subscribe)) {
            foreach ($this->subscribe as $key=>$p) {
                $path = __DIR__."/ftok/".$p['ftok_crc'].'.ftok';
                if(!is_file($path)) {
                    $file = fopen($path, 'w');
                    if($file){
                        fclose($file);
                    }
                }
                $id = ftok($path, 'A');
                //Semaphore
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
                
                $data_arr['timestamp'] = microtime(true)*1E6;
                $data_json = json_encode($data_arr);
                
                $shmId = shm_attach($id, strlen($data_json)+4096);
                $var = 1;
                shm_put_var($shmId, $var, $data_json);
                shm_detach($shmId);
                sem_release($semId);
            }
        }
        //$time = microtime(true) - $start;
        //Log::systemLog('debug', 'PONG TIME Order Book RAM NEW '.$time.'s');
        return true;
    }
    
    public function writeDepthRAMupdatePing() {
        //$start = microtime(true);
        if(!empty($this->subscribe)) {
            foreach ($this->subscribe as $key=>$p) {
                $path = __DIR__."/ftok/".$p['ftok_crc'].'.ftok';
                if(!is_file($path)) {
                    $file = fopen($path, 'w');
                    if($file){
                        fclose($file);
                    }
                }
                $id = ftok($path, 'A');
                //Semaphore
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
                
                $data_arr['timestamp'] = microtime(true)*1E6;
                $data_json = json_encode($data_arr);
                
                $shmId = shm_attach($id, strlen($data_json)+4096);
                $var = 1;
                shm_put_var($shmId, $var, $data_json);
                shm_detach($shmId);
                sem_release($semId);
            }
        }   
        return true;
    }
    
    public static function readDepthRAM($hash) {
        //$start = microtime(true);
        $path = __DIR__."/ftok/".$hash.'.ftok';
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id = ftok($path, 'A');
        //$start1 = microtime(true);
        //set semaphore
        $semId = sem_get($id);
        sem_acquire($semId);
        //read segment
        $shmId = shm_attach($id);
        $var = 1;
        $data = '';
        $ret_data = array();
        if(shm_has_var($shmId, $var)) {
            //get data
            $data = shm_get_var($shmId, $var);
        } 
        else {
            shm_detach($shmId);
            sem_release($semId);
            return false;
        }
        shm_detach($shmId);
        sem_release($semId);
        //$time1 = microtime(true) - $start1;
        //Log::systemLog('debug', 'READ TIME Order Book ONLY RAM '.$time1.'s');
        //
        if(!empty($data)) {
            $data_arr = json_decode($data,JSON_OBJECT_AS_ARRAY);
        }
        else {
            return false;
        }
          
        //$time = microtime(true) - $start;
        //Log::systemLog('debug', 'READ TIME Order Book RAM '.$time.'s');
        return $data_arr; 
    }
    public static function writeBBORAM($data) {
        $path = __DIR__."/ftok/".$data['data'][0]['ftok_crc'].'.ftok';
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id = ftok($path, 'B');
        $data_mod = array();
        $data_mod = $data['data'][0];
        unset($data_mod['pair_id']);
        unset($data_mod['ftok_crc']);
        $data_json = json_encode($data_mod);
        
        //Semaphore
        $semId = sem_get($id);
        sem_acquire($semId);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        return true;
    }
    
    public static function readBBORAM($hash) {
        $path = __DIR__."/ftok/".$hash.'.ftok';
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id = ftok($path, 'B');
        //set semaphore
        $semId = sem_get($id);
        sem_acquire($semId);
        //read segment
        $shmId = shm_attach($id);
        $var = 1;
        $data = '';
        if(shm_has_var($shmId, $var)) {
            $data = shm_get_var($shmId, $var);
        } 
        else {
            shm_detach($shmId);
            sem_release($semId);
            Log::systemLog('debug', "BBO RAM DATA is empty, read from DEPTH hash=".$hash.' ', "Trader");
            $data = self::readDepthRAM($hash);
            if(empty($data)) {
                return false;
            }
            //read from depht
            $bbo = array();
            $bbo['sys_pair'] = $data['sys_pair'];
            $bbo['pair'] = $data['pair'];
            $bbo['price_timestamp'] = $data['price_timestamp'];
            $bbo['ask_price'] = $data['asks'][0][0];
            $bbo['ask_volume'] = $data['asks'][0][1];  
            $bbo['bid_price'] = $data['bids'][0][0];
            $bbo['bid_volume'] =  $data['bids'][0][1];         
            
            $bbow = array();
            $bbow['data'][0] = $bbo;
            $bbow['data'][0]['ftok_crc'] = $hash;
            self::writeBBORAM($bbow);
            
            return $bbo;
        }
        shm_detach($shmId);
        sem_release($semId);
        if(!empty($data)) {
            $data_arr = json_decode($data,JSON_OBJECT_AS_ARRAY);
            return $data_arr;
        }
        else {
            Log::systemLog('warn', "BBO RAM DATA is empty hash=".$hash.' ', "Trader");
            return false;
        }
    }
}

