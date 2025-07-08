<?php

abstract class AbstractExchange {
    protected int $exchange_id = 0;
    protected string $name = '';
    
    protected int $account_id = 0;
    protected string $api_key = '';
    protected string $secret_key = '';
    protected string $passphrase = '';
        
    protected $timestamp = 0;
    
    //Get Exchange ID
    public function getId() {
        return $this->exchange_id;
    }
    //Get Exchange Name
    public function getName() {
        return $this->name;
    }
    //Get Exchange Account ID
    public function getAccountId() {
        return $this->account_id;
    }

}