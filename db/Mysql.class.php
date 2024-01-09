<?php
/**
 * Class for MySQL Database
 *
 * @author titaev_lv
 */
class MySQL implements DbInterface {
    public $conn;
    private $last_erorr = '';
    
    private $host = '';
    private $user = '';
    private $pass = '';
    private $base = '';

    public function connect($credentials=false) {
        try {
            if($credentials) {
                $this->host = $credentials['host'];
                $this->user = $credentials['user'];
                $this->pass = $credentials['pass']; 
                $this->base = $credentials['base'];
            }
            $this->conn = mysqli_connect($this->host,$this->user,$this->pass,$this->base);
            return true;
        } 
        catch(Exception $e) {
            $this->last_erorr = $e->getMessage();
            return false;
        } 
    }
    
    public function select($sql,$bind=false) {
        $this->check_connection();   
        try {
	    $stmt = mysqli_prepare($this->conn, $sql);
            if($bind) {
                $arg = array($stmt);
                $type = '';
                for($i=0;$i<count($bind);$i++) {
                    $type .= $bind[$i]['type'];
                }
                array_push($arg,$type);
                for($i=0;$i<count($bind);$i++) {
                    $arg[] = &$bind[$i]['value'];
                }
                call_user_func_array("mysqli_stmt_bind_param",$arg);
            }
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $data = array();
            while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {
                $data[] = $row;
            }          
            mysqli_stmt_close($stmt);
	    return $data;
	} catch (Exception $ex) {
            $this->last_erorr = "MYSQL ERROR (".mysqli_errno($this->conn).") ".mysqli_error($this->conn);
	    return false;
	}
        return false;
    }
    public function update($sql,$bind=false){
        $this->check_connection();
        try {
	    $stmt = mysqli_prepare($this->conn, $sql);
            if($bind) {
                $arg = array($stmt);
                $type = '';
                for($i=0;$i<count($bind);$i++) {
                    $type .= $bind[$i]['type'];
                }
                array_push($arg,$type);
                for($i=0;$i<count($bind);$i++) {
                    $arg[] = &$bind[$i]['value'];
                }
                call_user_func_array("mysqli_stmt_bind_param",$arg);
            }
            $a = mysqli_stmt_execute($stmt);
            $ret = mysqli_stmt_affected_rows($stmt);    
            mysqli_stmt_close($stmt);            
	    return $ret;
	} catch (Exception $ex) {
            $this->last_erorr = "MYSQL ERROR (".mysqli_errno($this->conn).") ".mysqli_error($this->conn);
	    return false;
	}
        return false;
    }
    public function insert($sql,$bind=false){
        $this->check_connection();
        try {
	    $stmt = mysqli_prepare($this->conn, $sql);
            if($bind) {
                $arg = array($stmt);
                $type = '';
                for($i=0;$i<count($bind);$i++) {
                    $type .= $bind[$i]['type'];
                }
                array_push($arg,$type);
                for($i=0;$i<count($bind);$i++) {
                    $arg[] = &$bind[$i]['value'];
                }
                call_user_func_array("mysqli_stmt_bind_param",$arg);
            }
            mysqli_stmt_execute($stmt);  
            $ret = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
	    return $ret;
	} catch (Exception $ex) {
            $this->last_erorr = "MYSQL ERROR (".mysqli_errno($this->conn).") ".mysqli_error($this->conn);
	    return false;
	}
        return false;
    }
    public function delete($sql,$bind=false){
        $this->check_connection();
        try {
	    $stmt = mysqli_prepare($this->conn, $sql);
            if($bind) {
                $arg = array($stmt);
                $type = '';
                for($i=0;$i<count($bind);$i++) {
                    $type .= $bind[$i]['type'];
                }
                array_push($arg,$type);
                for($i=0;$i<count($bind);$i++) {
                    $arg[] = &$bind[$i]['value'];
                }
                call_user_func_array("mysqli_stmt_bind_param",$arg);
            }
            mysqli_stmt_execute($stmt);
            $ret = mysqli_stmt_affected_rows($stmt);         
            mysqli_stmt_close($stmt);
            
	    return $ret;
	} catch (Exception $ex) {
            $this->last_erorr = "MYSQL ERROR (".mysqli_errno($this->conn).") ".mysqli_error($this->conn);
	    return false;
	}
        return false;
    }
    public function getLastError(){
        return $this->last_erorr;
    }
    public function startTransaction() {
        $this->check_connection();
        $st = mysqli_begin_transaction($this->conn);
        if(!$st) {
            Log::writeLog(1, "MYSQL ERROR failed start trandsaction");      
        }
        return $st;
    }
    public function commitTransaction() {
        $this->check_connection();
        $c = mysqli_commit($this->conn);
        if(!$c) {
            $this->last_erorr = "MYSQL ERROR failed commit trandsaction";      
        }
        return $c;
    }
    public function rollbackTransaction() {
        $this->check_connection();
        $r = mysqli_rollback($this->conn);
        if(!$r) {
            $this->last_erorr = "MYSQL ERROR failed rollback trandsaction";      
        }
        return $r;
    }
    public function getLastID() {
        $this->check_connection();
        return mysqli_insert_id($this->conn);
    }
    public function sql_not_need_prepared ($sql) {
        $q = mysqli_query($this->conn, $sql);
        if($q == false) {
            $this->last_erorr = "MYSQL ERROR (".mysqli_errno($this->conn).") ".mysqli_error($this->conn);
            return $q;
        }
        return true;
    } 
    function __destruct() {
        if(isset($this->conn->server_info)) {
            mysqli_close($this->conn);
        }
    }
    public function close() {
        if(isset($this->conn->server_info)) {
            mysqli_close($this->conn);
        }
    }
    private function check_connection() {
        try {
            mysqli_query($this->conn, "SELECT 1");
            return true;
        }
        catch (Exception $ex) {
            if(mysqli_errno($this->conn) == '2006') {
               $c = 0;
               while($c < 100) {
                    try {
                       $this->conn = mysqli_connect($this->host,$this->user,$this->pass,$this->base);
                       Log::systemLog(4, "Database reconnection return true");
                       usleep(10000);
                       return true;
                    } 
                    catch (Exception $ex2) {
                       $c++;
                        sleep(1);
                    }
                }
            }
            Log::systemLog(1, "Database reconnection return false. ERROR:".mysqli_errno($this->conn));
	    return false;
	}
    }
}
