<?php

class ServiceRAM {
    
    public static function read($action) {
        //$start = microtime(true);
        $pid = getmypid();
        $id = self::getTok();
        //Log::systemLog(4, 'START read from RAM action-'.json_encode($id));
        $semId = sem_get($id);
        //set semaphore
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
        //Log::systemLog(4, 'Reader read RAM '.json_encode($data_arr));   
        if(is_array($data_arr)) {
            foreach ($data_arr as $k=>$v) {
                //Only my pid
                if($k == $pid) {
                    $found = array();
                    $ret_data = array();
                    foreach ($v as $k2=>$v2) { 
                        if($v2['action'] == $action) {
                            $tmp = array();
                            $tmp['data'] = $v2['data'];
                            $tmp['timestamp'] = $v2['timestamp'];
                            $ret_data[] = $tmp;
                            $found[] = $k2;
                        }
                    }
                    if(!empty($found)) {
                        foreach ($found as $fk) {
                            unset($v[$fk]);
                        }
                        if(!empty($v)) {
                            usort($v, function($a,$b){return $a['timestamp']<=>$b['timestamp'];}); 
                        }
                    }
                    if(!empty($v)) {
                        $data_arr[$pid] = $v;
                    }
                    else {
                        unset($data_arr[$pid]);
                    }
                    break;
                }
            }
            //Clear old messages
            foreach ($data_arr as $k=>$v) {
                $now = microtime(true)*1E6;
                if(count($v) > 0) {
                    foreach ($v as $k2=>$v2) { 
                        if(($now-$v2['timestamp']) > 600000000) { //10 min
                            unset($data_arr[$k][$k2]);
                            Log::systemLog('warn', 'Delete old message from ServiceRAM '.json_encode($v2).' For process = '.$k);   
                        }
                    }
                } 
                //
                if(empty($data_arr[$k])) {
                    unset($data_arr[$k]);
                }
            }
        }
        $data_json = json_encode($data_arr);
        //Log::systemLog(4, 'Reader write into RAM '.$data_json);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        //Log::systemLog(4, 'STOP read from RAM action-'.json_encode($action));
        //$time = microtime(true) - $start;
        //Log::systemLog('debug', 'READ TIME SERVICE RAM '.$time.'u');
        return $ret_data;
    }
    
    public static function write($pid, $action, $datas) {
        //$start = microtime(true);
        $id = self::getTok();
        //Log::systemLog(4, 'START write from RAM action-'.json_encode($id));
        $semId = sem_get($id);
        //set semaphore
        //Log::systemLog(4, 'START write into RAM '.json_encode($datas));
        sem_acquire($semId);
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
        
        $tmp = array();
        $tmp['action'] = $action;
        $tmp['data'] = $datas;
        $tmp['timestamp'] = microtime(true)*1E6;

        if(empty($data_arr)){
            $data_arr[$pid][] = $tmp;
        }
        elseif(!isset($data_arr[$pid])) {
            $data_arr[$pid][] = $tmp;
        }
        else {
            $data_arr[$pid][] = $tmp;
            usort($data_arr[$pid], function($a,$b){return $a['timestamp']<=>$b['timestamp'];});
        }
        $data_json = json_encode($data_arr);
        //Log::systemLog(4, 'Write into RAM '.$data_json);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        //Log::systemLog(4, 'STOP write into RAM '.json_encode($datas));
        //$time = microtime(true) - $start;
        //Log::systemLog('debug', 'WRIRE TIME SERVICE RAM '.$time.'s');
        return true;
    }
    
    private static function getTok() {
        global $Daemon;
        $id = ftok($Daemon->root_dir."/ftok/ServiceRAM.php", 'A');
        return $id;
    }
}
