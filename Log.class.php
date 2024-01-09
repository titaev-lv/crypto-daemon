<?php

class Log {
    private static $system_log_file = '';
    private static $system_log_level = 1;    
    private static $trade_log_file = '';


    public static function defineSystemLogFIile($path) {
        self::$system_log_file = $path;
        return true;
    }
    public static function defineTradeLogFIile($path) {
        self::$trade_log_file = $path;
        return true;
    }
    public static function defineSystemLogLevel($level) {
        self::$system_log_level = $level;
        return true;
    }
    
    public static function systemLog($errlevel, $errstr, $proc_name = false) {
        $level = strtolower($errlevel);
        $log_level_wr = '';
        $log_level = false;
        switch ($level) {
            case 'error':
            case     '1':
                $log_level = 1;
                $log_level_wr = '[ERROR]';
                break;
            case 'warn':
            case    '2':
                $log_level = 2;
                $log_level_wr = '[WARN]';
                break;
            case 'info':
            case      '3':
                $log_level = 3;
                $log_level_wr = '[INFO]';
                break;
            case 'debug':
            case      '4':   
                $log_level = 4;
                $log_level_wr = '[DEBUG]';
                break;
            case 'system':
            case 0:
            default:
                $log_level_wr = '[SYSTEM]';
        } 
	$date = '';
        $dobj = DateTime::createFromFormat('U.u', microtime(true));
	if($dobj) {
	    $dobj->setTimeZone(new DateTimeZone('Europe/Moscow'));
	    $date = $dobj->format('Y-m-d H:i:s.u');
	    unset($dobj);
	}
	else {
	    for($y=0;$y<100;$y++) {
		$dobj = DateTime::createFromFormat('U.u', microtime(true));
		if($dobj) {
		    $dobj->setTimeZone(new DateTimeZone('Europe/Moscow'));
		    $date = $dobj->format('Y-m-d H:i:s.u');
		    unset($dobj);
		    break;
		}
		usleep(10);
	    } 
	}
        $message = $date."  ".$log_level_wr."\t". getmypid()."\t".str_pad($proc_name, 16, " ",STR_PAD_RIGHT)."\t".$errstr;
        if($log_level <= self::$system_log_level){
            $path_file = self::$system_log_file;
            if(!@file_put_contents($path_file, $message.PHP_EOL, FILE_APPEND)) {
               printf("ERROR. Unable write log file $path_file.".PHP_EOL);
               return false;
            }
        }
        return true;
    }
}