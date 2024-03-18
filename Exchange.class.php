<?php
//Factory class for exchange
class Exchange {   
    public static function init($id, $account_id=false) {
        global $DB;
        
        if($id == 0) {
            return false;
        }
        if(is_int($id)) {
            $sql = "SELECT `CLASS_TO_FACTORY` FROM `EXCHANGE` WHERE `ID`=?";
            $bind = array();
            $bind[0]['type'] = 'i';
            $bind[0]['value'] = $id;
            $ret = $DB->select($sql,$bind);
            $class_name = $ret[0]['CLASS_TO_FACTORY'];
        }
        else {
            $class_name = $id;
        }
        return new $class_name($id, $account_id);
    }
    public static function detectCoinIdByName($name,$exchange_id) {
        global $DB;
        //First - select from exception
        $sql = 'SELECT 
                        `ID` 
                    FROM 
                        `COIN` c 
                    CROSS JOIN 
                        `COIN_EXCEPTION` ce 
                            ON c.ID=ce.COIN_ID 
                            WHERE c.`SYMBOL`=? AND ce.EXCHANGE_ID = ?
                    ORDER BY `ID`';
        $bind = array();
        $bind[0]['type'] = 's';
        $bind[0]['value'] = $name;
        $bind[1]['type'] = 'i';
        $bind[1]['value'] = $exchange_id;
        $c0 = $DB->select($sql,$bind);
        if(count($c0) > 0) {
            return $c0[0]['ID'];
        }
        //Second - else
        $sql = 'SELECT `ID` FROM `COIN` WHERE `SYMBOL`=? ORDER BY `ID`';
        $bind = array();
        $bind[0]['type'] = 's';
        $bind[0]['value'] = $name;
        $c = $DB->select($sql,$bind);
        if(count($c) < 1) {
            return false;
        }
        return $c[0]['ID'];
    }

    public static function detectIdPair($pair, $exchange_id) {
        global $DB;
        $ar_pair = explode("/",$pair);
        $base = $ar_pair[0];
        $quote = $ar_pair[1];
        $base_id = self::detectCoinIdByName($base, $exchange_id);
        $quote_id = self::detectCoinIdByName($quote, $exchange_id);
        
        if($base_id > 0 && $quote_id > 0){
            $sql = "SELECT `ID` FROM `SPOT_TRADE_PAIR` WHERE `BASE_CURRENCY_ID`=? AND `QUOTE_CURRENCY_ID`=? AND `EXCHANGE_ID`=?";
            $bind = array();
            $bind[0]['type'] = 'i';
            $bind[0]['value'] = $base_id;
            $bind[1]['type'] = 'i';
            $bind[1]['value'] = $quote_id;
            $bind[2]['type'] = 'i';
            $bind[2]['value'] = $exchange_id;
            $pid = $DB->select($sql,$bind);
            if(count($pid) > 0 && (int)$pid[0]['ID'] > 0) {
                return (int)$pid[0]['ID'];
            }
            
        }
        return false;
    }
    public static function detectNamesPair($pair_id) {
        global $DB;
        if($pair_id >0) {
            $sql = "SELECT
                        CONCAT(c1.`SYMBOL`,'/',c2.`SYMBOL`) AS `NAME`
                    FROM
                        `SPOT_TRADE_PAIR` stp
                    LEFT JOIN 
                        `COIN` c1 ON c1.`ID` = stp.`BASE_CURRENCY_ID`
                    LEFT JOIN 
                        `COIN` c2 ON c2.`ID` = stp.`QUOTE_CURRENCY_ID`
                    WHERE
                        stp.`ID` = ?";
            $bind = array();
            $bind[0]['type'] = 'i';
            $bind[0]['value'] = $pair_id;
            $pname = $DB->select($sql,$bind);
            if(is_array($pname) && count($pname)>0) {
                return $pname[0]['NAME'];
            }
        }
        return false;
    }
    public static function delistSpotTradePair($exchange_id) {
        global $DB;
        $sql = "SELECT `EXCHANGE_ID`, UNIX_TIMESTAMP(MAX(`MODIFY_DATE`))-1000 AS `LAST_UPDATE` FROM `SPOT_TRADE_PAIR` GROUP BY `EXCHANGE_ID`";
        $max = $DB->select($sql);
        if(count($max) > 0 ) {
            $i = 0;
            foreach ($max as $m) {
                if($m['EXCHANGE_ID'] == $exchange_id) {
                    $sql = 'UPDATE `SPOT_TRADE_PAIR` SET `ACTIVE`=0 WHERE `EXCHANGE_ID`=? AND `MODIFY_DATE` < FROM_UNIXTIME(?) AND `ACTIVE`=1';
                    $bind = array();
                    $bind[0]['type'] = 'i';
                    $bind[0]['value'] = $max[$i]['EXCHANGE_ID'];
                    $bind[1]['type'] = 's';
                    $bind[1]['value'] = $max[$i]['LAST_UPDATE'];
                    $DB->update($sql,$bind);
                }
                $i++;
            }
            return true;
        }
        return false;
    }
    
    public static function getTradePirs($exchange_1, $exchange_2) {
        global $DB;
        $sql = "SELECT
                            CONCAT(c1.SYMBOL, '/', c2.SYMBOL) as pair
                    FROM
                            (
                            SELECT
                                    p1.BASE_CURRENCY_ID AS BC_ID,
                                    p1.QUOTE_CURRENCY_ID AS QC_ID
                            FROM
                                    (
                                    SELECT
                                            BASE_CURRENCY_ID,
                                            QUOTE_CURRENCY_ID
                                    FROM
                                            SPOT_TRADE_PAIR stp1
                                    WHERE
                                            stp1.EXCHANGE_ID = ?
                                            AND stp1.ACTIVE = 1 
                                    ) p1
                            INNER JOIN 
                            (
                                    SELECT
                                            BASE_CURRENCY_ID,
                                            QUOTE_CURRENCY_ID
                                    FROM
                                            SPOT_TRADE_PAIR stp2
                                    WHERE
                                            stp2.EXCHANGE_ID = ?
                                            AND stp2.ACTIVE = 1 
                            ) p2
                                    ON
                                    p1.`BASE_CURRENCY_ID` = p2.`BASE_CURRENCY_ID`
                                    AND p1.`QUOTE_CURRENCY_ID` = p2.`QUOTE_CURRENCY_ID`
                    ) p3
                    INNER JOIN 
                            COIN c1 ON
                            c1.ID = p3.BC_ID 
                    INNER JOIN 
                            COIN c2 ON
                            c2.ID = p3.QC_ID
                    ORDER BY c1.SYMBOL, c2.SYMBOL";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $exchange_1;
        $bind[1]['type'] = 'i';
        $bind[1]['value'] = $exchange_2;
        $d = $DB->select($sql,$bind); 
        
        if(count($d) > 0) {
            return $d;
        }
        return false;
    }
    public static function detectExchangeByAccountId($account_id) {
        global $DB;
        $sql = "SELECT `EXID` FROM `EXCHANGE_ACCOUNTS` WHERE `ID` = ?";
        $bind = array();
        $bind[0]['type'] = 'i';
        $bind[0]['value'] = $account_id;
        $exid= $DB->select($sql,$bind); 
        if(is_array($exid) && !empty($exid[0]['EXID'])) {
            return intval($exid[0]['EXID']);
        }
        return false;
    }
    public function getExchangeNameById($id) {
        
    }
    public static function detectChainByName($str) {
        global $DB;
        $ch = mb_strtoupper($str);
        
        //PreProcessing
        switch ($ch) {
            case 'BSC':
                $ch_p = 'BEP20';
                break;
            case 'AVA_C':
                $ch_p = 'AVAXC';
                break;
            default: 
                $ch_p =  $ch;
        }
        
        $sql = "SELECT `ID` FROM `CHAIN` WHERE `NAME` = ? LIMIT 1";
        $bind = array();
        $bind[0]['type'] = 's';
        $bind[0]['value'] = $ch_p;
        $ch_id= $DB->select($sql,$bind); 
        if(is_array($ch_id) && !empty($ch_id[0]['ID'])) {
            return intval($ch_id[0]['ID']);
        }
        return false;
    }
}