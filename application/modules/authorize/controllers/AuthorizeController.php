<?php

set_time_limit(0);
ini_set('max_execution_time', 0);

include_once __DIR__ . "/../models/Product.php";
include_once __DIR__ . "/../models/Order.php";

class AuthorizeController extends Authorize {

    /**
     * @url GET /
     */
    public function hello() {
        return "ChinesePod Authorize Controller";
    }

    /**
     * This function is used for adding missing recurring transactions
     * @url GET /add-transactions-to-crm
     * @url POST /add-transactions-to-crm
     * 
     * @param type $start_date
     * @param type $end_date
     */
    public function addTransactionsToCRM($days_before = null, $first_date = null, $last_date = null) {

        set_time_limit(0);
        $transactionModel = new \Praxis\Transaction();
        $subscriptionModel = new \Praxis\Subscription();

        $tranObj = new AuthorizeNetTD;
        //get transactions prior to one week
        $transactions = array();
        $last_date = date('Y-m-d', strtotime('now -1 day'));
        $days_before = 3;
        //getting one week before date
        $first_date = date('Y-m-d', strtotime('-' . $days_before . ' day', strtotime($last_date)));
        $start_month = date('m', strtotime($first_date));
        $start_day = date('d', strtotime($first_date));
        $start_year = date('Y', strtotime($first_date));

        $last_month = date('m', strtotime($last_date));
        $last_day = date('d', strtotime($last_date));
        $last_year = date('Y', strtotime($last_date));

        $firstSettlementDate = substr(date('c', mktime(0, 0, 0, (int) $start_month, (int) $start_day, (int) $start_year)), 0, -6);

        $lastSettlementDate = substr(date('c', mktime(0, 0, 0, (int) $last_month, (int) $last_day, (int) $last_year)), 0, -6);

        $response = $tranObj->getSettledBatchList(true, $firstSettlementDate, $lastSettlementDate);
        $batches = $response->xpath("batchList/batch");
        foreach ($batches as $batch) {
            $batch_id = (string) $batch->batchId;
            $request = new AuthorizeNetTD;
            $tran_list = $request->getTransactionList($batch_id);
            $transactions = array_merge($transactions, $tran_list->xpath("transactions/transaction"));
        }
        $encoded_value = json_encode($transactions);
        $objArr = json_decode($encoded_value);

        if (!empty($objArr)) {
            foreach ($objArr as $obj) {
                $subscription_id = $obj->subscription->id;
                $transaction_id = $obj->transId;
                //checking only for transactions which are recurring
                if ($subscription_id && $obj->subscription->payNum > 1) {
                    $dbTranArr = array();
                    //getting transaction IDs to filter whether they are already in CRM or not
                    $db_transactions = $transactionModel->getSubscriptionTransactionIdsByDateAndPaymethod($subscription_id, 1);
                    //print_r($db_transactions);exit;
                    if (!empty($db_transactions)) {
                        foreach ($db_transactions as $db_transaction) {
                            $dbTranArr[] = $db_transaction['transaction_id'];
                        }
                    }
                    //checking whether transaction id already exists in the database if it exists then nothing should be done
                    if (!in_array($transaction_id, $dbTranArr)) {

                        //fetch data from subscription table
                        $subscription = $subscriptionModel->getSubscriptionBySubscriptionId($subscription_id);
                        $productLength = $subscription['product_length'];
                        $length = (int) $productLength === 7 ? 'day' : 'months';
                        $transaction_date = date('Y-m-d H:i:s', strtotime($obj->submitTimeUTC));
                        $next_billing_date = date('Y-m-d', strtotime('+ ' . $productLength . ' ' . $length, strtotime($transaction_date)));
                        //if subscription is not found on subscription table then probably old subscription on orders table
                        // we might not need this code going forward since subscription data should always be there in subscription
                        // table
                        $next_billing_time = strtotime($next_billing_date);
                        // insert a row in a table
                        $transactionData = array(
                            'transaction_id' => $transaction_id,
                            'subscription_id' => $subscription_id,
                            'product_id' => $subscription['product_id'],
                            'product_length' => $subscription['product_length'],
                            'is_old' => $subscription['is_old'],
                            'product_price' => $obj->settleAmount,
                            'is_recurring_payment' => 1,
                            'is_recurring_product' => 1,
                            'user_id' => $subscription['user_id'],
                            'billed_amount' => $obj->settleAmount,
                            'pay_status' => ($obj->transactionStatus == 'settledSuccessfully') ? 2 : 4, //paid or failed
                            'pay_method' => 1, //authorize
                            'date_created' => date('Y-m-d H:i:s', strtotime($transaction_date)),
                            'last_modified' => date('Y-m-d H:i:s'),
                            'created_by' => $subscription['user_id'],
                            'modified_by' => $subscription['user_id']
                        );
                        $trans_id = $transactionModel->addTransaction($transactionData);
                        if ($trans_id) {
                            //checking if missed transaction data is too old, if its too old then do not update next_billing_time
                            //echo $subscription['next_billing_time'].'subscription time'.strtotime($subscription['next_billing_time']);
                            if (strtotime($subscription['next_billing_time']) <= $next_billing_time && $subscription['status'] == 1) {
                                $subscriptionData = array(
                                    'next_billing_time' => $next_billing_date,
                                    'status' => ($obj->transactionStatus == 'settledSuccessfully') ? 1 : 3, //status kept as it is since sometimes actual status is already cancelled
                                    'last_modified' => date('Y-m-d H:i:s'),
                                );
                                $subscriptionModel->updateSubscription($subscriptionData, $subscription['id']);
                            }
                            //if transaction is actually paid then make update to  user_site_links and credits table if applicable
                            if ($obj->transactionStatus == 'settledSuccessfully' && $subscription['status'] == 1) {
                                //update user sites links table
                                if ($subscription['subscription_type']) {
                                    $userSiteLinksData = array(
                                        'usertype_id' => ($subscription['subscription_type'] == 1) ? 6 : 5,
                                        'expiry' => $next_billing_date,
                                        'active' => 1,
                                        'deactive' => 0,
                                    );
                                }
                                $userSiteLinksObj = new \Praxis\UserSiteLink();
                                $userSiteLinksObj->updateData($userSiteLinksData, $subscription['user_id'], 2);
                                // Add credits for Virtual Classroom payments
                                $creditModel = new \Praxis\Credit();
                                $creditModel->update($subscription['product_id'], $subscription['user_id'], $next_billing_date);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * This function is used for adding missing transactions one by one using a transaction ID
     * These are all recurring transactions that are missed if the site is down
     * @url GET /add-transaction-crm/$transaction_id/$subcription_id
     * @url POST /add-transaction-crm/$transaction_id/$subcription_id
     * @param type $transaction_id
     * @param type $subscription_id
     */
    public function addTransactionToCRM($transaction_id = false, $subscription_id = false) {
        $transactionModel = new \Praxis\Transaction();
        $subscriptionModel = new \Praxis\Subscription();
        $userSiteLinksObj = new \Praxis\UserSiteLink();
        //$data=array('expiry_date'=>date('Y-m-d'));
        //$userSiteLinksObj->updateData($set, $user_id, $site_id);
        //exit;
        $tranObj = new AuthorizeNetTD;
        $transaction = array();
        //check if transaction already exists or not
        $transaction = $transactionModel->getTransactionByTransactionId($transaction_id);
        //proceed only if transaction does not exist in the table
        if (empty($transaction)) {
            $response = $tranObj->getTransactionDetails($transaction_id);
            $transaction = array_merge($response->xpath("transaction"));
            $transactionJson = json_encode($transaction);
            $transactionArr = json_decode($transactionJson, TRUE);
            $finalTransactionArr = $transactionArr[0];

            if (!empty($finalTransactionArr)) {
                if (!$subscription_id) {
                    $subscription_id = $finalTransactionArr['subscription']['id'];
                }

                //fetch data from subscription table
                $subscription = $subscriptionModel->getSubscriptionBySubscriptionId($subscription_id);
                $productLength = $subscription['product_length'];
                $length = (int) $productLength === 7 ? 'day' : 'months';
                $transaction_date = date('Y-m-d H:i:s', strtotime($finalTransactionArr['submitTimeUTC']));
                $next_billing_date = date('Y-m-d', strtotime('+ ' . $productLength . ' ' . $length, strtotime($transaction_date)));
                //if subscription is not found on subscription table then probably old subscription on orders table
                // we might not need this code going forward since subscription data should always be there in subscription
                // table

                $next_billing_time = strtotime($next_billing_date);
                //checking if missed transaction data is too old, if its too old then do not update next_billing_time
                //echo $subscription['next_billing_time'].'subscription time'.strtotime($subscription['next_billing_time']);
                // insert a row in a table
                $transactionData = array(
                    'transaction_id' => $transaction_id,
                    'subscription_id' => $subscription_id,
                    'product_id' => $subscription['product_id'],
                    'product_length' => $subscription['product_length'],
                    'is_old' => $subscription['is_old'],
                    'product_price' => $finalTransactionArr['settleAmount'],
                    'is_recurring_payment' => 1,
                    'is_recurring_product' => 1,
                    'user_id' => $subscription['user_id'],
                    'billed_amount' => $finalTransactionArr['settleAmount'],
                    'pay_status' => ($finalTransactionArr['transactionStatus'] == 'settledSuccessfully') ? 2 : 4, //paid or failed
                    'pay_method' => 1, //authorize
                    'date_created' => date('Y-m-d H:i:s', strtotime($transaction_date)),
                    'last_modified' => date('Y-m-d H:i:s'),
                    'created_by' => $subscription['user_id'],
                    'modified_by' => $subscription['user_id']
                );
                $trans_id = $transactionModel->addTransaction($transactionData);
                if ($trans_id) {
                    //update next billing time
                    if (strtotime($subscription['next_billing_time']) <= $next_billing_time && $subscription['status'] == 1) {
                        $subscriptionData = array(
                            'next_billing_time' => $next_billing_date,
                            //'status' => $finalTransactionArr['responseCode'] == 2 ? 3 : 1,//status kept as it is since sometimes actual status is already cancelled
                            'last_modified' => date('Y-m-d H:i:s'),
                        );
                        $subscriptionModel->updateSubscription($subscriptionData, $subscription['id']);
                    }
                    //if transaction is actually paid then make update to  user_site_links and credits table if applicable
                    if ($finalTransactionArr['transactionStatus'] == 'settledSuccessfully' && $subscription['status'] == 1) {
                        //update user sites links table
                        if ($subscription['subscription_type']) {
                            $userSiteLinksData = array(
                                'usertype_id' => ($subscription['subscription_type'] == 1) ? 6 : 5,
                                'expiry' => $next_billing_date,
                                'active' => 1,
                                'deactive' => 0,
                            );
                        }
                        $userSiteLinksObj = new \Praxis\UserSiteLink();
                        $userSiteLinksObj->updateData($userSiteLinksData, $subscription['user_id'], 2);
                        // Add credits for Virtual Classroom payments
                        $creditModel = new \Praxis\Credit();
                        $creditModel->update($subscription['product_id'], $subscription['user_id'], $next_billing_date);
                    }
                    // Log Transaction error in DB
                    $log = new \Praxis\TransactionLog();

                    $log->log(array(
                        'user_email' => isset($_POST['x_email']) ? $_POST['x_email'] : '',
                        'user_id' => $subscription['user_id'],
                        'time' => date('Y-m-d H:i:s'),
                        'subscription_id' => $subscription_id,
                        'type' => 'MARB',
                        'txn_type' => 'MARB',
                        'description' => 'Missing ARB Logs Addition',
                        'amount' => $finalTransactionArr['settleAmount'],
                        'card_num' => isset($_POST['x_account_number']) ? $_POST['x_account_number'] : '',
                        'exp_date' => '',
                        'cust_id' => isset($_POST['x_invoice_num']) ? $_POST['x_invoice_num'] : 0,
                        'first_name' => isset($_POST['x_first_name']) ? $_POST['x_first_name'] : '',
                        'last_name' => isset($_POST['x_last_name']) ? $_POST['x_last_name'] : '',
                        'email' => isset($_POST['x_email']) ? $_POST['x_email'] : '',
                        'phone' => isset($_POST['x_phone']) ? $_POST['x_phone'] : '',
                        'company' => isset($_POST['x_company']) ? $_POST['x_company'] : '',
                        'country' => isset($_POST['x_country']) ? $_POST['x_country'] : '',
                        'address' => isset($_POST['x_address']) ? $_POST['x_address'] : '',
                        'city' => isset($_POST['x_city']) ? $_POST['x_city'] : '',
                        'zip' => isset($_POST['x_zip']) ? $_POST['x_zip'] : '',
                        'state' => isset($_POST['x_state']) ? $_POST['x_state'] : '',
                        'approved' => true,
                        'edited' => 1,
                        'status' => 1,
                        'gift' => 1,
                        'resp_code' => isset($_POST['x_response_code']) ? $_POST['x_response_code'] : '',
                        'resp_reason_code' => isset($_POST['x_response_reason_code']) ? $_POST['x_response_reason_code'] : '',
                        'resp_reason_text' => isset($_POST['x_response_reason_text']) ? $_POST['x_response_reason_text'] : '',
                        'response' => isset($_POST['x_response']) ? $_POST['x_response'] : '',
                        'text' => isset($_POST['x_response_reason_text']) ? $_POST['x_response_reason_text'] : '',
                        'serialize' => serialize($_POST),
                        'cron' => 1,
                    ));
                }
            }
        } else {
            echo "Transaction already exists";
        }
    }

    /**
     * @url GET /transaction-details
     * @url POST /transaction-details
     */
    public function transactionDetails() {
        $paypalService = new PayPalAPIInterfaceServiceService(Configuration::getAcctAndConfig());
        $transactionDetails = new GetTransactionDetailsRequestType();
        $request = new GetTransactionDetailsReq();
        $transactionDetails->TransactionID = '66299389XG042531C';
        $request->GetTransactionDetailsRequest = $transactionDetails;
        $transDetailsResponse = $paypalService->GetTransactionDetails($request);
        echo "<pre>";
        print_r($transDetailsResponse);
    }
    
     /**
     * @url GET /authorize-transaction-details
     * @url POST /authorize-transaction-details
     */
    public function authorizeTransactionDetails() {
       $transaction_id='8437127266'; 
       $tranObj = new AuthorizeNetTD;
       $response = $tranObj->getTransactionDetails($transaction_id);
       $transaction = array_merge($response->xpath("transaction"));
       $transactionJson = json_encode($transaction);
       $transactionArr = json_decode($transactionJson, TRUE);
       $finalTransactionArr = $transactionArr[0];
       echo "<pre>";
       print_r($finalTransactionArr);
    }

    /**
     * @url GET /get-recurring-details
     * @url POST /get-recurring-details
     */
    public function getRecurringDetails() {

        $paypalService = new PayPalAPIInterfaceServiceService(Configuration::getAcctAndConfig());
        /*
         * Obtain information about a recurring payments profile. 
         */
        $getRPPDetailsReqest = new GetRecurringPaymentsProfileDetailsRequestType();
        /*
         * (Required) Recurring payments profile ID returned in the CreateRecurringPaymentsProfile response. 19-character profile IDs are supported for compatibility with previous versions of the PayPal API.
         */
        // $getRPPDetailsReqest->ProfileID = 'I-VV6C35DDY0TR';


        $getRPPDetailsReq = new GetRecurringPaymentsProfileDetailsReq();
        $getRPPDetailsReq->GetRecurringPaymentsProfileDetailsRequest = $getRPPDetailsReqest;
        try {
            /* wrap API method calls on the service object with a try catch */
            $getRPPDetailsResponse = $paypalService->GetRecurringPaymentsProfileDetails($getRPPDetailsReqest);
        } catch (Exception $ex) {
            $fp = fopen('/tmp/paypaltransactionsearcherror.log', 'w');
            file_put_contents('/tmp/' . date('Y-m-d') . 'paypaltransactionsearcherror.log', $output, FILE_APPEND);
            exit;
        }

        if (isset($getRPPDetailsResponse)) {
            echo "<pre>";
            print_r($getRPPDetailsResponse);
        }
    }

    /**
     * @url GET /transactionc-search
     * @url POST /transactionc-search
     */
    public function transactioncSearch() {
        $start_date = '2016-09-01T05:38:48Z';
        $end_date = '2016-09-30T05:38:48Z';
        $info = 'USER=[paypal-payments_api1.praxislanguage.com]'
                . '&PWD=[VS9X6SKZME3YWBAR]'
                . '&SIGNATURE=[Ai-lAsxyVjZgD6THAZznOmbLx7VxAtxluCRWCTf0TAqCKEBtxMXF0o2Q]'
                . '&METHOD=TransactionSearch'
                . '&TRANSACTIONCLASS=RECEIVED'
                . '&STARTDATE=' . $start_date
                . '&ENDDATE=' . $end_date
                . '&VERSION=94';

        $curl = curl_init('https://api-3t.paypal.com/nvp');
        //curl_setopt($curl, CURLOPT_VERBOSE, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, true);
        //curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        //curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $info);

        //curl_setopt($ch, CURLOPT_HEADER, false);
        //curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        //curl_setopt($ch, CURLOPT_POST, true);
        //curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($ch, CURLOPT_VERBOSE, true);
        //curl_setopt($ch, CURLOPT_USERPWD, CLIENT_ID . ":" . CLIENT_SECRET);
        $fp = fopen('/tmp/paypalnvpcurlerror.log', 'w');
        curl_setopt($curl, CURLOPT_STDERR, $fp);

        $result = curl_exec($curl);

        print_r($result);

        # Bust the string up into an array by the ampersand (&)
        # You could also use parse_str(), but it would most likely limit out
        $result = explode("&", $result);

        # Loop through the new array and further bust up each element by the equal sign (=)
        # and then create a new array with the left side of the equal sign as the key and the right side of the equal sign as the value
        foreach ($result as $value) {
            $value = explode("=", $value);
            $temp[$value[0]] = $value[1];
        }

        # At the time of writing this code, there were 11 different types of responses that were returned for each record
        # There may only be 10 records returned, but there will be 110 keys in our array which contain all the different pieces of information for each record
        # Now create a 2 dimensional array with all the information for each record together
        for ($i = 0; $i < count($temp) / 11; $i++) {
            $returned_array[$i] = array(
                "timestamp" => urldecode($temp["L_TIMESTAMP" . $i]),
                "timezone" => urldecode($temp["L_TIMEZONE" . $i]),
                "type" => urldecode($temp["L_TYPE" . $i]),
                "email" => urldecode($temp["L_EMAIL" . $i]),
                "name" => urldecode($temp["L_NAME" . $i]),
                "transaction_id" => urldecode($temp["L_TRANSACTIONID" . $i]),
                "status" => urldecode($temp["L_STATUS" . $i]),
                "amt" => urldecode($temp["L_AMT" . $i]),
                "currency_code" => urldecode($temp["L_CURRENCYCODE" . $i]),
                "fee_amount" => urldecode($temp["L_FEEAMT" . $i]),
                "net_amount" => urldecode($temp["L_NETAMT" . $i]));
        }
        echo "<pre>";
        print_r($returned_array);

        //Also, I came up with this nifty little, simple script to get more details about a particular transaction:
        //https://developer.paypal.com/webapps/developer/docs/classic/api/merchant/GetTransactionDetails_API_Operation_NVP/


        /* $info = 'USER=[API_USERNAME]'
          . '&PWD=[API_PASSWORD]'
          . '&SIGNATURE=[API_SIGNATURE]'
          . '&VERSION=94'
          . '&METHOD=GetTransactionDetails'
          . '&TRANSACTIONID=[TRANSACTION_ID]'
          . '&STARTDATE=2013-07-08T05:38:48Z'
          . '&ENDDATE=2013-07-12T05:38:48Z';

          $curl = curl_init('https://api-3t.paypal.com/nvp');
          curl_setopt($curl, CURLOPT_FAILONERROR, true);
          curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
          curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
          curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
          curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

          curl_setopt($curl, CURLOPT_POSTFIELDS, $info);
          curl_setopt($curl, CURLOPT_HEADER, 0);
          curl_setopt($curl, CURLOPT_POST, 1);

          $result = curl_exec($curl);

          parse_str($result, $result);

          foreach ($result as $key => $value) {
          echo $key . ' => ' . $value . "<BR>";
          } */
    }

    /*
     * @url /GET /tran-search
     * @url /POST /tran-search
     */

    public function tranSearch() {

        /* $info = 'USER=[API_USERNAME]'
          . '&PWD=[API_PASSWORD]'
          . '&SIGNATURE=[API_SIGNATURE]'
          . '&METHOD=TransactionSearch'
          . '&TRANSACTIONCLASS=RECEIVED'
          . '&STARTDATE=2013-01-08T05:38:48Z'
          . '&ENDDATE=2013-07-14T05:38:48Z'
          . '&VERSION=94'; */
        $start_date = '2016-09-01T05:38:48Z';
        $end_date = '2016-09-30T05:38:48Z';
        $info = 'USER=[paypal-payments_api1.praxislanguage.com]'
                . '&PWD=[VS9X6SKZME3YWBAR]'
                . '&SIGNATURE=[Ai-lAsxyVjZgD6THAZznOmbLx7VxAtxluCRWCTf0TAqCKEBtxMXF0o2Q]'
                . '&METHOD=TransactionSearch'
                . '&TRANSACTIONCLASS=RECEIVED'
                . '&STARTDATE=' . $start_date
                . '&ENDDATE=' . $end_date
                . '&VERSION=94';

        $curl = curl_init('https://api-3t.paypal.com/nvp');
        curl_setopt($curl, CURLOPT_FAILONERROR, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($curl, CURLOPT_POSTFIELDS, $info);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_POST, 1);

        $result = curl_exec($curl);

        # Bust the string up into an array by the ampersand (&)
        # You could also use parse_str(), but it would most likely limit out
        $result = explode("&", $result);

        # Loop through the new array and further bust up each element by the equal sign (=)
        # and then create a new array with the left side of the equal sign as the key and the       right side of the equal sign as the value
        foreach ($result as $value) {
            $value = explode("=", $value);
            $temp[$value[0]] = $value[1];
        }


        for ($i = 0; $i < (count($temp) / 11) - 1; $i++) {
            $returned_array[$i] = array(
                "timestamp" => urldecode($temp["L_TIMESTAMP" . $i]),
                "timezone" => urldecode($temp["L_TIMEZONE" . $i]),
                "type" => urldecode($temp["L_TYPE" . $i]),
                "email" => urldecode($temp["L_EMAIL" . $i]),
                "name" => urldecode($temp["L_NAME" . $i]),
                "transaction_id" => urldecode($temp["L_TRANSACTIONID" . $i]),
                "status" => urldecode($temp["L_STATUS" . $i]),
                "amt" => urldecode($temp["L_AMT" . $i]),
                "currency_code" => urldecode($temp["L_CURRENCYCODE" . $i]),
                "fee_amount" => urldecode($temp["L_FEEAMT" . $i]),
                "net_amount" => urldecode($temp["L_NETAMT" . $i]));
        }

        var_dump($returned_array);
    }

    /**
     * @url GET /transaction-search
     * @url POST /transaction-search
     */
    public function transactionSearch() {

        $PayPal = new PayPalAPIInterfaceServiceService(Configuration::getAcctAndConfig());

        $StartDate = gmdate("Y-m-d\\TH:i:sZ", strtotime('now - 1 day'));

        $TSFields = array(
            'startdate' => $StartDate, // Required.  The earliest transaction date you want returned.  Must be in UTC/GMT format.  2008-08-30T05:00:00.00Z
            'enddate' => '', // The latest transaction date you want to be included.
            'email' => '', // Search by the buyer's email address.
            'receiver' => '', // Search by the receiver's email address.  
            'receiptid' => '', // Search by the PayPal account optional receipt ID.
            'transactionid' => '', // Search by the PayPal transaction ID.
            'invnum' => '', // Search by your custom invoice or tracking number.
            'acct' => '', // Search by a credit card number, as set by you in your original transaction.  
            'auctionitemnumber' => '', // Search by auction item number.
            'transactionclass' => '', // Search by classification of transaction.  Possible values are: All, Sent, Received, MassPay, MoneyRequest, FundsAdded, FundsWithdrawn, Referral, Fee, Subscription, Dividend, Billpay, Refund, CurrencyConversions, BalanceTransfer, Reversal, Shipping, BalanceAffecting, ECheck
            'amt' => '', // Search by transaction amount.
            'currencycode' => '', // Search by currency code.
            'status' => '', // Search by transaction status.  Possible values: Pending, Processing, Success, Denied, Reversed
            'profileid' => ''       // Recurring Payments profile ID.  Currently undocumented but has tested to work.
        );

        $PayerName = array(
            'salutation' => '', // Search by payer's salutation.
            'firstname' => '', // Search by payer's first name.
            'middlename' => '', // Search by payer's middle name.
            'lastname' => '', // Search by payer's last name.
            'suffix' => ''         // Search by payer's suffix.
        );

        $PayPalRequest = array(
            'TSFields' => $TSFields,
            'PayerName' => $PayerName
        );

        $PayPalResult = $PayPal->TransactionSearch($PayPalRequest);
        echo '<pre>';
        print_r($PayPalResult);
    }

    /**
     * @url GET /authorize-transactions
     * @url POST /authorize-transactions
     */
    public function authorizeTransactions() {
        //$this->_helper->viewRenderer->setNoRender();
        //$this->_layout->disableLayout();

        $nbDays = isset($_GET['day']) ? (int) $_GET['day'] : 1;
        $yesterday = date("Y-m-d", time() - $nbDays * 24 * 60 * 60);
        $today = date('Y-m-d');
        $begin = $yesterday . ' 00:00:00';
        $end = $today . ' 23:59:59';

        $transactionModel = new \Praxis\Transaction();
        $subscriptionModel = new \Praxis\Subscription();
        $orderModel = new \Praxis\Order();
        $product2015Model = new \Praxis\Products2015();
        $productModel = new \Praxis\Product();

        $transactionLogModel = new \Praxis\TransactionLogs();
        $transactionLogs = $transactionLogModel->getTransactionLogByDayAndSource($begin, $end, 1);
        foreach ($transactionLogs as $log) {
            try {
                $transactionId = $log['transaction_id'];
                $subscriptionId = $log['subscription_id'];
                $status = $log['status'];
                $responseCode = $log['response_code'];
                if ($transactionId) {
                    $transaction = $transactionModel->getTransactionByTransactionId($transactionId);
                    if ($transaction['id']) {
                        continue; //transaction exists
                    }
                }
                //update subscription
                if ($subscriptionId) {
                    $subscription = $subscriptionModel->getSubscriptionBySubscriptionId($subscriptionId);
                    print_r($subscription);
                    if (!$subscription) {
                        //fixed the old data, as all the subscriptions info stored in the table orders 
                        $order = $orderModel->getOrderBysubscriptionId($subscriptionId);
                        if ($order) {
                            //init subscription data and save to table subscriptions
                            $productId = $order['product2015_id'] ? $order['product2015_id'] : $order['product_id'];
                            $isOld = $order['product2015_id'] ? 0 : 1;
                            if ($isOld) {
                                $productInfo = $productModel->findProductByProductId($productId);
                                $subscriptionType = ($productInfo['usertype_id'] == 6) ? 1 : 2;
                                $productLength = round($productInfo['length_in_days'] / 30);
                            } else {
                                $productInfo = $product2015Model->getProductInfoById($productId);
                                $subscriptionType = ($productInfo['subscription_type'] == 1) ? 1 : 2;
                                $productLength = $productInfo['product_length'] ? $productInfo['product_length'] : 0;
                            }

                            $length = (int) $productLength === 7 ? 'day' : 'month';

                            $subscriptionData = array(
                                'user_id' => $order['user_id'],
                                'subscription_id' => $subscriptionId,
                                'subscription_from' => 1,
                                'subscription_type' => $subscriptionType,
                                'is_old' => $isOld,
                                'product_id' => $productId,
                                'product_length' => $productLength,
                                'status' => ($responseCode == 2) ? 3 : 1, //1=active, 3=past due
                                'date_created' => $order['created_at'],
                                'next_billing_time' => date('Y-m-d', strtotime('+ ' . $productLength . ' ' . $length)),
                            );
                        } else {
                            $output = "Can not get Subscription information in database, Subscription Id: " . $subscriptionId . "\n\r";
                            file_put_contents('/tmp/logs/' . date('Y-m-d') . '-authorize-transaction.log', $output, FILE_APPEND);
                            continue;
                        }
                        $sid = $subscriptionModel->addSubscription($subscriptionData);
                        $subscriptionData['id'] = $sid;
                        $subscription = $subscriptionData;
                    } else {
                        $productLength = $subscription['product_length'];
                        $length = (int) $productLength === 7 ? 'day' : 'month';
                        $subscriptionData = array(
                            'next_billing_time' => date('Y-m-d', strtotime('+ ' . $productLength . ' ' . $length)),
                            'status' => $responseCode == 2 ? 3 : 1,
                        );
                        $subscriptionModel->updateSubscription($subscriptionData, $subscription['id']);
                    }
                }

                $transactionData = array(
                    'transaction_id' => $transactionId,
                    'subscription_id' => $subscriptionId,
                    'product_id' => $subscription['product_id'],
                    'product_length' => $subscription['product_length'],
                    'is_old' => $subscription['is_old'],
                    'product_price' => $log['amount'],
                    'is_recurring_payment' => 1,
                    'is_recurring_product' => 1,
                    'user_id' => $subscription['user_id'],
                    'billed_amount' => $log['amount'],
                    'pay_status' => ($status == 1) ? 2 : 4, //paid or failed
                    'pay_method' => 1, //authoirze
                    'date_created' => date('Y-m-d H:i:s'),
                    'created_by' => $subscription['user_id'],
                    'modified_by' => $subscription['user_id']
                );
                $transactionModel->addTransaction($transactionData);
                print_r($transactionData);
                echo '----------------------------------------' . "\n\r";
                /**
                 * update user access right, we have tree general types of product
                 * 1 = basic, 2 = premium, 3 = speaking class
                 */
                if ($status) {
                    if ($subscription['subscription_type']) {
                        $userSiteLinksData = array(
                            'usertype_id' => ($subscription['subscription_type'] == 1) ? 6 : 5,
                            'expiry' => date("Y-m-d", strtotime("+ {$subscription['product_length']} month")),
                            'active' => 1,
                            'deactive' => 0,
                        );
                    }
                    $userSiteLinksObj = new \Praxis\UserSiteLink();
                    $userSiteLinksObj->updateData($userSiteLinksData, $subscription['user_id'], 2);
                    // Add credits for Clasroom payments
                    $creditsModel = new Praxis\Credit();
                    $creditsModel->update($subscription['product_id'], $subscription['user_id']);
                }
            } catch (Exception $e) {
                $output = "Can not get Subscription information in database, Subscription Id: " . $subscriptionId . "\n\r";
                file_put_contents('/tmp/logs/' . date('Y-m-d') . '-authorize-transaction.log', $output, FILE_APPEND);
            }
        }
    }

    /**
     * @url GET /authorize-subscriptions
     * @url POST /authorize-subscriptions
     * sync subscriptions status from authorize.net
     */
    public function authorizeSubscriptions() {

        set_time_limit(0);
        //Authorize Configurations
        //$paymentConfigurations = require_once('Praxis/Config/PaymentConfig.php');
        //require_once('Payment/anet_php_sdk/AuthorizeNet.php'); // authorize sdk.
        //define("AUTHORIZENET_API_LOGIN_ID", $paymentConfigurations['authorize']['api_login_id']);
        //define("AUTHORIZENET_TRANSACTION_KEY", $paymentConfigurations['authorize']['transaction_key']);
        //define("AUTHORIZENET_SANDBOX", false);
        //define("AUTHORIZENET_LOG_FILE", dirname(__FILE__) . "/log");
        //status : active, expired, suspended, cancelled, terminated
        /*
          Active - The subscription is being processed successfully according to schedule.
          Suspended - The subscription is currently suspended due to a transaction decline, rejection, or error. Suspended subscriptions must be reactivated before the next scheduled transaction or the subscription will be terminated by the payment gateway.
          Terminated - The suspended subscription has been terminated by the payment gateway. Terminated subscriptions cannot be reactivated. If necessary, they can be recreated.
          Canceled - The subscription has been manually canceled by the merchant. Canceled subscriptions cannot be reactivated. If necessary, they can be recreated.
          Expired - The subscription has successfully completed its billing schedule. Expired subscriptions cannot be renewed.
         */

        $pageSize = 1000;
        $subscriptionModel = Praxis_Model::factory('Subscription');
        $subscriptionCount = $subscriptionModel->getSubscriptionCount('1');
        echo "\n\r" . '=======================total:' . $subscriptionCount . '=======================' . "\n\r";

        $pageNums = ceil($subscriptionCount / $pageSize);
        for ($i = 0; $i < $pageNums; $i++) {
            $subscriptions = $subscriptionModel->getDetail(array('subscription_from' => 1, 'more' => true, 'limit' => $pageSize, 'pageId' => $i));
            foreach ($subscriptions as $k => $v) {
                $output = '';
                try {
                    if ($v['subscription_id'] && ($v['subscription_from'] == 1)) {
                        $statusRequest = new AuthorizeNetARB;
                        $statusResponse = $statusRequest->getSubscriptionStatus($v['subscription_id']);
                        if ($statusResponse->getSubscriptionStatus() == 'active') {
                            $subscriptionStatus = '1'; //active
                        } else if ($statusResponse->getSubscriptionStatus() == 'suspended') {
                            $subscriptionStatus = '3'; //past due
                        } else {
                            $subscriptionStatus = '2'; // cancelled
                        }
                        if ($v[id]) {
                            $subscriptionData = array(
                                'status' => $subscriptionStatus
                            );
                            $subscriptionModel->updateSubscription($subscriptionData, $v[id]);
                            $output .= 'Id: ' . $v['id'] . '============ Subscription_Id: ' . $v['subscription_id'] . '============ Status: ' . $statusResponse->getSubscriptionStatus() . '============ ResultCode: ' . $statusResponse->getResultCode() . "\t\n\r";
                        }

                        file_put_contents(APPLICATION_PATH . '/../temp/logs/' . date('Y-m-d') . '-authorize-subscritption.log', $output, FILE_APPEND);
                    }
                } catch (Exception $e) {
                    echo $output;
                }
            }
        }
    }

}

?>
