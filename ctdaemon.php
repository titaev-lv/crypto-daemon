#!/usr/bin/env php
<?php
//define('CURL_SSLVERSION_TLSv1_2', 6);
ini_set('log_errors', 'On');
ini_set('memory_limit', '4096M');
error_reporting(E_ALL);
require_once __DIR__.'/ctdaemon.class.php';  
require_once __DIR__.'/signal.php';
require_once __DIR__.'/fatal_error.php';
require_once __DIR__.'/interfaces.php';
require_once __DIR__.'/OrderBook.class.php';
require_once __DIR__.'/PriceLog.class.php';
require_once __DIR__.'/Service.class.php';
require_once __DIR__.'/Trader.class.php';
require_once __DIR__.'/TraderInstance.class.php';
require_once __DIR__.'/OrderAndTransaction.class.php';
require_once __DIR__.'/TradeWorker.class.php';
//Database
require_once __DIR__.'/db/Db.class.php';
require_once __DIR__.'/db/Mysql.class.php';
//RAM
require_once __DIR__.'/ServiceRAM.class.php';
require_once __DIR__.'/ExternalRAM.class.php';
//Exchanges
require_once __DIR__.'/Exchange.class.php';
require_once __DIR__.'/exchanges/CoinEx.class.php';
require_once __DIR__.'/exchanges/KuCoin.class.php';
require_once __DIR__.'/exchanges/Poloniex.class.php';
require_once __DIR__.'/exchanges/Huobi.class.php';
//
require_once __DIR__.'/Log.class.php';
//VENDOR
//webscocket
require_once __DIR__.'/vendor/autoload.php';

register_shutdown_function('fatal_error');

umask(0);
$Daemon = new ctdaemon();

$pid = pcntl_fork();
if ($pid == -1) {
    //Error fork 
    Log::systemLog('error',"Error forked", "Main        ");
    exit('FATAL ERROR. Error forked'.PHP_EOL);
} else if ($pid) {
    //Parent process, to kill
    exit();
} else {
    Log::systemLog('debug',"Process forked success", "Main        ");  
    //Lost terminal
    $sid = posix_setsid();
    if($sid < 0) {
       Log::systemLog('error',"Can not set current process a session leader", "Main        ");
       exit("FATAL ERROR. Can not set current process a session leader".PHP_EOL);
    }
    else {
        Log::systemLog('debug',"Set current process a session leader", "Main        ");
    }
    $Daemon->proc_name = "ctd_main";
    if(function_exists('cli_set_process_title')) {
        cli_set_process_title("ctd_main");
        $Daemon->proc_name = "ctd_main";
        Log::systemLog("debug","Set daemon main process name ctd_main", "Main        ");
    }
    if(@file_put_contents(__DIR__.'/run/ctdaemon.pid', getmypid())) {
        Log::systemLog('debug',"Create pid file ".__DIR__."/run/ctdaemon.pid", "Main        ");
    }
    else {
        Log::systemLog('error',"Error create pid file ".__DIR__."/run/ctdaemon.pid. Access deny", "Main        ");
    }
    Log::systemLog('debug',"Main process is pid=".getmypid(), "Main        "); 
    //Register system interrupt
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, "sigHandler");
    pcntl_signal(SIGCHLD, "sigHandler");
    pcntl_signal(SIGUSR1, "sigHandler");
    pcntl_signal(SIGUSR2, "sigHandler");

    Log::systemLog(0,"ctdaemon STARTED", "Main        ");
    printf("ctdaemon started".PHP_EOL);
    $Daemon->start = microtime(true)*1E6;
    $Daemon->timestamp = microtime(true)*1E6;
    
    //Create DB connection
    //$DB = DB::init($Daemon->getDBEngine(),$Daemon->getDBCredentials());
    
    //Start process for manage exchanges order book
    $ret = $Daemon->newProcess("ctd_orderbook_monitor");
    
    //Run process for monitoring and log pair price
    $mon = $Daemon->newProcess("ctd_price_monitor");
    
    //Run Trader Worker monitors
    $trw = $Daemon->newProcess("ctd_trade_worker_monitor");
    
    //Run Order and Transaction monitor
    $ot = $Daemon->newProcess("ctd_order_trans_monitor");
    
    //Run trader processes monitor
    $tr = $Daemon->newProcess("ctd_trade_monitor");
    
    //run service process (sync trade pair, sync exchange's fee and other)
    $serv = $Daemon->newProcess("ctd_service");
   
    while(true) {
        $Daemon->timestamp = microtime(true)*1E6;
        
        //For every process need update ProcTree for main perocee
        $Daemon->updateProcTree();
        
        //Write Proc Tree into external RAM (rewrite)
        $pid = getmypid();
        $daemon_status['pid'] = $pid;
        $daemon_status['name'] = $Daemon->proc_name;
        $daemon_status['timestamp'] = $Daemon->timestamp;
        $daemon_status['child'] = $Daemon->proc_tree;
        ExternalRAM::write('daemon_status', $daemon_status);
        
        //Control Child Process
        foreach ($Daemon->proc as $i=>$ch_proc) {
            $delta = $Daemon->timestamp - $ch_proc['timestamp'];
            if($delta > 30000000) { //30sec
                Log::systemLog('warn', 'Process pid='.$ch_proc['pid']. ' '.$ch_proc['name'] . ' have expire timestamp = '.$delta. '. Restart process');
                $kill = posix_kill($ch_proc['pid'], SIGTERM);  
                if($kill) {
                    unset($Daemon->proc[$i]);
                    if(!empty($Daemon->proc_tree)) {   
                        foreach($Daemon->proc_tree as $kt=>$proct) {
                            if($ch_proc['pid'] == $proct['pid']) {
                                unset($Daemon->proc_tree[$kt]);
                            }
                        }
                    }
                    $Daemon->newProcess($ch_proc['name']);
                }
            }
        }
        
        //Log::systemLog('debug', 'PROC TREE '. json_encode($Daemon->proc_tree).' proc='. getmypid());
        
        usleep(200000);
    }
}