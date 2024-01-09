<?php

class ExternalRAM {
    public static function write($section, $write_data) {
        $id = ftok(__DIR__."/ftok/ExternalRAM.php", 'A');
        $semId = sem_get($id);
        //set semaphore
        //Log::systemLog(4, 'START write into RAM '.json_encode($datas));
        sem_acquire($semId);
        $shmId = shm_attach($id, null, 0666);
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
        
        $data_arr[$section] = $write_data;
        $data_arr['timestamp'] = microtime(true)*1E6;

        $data_json = json_encode($data_arr);
        //Log::systemLog(4, 'Write into RAM '.$data_json);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
    }
}
?>