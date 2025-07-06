#!/usr/bin/env php
<?php
ini_set('log_errors', 'On');
ini_set('memory_limit', '4096M');
ini_set('serialize_precision', -1);
error_reporting(E_ALL);

//System Processes
require_once __DIR__.'/SystemProc.class.php';
//Abstract classes
require_once __DIR__.'/abstract/AbstractProc.class.php';
require_once __DIR__.'/abstract/AbstractMonitor.class.php';
require_once __DIR__.'/abstract/AbstractWorker.class.php';

//Interfaces
require_once __DIR__.'/interfaces/DbInterface.php';
require_once __DIR__.'/interfaces/ExchangeInterface.php';

//Systems
require_once __DIR__.'/ctdaemon.class.php';  
require_once __DIR__.'/signal.php';
require_once __DIR__.'/fatal_error.php';

//Monitor classes
require_once __DIR__.'/monitor/OrderBookMonitor.class.php';

//Worker classes
require_once __DIR__.'/worker/OrderBookWorker.class.php';

//require_once __DIR__.'/OrderBook.class.php';
require_once __DIR__.'/PriceLog.class.php';
require_once __DIR__.'/Service.class.php';
require_once __DIR__.'/Trader.class.php';
require_once __DIR__.'/TraderInstance.class.php';
require_once __DIR__.'/OrderAndTransaction.class.php';
require_once __DIR__.'/TradeWorker.class.php';
//Databases classes
require_once __DIR__.'/db/Db.class.php';
require_once __DIR__.'/db/Mysql.class.php';
//RAM classes
require_once __DIR__.'/ram/ServiceRAM.class.php';
require_once __DIR__.'/ram/ExternalRAM.class.php';
require_once __DIR__.'/ram/OrderBookRAM.class.php';
//Exchanges classes
require_once __DIR__.'/Exchange.class.php';
require_once __DIR__.'/exchanges/CoinEx.class.php';
require_once __DIR__.'/exchanges/CoinExSpot.class.php';
//require_once __DIR__.'/exchanges/CoinExFeatures.class.php';
require_once __DIR__.'/exchanges/KuCoin.class.php';
require_once __DIR__.'/exchanges/KuCoinSpot.class.php';
//require_once __DIR__.'/exchanges/KuCoinFeatures.class.php';
//require_once __DIR__.'/exchanges/Poloniex.class.php';
require_once __DIR__.'/exchanges/Huobi.class.php';
require_once __DIR__.'/exchanges/HuobiSpot.class.php';
//require_once __DIR__.'/exchanges/HuobiFeatures.class.php';
//
require_once __DIR__.'/Log.class.php';
require_once __DIR__.'/MathCalc.class.php';
//VENDOR
//webscocket
require_once __DIR__.'/vendor/autoload.php';

register_shutdown_function('fatal_error');

umask(0);
$Daemon = new SystemProc();
$Daemon->setProcName("ctd_main");
$Daemon->root_dir = __DIR__;

$pid = pcntl_fork();
if ($pid == -1) {
    //Error fork 
    Log::systemLog('error',"Error forked", $Daemon->getProcName());
    exit('FATAL ERROR. Error forked'.PHP_EOL);
} else if ($pid) {
    //Parent process, to kill
    exit();
} else {
    Log::systemLog('debug',"Process forked success", $Daemon->getProcName());  
    //Lost terminal
    $sid = posix_setsid();
    if($sid < 0) {
       Log::systemLog('error',"Can not set current process a session leader", $Daemon->getProcName());
       exit("FATAL ERROR. Can not set current process a session leader".PHP_EOL);
    }
    else {
        Log::systemLog('debug',"Set current process a session leader", $Daemon->getProcName());
    }
    if(function_exists('cli_set_process_title')) {
        cli_set_process_title("ctd_main");
        Log::systemLog("debug","Set daemon main process name ctd_main", $Daemon->getProcName());
    }
    if(@file_put_contents(__DIR__.'/run/ctdaemon.pid', getmypid())) {
        Log::systemLog('debug',"Create pid file ".__DIR__."/run/ctdaemon.pid", $Daemon->getProcName());
    }
    else {
        Log::systemLog('error',"Error create pid file ".__DIR__."/run/ctdaemon.pid. Access deny", $Daemon->getProcName());
    }
    Log::systemLog('debug',"Main process is pid=".getmypid(), $Daemon->getProcName()); 
    //Register system interrupt
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, "sigHandler");
    pcntl_signal(SIGCHLD, "sigHandler");
    pcntl_signal(SIGUSR1, "sigHandler");
    pcntl_signal(SIGUSR2, "sigHandler");

    Log::systemLog(0,"ctdaemon STARTED", $Daemon->getProcName());
    printf("ctdaemon started".PHP_EOL);
       
    //Start process Monitor for manage exchanges order book
    $Daemon->newProcess('OrderBook','monitor');
 
    $Main = new ctdaemon();
    $Main->setProcName($Daemon->getProcName());
    
    $Main->run();
 
    //Run process for monitoring and log pair price
  //  $mon = $Daemon->newProcess("ctd_price_monitor");
    
    //Run Trader Worker monitors
    //$trw = $Daemon->newProcess("ctd_trade_worker_monitor");
    
    //Run Order and Transaction monitor
   // $ot = $Daemon->newProcess("ctd_order_trans_monitor");
    
    //Run trader processes monitor
  //  $tr = $Daemon->newProcess("ctd_trade_monitor");
    
    //run service process (sync trade pair, sync exchange's fee and other)
  //  $serv = $Daemon->newProcess("ctd_service");

}