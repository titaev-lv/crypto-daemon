<?php
/* 
 * Class for create Order Book Workers
 * 
 */

abstract class AbstractWorker extends AbstractProc {
    
    protected string $proc_type;
    protected string $proc_type2;
       
    
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
    
    public function huntToZombie() {
        return false;
    }
}