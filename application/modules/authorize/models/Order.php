<?php
namespace Praxis;
class Order extends \Root {

    private $_current_table = "orders";

    /*public function __construct() {
        $this->praxis_db = Zend_Registry::get('praxis_db');
//        $this->cpod2015_db = Zend_Registry::get("cpod2015_db");
    }*/

    public function getTableName() {
        return $this->_current_table;
    }

    public function setTableName($tableName) {
        $this->_current_table = $tableName;
    }

    /**
     *  find orders by user id
     */
    public function findOrdersByUserSiteLinkId($user_site_link_id, $pay_status = "paid", $limit = 10) {
        $where = $this->praxis_db->quoteInto("SELECT o.created_at,o.billed_amount,ut.name,p.length FROM `$this->_current_table` AS o " .
                " INNER JOIN `products` AS p ON o.product_id = p.id " .
                " INNER JOIN `usertypes` AS ut ON ut.id = p.usertype_id" .
                " AND o.user_site_link_id = ?", $user_site_link_id);
        $where .= $this->praxis_db->quoteInto(" AND o.pay_status = ? ORDER BY o.id DESC LIMIT $limit", $pay_status);
        $orders = $this->praxis_db->fetchAll($where);

        return $orders;
    }

    /**
     * find orders by user id and site id
     */
    public function findOrdersByUserIdAndSiteId($user_id, $site_id) {
        $where = $this->praxis_db->quoteInto(" SELECT o.id, o.product_id, o.user_id, o.pay_status, o.start_date, o.site_id, p.length " .
                " FROM `orders` o, `products` p WHERE o.user_id = ?", $user_id);
        $where .= $this->praxis_db->quoteInto(" AND o.site_id = ? ", $site_id);
        $where .= " AND ( o.pay_status = 'paid' OR o.pay_status = 'subscribe' ) AND p.id= o.product_id ORDER BY o.id DESC LIMIT 1";
        $orders = $this->praxis_db->fetchRow($where);

        return $orders;
    }

    /**
     *  find orders by user id
     */
    public function findOrdersByUsername($username) {
        $where = $this->praxis_db->quoteInto("SELECT o.created_at,o.pay_status,p.name FROM `$this->_current_table` AS o LEFT JOIN `products` AS p ON o.product_id = p.id " .
                "WHERE o.username = ?", $username);
        $orders = $this->praxis_db->fetchAll($where);
        return $orders;
    }

    public function findOrdersByUserId($uid) {
        $where = $this->praxis_db->quoteInto("SELECT o.* ,o.id as order_id FROM `$this->_current_table` AS o LEFT JOIN `products` AS p ON o.product_id = p.id " .
                "WHERE o.user_id = ?", $uid);
        $where .= " AND  (o.pay_status != 'to pay')";
        $where .= " ORDER BY o.id DESC ";
        $orders = $this->praxis_db->fetchAll($where);
        return $orders;
    }

    public function findOrdersSerialByUserId($uid) {
        $where = $this->praxis_db->quoteInto("SELECT o.* ,o.id as order_id FROM `$this->_current_table` AS o LEFT JOIN `products` AS p ON o.product_id = p.id " .
                "WHERE o.user_id = ?", $uid);
        $where .= " AND  (o.pay_status != 'to pay')";
        $where .= " ORDER BY `o`.serial_id desc,o.id asc ";
        $orders = $this->praxis_db->fetchAll($where);
        return $orders;
    }

    public function findOrdersSerialTotalPaymentByUserId($uid) {
        $where = $this->praxis_db->quoteInto("SELECT `serial_id` , SUM( `billed_amount` ) as `totalPayment`" .
                " FROM `$this->_current_table`AS o WHERE `o`.`user_id` =?", $uid);
        $where.=" GROUP BY `serial_id`";
        $serials = $this->praxis_db->fetchAll($where);
        return $serials;
    }

    public function findOrdersById($pay_id, $uid) {
        $where = $this->praxis_db->quoteInto("SELECT p.*,o.*,u.desc,u.length FROM `$this->_current_table` AS o LEFT JOIN `users` AS p ON o.user_id = p.id LEFT JOIN products AS u ON u.id=o.product_id " .
                "WHERE o.user_id = ?", $uid);
        $where .="  AND o.id=" . intval($pay_id);
        $orders = $this->praxis_db->fetchRow($where);

        return $orders;
    }

    public function findOrdersByTransactionId($transaction_id) {
        $where = $this->praxis_db->quoteInto("SELECT o.*,p.desc,p.length FROM `$this->_current_table` AS o LEFT JOIN  products AS p ON p.id=o.product_id " .
                "WHERE o.transaction_id = ?", $transaction_id);
        $orders = $this->praxis_db->fetchAll($where);
        return $orders;
    }

    public function findOrdersBySerialId($serial_id) {
        $where = $this->praxis_db->quoteInto("SELECT o.*,p.desc,p.length FROM `$this->_current_table` AS o LEFT JOIN  products AS p ON p.id=o.product_id " .
                "WHERE o.serial_id = ?", $serial_id);
        $orders = $this->praxis_db->fetchAll($where);
        return $orders;
    }

    public function findLastOrderDateByUserIdAndSiteId($id_array, $siteId) {
        if ($id_array) {
            $in_str = "(";
            foreach ($id_array as $id) {
                $in_str .= "'" . $id . "',";
            }
            $in_str .= ")";
            $in_str = str_replace(",)", ")", $in_str);
            $in_sql = " AND user_id IN " . $in_str;
        }
        $sql = " SELECT id, user_id, max(`start_date`) as maxdate FROM `orders` WHERE " .
                "(`pay_status` = 'paid' OR pay_status = 'subscribe') AND site_id = $siteId " .
                " AND payment != 0 $in_sql GROUP by user_id";
        $rowset = $this->praxis_db->query($sql)->fetchAll();
        return $rowset;
    }

    /**
     *  find order by order id
     */
    public function findOrderByOrderId($orderId) {
        $where = $this->praxis_db->quoteInto("SELECT * FROM `$this->_current_table` WHERE `id` = ?", $orderId);
        $order = $this->praxis_db->fetchRow($where);

        return $order;
    }

    /**
     * 
     * @param string $recurringPaymentId
     * @author szm
     */
    public function findOrderByRecurringRecurringPaymentId($recurringPaymentId) {
        $where = $this->praxis_db->quoteInto("SELECT * FROM `$this->_current_table` WHERE (`recurring_payment_id` = ? ", $recurringPaymentId);
        $where.=$this->praxis_db->quoteInto(" or pp_profile_id=? )", $recurringPaymentId);
        $where.="and (`pay_status`='subscribe' or `pay_status`='paid' or `pay_status`='extend')";
        $where.=" order by id desc limit 1";
        $order = $this->praxis_db->fetchRow($where);

        return $order;
    }

    public function insert($data) {
        $rt = $this->praxis_db->insert($this->_current_table, $data);
        if (!$rt) {
            $this->praxis_db->rollBack();
            throw new Zend_Exception("Order unsuccessful. Please try again later.");
        }
        $id = $this->praxis_db->lastInsertId();

//    	$this->praxis_db->commit();
        return $id;
    }

    public function fetchOrdersBySerialId($serialId) {
        if (empty($serialId))
            return false;

        $select = $this->praxis_db->select();
        $select->from($this->_current_table . " AS o");
        $select->where("o.serial_id=?", $serialId);

        return $this->praxis_db->fetchAll($select);
    }

    public function updateOrder($data, $orderId) {
        $where = $this->praxis_db->quoteInto("id=?", $orderId);
        $res = $this->praxis_db->update($this->_current_table, $data, $where);
//    	$this->praxis_db->commit();
        return $res;
    }

    public function getRenewOrder($userId, $productList) {
        if (!is_array($productList) || empty($productList)) {
            return false;
        }
        $sql = "SELECT * FROM `orders` WHERE user_id=? AND (pay_status='paid' OR pay_status='subscribe') ";
        $sql.= "ORDER BY created_at DESC LIMIT 1";
        //$fp = @ fopen('/tmp/xianqing.log',"a");
        //@fwrite($fp,$sql);
        //@fclose($fp);    

        $sql = $this->praxis_db->quoteInto($sql, $userId);
        //echo $sql;
        $row = $this->praxis_db->fetchRow($sql);
        if (in_array($row['product_id'], $productList)) {
            // the last purchase was an old product that is upgradable
            return $row;
        } else {
            // the last purchase was a new product
            return false;
        }
    }

    public function findUserRecurringOrder($userId) {
        $sql = "SELECT * FROM orders WHERE user_id=? AND recurring=1 AND finished=0 " .
                "AND pay_status='subscribe' LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userId);

        return $this->praxis_db->fetchRow($sql);
    }

    public function getArbOrderRemainning($recurringOrder) {
        if (!$recurringOrder["recurring"]) {
            return false;
        }
        if ($recurringOrder["product2015_id"] > 0) {
            $productModel = Praxis_Model::factory("Products2015");
            $productInfo = $productModel->getProductInfoById($recurringOrder["product2015_id"]);
            $interval = $productInfo["product_length"] * 30;
        } elseif ($recurringOrder["product_id"] > 0) {
            $productModel = Praxis_Model::factory("Product");
            $productInfo = $productModel->findProductByProductId($recurringOrder["product_id"]);
            $interval = $productInfo["length_in_days"];
        } else {
            
        }
        $endDate = $recurringOrder["end_date"];

        $remainningDate = (int) ((strtotime($endDate) - time()) / (3600 * 24));
        $remainningPrice = sprintf("%.2f", $recurringOrder["billed_amount"] * ($remainningDate / $interval));
        if ($remainningDate <= 0) {
            $remainningDate = 0;
            $remainningPrice = 0;
        }

        return array(
            'days' => $remainningDate,
            'fee' => $remainningPrice
        );
    }

    //szm:the subscription is using now;
    public function getCurrentSubscription($userId) {
        $sql = "SELECT * FROM orders WHERE user_id=? AND finished=0 AND product_type=2 " .
                "AND (pay_status='subscribe' OR pay_status='paid' OR pay_status='recurring_cancel' OR pay_status = 'voucher_code') LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userId);

        return $this->praxis_db->fetchRow($sql);
    }

    // Rich: new method because I need to get the latest end date
    public function getCurrentSubscription2($userId) {
        $sql = "SELECT * FROM orders WHERE user_id = ? AND finished = 0 AND product_type = 2 " .
                "AND (pay_status='subscribe' OR pay_status='paid' OR pay_status='recurring_cancel' OR pay_status = 'voucher_code' OR pay_status = 'extend') " .
                "AND end_date > '" . date("Y-m-d H:i:s") . "' ORDER BY id DESC LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userId);

        return $this->praxis_db->fetchRow($sql);
    }

    public function getLastRecurringCancelSubscriptionByUserId($userId) {
        $sql = "SELECT * FROM orders WHERE user_id=? AND product_type=2 " .
                "AND (pay_status='recurring_cancel') order by end_date desc LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userId);

        return $this->praxis_db->fetchRow($sql);
    }

    /**
     * 
     * @param unknown_type $userId
     * @return array; 
     * @author szm 2011-11-25
     */
    public function getOldCurrentSubscription($userId) {
        $userSiteLinkModel = Praxis_Model::factory("UserSiteLink");
        $userSiteLink = $userSiteLinkModel->getSubscriptionByUserIdAndSiteId($userId, 2);
        $userSiteLink['id'] && $userSiteLinkId = $userSiteLink['id'];
        if (empty($userSiteLinkId)) {
            return false;
        }
        $now = date('Y-m-d 00:00:00', time());
        $sql = "SELECT * FROM orders WHERE user_id=? AND finished=0 AND (product_type is null or product_type='' or product_type=0) AND (end_date> '{$now}')" .
                "AND (pay_status='subscribe' OR pay_status='paid' OR pay_status='recurring_cancel')";
        $sql = $this->praxis_db->quoteInto($sql, $userId);
        $where = " And user_site_link_id=?";
        $where = $this->praxis_db->quoteInto($where, $userSiteLinkId);
        $where.=" order by end_date desc LIMIT 1 ";
        $sql = $sql . $where;
        $rs = $this->praxis_db->fetchRow($sql);
        if (empty($rs)) {
            return false;
        } else {
            return $rs;
        }
    }

    /**
     * 
     * get www1.chinesepod.com subscription
     * @param $userSiteLinkId
     */
    public function getOldCurrentSubscription2($userSiteLinkId) {
        $sql = "SELECT * FROM orders WHERE user_site_link_id=? AND finished=0 " .
                "AND (pay_status='subscribe' OR pay_status='paid' OR pay_status='recurring_cancel' OR pay_status='voucher_code') ORDER BY id DESC LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userSiteLinkId);

        return $this->praxis_db->fetchRow($sql);
    }

    public function getCurrentSubscriptionBalance($currentSubscription) {
        if (empty($currentSubscription)) {
            return false;
        }
        if (empty($currentSubscription["start_date"]) || empty($currentSubscription["end_date"])) {
            throw new Zend_Exception("Invalid Current Subscription data.");
        }
        $now = time();
        $start_date = $currentSubscription["start_date"];
        $end_date = $currentSubscription["end_date"]; //this from order table
        if ($currentSubscription["product2015_id"]) {//new product
            $billed_amount = $currentSubscription["billed_amount"];
        } else {//old product
            $billed_amount = $currentSubscription["billed_amount"]; //this from the order table
        }

        if (strtotime(date('Y-m-d')) < strtotime(date('Y-m-d', strtotime($start_date)))) {
            $currentDate = strtotime($start_date);
            $leftMoney = $billed_amount;
            $remainningFee = sprintf("%.2f", $leftMoney);
        } elseif (strtotime(date('Y-m-d')) > strtotime(date('Y-m-d', strtotime($end_date)))) {
            $leftMoney = 0;
        } else {
            $curentDate = $now;
            $year = (date('Y', strtotime($end_date)) - date('Y', strtotime($start_date))) * 12 * 30;
            $month = (date('m', strtotime($end_date)) - date('m', strtotime($start_date))) * 30;
            $day = date('d', strtotime($end_date)) - date('d', strtotime($start_date));
            $interval = $year + $month + $day;

            $year = (date('Y', strtotime($end_date)) - date('Y', time())) * 12 * 30;
            $month = (date('m', strtotime($end_date)) - date('m', time())) * 30;
            $day = date('d', strtotime($end_date)) - date('d', time());
            $leftDays = $year + $month + $day;
            if ($interval == 0) {
                $leftMoney = $billed_amount;
            } else {
                $leftMoney = ($leftDays / $interval) * $billed_amount;
            }
            $remainningFee = sprintf("%.2f", $leftMoney);
        }
        $remainningDate = ceil((strtotime($end_date) - $curentDate) / (3600 * 24));

        if ($remainningDate <= 0) {
            $remainningDate = 0;
            $remainningFee = 0;
        }

        $data = array(
            'days' => $remainningDate,
            'fee' => $remainningFee
        );
        return $data;
    }

    /**
     * not used
     * @param unknown_type $order
     */
    /*    private function isArb($order){
      if($order['product2015_id']){
      $model_Products2015 = Praxis_Model::factory('Products2015');
      $product2015Info = $model_Products2015->getProductInfoById($order['product2015_id']);
      return !empty($product2015Info['recurring']);
      }else {
      $model_Product = Praxis_Model::factory('Product');
      $productInfo = $model_Product->findProductByProductId($order['product_id']);
      return !empty($productInfo['is_arb']);
      }
      } */
    public function getCurrentSpeakingClass($userId) {
        $sql = "SELECT * FROM orders WHERE user_id=? AND finished=0 AND product_type=3 " .
                "AND (pay_status='subscribe' OR pay_status='paid' OR pay_status='recurring_cancel' OR pay_status='voucher_code') ORDER BY id DESC LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userId);
        $row = $this->praxis_db->fetchRow($sql);
        if ($row) {
            $productModel = Praxis_Model::factory("Products2015");
            $productInfo = $productModel->getProductInfoById($row['product2015_id']);


            if (isset($productInfo['speakingclass_type'])) {
                $row['speakingclass_type'] = $productInfo['speakingclass_type'];
                return $row;
            } else {
                return false; // shouldn't ever get here
            }
        } else {
            return false;
        }
    }

    // Rich: Another one, this one gets the newest order, even if extended
    public function getCurrentSpeakingClass2($userId) {
        $sql = "SELECT * FROM orders WHERE user_id = ? AND finished = 0 AND product_type = 3 " .
                "AND (pay_status='subscribe' OR pay_status='paid' OR pay_status='recurring_cancel' OR pay_status='voucher_code' OR pay_status = 'extend') " .
                "AND end_date > '" . date("Y-m-d H:i:s") . "' ORDER BY id DESC LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userId);
        $row = $this->praxis_db->fetchRow($sql);
        if ($row) {
            $productModel = Praxis_Model::factory("Products2015");
            $productInfo = $productModel->getProductInfoById($row['product2015_id']);

            if (isset($productInfo['speakingclass_type'])) {
                $row['speakingclass_type'] = $productInfo['speakingclass_type'];
                return $row;
            } else {
                return false; // shouldn't ever get here
            }
        } else {
            return false;
        }
    }

    public function getCurrentSpeakingClassBalance($currentSpeakingClass) {
        if (empty($currentSpeakingClass["start_date"]) || empty($currentSpeakingClass["end_date"])) {
            throw new Zend_Exception("Invalid Current Subscription data.");
        }
        $now = time();
        $begin = $currentSpeakingClass["start_date"];
        $end = $currentSpeakingClass["end_date"];

        $remainningDate = ceil((strtotime($end) - $now) / (3600 * 24));
        $interval = (int) ((strtotime($end) - strtotime($begin)) / (3600 * 24));

        $remainningFee = sprintf("%.2f", $currentSpeakingClass["billed_amount"] * ($remainningDate / $interval));

        if ($remainningDate <= 0) {
            $remainningDate = 0;
            $remainningFee = 0;
        }

        return array(
            'days' => $remainningDate,
            'fee' => $remainningFee
        );
    }

    public function getCurrentSmartClass2($userId) {
        $sql = "SELECT * FROM orders WHERE user_id=? AND finished=0 AND product_type=5 " .
                "AND (pay_status='subscribe' OR pay_status='paid' OR pay_status='recurring_cancel' OR pay_status='voucher_code' OR pay_status='extend') " .
                "AND end_date > '" . date("Y-m-d H:i:s") . "' ORDER BY id DESC LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userId);
        $row = $this->praxis_db->fetchRow($sql);
        if ($row) {
            $productModel = Praxis_Model::factory("Products2015");
            $productInfo = $productModel->getProductInfoById($row['product2015_id']);


            if (isset($productInfo['subscription_type'])) {
                $row['subscription_type'] = $productInfo['subscription_type'];
            }
            if (isset($productInfo['speakingclass_type'])) {
                $row['speakingclass_type'] = $productInfo['speakingclass_type'];
            }
            if (isset($productInfo['course_id'])) {
                $row['course_id'] = $productInfo['course_id'];
            }

            return $row;
        } else {
            return false;
        }
    }

    public function getExtendSubscription($userId) {
        $sql = "SELECT * FROM orders WHERE user_id=? AND finished=0 AND product_type=2 " .
                "AND pay_status='extend'";
        $sql = $this->praxis_db->quoteInto($sql, $userId);

        return $this->praxis_db->fetchAll($sql);
    }

    public function getExtendSpeakingClass($userId) {
        $sql = "SELECT * FROM orders WHERE user_id=? AND finished=0 AND product_type=3 " .
                "AND pay_status='extend'";
        $sql = $this->praxis_db->quoteInto($sql, $userId);

        return $this->praxis_db->fetchAll($sql);
    }

    public function updateSerialAmount($serial_id, $amount) {
        $data = array(
            'serial_total' => $amount,
        );
        $where = $this->praxis_db->quoteInto("serial_id=?", $serial_id);
        $res = $this->praxis_db->update($this->_current_table, $data, $where);
//    	$this->praxis_db->commit();
        return $res;
    }

    public function getLastExtendSubscription($userId) {
        $sql = "SELECT * FROM orders WHERE user_id=? AND finished=0 AND product_type=2 " .
                "AND (pay_status='extend' or pay_status='recurring_cancel') ORDER BY end_date DESC LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userId);

        return $this->praxis_db->fetchRow($sql);
    }

    public function getLastExtendSpeakingClass($userId) {
        $sql = "SELECT * FROM orders WHERE user_id=? AND finished=0 AND product_type=3 " .
                "AND pay_status='extend' ORDER BY end_date DESC LIMIT 1";
        $sql = $this->praxis_db->quoteInto($sql, $userId);

        return $this->praxis_db->fetchRow($sql);
    }

    public function arb_paypal_complate($orderId) {
        $order = $this->findOrderByOrderId($orderId);
        if ($order['product2015_id']) {
            $products2015Model = Praxis_Model::factory("Products2015");
            $productInfo = $products2015Model->getProductInfoById($order['product2015_id']);
        } elseif ($order['product_id']) {
            $productModel = Praxis_Model::factory('Product');
            $productInfo = $productModel->findProductByProductId($order['product_id']);
            if (in_array($order['product_id'], array(217, 362))) {
                $productInfo['subscription_type'] = 1;
            } else {
                $productInfo['subscription_type'] = 2;
            }
        }
        $upe_data = array(
            'user_id' => $order['user_id'],
            'purchase_type' => 2, // 2 is subscription class type
            'channel_id' => $productInfo['channel_id'] ? $productInfo['channel_id'] : 0,
            'level_id' => $productInfo['level_id'] ? $productInfo['level_id'] : 0,
            'expire_date' => $order['end_date'],
            'date_created' => date("Y-m-d H:i:s"),
            'created_by' => $order['user_id'],
            'last_modified' => date("Y-m-d H:i:s"),
            'modified_by' => $order['user_id'],
            'access_type' => $productInfo['subscription_type'] ? $productInfo['subscription_type'] : 0,
        );
        $userPurchaseExpiriesModel = Praxis_Model::factory("UserPurchaseExpiries");

        $userPurchaseExpiriesModel->addUserPurchaseExpiries($upe_data);
        if ($upe_data['purchase_type'] == '2' && $upe_data['access_type'] == '3') {
            $clientSites = array(2, 3, 4, 5, 6);
            foreach ($clientSites as $site) {
                $modelUserSiteLink = Praxis_Model::factory('UserSiteLink');
                $mySites = $modelUserSiteLink->findDataByUserIdAndSiteId($order['user_id'], $site);
                if ($mySites['id']) {
                    $updateData = array(
                        'usertype_id' => 3,
                        'expiry' => $order['end_date'],
                        'active' => 1,
                        'is_public' => 1,
                        'allow_comment' => 1,
                        'updated_by' => $order['user_id'],
                        'updated_at' => date('Y-m-d H:i:s'),
                    );
                    $modelUserSiteLink->updateDataById($updateData, $mySites['id']);
                } else {
                    $newData = array(
                        'usertype_id' => 3,
                        'expiry' => $order['end_date'],
                        'user_id' => $order['user_id'],
                        'site_id' => $site,
                        'active' => 1,
                        'deactive' => 0,
                        'allow_comment' => 1,
                        'updated_by' => $order['user_id'],
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    );
                    $modelUserSiteLink->insertData($newData);
                }
            }
        }
    }

    private function checkExpireDate($userId, $currentDate) {
        $userPurchaseExpiriesModel = Praxis_Model::factory("UserPurchaseExpiries");
        $userSubscription = $userPurchaseExpiriesModel->getUserMaxExpireDate($userId);
        if (strtotime($userSubscription['expire_date']) >= strtotime($currentDate)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * @author szm 2011-11-01
     */
    public function arb_complete($order_id, $data) {
        if (@!$data['transaction_id']) {
            $data['transaction_id'] = 'manual';
        }

        $data['pay_status'] = 'paid';
        //$data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $rs = $this->updateOrder($data, $order_id);
        $userPurchaseExpiriesModel = Praxis_Model::factory("UserPurchaseExpiries");
        $order = $this->findOrderByOrderId($order_id);
        $products2015Model = Praxis_Model::factory("Products2015");
        $products2015 = $products2015Model->getProductInfoById($order['product2015_id']);
        if (empty($products2015)) {
            return false;
        }
        try {
            $isMaxTime = $this->checkExpireDate($order['user_id'], $order['end_date']);
            if (!$isMaxTime) {
                return false;
            }
        } catch (Excepiton $e) {
            
        }

        $upe_data = array(
            'user_id' => $order['user_id'],
            'purchase_type' => 2, // 2 is subscription class type
            'channel_id' => $products2015['channel_id'],
            'level_id' => $products2015['level_id'] ? $products2015['level_id'] : 0,
            'expire_date' => $order['end_date'],
            'date_created' => date("Y-m-d H:i:s"),
            'created_by' => $order['user_id'],
            'last_modified' => date("Y-m-d H:i:s"),
            'modified_by' => $order['user_id'],
            'access_type' => $products2015['subscription_type']
        );

        $userPurchaseExpiriesModel->addUserPurchaseExpiries($upe_data);

        if ($upe_data['purchase_type'] == '2' && $upe_data['access_type'] == '3') {
            $clientSites = array(2, 3, 4, 5, 6);
            foreach ($clientSites as $site) {
                $modelUserSiteLink = Praxis_Model::factory('UserSiteLink');
                $mySites = $modelUserSiteLink->findDataByUserIdAndSiteId($order['user_id'], $site);
                if ($mySites['id']) {
                    $updateData = array(
                        'usertype_id' => 3,
                        'expiry' => $order['end_date'],
                        'active' => 1,
                        'is_public' => 1,
                        'allow_comment' => 1,
                        'updated_by' => $order['user_id'],
                        'updated_at' => date('Y-m-d H:i:s'),
                    );
                    $modelUserSiteLink->updateDataById($updateData, $mySites['id']);
                } else {
                    $newData = array(
                        'usertype_id' => 3,
                        'expiry' => $order['end_date'],
                        'user_id' => $order['user_id'],
                        'site_id' => $site,
                        'active' => 1,
                        'deactive' => 0,
                        'allow_comment' => 1,
                        'updated_by' => $order['user_id'],
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    );
                    $modelUserSiteLink->insertData($newData);
                }
            }
        }
        if ($order->payment != 0) {
            //Praxis_Mailer::newEmailTemplates('payment_success', $account, array('order' => $order, 'product' => $product));
        }
        return $upe_data;
    }

    // check arb by hongdaqi 2011-11-10

    public function checkUserArb($userId, $product_type = NULL) {

        $where .= $this->praxis_db->quoteInto(" recurring = ?", 1);
        $where .= $this->praxis_db->quoteInto(" AND user_id = ?", $userId);
        $where .= $this->praxis_db->quoteInto(" AND product2015_id > ?", 0);
        $where .= " AND (pay_status='subscribe' OR pay_status='paid')";

        if ($product_type) {
            $where .= $this->praxis_db->quoteInto(" AND product_type = ?", $product_type);
        }
        $today = date("Y-m-d 00:00:00");
        $where .= " AND end_date > '$today'";
        $sql = "SELECT * FROM orders WHERE " . $where;
        $row = $this->praxis_db->fetchRow($sql);
        if ($row) {
            return TRUE;
        } else {
            return False;
        }
        //return (count($row)==1) ? TRUE: FALSE;
    }

    /**
     * calculate the recurringCancelSubscription money
     * @param unknown_type $userId
     * @return float
     */
    public function getRecurringCancelSubscription($userId) {
        $now = date('Y-m-d', time());
        $sql = "SELECT * FROM orders WHERE user_id=?  AND product_type=2 " .
                " AND start_date >'$now' AND pay_status='recurring_cancel'";

        $sql = $this->praxis_db->quoteInto($sql, $userId);
        $orders = $this->praxis_db->fetchAll($sql);
        $leftMoney = 0;

        foreach ($orders as $k => $v) {
            $data = $this->getCurrentSubscriptionBalance($v);
            $leftMoney+=$data['fee'];
        }

        return $leftMoney;
    }

    /**
     * calculate the recurringCancelSubscription money
     * @param unknown_type $userId
     * @return float
     */
    public function getRecurringCancelSubscriptionOrders($userId) {
        $now = date('Y-m-d', time());
        $sql = "SELECT * FROM orders WHERE user_id=?  AND product_type=2 " .
                " AND pay_status='recurring_cancel'";

        $sql = $this->praxis_db->quoteInto($sql, $userId);
        $orders = $this->praxis_db->fetchAll($sql);

        return $orders;
    }

    /**
     * check against if the user have used the promotion or not.
     * @param unknown_type $userId
     * @return boolean
     */
    public function checkPromotionCodeUsed($userId, $code) {
        $sql = "SELECT * FROM orders WHERE user_id=?  AND promo_code = '{$code}' " .
                " AND pay_status in ('recurring_cancel', 'paid', 'cancel', 'extend', 'recurring_cancel', 'refund', 'subscribe')";
        $sql = $this->praxis_db->quoteInto($sql, $userId);
        $orders = $this->praxis_db->fetchAll($sql);
        if ($orders) {
            return TRUE;
        }
        return FALSE;
    }
    /**
     * @api WS
     *  This function is used by API
     * @param type $subscriptionId
     * @return type
     */
    public function getOrderBysubscriptionId($subscriptionId) {
        $sql = "SELECT * FROM orders WHERE subscription_id=:0 LIMIT 1";
        $row = $this->q_db($this->dbh,$sql, $subscriptionId);

        return $row[0];
    }
    /**
     * @api WS
     * @param type $subscriptionId
     * @return type
     */
    public function getOrderByPaypalProfileId($subscriptionId) {
        $sql = "SELECT * FROM orders WHERE recurring_payment_id= ':0' OR pp_profile_id= ':0' LIMIT 1";
        $row = $this->q_db($this->dbh,$sql, $subscriptionId);
        return $row[0];
    }

}
