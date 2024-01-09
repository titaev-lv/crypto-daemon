<?php

class PriceLog {
    public $timer_update_price_ts = 0;
    public $timer_update_task_ts = 0;
    
    public function getActiveExchangePairMonitoring() {
       global $DB;

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
            Log::systemLog('error', $message, "Price Monitor");
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