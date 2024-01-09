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
        $this->timer_update_ob_ping = microtime(true)*1E6;
    }
    
    public function eraseDepthRAM () {
        $lit = '';
        switch ($this->market){
            case 'features':
                $lit = 'B';
                break;
            case 'spot':
            default:
                $lit = 'A';
        }
        $id = ftok(__DIR__."/ftok/".$this->exchange_name."Depth.php", $lit);
        
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
        return true;
    }
    
    public function writeDepthRAM($data_upd) {
        //$start = microtime(true);
        $lit = '';
        switch ($this->market){
            case 'features':
                $lit = 'B';
                break;
            case 'spot':
            default:
                $lit = 'A';
        }
        $id = ftok(__DIR__."/ftok/".$this->exchange_name."Depth.php", $lit);
        
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
        
        //Log::systemLog(4, 'ORDER BOOK READ RAM '.json_encode($data_arr));
        
        foreach ($data_upd['data'] as $d) {
            $element = array();
            if($d['diff'] === false || !isset($data_arr[$d['pair_id']])) {
                $element['sys_pair'] = $d['sys_pair'];
                $element['pair'] = $d['pair'];
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
                $data_arr[$d['pair_id']] = $element;
            }
            elseif($d['diff'] === true){
                $src_mem = $data_arr[$d['pair_id']];
                
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
                $src_mem['timestamp'] = $d['timestamp'];
                $data_arr[$d['pair_id']] = $src_mem;
                //Log::systemLog(4, 'ORDER BOOK ARR FOR MERGE '.json_encode($src));                
            }
        }
        //Log::systemLog(4, 'ORDER BOOK WRITE TO RAM '.json_encode($data_arr));
        $data_json = json_encode($data_arr);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        //$time = microtime(true) - $start;
        //Log::systemLog('debug', 'WRITE TIME Order Book RAM '.$time.'s');
        return true;
    }
    
    public function writeDepthRAMupdatePong() {        
        $lit = '';
        switch ($this->market){
            case 'features':
                $lit = 'B';
                break;
            case 'spot':
            default:
                $lit = 'A';
        }
        $id = ftok(__DIR__."/ftok/".$this->exchange_name."Depth.php", $lit);
        
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
        
        //Log::systemLog(4, 'ORDER BOOK READ PING RAM '.json_encode($data_arr));
        foreach ($data_arr as $k=>$d) {
            $data_arr[$k]['timestamp'] = microtime(true)*1E6;
        }
        //Log::systemLog(4, 'ORDER BOOK WRITE PING RAM '.json_encode($data_arr));
        $data_json = json_encode($data_arr);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        return true;
    }
    public function writeDepthRAMupdatePing() {
        $lit = '';
        switch ($this->market){
            case 'features':
                $lit = 'B';
                break;
            case 'spot':
            default:
                $lit = 'A';
        }
        $id = ftok(__DIR__."/ftok/".$this->exchange_name."Depth.php", $lit);
        
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
        
        //Log::systemLog(4, 'ORDER BOOK READ PING RAM '.json_encode($data_arr));
        foreach ($data_arr as $k=>$d) {
            $data_arr[$k]['timestamp'] = microtime(true)*1E6;
        }
        //Log::systemLog(4, 'ORDER BOOK WRITE PING RAM '.json_encode($data_arr));
        $data_json = json_encode($data_arr);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        return true;
    }
    public static function readDepthRAM($exchange_name,$market) {
        //$start = microtime(true);
        $lit = '';
        switch ($market){
            case 'features':
                $lit = 'B';
                break;
            case 'spot':
            default:
                $lit = 'A';
        }
        $id = ftok(__DIR__."/ftok/".$exchange_name."Depth.php", $lit);
        
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
        //
        if(!empty($data)) {
            $data_arr = json_decode($data,JSON_OBJECT_AS_ARRAY);
        }
        else {
            sem_release($semId);
            return false;
        }
        sem_release($semId);  
        //$time = microtime(true) - $start;
        //Log::systemLog('debug', 'READ TIME Order Book RAM '.$time.'s');
        return $data_arr; 
    }
}

