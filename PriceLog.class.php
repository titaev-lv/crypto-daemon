<?php

class PriceLog extends AbstractWorker {
    public $timer_update_price = 500000;
    public $timer_update_price_ts = 0;
    public $timer_update_task = 10000000;
    public $timer_update_task_ts = 0;
    
    private $tasks = array();   
    
    public function processing() {
        global $Daemon, $DB;
        
        //Read tasks
        if($this->probeTimer("timer_update_task") === true) {
             $this->tasks = $this->getActiveExchangePairMonitoring();
             //Log::systemLog('debug',"Process type \"Price Monitor\" TASKS". json_encode($tasks),$Daemon->getProcName());
        }

        //Price Logger
        if($this->probeTimer("timer_update_price") === true) {
            if(isset($this->tasks) && is_array($this->tasks)) {
                $date_obj = new DateTime('now', new DateTimeZone('UTC'));
                $spot_data = array();
                foreach ($this->tasks as $key=>$t) {
                    if(isset($t['spot'])) {
                        foreach ($t['spot'] as $key2=>$t2) {
                            // Exchange ID | Market (spot) | PAIR ID
                            $hash = hash('xxh3', $key.'|spot|'.$t2['id']);
                            $spot_data[$t2['id']] = OrderBookRAM::readDepthRAM($hash);
                        }
                    }
                    if(isset($t['features'])) {

                    }
                }
                //Log::systemLog('debug',"RAM PRICE". json_encode($spot_data),$Daemon->getProcName());
                if(!empty($spot_data)) {
                    if(count($spot_data) > 0) {
                        //Log::systemLog('debug',"Process type \"Price Monitor\" DATA". json_encode($spot_data),$Daemon->getProcName());
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
                                   // Log::systemLog('warn',"Process type \"Price Monitor\" spot DATA price timestamp very old ".$tmp. ' '.$d->format("Y-m-d H:i:s.u").' '. json_encode($q), $Daemon->getProcName());
                                }
                            }
                        }
                        $DB->commitTransaction();
                    }
                }
                //Log::systemLog('debug',"Process type \"Price Monitor\" WRITE INTO DB".(microtime(true)-$start_time));
                //Log::systemLog('debug',"Process type \"Price Monitor\" DATA". json_encode($spot_data),$Daemon->getProcName());
            }
        }
        usleep(100);
     }
    
    public function getActiveExchangePairMonitoring() {
       global $Daemon, $DB;

       $sql = 'SELECT 
                    stp.EXCHANGE_ID,
                    t.PAIR_ID,
                    e.NAME
                FROM
                    (
                     SELECT
                         DISTINCT(msa.PAIR_ID) AS PAIR_ID
                     FROM
                         MONITORING m
                     INNER JOIN 
                         MONITORING_SPOT_ARRAYS msa 
                                ON m.ID = msa.MONITOR_ID 
                     WHERE
                         ACTIVE = 1
                    ) t
                INNER JOIN 
                    SPOT_TRADE_PAIR stp 
                        ON t.PAIR_ID = stp.ID
                LEFT JOIN EXCHANGE e 
                    ON e.ID=stp.EXCHANGE_ID
                WHERE 
                    e.ACTIVE = 1
                ORDER BY 
                    EXCHANGE_ID ASC';
              
       
        $ex_list = $DB->select($sql);
        if(!$ex_list && !empty($DB->getLastError())) {
            $message = "ERROR select Active Exchange Price Monitoring. ".$DB->getLastError();
            Log::systemLog('error', $message, $Daemon->getProcName);
            return false;
        }
        $ret = array();

        foreach ($ex_list as $l) {
            $tmp = array();
            $tmp['id'] = $l['PAIR_ID'];
            $ret[$l['EXCHANGE_ID']]['spot'][] = $tmp;
            $ret[$l['EXCHANGE_ID']]['name'] = $l['NAME'];
        }
        
        //test features
        /*$tmptmp['id'] = "1";
        $ret[1]['features'][] = $tmptmp;*/

        return $ret;
    }
}