<?php

class SystemProc {
    private string $proc_name = '';
    private ?int $parent_proc;
    public $root_dir = '';
    
    //Child proceses
    public array $proc = array(); // name - proc name; pid - pid; timestamp
    public array $proc_tree = array(); // child tree processes
    
    public int $start = 0; //Start time
    public ?int $timestamp = NULL;
    
    //DB connection
    private string $db_engine;
    private array $db_credentials;
    
    public int $timeout_child = 15000000;
    
    //Timers
    /*public int $timer_update_tree = 200000; // 0.2sec
    public int $timer_update_tree_ts = 0;
    
    public int $timeout_child = 15000000;*/
    
    
    public function isDaemonActive($pid_file) {
        if(is_file($pid_file)) {
            $pid = file_get_contents($pid_file);
            //check process
            if(posix_kill(intval($pid),0)) {
                //daemon already started
                return true;
            } else {
                //pid file present, proc is died
                if(!unlink($pid_file)) {
                    //error delete pid-file
                    exit(-1);
                }
            }
        }
        return false;
    }
    
    public function getDBEngine() {
        return $this->db_engine;
    }
    
    
    public function getDBCredentials() {
        return $this->db_credentials;
    }
    
    
    public function setDBEngine($var) {
        $this->db_engine = $var;
    }
    
    
    public function setDBCredentials($ar) {
        $this->db_credentials = $ar;
    }
    
    
    public function getProcName() {
        return $this->proc_name;
    }
    
    
    public function setProcName($name) {
        $this->proc_name = $name;
    }
    
    
    public function setParentProc($pid) {
        $this->parent_proc = $pid;
    }
    
    public function getParentProc() {
        return $this->parent_proc;
    }
    
    public function newProcess($type, $type2) {
        $new_pid = pcntl_fork();
        if ($new_pid == -1) {
            //Error fork 
            Log::systemLog('error',"Error forked process ".$type,$this->proc_name);
            exit('FATAL ERROR. Error forked process '.$type.PHP_EOL);
        } 
        else if ($new_pid) {
            //Parent process 
            $tmp['name'] = 'ctd_'. strtolower($type).'_'.$type2;
            $tmp['pid'] = $new_pid;
            $tmp['type'] = $type;
            $tmp['type2'] = $type2;
            $tmp['timestamp'] = microtime(true)*1E6;
            $this->proc[] = $tmp;
            $data['type'] =  $type;
            $data['type2'] =  $type2;
            $data['parent_proc'] = getmypid();
            ServiceRAM::write($new_pid, 'create_proc', $data);
            Log::systemLog('debug', 'SEND to ServiceRAM command "create_proc" to process = '. $new_pid.' from pid = '.getmypid().' '. json_encode($data), $this->getProcName());
            return $new_pid;
        } 
        else {
            Log::systemLog('debug',"Create new process pid=".getmypid(), "New Process");
            $this->proc = array();
            $this->proc_tree = array();
            switch ($type2) {
                case 'monitor':
                    switch ($type) {
                        case 'OrderBook':
                            $OrderBookMonitor = new OrderBookMonitor();
                            break;
                        default:
                    }
                    break;
                case 'worker':
                    switch ($type) {
                        case 'OrderBook':
                            $OrgerBookWorker = new OrderBookWorker();
                            break;
                        default:
                    }
                    break;
                default:
            }
        }
    }
    
    
    public function receiveProcTree () {
        $pt_arr = ServiceRAM::read("proc_tree");
        if($pt_arr !== false && !empty($pt_arr)) {
           //Log::systemLog('debug', 'Recevive PROC TREE from RAM '. json_encode($pt_arr).' proc='. getmypid());
           return $pt_arr;
        } 
        return false;
    }
    
    
    public function sendProcTree() {
        $send = array();
        $pid = getmypid();
        $send[$pid] = $this->proc_tree;
        ServiceRAM::write($this->getParentProc(), "proc_tree", $send);
        //Log::systemLog('debug', 'SEND to ServiceRAM PROC TREE from proc='. getmypid().' to parent '.$this->parent_proc.' '. json_encode($send).' ');
    }
    
    
    function __construct() {   
        if ($this->isDaemonActive(__DIR__.'/run/ctdaemon.pid')) {
            echo 'ctdaemon already active'.PHP_EOL;
            exit;
        }
        if (is_resource(STDIN)) {
           fclose(STDIN);
           $STDIN = fopen('/dev/null', 'r');
        }

        //Read config.
        if (file_exists(__DIR__."/ctdaemon.ini")) {
            $file_conf = __DIR__."/ctdaemon.ini";
        }
        else {
            echo "FATAL ERROR read file configuration ctdaemon.ini. File not found.".PHP_EOL;
            echo "ctdaemon terminated".PHP_EOL;
            exit();
        }
        $conf = parse_ini_file($file_conf,true);
      
        //Set system log level
        if(!isset($conf['log_error']) || empty($conf['log_error'])) {
            echo "FATAL ERROR Not set system log in ctdaemon.ini.".PHP_EOL;
            echo "ctdaemon terminated".PHP_EOL;
            exit();
        }
        else {
            if($f = @fopen($conf['log_error'], "a")) {
                fclose($f);
                Log::defineSystemLogFile($conf['log_error']);
                chmod($conf['log_error'], 0644);
                set_error_handler(array('Log', 'systemLog'));
            }
            else {
                echo "FATAL ERROR Access deny write system log file.".PHP_EOL;
                echo "daemon terminated".PHP_EOL;
                exit();
            }
        }
        
        Log::systemLog('system',"INIT START ctdaemon", $this->getProcName());
                
        //Set system log level
        if(!isset($conf['log_level']) || empty($conf['log_level'])) {
            Log::systemLog('warn',"System log level is not define. Use default log level 'error'", $this->getProcName());
        }
        else {
            Log::defineSystemLogLevel($conf['log_level']);
            Log::systemLog('debug',"System log level set ".$conf['log_level'], $this->getProcName());
        }
        //Set Trade log
        if(!isset($conf['log_trade']) || empty($conf['log_trade'])) {
            $msg = "FATAL ERROR Not set trade log in ctdaemon.ini.";
            echo $msg.PHP_EOL;
            Log::systemLog('error', $msg, $this->getProcName());
            echo "daemon terminated".PHP_EOL;
            exit();
        }
        else {
            if($f = @fopen($conf['log_trade'], "a")) {
                fclose($f);
                Log::defineTradeLogFile($conf['log_trade']);
                Log::systemLog('debug',"Set Trade log ".$conf['log_trade'], $this->getProcName());
            }
            else {
                $msg = "FATAL ERROR Access deny write trade log file.";
                echo $msg.PHP_EOL;
                Log::systemLog('error', $msg, $this->getProcName());
                echo "daemon terminated".PHP_EOL;
                exit();
            }
        }
               
        //Test Database
        //check param
        if(!isset($conf['db_engine']) || empty($conf['db_engine'])){
            $conf['db_engine'] = 'mysql';
            $message = "Error read database engine from config ctdaemon.ini. Use default mysql.";
            Log::systemLog('warn',$message, $this->getProcName());
        }
        $credentials = array();
        if(!isset($conf['db_host']) || empty($conf['db_host'])){
            $conf['db_host'] = 'localhost';
            $message = "Error read database host from config ctdaemon.ini. Use default localhost.";
            Log::systemLog('warn',$message, $this->getProcName());
        }
        $credentials['host'] = $conf['db_host'];
        if(!isset($conf['db_user']) || empty($conf['db_user'])){
            $message = "FATAL ERROR read databse DB_USER from config ctdaemon.ini. Parameter is not set or empty";
            echo "ctdaemon terminated".PHP_EOL;
            Log::systemLog('error', $message, $this->getProcName());
            exit($message.PHP_EOL);
        }
        $credentials['user'] = $conf['db_user'];
        if(!isset($conf['db_pass'])){
            $message = "FATAL ERROR read databse DB_PASS from config ctdaemon.ini. Parameter is not set";
            echo "ctdaemon terminated".PHP_EOL;
            Log::systemLog('error', $message, $this->getProcName());
            exit($message.PHP_EOL);
        }
        $credentials['pass'] = $conf['db_pass'];
        if(!isset($conf['db_base'])|| empty($conf['db_base'])){
            $message = "FATAL ERROR read databse DB_BASE from config ctdaemon.ini. Parameter is not set";
            echo "ctdaemon terminated".PHP_EOL;
            Log::systemLog('error', $message, $this->getProcName());
            exit($message.PHP_EOL);
        }
        $credentials['base'] = $conf['db_base'];
        $DB = Db::init($conf['db_engine'], $credentials);
        if(!$DB || !empty($DB->getLastError())) {
            $message = "FATAL ERROR Connected to database. ".$DB->getLastError();
            Log::systemLog('error', $message, $this->getProcName());
            exit($message.PHP_EOL);
        }
        //set database 
        $sql = 'USE '.$conf['db_base'];
        $DB->sql($sql);
        if(!empty($DB->getLastError())) {
            $message = "FATAL ERROR select database. ".$DB->getLastError();
            Log::systemLog('error', $message, $this->getProcName());
            exit($message.PHP_EOL);
        }
        //Set ARBITRAGE TRANSACTION new status to suspend
        $sql = "UPDATE `ARBITRAGE_TRANS` SET `STATUS`=3 WHERE `STATUS`=1";
        $DB->sql($sql);
        $DB->close();
        unset($DB);
        $this->setDBEngine($conf['db_engine']);
        $this->setDBCredentials($credentials);
        
        Log::systemLog("debug","Connection to database verified ok", $this->getProcName());
        $this->start = time();
        $this->timestamp = $this->start;
    }
}