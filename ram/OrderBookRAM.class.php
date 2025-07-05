<?php
 
class OrderBookRAM {
    
    public static function eraseDepthRAM ($subscribe) {
        if(!empty($subscribe)) {
            foreach ($subscribe as $key=>$p) {
                $id = self::getTok($p['ftok_crc']);
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
    
    public static function writeDepthRAM($data_upd, $update_subscribe = false) {
        //$start = microtime(true);
        //Log::systemLog(4, 'ORDER BOOK DATA TO WRITE RAM '.json_encode($data_upd));
        $nu = array();
        foreach ($data_upd['data'] as $d) {
            //$start1 = microtime(true);
            $nu[] = $d['ftok_crc'];
            $id = self::getTok($d['ftok_crc']);
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
                $element['last_price'] = $d['last_price'];
                foreach ($d['asks'] as $b) {
                   $tmp = array();
                   $tmp[0] = $b[0];
                   $tmp[1] = $b[1];
                   $element['asks'][] = $tmp;
                   unset($tmp);
                }
                foreach ($d['bids'] as $b) {
                   $tmp = array();
                   $tmp[0] = $b[0];
                   $tmp[1] = $b[1];
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
                            $cmp = bccomp($v[0], $b[0], 12);
                            if($cmp == 0){
                                $cmp2 = bccomp($b[1], 0, 12);
                                if($cmp2 == 0) {
                                    unset($src_mem['bids'][$k]);
                                    $resort_bids = true;
                                    $up = true; //not insert
                                }
                                else {
                                    $src_mem['bids'][$k][1] = $b[1];
                                    $up = true; //not insert
                                }
                            }
                        }
                        if($up === false) {
                            $tmp[0] = $b[0];
                            $tmp[1] = $b[1];
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
                            $cmp = bccomp($v[0], $b[0], 12);
                            if($cmp == 0){
                                $cmp2 = bccomp($b[1], 0, 12);
                                if ($cmp2 == 0){
                                    unset($src_mem['asks'][$k]);
                                    $resort_asks = true;
                                    $up = true; //not insert
                                }
                                else {
                                    $src_mem['asks'][$k][1] = $b[1];
                                    $up = true; //not insert
                                }
                            }
                        }
                        if($up === false) {
                            $tmp[0] = $b[0];
                            $tmp[1] = $b[1];
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
            }
        }
        
        $data_json = json_encode($data_arr);
        $shmId = shm_attach($id, strlen($data_json)+4096);
        $var = 1;
        shm_put_var($shmId, $var, $data_json);
        shm_detach($shmId);
        sem_release($semId);
        //Log::systemLog(4, 'ORDER BOOK AFTER MERGE '.json_encode($data_arr));
        //$term1 = microtime(true) - $start1;
        //Log::systemLog(4, 'NEW Write RAM STEP1 '.$term1. ' '.$d['ftok_crc']);
        //Update timestamp for all exchange's trade pair
        if($update_subscribe && !empty($update_subscribe) && is_iterable($update_subscribe)) {
            foreach ($update_subscribe as $key=>$p) {
                if(!in_array($p['ftok_crc'], $nu)) {
                    //$start2 = microtime(true);
                    $id = self::getTok($p['ftok_crc']);
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
      
    public static function writeDepthRAMupdatePong($update_subscribe) {  
        //$start = microtime(true);
        if(!empty($update_subscribe) && is_iterable($update_subscribe)) {
            foreach ($update_subscribe as $key=>$p) {
                $id = self::getTok($p['ftok_crc']);
                $id2 = self::getTokBBO($p['ftok_crc']);
                
                //Write timestamp order book
                $semId = sem_get($id); //Semaphore
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
                
                //write timestamp bbo
                $semId2 = sem_get($id2); //semaphore
                sem_acquire($semId2);
                //read segment
                $shmId2 = shm_attach($id2);
                $var = 1;
                $data = '';
                $data_arr = array();
                if(shm_has_var($shmId2, $var)) {
                    //get data
                    $data = shm_get_var($shmId2, $var);
                } 
                shm_detach($shmId2);
                //
                if(!empty($data)) {
                    $data_arr = json_decode($data,JSON_OBJECT_AS_ARRAY);
                    if(is_array($data_arr)) {
                        $data_arr['timestamp'] = microtime(true)*1E6;
                        $data_json = json_encode($data_arr);
                        $shmId2 = shm_attach($id2, strlen($data_json)+4096);
                        $var = 1;
                        shm_put_var($shmId2, $var, $data_json);
                        shm_detach($shmId2);
                    }
                }
                sem_release($semId2);
            }
        }
        //$time = microtime(true) - $start;
        //Log::systemLog('debug', 'PONG TIME Order Book RAM NEW '.$time.'s');
        return true;
    }
    
    public static function writeDepthRAMupdatePing($update_subscribe) {
        //$start = microtime(true);
        if(!empty($update_subscribe) && is_iterable($update_subscribe)) {
            foreach ($update_subscribe as $key=>$p) {
                $id = self::getTok($p['ftok_crc']);
                $id2 = self::getTokBBO($p['ftok_crc']);
                
                //Write timestamp order book
                $semId = sem_get($id);//Semaphore
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
                
                //write timestamp bbo
                $semId2 = sem_get($id2); //semaphore
                sem_acquire($semId2);
                //read segment
                $shmId2 = shm_attach($id2);
                $var = 1;
                $data = '';
                $data_arr = array();
                if(shm_has_var($shmId2, $var)) {
                    //get data
                    $data = shm_get_var($shmId2, $var);
                } 
                shm_detach($shmId2);
                //
                if(!empty($data)) {
                    $data_arr = json_decode($data,JSON_OBJECT_AS_ARRAY);
                    if(is_array($data_arr)) {
                        $data_arr['timestamp'] = microtime(true)*1E6;
                        $data_json = json_encode($data_arr);
                        $shmId2 = shm_attach($id2, strlen($data_json)+4096);
                        $var = 1;
                        shm_put_var($shmId2, $var, $data_json);
                        shm_detach($shmId2);
                    }
                }
                sem_release($semId2);
            }
        }   
        return true;
    }
    
    public static function writeBBORAM($data) {
        $id = self::getTokBBO($data['data'][0]['ftok_crc']);
        $data_mod = array();
        $data_mod = $data['data'][0];
        unset($data_mod['pair_id']);
        unset($data_mod['ftok_crc']);
        $data_mod['timestamp'] = microtime(true)*1E6;
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
        $id = self::getTokBBO($hash);
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
            Log::systemLog('debug', "BBO RAM DATA is empty, read from DEPTH hash=".$hash.' ');
            $data = self::readDepthRAM($hash);
            if(empty($data)) {
                return false;
            }
            //read from depht
            $bbo = array();
            $bbo['sys_pair'] = $data['sys_pair'];
            $bbo['pair'] = $data['pair'];
            $bbo['price_timestamp'] = $data['price_timestamp'];
            $bbo['timestamp'] = microtime(true)*1E6;
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
            Log::systemLog('warn', "BBO RAM DATA is empty hash=".$hash.' ');
            return false;
        }
    }
    
    private static function getTok($crc) {
        global $Daemon;
        $path = $Daemon->root_dir."/ftok/".$crc.".ftok";
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id = ftok($path, 'A');
        //Log::systemLog(4, 'RAM '.$id);
        return $id;
    }
    private static function getTokBBO($crc) {
        global $Daemon;
        $path = $Daemon->root_dir."/ftok/".$crc.".ftok";
        if(!is_file($path)) {
            $file = fopen($path, 'w');
            if($file){
                fclose($file);
            }
        }
        $id = ftok($path, 'B');
        return $id;
    }
}

