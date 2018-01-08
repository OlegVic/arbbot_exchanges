<?php

require_once __DIR__ . '/../Config.php';

class Hitbtc extends Exchange {

    const ID = 7;
    //
    const PUBLIC_URL = 'https://api.hitbtc.com/api/2/public/';
    const PRIVATE_URL = 'https://api.hitbtc.com/api/2/';

    private $fullOrderHistory = null;

    function __construct() {
        parent::__construct( Config::get( "hitbtc.key" ), Config::get( "hitbtc.secret" ) );

    }

    public function addFeeToPrice( $price ) {
        return $price * 1.003;

    }

    public function deductFeeFromAmountBuy( $amount ) {
        return $amount * 0.997;

    }

    public function deductFeeFromAmountSell( $amount ) {
        return $amount * 0.997;

    }

    public function getTickers( $currency ) {

        $ticker = [ ];

        $markets = $this->queryMarketSummary();
        $currencies = $this->queryMarkets();

        foreach ( $markets as $market ) {

            $symbol = $market[ 'symbol' ];
            $currency_ = $currencies[ $symbol ][ 'quoteCurrency' ];
            $tradeable_ = $currencies[ $symbol ][ 'baseCurrency' ];

            if ( $currency_ != $currency ) {
                continue;
            }

            $ticker[ $tradeable_ ] = $market[ 'last' ];
        }

        return $ticker;

    }

    public function withdraw( $coin, $amount, $address ) {

        try {
            $this->queryWithdraw( $coin, $amount, $address );
            return true;
        }
        catch ( Exception $ex ) {
            echo( $this->prefix() . "Withdrawal error: " . $ex->getMessage() );
            return false;
        }

    }

    public function getDepositAddress( $coin ) {

        return $this->queryDepositAddress( $coin );

    }

    public function buy( $tradeable, $currency, $rate, $amount ) {

        try {
            return $this->queryOrder( $tradeable, $currency, 'buy', $rate, $amount );
        }
        catch ( Exception $ex ) {
            if ( strpos( $ex->getMessage(), 'MARKET_OFFLINE' ) !== false ) {
                $this->onMarketOffline();
            }
            logg( $this->prefix() . "Got an exception in buy(): " . $ex->getMessage() );
            return null;
        }

    }

    public function sell( $tradeable, $currency, $rate, $amount ) {

        try {
            return $this->queryOrder( $tradeable, $currency, 'sell', $rate, $amount );
        }
        catch ( Exception $ex ) {
            if ( strpos( $ex->getMessage(), 'MARKET_OFFLINE' ) !== false ) {
                $this->onMarketOffline();
            }
            logg( $this->prefix() . "Got an exception in sell(): " . $ex->getMessage() );
            return null;
        }

    }

    public function getFilledOrderPrice( $type, $tradeable, $currency, $id ) {
        $market = $tradeable . $currency;
        $result = $this->queryAPI( 'history/trades?symbol=' . $market . '&sort=DESC&by=timestamp&limit=10' );
        $id = trim( $id, '{}' );

        foreach ($result as $order) {
            if ($order[ 'clientOrderId' ] == $id) {
                $factor = ($type == 'sell') ? -1 : 1;
                return $order[ 'price' ] + $factor * $order[ 'fee' ];
            }
        }
        return null;
    }

    public function queryTradeHistory( $options = array( ), $recentOnly = false ) {
        $results = array( );

        // Since this exchange was added after merging of the pl-rewrite branch, we don't
        // need the full trade history for the initial import, so we can ignore $recentOnly!

        $result = $this->queryAPI( 'history/trades?sort=DESC&by=timestamp&limit=100' );
        $currencies = $this->queryMarkets();

        foreach ($result as $row) {
            $market = $row[ 'symbol' ];

            $currency = $currencies[ $market ][ 'quoteCurrency' ];
            $tradeable = $currencies[ $market ][ 'baseCurrency' ];

            $market = $tradeable . "_" .$tradeable;

            if (!in_array( $market, array_keys( $results ) )) {
                $results[ $market ] = array();
            }
            $amount = $row[ 'quantity' ];
            $feeFactor = ($row[ 'side' ] == 'sell') ? -1 : 1;

            $results[ $market ][] = array(
                'rawID' => $row[ 'clientOrderId' ],
                'id' => $row[ 'clientOrderId' ],
                'currency' => $currency,
                'tradeable' => $tradeable,
                'type' => strtolower( $row[ 'side' ] ),
                'time' => strtotime( $row[ 'timestamp' ] ),
                'rate' => $row[ 'price' ],
                'amount' => formatBTC( $amount ),
                'fee' => $feeFactor * $row[ 'fee' ],
                'total' => $row[ 'price' ] * $amount,
            );

        }

        foreach ( array_keys( $results ) as $market ) {
            usort( $results[ $market ], 'compareByTime' );
        }

        return $results;
    }

    public function getRecentOrderTrades( &$arbitrator, $tradeable, $currency, $type, $orderID, $tradeAmount ) {

        $results = $this->queryAPI( 'history/trades?sort=DESC&by=timestamp&limit=100&symbol=' . $tradeable . $currency );

        $trades = array( );
        $feeFactor = ($type == 'sell') ? -1 : 1;
        foreach ( $results as $row ) {
            if($row[ 'clientOrderId' ] != $orderID){
                continue;
            }
            $trades[] = array(
                'rawID' => $row[ 'id' ] . '/' . $row[ 'orderId' ],
                'id' => $orderID,
                'currency' => $currency,
                'tradeable' => $tradeable,
                'type' => $type,
                'time' => strtotime( $row[ 'timestamp' ] ),
                'rate' => floatval( $row[ 'price' ] ),
                'amount' => floatval( $row[ 'quantity' ] ),
                'fee' => floatval( $row[ 'fee' ] * $feeFactor ),
                'total' => floatval( $row[ 'price' ] * $row[ 'quantity' ] ),
            );
        }

        return $trades;

    }

    private function queryRecentTransfers( $type, $currency ) {

        $req = $currency ? "&currency=" . $currency : '';
        $history = $this->queryAPI( 'account/transactions?sort=DESC&by=timestamp&limit=100' . $req );

        $result = array();
        foreach ( $history as $row ) {
            if($row[ 'type' ] == $type){
                $result[] = array(
                    'currency' => $row[ 'currency' ],
                    'amount' => formatBTC( $row[ 'amount' ]),
                    'txid' => $row[ 'hash' ],
                    'address' => $row[ 'address' ],
                    'time' => strtotime( $row[ 'updatedAt' ] ),
                    'pending' => $row[ 'status' ] != 'success',
                );
            }
        }

        usort( $result, 'compareByTime' );

        return $result;

    }

    public function queryRecentDeposits( $currency = null ) {

        return $this->queryRecentTransfers( 'payin', $currency );

    }

    public function queryRecentWithdrawals( $currency = null ) {

        return $this->queryRecentTransfers( 'payout', $currency );

    }

    function fetchOrderbook( $tradeable, $currency ) {

        $orderbook = $this->queryOrderbook( $tradeable, $currency );
        if ( count( $orderbook ) == 0 ) {
            return null;
        }

        $ask = $orderbook[ 'ask' ];
        if ( count( $ask ) == 0 ) {
            return null;
        }

        $bestAsk = $ask[ 0 ];

        $bid = $orderbook[ 'bid' ];
        if ( count( $bid ) == 0 ) {
            return null;
        }

        $bestBid = $bid[ 0 ];


        return new Orderbook( //
            $this, $tradeable, //
            $currency, //
            new OrderbookEntry( $bestAsk[ 'size' ], $bestAsk[ 'price' ] ), //
            new OrderbookEntry( $bestBid[ 'size' ], $bestBid[ 'price' ] ) //
        );

    }

    public function cancelOrder( $orderID ) {

        try {
            $this->queryCancelOrder( $orderID );
            return true;
        }
        catch ( Exception $ex ) {
            if ( strpos( $ex->getMessage(), 'Order not found' ) === false ) {
                logg( $this->prefix() . "Got an exception in cancelOrder(): " . $ex->getMessage() );
                return true;
            }
            return false;
        }

    }

    public function cancelAllOrders() {

        $orders = $this->queryOpenOrders();
        foreach ( $orders as $order ) {
            $uuid = $order[ 'clientOrderId' ];
            $this->cancelOrder( $uuid );
        }

    }

    public function refreshExchangeData() {

        $pairs = [ ];
        $markets = $this->queryMarkets();
        $currencies = $this->queryCurrencies();

        // This is a list of tradeables that have a market. Used to filter the
        // tx-fee list, which is later used to seed the wallets
        $tradeables = [ ];
        $names = [ ];
        $txFees = [ ];
        $conf = [ ];
        foreach ( $markets as $market ) {

            $tradeable = $market[ 'baseCurrency' ];
            $currency = $market[ 'quoteCurrency' ];

            if ( !Config::isCurrency( $currency ) ||
                Config::isBlocked( $tradeable ) ) {
                continue;
            }

            if ( $currencies[ $tradeable ][ 'payinEnabled' ] == false ||
                $currencies[ $tradeable ][ 'payoutEnabled' ] == false ||
                $currencies[ $tradeable ][ 'transferEnabled' ] == false ) {
                continue;
            }

            $tradeables[] = $tradeable;
            $pairs[] = $tradeable . '_' . $currency;
            $names[ $tradeable ] = strtoupper( $currencies[ $tradeable ][ 'fullName' ] );
            $txFees[ $tradeable ] = $market[ 'takeLiquidityRate' ];
            $conf[ $tradeable ] = $currencies[ $tradeable ][ 'payinConfirmations' ];
        }


        $this->pairs = $pairs;
        $this->names = $names;
        $this->transferFees = $txFees;
        $this->confirmationTimes = $conf;

        $this->calculateTradeablePairs();

    }

    private function onMarketOffline( $tradeable ) {
        $keys = array( );
        foreach ( $this->pairs as $pair ) {
            if ( startsWith( $pair, $tradeable . '_' ) ) {
                $keys[] = $pair;
            }
        }
        foreach ( $keys as $key ) {
            unset( $this->pairs[ $key ] );
        }

        unset( $this->names[ $tradeable ] );
        unset( $this->transferFees[ $tradeable ] );
        unset( $this->connfirmationTimes[ $tradeable ] );
    }

    public function detectStuckTransfers() {

        // TODO: Detect stuck transfers!

    }

    public function getWalletsConsideringPendingDeposits() {

        $result = [ ];
        foreach ( $this->wallets as $coin => $balance ) {
            $result[ $coin ] = $balance;
        }

        $history = $this->queryDepositsAndWithdrawals();
        foreach ( $history as $row ) {
            if( $row[ 'status' ] != 'pending'){
                continue;
            }
            $coin = $row[ 'currency' ];
            $result[ $coin ] += $row[ 'amount' ];
        }

        try {
            $balancesAccount = $this->queryBalancesAccount();
            foreach ($balancesAccount as $balance) {
                if( !key_exists( $balance[ 'currency' ], $result ) ) {
                    continue;
                }
                if ($balance['available'] > 0) {
                    $result[ strtoupper( $balance[ 'currency' ] ) ] += $balance[ 'available' ] + $balance[ 'reserved' ];
                }
            }
        }
        catch ( Exception $ex ) {
            $error = $ex->getMessage();
            logg($this->prefix() . $error);
        }

//        $balancesTrade = $this->queryBalancesTrade();
//        foreach ( $balancesTrade as $balance ) {
//            $result[ strtoupper( $balance[ 'currency' ] ) ] += $balance[ 'available' ] + $balance[ 'reserved' ];
//        }

        return $result;

    }

    public function dumpWallets() {

        logg( $this->prefix() . print_r( $this->queryBalancesTrade(), true ) );

    }

    public function refreshWallets() {

        $wallets = [ ];

        // Create artifical wallet balances for all traded coins:
        $currencies = $this->transferFees;
        if (!count( $currencies )) {
            // If this is the first call to refreshWallets(), $this->transferFees isn't
            // initialized yet.
            $currencies = $this->queryCurrencies();
        }
        foreach ( array_keys( $currencies ) as $coin ) {
            $wallets[ $coin ] = 0;
        }

        try {
            $balancesAccount = $this->queryBalancesAccount();
            foreach ($balancesAccount as $balance) {
                if( !key_exists( $balance[ 'currency' ], $wallets ) ) {
                    continue;
                }
                if ($balance['available'] > 0) {
                    $this->queryBalancesTransfer(strtoupper($balance['currency']), $balance['available'], 'bankToExchange');
                }
            }
        }
        catch ( Exception $ex ) {
            $error = $ex->getMessage();
            logg($this->prefix() . $error);
        }

        $balances = $this->queryBalancesTrade();
        foreach ( $balances as $balance ) {
            $wallets[ strtoupper( $balance[ 'currency' ] ) ] = $balance[ 'available' ];
        }

        $this->wallets = $wallets;

    }

    public function testAccess() {

        $this->queryBalancesTrade();

    }

    public function getSmallestOrderSize() {

        return '0.00100000';

    }

    public function getID() {

        return Hitbtc::ID;

    }

    public function getName() {

        return "HITBTC";

    }

    public function getTradeHistoryCSVName() {

        return "hitbtc-fullOrders.csv";

    }

    // Internal functions for querying the exchange

    private function queryDepositAddress( $coin ) {

        for ( $i = 0; $i < 100; $i++ ) {
            try {
                $data = $this->queryAPI( 'account/crypto/address/' . $coin );
                return $data[ 'address' ];
            }
            catch ( Exception $ex ) {
                $info = json_decode($ex->getTrace()[ 0 ][ 'args' ][ 0 ]);
                if ($info->success === false &&
                    $info->message === 'ADDRESS_GENERATING') {
                    // Wait while the address is being generated.
                    sleep( 30 );
                    continue;
                }
                throw $ex;
            }
        }

    }

    private function queryDepositsAndWithdrawals()
    {
        return $this->queryAPI('account/transactions?sort=DESC&by=timestamp&limit=100' );
    }

    private function queryOrderbook( $tradeable, $currency ) {
        return $this->xtractResponse( $this->queryPublicJSON( Hitbtc::PUBLIC_URL . 'orderbook/' . $tradeable . $currency ) );

    }

    private function queryMarkets() {
        $data = $this->xtractResponse( $this->queryPublicJSON( Hitbtc::PUBLIC_URL . 'symbol' ) );
        $symbols = [ ];
        foreach ( $data as $row ) {
            $symbols[ $row['id'] ] = $row;
        }

        return $symbols;

    }

    private function queryCurrencies() {
        $data = $this->xtractResponse( $this->queryPublicJSON( Hitbtc::PUBLIC_URL . 'currency' ) );

        $currencies = [ ];
        foreach ( $data as $row ) {
            $currencies [ $row['id'] ] = $row;
        }

        return $currencies;

    }

    private function queryMarketSummary() {
        return $this->xtractResponse( $this->queryPublicJSON( Hitbtc::PUBLIC_URL . 'ticker' ) );

    }

    private function queryCancelOrder( $uuid ) {
        return $this->queryAPI( 'order/' . $uuid, 'DELETE');

    }

    private function queryOpenOrders() {
        return $this->queryAPI( 'order?limit=100' );

    }

    private function queryOrder( $tradeable, $currency, $orderType, $rate, $amount ) {

        $result = $this->queryAPI( 'order', 'POST',
            [
                'symbol' => $tradeable . $currency,
                'side' => strtolower( $orderType ),
                'quantity' => formatBTC( $amount ),
                'type' => 'limit',
                'price' => formatBTC( $rate ),
//                'strictValidate' => 'true'
            ]
        );

        return $result[ 'clientOrderId' ];

    }

    private function queryBalancesTrade() {
        return $this->queryAPI( 'trading/balance' );

    }

    private function queryBalancesAccount() {
        return $this->queryAPI( 'account/balance' );

    }

    private function queryBalancesTransfer($currency, $amount, $way) {
        return $this->queryAPI( 'account/transfer', 'POST',
            [
                'currency' => $currency,
                'amount' => formatBTC( $amount ),
                'type' => $way
            ]
            );

    }

    private function queryWithdraw( $coin, $amount, $address ) {
        $this->queryBalancesTransfer($coin, $amount, 'exchangeToBank');
        sleep(1);
        return $this->queryAPI( 'account/crypto/withdraw', 'POST',
            [
                'currency' => $coin,
                'amount' => formatBTC( $amount ),
                'address' => $address,
                'includeFee' => 'true'
            ]
        );

    }

    private function xtractResponse( $response ) {

        $data = json_decode( $response, true );

        if ( !is_array($data) ) {
            throw new Exception( "Invalid data received: (" . $response . ")" );
        }

        if ( key_exists( 'error', $data ) ) {
            throw new Exception( "API error response: Code [" . $data[ 'error' ][ 'code' ] . "] " . $data[ 'error' ][ 'message' ] );
        }

        return $data;

    }

    private function queryAPI( $method, $request = 'GET', $param = [ ] ) {

        $key = $this->apiKey;
        $secret = $this->apiSecret;

        $uri = Hitbtc::PRIVATE_URL . $method;

        static $ch = null;
//        if ( is_null( $ch ) ) {
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, TRUE );
            curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Cryptsy API PHP client; ' . php_uname( 's' ) . '; PHP/' . phpversion() . ')' );
//        }
        $post_data = http_build_query( $param, '', '&' );
        $header = [ ];
        if($request == 'POST'){
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_data );
            $header[] = 'application/x-www-form-urlencoded';
        }else{
            $header[] = 'accept: application/json';
        }
        if($request == 'PUT' || $request == 'DELETE'){
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $request);
        }
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt( $ch, CURLOPT_USERPWD, $key . ":" . $secret);
        curl_setopt( $ch, CURLOPT_URL, $uri );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );

        $error = null;
        for ( $i = 0; $i < 5; $i++ ) {
            try {
                $data = curl_exec( $ch );
                $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                if ( $code == 600 ) {
                    throw new Exception( "HTTP ${code} received from server" );
                }
                //

                if ( $data === false || $data == '' ) {
                    $error = $this->prefix() . "Could not get reply: " . curl_error( $ch );
                    logg( $error );
                    continue;
                }
                return $this->xtractResponse( $data );
            }
            catch ( Exception $ex ) {
                $error = $ex->getMessage();
                logg( $this->prefix() . $error );

                if ( strpos( $error, 'ORDER_NOT_OPEN' ) !== false ) {
                    // Real error, don't attempt to retry needlessly.
                    break;
                }

                // Refresh request parameters
                $uri = Hitbtc::PRIVATE_URL . $method;
                curl_setopt( $ch, CURLOPT_URL, $uri );
                curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $request);
                curl_setopt( $ch, CURLOPT_USERPWD, $key . ":" . $secret);
                continue;
            }
        }
        throw new Exception( $error );

    }

}
