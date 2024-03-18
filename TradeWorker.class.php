<?php

class TradeWorker {
    private $account_exchange;
    private $exchange_id = 0;
    private $worker_account_id = 0;
    private $worker_pair_id = 0;
    private $worker_market = 0;

    public function __construct($create_arr) {
        global $DB;
        
        if($create_arr['trade_worker_pair_id'] > 0) {
            $this->worker_pair_id = $create_arr['trade_worker_pair_id'];
        }
        $this->worker_market = $create_arr['trade_worker_market'];
        $this->worker_account_id = $create_arr['trade_worker_account_id'];
        $this->exchange_id = Exchange::detectExchangeByAccountId($this->worker_account_id);
               
        $this->account_exchange = Exchange::init($this->exchange_id, $this->worker_account_id, $this->worker_market);
    }
}