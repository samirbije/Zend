<?php
namespace Praxis;
class Product extends \Root
{
    private $_current_table = "products";
    
    /*public function __construct()
    {
        $this->praxis_db = Zend_Registry::get('praxis_db');    
    }*/
    
    public function getTableName()
    {
        return $this->_current_table;
    }

    public function setTableName($tableName)
    {
        $this->_current_table = $tableName;
    }
    
    //start
    public function getProductById($productId)
    {
        if(is_array($productId)){
            $sql = $this->praxis_db->quoteInto("SELECT * FROM `$this->_current_table` WHERE `id` in (?)", $productId);
            //echo $sql;exit;
            $row = $this->praxis_db->fetchAll($sql);
        }else{
            $sql = $this->praxis_db->quoteInto("SELECT * FROM `$this->_current_table` WHERE `id` = ?", $productId);
            $row = $this->praxis_db->fetchRow($sql);
        }
        
        return $row;
    }
    //end
    
    /**
     * This function is used by authorize API
     *  find product by product id
     */
    public function findProductByProductId($productId)
    {
        $sql = "SELECT * FROM `$this->_current_table` WHERE `id` = :0";
        $row = $this->q_db($this->dbh,$sql,$productId);
        return $row[0];
    }
    
    public function findProductByProductName($productId)
    {
        $sql = $this->praxis_db->quoteInto("SELECT * FROM `$this->_current_table` WHERE `id` = ?", $productId);
        $row = $this->praxis_db->fetchRow($sql);
        
        return $row;
    }
	
    /**
     *  find product by product id
     */
    public function findProductByCondition($userTypeId, $siteId = 2, $versionId = 0)
    {
        $sql = $this->praxis_db->quoteInto("SELECT * FROM `$this->_current_table` WHERE `usertype_id` = ?", $userTypeId);
        $sql .= $this->praxis_db->quoteInto(" AND site_id = $siteId AND version_id = ?", $versionId);
        $row = $this->praxis_db->fetchRow($sql);
        
        return $row;
    }
    
    /**
     *  find product by usertype id and price 
     */
    public function findProductByPrice($userTypeId, $price = 0, $siteId = 2, $versionId = 0)
    {
        $sql = $this->praxis_db->quoteInto("SELECT * FROM `$this->_current_table` WHERE `usertype_id` = ?", $userTypeId);
        $sql .= $this->praxis_db->quoteInto(" AND site_id = $siteId AND price = $price AND version_id = ?", $versionId);
        $row = $this->praxis_db->fetchRow($sql);
        
        return $row;
    }
    
    public function findProductsByCondition($conditions = array(), $order = 'id DESC')
    {
        $sql = "SELECT * FROM `$this->_current_table` WHERE 1=1 ";
        if ($conditions['usertype_id']) {
        	$sql .= $this->praxis_db->quoteInto(" AND `usertype_id` = ?", $conditions['usertype_id']);
        }
        
        if (isset($conditions['site_id'])) {
        	$sql .= $this->praxis_db->quoteInto(" AND `site_id` = ?", $conditions['site_id']);
        }
        
        if (isset($conditions['length_in_days'])) {
        	$sql .= $this->praxis_db->quoteInto(" AND `length_in_days` = ?", $conditions['length_in_days']);
        }
        
        if (isset($conditions['version_id'])) {
        	$sql .= $this->praxis_db->quoteInto(" AND `version_id` = ?", $conditions['version_id']);
        }
        
    	$sql .= " ORDER BY $order";

        $rows = $this->praxis_db->fetchAll($sql);
        
        return $rows;
    }
    
    public function fetchRenewProductList()
    {
    	$sql = "SELECT * FROM products WHERE `desc` LIKE \"Basic%\" OR `desc` LIKE \"Premium%\" OR `desc` LIKE \"Praxis%\" OR `desc` LIKE \"Guided%\" OR `desc` LIKE \"Executive%\" OR `desc` LIKE \"Exective%\" and `is_arb` = 0";
    	
    	return $this->praxis_db->fetchCol($sql);
    }
    
    public function getSubscriptionType($productDesc)
    {
    	if(substr($productDesc, 0, 5)=="Basic")
    	{
    		return 1;
    	}
    	elseif(substr($productDesc, 0, 7)=="Premium")
    	{
    		return 2;
    	}
    	elseif(substr($productDesc, 0, 6)=="Praxis")
    	{
    		return 3;
    	}
    	
    	return false;
    }
    
    public function getSpeakingClassType($productDesc)
    {
    	
    	return null;
    }
}
