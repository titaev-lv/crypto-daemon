<?php

class Service extends AbstractWorker {
    public $timer_sync_trade_pairs = 60*1E6;
    public $timer_sync_trade_pairs_ts = 0;
    public $timer_sync_fees_active_trade_pairs = 30*1E6;
    public $timer_sync_fees_active_trade_pairs_ts = 0;
    public $timer_sync_coin_data = 45*1E6;
    public $timer_sync_coin_data_ts = 0;
    
    
    public function processing() {
    
        $this->syncTradePair();
            
        //sync active pair's fee from all exchanges 
        //$service->syncFeesActivePairs();
            
        //sync coins from all exchanges (deposit, withdrawal)
        //$service->syncCoins();
            
        usleep(1000000);
    }
    
    
    
    public function syncTradePair() {
        global $DB;       
        //UPDATE TRADE PAIRS FROM EXCHANGES
        if($this->probeTimer("timer_sync_trade_pairs") === true) { 
            //Log::systemLog('debug', 'Sync pairs TIMER');
            //Check need update from DB
            $sql = "SELECT 
                        `EXCHANGE_ID`
                    FROM
                    (
                        SELECT
                            CASE
                                WHEN ((UNIX_TIMESTAMP(SYSDATE()) - UNIX_TIMESTAMP(MAX(stp.`MODIFY_DATE`))) - us.`INTERVAL`) > 0 THEN 1
                                ELSE 0
                            END AS `UPDATE`,
                            stp.`EXCHANGE_ID`
                        FROM
                            `SPOT_TRADE_PAIR` stp
                        LEFT JOIN 
                            `UPDATE_STATUS` us ON us.`COMPONENT` = 'interval_update_spot_trade_pair'
                        LEFT JOIN 
                            `EXCHANGE` e ON stp.`EXCHANGE_ID` = e.`ID`
                        WHERE
                            e.`ACTIVE` = 1
                        GROUP BY
                            us.`INTERVAL`,
                            stp.`EXCHANGE_ID`
                    ) t
                    WHERE 
                        `UPDATE` = 1";
            $exchanges = $DB->select($sql);
            
            if(is_array($exchanges) && !empty($exchanges)) {
                foreach ($exchanges as $exch) {
                    $exch_obj = Exchange::init($exch['EXCHANGE_ID'], false, 'spot');
                    if($exch_obj->getId() < 1) {
                        Log::systemLog('error', 'Error create Exchange object at Sync spot trade pairs', "Service");
                    }
                    else {
                        $status_sync_pair = $exch_obj->syncSpotAllTradePair();
                        if($status_sync_pair) {
                            Log::systemLog('debug', 'Exchange\'s trade pairs '.$exch_obj->getName().' is syncronised', "Service");
                        }
                    }
                    unset($exch_obj);
                }
            }
            //Log::systemLog('debug', 'Exchanges ACTIVE '. json_encode($exchanges));
        }
    }    
    
    public function syncFeesActivePairs() {
        global $DB;
        //UPDATE FEES
        if(ctdaemon::checkTimer($this->timer_sync_fees_active_trade_pairs, $this->timer_sync_fees_active_trade_pairs_ts)) {
            $this->timer_sync_fees_active_trade_pairs_ts = microtime(true)*1E6;
            //Log::systemLog('debug', 'Sync fee TIMER');
            
            //Check need update from DB
            $sql = "WITH
                        account AS (
                            SELECT
                                ea.ID AS EXCHANGE_ACCOUNT_ID,
                                ea.EXID AS EXCHANGE_ID,
                                e.NAME AS EXCHANGE_NAME,
                                u.LOGIN 
                            FROM
                                `EXCHANGE_ACCOUNTS` ea
                            INNER JOIN 
                                `USER` u ON u.`ID` = ea.`UID`
                            LEFT JOIN 
                                `USERS_GROUP` ug ON u.`ID` = ug.`UID` AND ug.`GID` = 2
                            LEFT JOIN 
                                `GROUP` g ON g.`ID` = ug.`GID`  
                            INNER JOIN 
                                `EXCHANGE` e ON e.ID = ea.EXID
                            WHERE
                                1=1
                                AND u.`ACTIVE` = 1
                                AND ea.`ACTIVE` = 1 
                                AND g.`ACTIVE` = 1
                                AND e.`ACTIVE` = 1
                                AND ea.`DELETED` = 0 
                            ORDER BY 
                                e.ID, u.ID
                        ),
                        monitor AS (
                            SELECT 
                                PAIR_ID,
                                EXCHANGE_ACCOUNT_ID AS EA_ID,
                                EXCHANGE_ID_M AS EX_ID
                            FROM (
                                SELECT
                                    DISTINCT(msa.PAIR_ID) AS PAIR_ID,
                                    e.ID AS EXCHANGE_ID_M
                                FROM
                                    MONITORING m
                                INNER JOIN 
                                    MONITORING_SPOT_ARRAYS msa ON m.ID = msa.MONITOR_ID
                                LEFT JOIN 
                                    SPOT_TRADE_PAIR stp ON stp.ID = msa.PAIR_ID 
                                LEFT JOIN 
                                    EXCHANGE e ON e.ID = stp.EXCHANGE_ID 
                                WHERE
                                    m.ACTIVE = 1 
                                    AND stp.ACTIVE = 1
                                    AND e.ACTIVE = 1
                                    AND m.ACTIVE = 1
                                ORDER BY msa.PAIR_ID ASC
                                ) tmp
                            INNER JOIN 
                                account a ON a.EXCHANGE_ID = tmp.EXCHANGE_ID_M
                            GROUP BY tmp.PAIR_ID, a.EXCHANGE_ACCOUNT_ID
                            ORDER BY tmp.PAIR_ID ASC, a.EXCHANGE_ACCOUNT_ID ASC
                        ),
                        trade AS (
                            SELECT
                                tsa.PAIR_ID AS PAIR_ID,
                                tsa.EAID AS EA_ID,
                                e2.ID AS EX_ID
                            FROM
                                TRADE t
                            INNER JOIN 
                                TRADE_SPOT_ARRAYS tsa ON t.ID = tsa.TRADE_ID
                            LEFT JOIN 
                                SPOT_TRADE_PAIR stp2 ON stp2.ID = tsa.PAIR_ID 
                            LEFT JOIN 
                                EXCHANGE e2 ON e2.ID = stp2.EXCHANGE_ID
                            LEFT JOIN 
                                EXCHANGE_ACCOUNTS ea2 ON ea2.ID = tsa.EAID 
                            LEFT JOIN 
                                `USER` u2 ON u2.`ID` = ea2.`UID`
                            LEFT JOIN 
                                `USERS_GROUP` ug2 ON u2.`ID` = ug2.`UID` AND ug2.`GID` = 2
                            LEFT JOIN 
                                `GROUP` g2 ON g2.`ID` = ug2.`GID` 
                            WHERE
                                t.ACTIVE = 1
                                AND stp2.ACTIVE = 1
                                AND e2.ACTIVE = 1
                                AND ea2.ACTIVE = 1
                                AND u2.ACTIVE = 1
                                AND g2.ACTIVE = 1
                            GROUP BY 	
                                tsa.PAIR_ID, tsa.EAID
                            ORDER BY tsa.PAIR_ID ASC
                        ),
                        summ AS (
                            (SELECT * FROM monitor) UNION (SELECT * FROM trade) ORDER BY EX_ID ASC
                        ),
                        for_update AS (
                            SELECT 
                                PAIR_ID,
                                EA_ID,
                                EX_ID,
                                CASE
                                    WHEN f.`MODIFY_DATE` IS NULL THEN 1
                                    WHEN ((UNIX_TIMESTAMP(SYSDATE()) - UNIX_TIMESTAMP(f.`MODIFY_DATE`)) - us.`INTERVAL`) > 0 THEN 1
                                    ELSE 0
                                END AS `UPDATE`
                            FROM
                                summ
                            LEFT JOIN 
                                `UPDATE_STATUS` us ON us.`COMPONENT` = 'interval_update_spot_trade_pair_fee'
                            LEFT JOIN 
                                SPOT_TRADE_PAIR_FEE f ON (f.TRADE_PAIR_ID = summ.PAIR_ID AND EA_ID = f.EAID)
                        )
                    SELECT 
                        PAIR_ID,
                        EA_ID,
                        EX_ID
                    FROM 
                        for_update
                    WHERE 
                        `UPDATE` = 1";
            $list = $DB->select($sql);
            //Log::systemLog('debug', json_encode($list));
            
            //Request fees
            if(is_array($list) && count($list) > 0) {
                $last_ex = false;
                foreach ($list as $l) {
                    if(!$last_ex) {
                        $exchange = Exchange::init($l['EX_ID'], $l['EA_ID']);
                    }
                    elseif ($last_ex !== $l['EX_ID']) {
                        unset($exchange);
                        $exchange = Exchange::init($l['EX_ID'], $l['EA_ID']);
                    }
                    $fee = $exchange->requestSpotTradeFee($l['PAIR_ID']);                    
                    //Log::systemLog('debug', "Exchange ".$l['EX_ID'].' pair='.$l['PAIR_ID'].' '.json_encode($fee));
                    if($fee) {
                        $sql = "INSERT INTO `SPOT_TRADE_PAIR_FEE` (`EAID`,`TRADE_PAIR_ID`,`TAKER_FEE`,`MAKER_FEE`) VALUES(?,?,?,?)
                                    ON DUPLICATE KEY UPDATE `TAKER_FEE`=?,`MAKER_FEE`=?,`MODIFY_DATE`=NOW()";
                        $bind = array();
                        $bind[0]['type'] = 'i';
                        $bind[0]['value'] = $l['EA_ID'];
                        $bind[1]['type'] = 'i';
                        $bind[1]['value'] = $l['PAIR_ID'];
                        $bind[2]['type'] = 'd';
                        $bind[2]['value'] = $fee['taker_fee'];
                        $bind[3]['type'] = 'd';
                        $bind[3]['value'] = $fee['maker_fee'];
                        $bind[4]['type'] = 'd';
                        $bind[4]['value'] = $fee['taker_fee'];
                        $bind[5]['type'] = 'd';
                        $bind[5]['value'] = $fee['maker_fee'];
                        $ins = $DB->insert($sql, $bind);
                        if(!empty($DB->getLastError())) {
                            Log::systemLog("error", 'Error insert trade pair FEE Exchange='.$l['EX_ID'].' Account_id='.$l['EA_ID'], "Service");
                        }
                        else {
                            Log::systemLog("debug", 'Trade pair\'s Fee Exchange='.$exchange->getName().' Account_id='.$l['EA_ID'].' Pair='.Exchange::detectNamesPair($l['PAIR_ID']) .' is updated', "Service");
                        }
                    }
                    $last_ex = $l['EX_ID'];
                    usleep(200000);
                }
            }
        }
    }

    public function syncCoins() {
        global $DB;
        //UPDATE COINS FROM EXCHANGES
        //Sync Coin data (deposit, withdraw)
        if(ctdaemon::checkTimer($this->timer_sync_coin_data, $this->timer_sync_coin_data_ts)) {
            $this->timer_sync_coin_data_ts = microtime(true)*1E6;
            //SELECT trade pairs and monitor, detect coins
            /*$sql = "WITH
                        active_coin AS (
                            SELECT 
                                stp.EXCHANGE_ID,
                                stp.BASE_CURRENCY_ID,
                                stp.QUOTE_CURRENCY_ID
                            FROM
                                (
                                    (
                                        SELECT
                                             DISTINCT(tsa.PAIR_ID) AS PAIR_ID
                                        FROM
                                            TRADE t
                                        INNER JOIN 
                                            TRADE_SPOT_ARRAYS tsa ON t.ID = tsa.TRADE_ID 
                                        WHERE
                                            t.ACTIVE = 1
                                    )
                                    UNION 
                                    (
                                        SELECT
                                            DISTINCT(msa.PAIR_ID) AS PAIR_ID
                                        FROM
                                            MONITORING m
                                        INNER JOIN 
                                            MONITORING_SPOT_ARRAYS msa 
                                                   ON m.ID = msa.MONITOR_ID 
                                        WHERE
                                            m.ACTIVE = 1
                                    )
                                ) uni
                            INNER JOIN 
                                SPOT_TRADE_PAIR stp ON uni.PAIR_ID = stp.ID 
                            WHERE
                                stp.ACTIVE = 1
                        ),
                        merged AS (
                            (
                                SELECT 
                                    BASE_CURRENCY_ID AS COIN 
                                FROM 
                                    active_coin t1
                            ) 
                            UNION 
                            (
                                SELECT 
                                    QUOTE_CURRENCY_ID AS COIN 
                                FROM 
                                    active_coin t2
                            )
                        )
                    SELECT  
                        me.COIN, 
                        c.SYMBOL,
                        ac.EXCHANGE_ID  
                    FROM 
                        merged me               
                    LEFT JOIN 
                        active_coin ac ON (me.COIN = ac.BASE_CURRENCY_ID OR me.COIN = QUOTE_CURRENCY_ID)
                    LEFT JOIN 
                        COIN c ON me.COIN=c.ID
                    GROUP BY 
                        COIN, 
                        EXCHANGE_ID";*/
            $sql = "WITH
                        active_coin AS (
                            SELECT 
                                stp.EXCHANGE_ID, 
				stp.BASE_CURRENCY_ID,
                                stp.QUOTE_CURRENCY_ID
                            FROM
                                SPOT_TRADE_PAIR stp 
                            INNER JOIN 
                                EXCHANGE e ON e.ID = stp.EXCHANGE_ID
                            WHERE
                                e.ACTIVE = 1
                        ),
                        merged AS (
                            (
                                SELECT 
                                    BASE_CURRENCY_ID AS COIN, 
                                    EXCHANGE_ID 
                                FROM 
                                    active_coin t1
                            ) 
                            UNION 
                            (
                                SELECT 
                                    QUOTE_CURRENCY_ID AS COIN, 
                                    EXCHANGE_ID 
                                FROM 
                                    active_coin t2
                            )
                        )
                    SELECT 
                        EXCHANGE_ID AS EXCHANGE_ID
                    FROM 
                        merged  
                    LEFT JOIN 
                        COIN c ON c.ID = merged.COIN
                    LEFT JOIN 
                    	UPDATE_STATUS u ON u.`COMPONENT` = 'interval_update_spot_coin'
                    WHERE 
                    	((UNIX_TIMESTAMP(SYSDATE()) - UNIX_TIMESTAMP(u.`DATE_UPDATE`)) - u.`INTERVAL`) > 0
                    	OR u.`DATE_UPDATE` IS NULL
                    GROUP BY EXCHANGE_ID
                    ORDER BY 
                        EXCHANGE_ID";
            $exch_update = $DB->select($sql);
            if(is_array($exch_update) && !empty($exch_update)) {
                foreach ($exch_update as $eu) {
                    $exchange = Exchange::init($eu['EXCHANGE_ID']);
                    $result = $exchange->updateCoinsInfoData();
                    Log::systemLog("debug", "Echange ".$exchange->getName()." update coins info is finished", "Service");
                    unset($exchange);
                }
                $sql = "UPDATE `UPDATE_STATUS` SET `DATE_UPDATE`=NOW() WHERE `COMPONENT`='interval_update_spot_coin'";
                $upd = $DB->update($sql);
                if(!$upd && !empty($DB->getLastError())) {
                    $DB->rollbackTransaction();
                    Log::systemLog('error', $DB->getLastError(), "Service");
                }
            }
        }
    }
}