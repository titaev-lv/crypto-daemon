<?php
/* 
 * Class for create monitoring prosesses
 * 
 */

abstract class AbstractMonitor extends AbstractProc {
    
    protected string $proc_type;
    protected string $proc_type2;
    
    protected array $proc_not_response_arr;
    
    function __construct() {
        $this->initChildProc(); 
        $this->initAdditinal();  
        $this->run();   
    }
    
    //Addition init action for monitor presess's
    private function initAdditinal() {
        global $Daemon, $DB;
        //Open DB connection
        $DB = DB::init($Daemon->getDBEngine(),$Daemon->getDBCredentials());
    }
}