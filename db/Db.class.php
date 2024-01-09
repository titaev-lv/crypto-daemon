<?php
/**
 * Factory DB class
 *
 * @author titaev_lv
 */
class Db {  
    public static function init($engine, $credentials) {
        switch ($engine) {
            case 'mysql':
                $db = new MySQL();
                break;
            case 'oracle':
                $db = new Oracle();
                break;
            default:
                exit('Error create database object');
        }
        $db->connect($credentials);
        return $db;
    }
}