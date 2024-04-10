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

interface ExchangeInterface {
    //Get Exchange ID
    public function getId();
    //Get Exchange Name
    public function getName();
    //Get Exchange Account ID
    public function getAccountId();
    public function getMarket(); 
    public function syncSpotAllTradePair();
    public function requestSpotTradeFee($pair);
    public function updateCoinsInfoData();
    public function getKLine($pair,$timeframe);
    //Detect Symbols trade pair by ID in system, and market (spot/features)
    public function getTradePairName($pair,$market);
    //Merge data from exchange's websocket and addition data (id trade pair, sys symbol trade pair) 
    public function mergeTradePairData($src, $add_data);
    public function isEnableWebsocket();
    public function isNeedPingWebsocket();
    public function webSocketConnect();
    public function getWebSoketCount();
    public function webSocketPing($client_ws);
    public function webSocketParse($receive);
    public function webSocketMultiSubsribeDepth($client_ws, $data, $previous_subscribe); 
    public function webSocketMultiSubsribeBBO($client_ws, $data, $previous_subscribe);
    public function restMarketDepth ($symbol, $merge, $limit);
    public function restMarketDepthParse($receive);
}