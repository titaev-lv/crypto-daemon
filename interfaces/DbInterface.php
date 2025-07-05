<?php

interface DbInterface {
    public function connect($credentials);
    public function select($sql,$bind=false);
    public function update($sql,$bind=false);
    public function insert($sql,$bind=false);
    public function delete($sql,$bind=false);
    public function getLastError();
    public function startTransaction();
    public function commitTransaction();
    public function rollbackTransaction();
    public function getLastID();
}