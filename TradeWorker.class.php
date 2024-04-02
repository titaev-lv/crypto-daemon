<?php

class TradeWorker {
    private $account_exchange;
    private $exchange_id = 0;
    private $worker_account_id = 0;
    private $worker_pair_id = 0;
    private $worker_market = 0;
    private $ram_hash = ''; //WORKER|ACCOUNT ID|MARKET|PAIR_ID
    
    public function __construct($create_arr) {
        global $DB;
        
        if($create_arr['trade_worker_pair_id'] > 0) {
            $this->worker_pair_id = $create_arr['trade_worker_pair_id'];
        }
        $this->worker_market = $create_arr['trade_worker_market'];
        $this->worker_account_id = $create_arr['trade_worker_account_id'];
        $this->exchange_id = Exchange::detectExchangeByAccountId($this->worker_account_id);
        $this->account_exchange = Exchange::init($this->exchange_id, $this->worker_account_id, $this->worker_market);
        //WORKER|ACCOUNT ID|MARKET|PAIR_ID
        $this->ram_hash = hash('xxh3',"WORKER|".$this->worker_account_id.'|'.$this->worker_market.'|'. $this->worker_pair_id);
        $this->initRAM();
        Log::systemLog('warn', 'acc_id='.$this->worker_account_id.' exch_id='.$this->exchange_id.' market='.$this->worker_market.' pair='.$this->worker_pair_id.' ram_hash = '. $this->ram_hash, "TradeWorker");
        
    }
    
    private function initRAM() {
        $path = __DIR__."/ftok/".$this->ram_hash.'.ftok';
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id_input = ftok($path, 'I');
        $id_output = ftok($path, 'O');
        $semId_input = sem_get($id_input);
        sem_acquire($semId_input);
        $data_arr = array();
        $data_json = json_encode($data_arr);
        $shmId_input = shm_attach($id_input, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId_input, $var, $data_json);
        shm_detach($shmId_input);
        sem_release($semId_input);
        $semId_output = sem_get($id_output);
        sem_acquire($semId_output);
        $data_arr = array();
        $data_json = json_encode($data_arr);
        $shmId_output = shm_attach($id_output, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId_output, $var, $data_json);
        shm_detach($shmId_output);
        sem_release($semId_output);
    }
    
    public function readInputRAM() {
        $element = false;
        $path = __DIR__."/ftok/".$this->ram_hash.'.ftok';
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id = ftok($path, 'I');
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
        if(is_array($data_arr) && !empty($data_arr)) {
            $element = array_shift($data_arr);
        }
        $data_json = json_encode($data_arr);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        return $element;
    }
    
    public function writeOutputRAM($input_data) {
        $path = __DIR__."/ftok/".$this->ram_hash.'.ftok';
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id = ftok($path, 'O');
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
        if(is_array($data_arr)) {
            array_push($data_arr, $input_data);
        }
        $data_json = json_encode($data_arr);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        return true;
    }    
}