<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magento.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magento.com for more information.
 *
 * @category    Mage
 * @package     Mage_Sales
 * @copyright  Copyright (c) 2006-2015 X.commerce, Inc. (http://www.magento.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


/**
 * Quote resource model
 *
 * @category    Mage
 * @package     Mage_Sales
 * @author      Magento Core Team <core@magentocommerce.com>
 */
class Mage_Sales_Model_Resource_Quote extends Mage_Sales_Model_Resource_Abstract
{
    /**
     * Initialize table nad PK name
     *
     */
    protected function _construct()
    {
        $this->_init('sales/quote', 'entity_id');
    }

    /**
     * Retrieve select object for load object data
     *
     * @param string $field
     * @param mixed $value
     * @param Mage_Core_Model_Abstract $object
     * @return Varien_Db_Select
     */
    protected function _getLoadSelect($field, $value, $object)
    {
        $select   = parent::_getLoadSelect($field, $value, $object);
        $storeIds = $object->getSharedStoreIds();
        if ($storeIds) {
            $select->where('store_id IN (?)', $storeIds);
        } else {
            /**
             * For empty result
             */
            $select->where('store_id < ?', 0);
        }

        return $select;
    }

    /**
     * Load quote data by customer identifier
     *
     * @param Mage_Sales_Model_Quote $quote
     * @param int $customerId
     * @return Mage_Sales_Model_Resource_Quote
     */
    public function loadByCustomerId($quote, $customerId)
    {
        $adapter = $this->_getReadAdapter();
        $select  = $this->_getLoadSelect('customer_id', $customerId, $quote)
            ->where('is_active = ?', 1)
            ->order('updated_at ' . Varien_Db_Select::SQL_DESC)
            ->limit(1);

        $data    = $adapter->fetchRow($select);

        if ($data) {
            $quote->setData($data);
        }

        $this->_afterLoad($quote);

        return $this;
    }

    /**
     * Load only active quote
     *
     * @param Mage_Sales_Model_Quote $quote
     * @param int $quoteId
     * @return Mage_Sales_Model_Resource_Quote
     */
    public function loadActive($quote, $quoteId)
    {
        $adapter = $this->_getReadAdapter();
        $select  = $this->_getLoadSelect('entity_id', $quoteId, $quote)
            ->where('is_active = ?', 1);

        $data    = $adapter->fetchRow($select);
        if ($data) {
            $quote->setData($data);
        }

        $this->_afterLoad($quote);

        return $this;
    }

    /**
     * Load quote data by identifier without store
     *
     * @param Mage_Sales_Model_Quote $quote
     * @param int $quoteId
     * @return Mage_Sales_Model_Resource_Quote
     */
    public function loadByIdWithoutStore($quote, $quoteId)
    {
        $read = $this->_getReadAdapter();
        if ($read) {
            $select = parent::_getLoadSelect('entity_id', $quoteId, $quote);

            $data = $read->fetchRow($select);

            if ($data) {
                $quote->setData($data);
            }
        }

        $this->_afterLoad($quote);
        return $this;
    }

    /**
     * Get reserved order id
     *
     * @param Mage_Sales_Model_Quote $quote
     * @return string
     */
    public function getReservedOrderId($quote)
    {
        $storeId = (int)$quote->getStoreId();
        return Mage::getSingleton('eav/config')->getEntityType(Mage_Sales_Model_Order::ENTITY)
            ->fetchNewIncrementId($storeId);
    }

    /**
     * Check is order increment id use in sales/order table
     *
     * @param int $orderIncrementId
     * @return boolean
     */
    public function isOrderIncrementIdUsed($orderIncrementId)
    {
        $adapter   = $this->_getReadAdapter();
        $bind      = array(':increment_id' => (int)$orderIncrementId);
        $select    = $adapter->select();
        $select->from($this->getTable('sales/order'), 'entity_id')
            ->where('increment_id = :increment_id');
        $entity_id = $adapter->fetchOne($select, $bind);
        if ($entity_id > 0) {
            return true;
        }

        return false;
    }

    /**
     * Mark quotes - that depend on catalog price rules - to be recollected on demand
     *
     * @return Mage_Sales_Model_Resource_Quote
     */
    public function markQuotesRecollectOnCatalogRules()
    {
        $quoteItemTable = $this->getTable('sales/quote_item');
        $productPriceTable = $this->getTable('catalogrule/rule_product_price');

        $select = $this->_getReadAdapter()
          ->select()
          ->distinct()
          ->from(array('t2' => $quoteItemTable), array('entity_id' => 'quote_id'))
          ->join(array('t3' => $productPriceTable), 't2.product_id = t3.product_id', array());

        $entityIds = $this->_getReadAdapter()->fetchCol($select);

        if (count($entityIds) > 0) {
            $where = $this->_getWriteAdapter()->quoteInto('entity_id IN (?)', $entityIds);
            $this->_getWriteAdapter()->update($this->getTable('sales/quote'), array('trigger_recollect' => 1), $where);
        }

        return $this;
    }

    /**
     * Subtract product from all quotes quantities
     *
     * @param Mage_Catalog_Model_Product $product
     * @return Mage_Sales_Model_Resource_Quote
     */
    public function substractProductFromQuotes($product)
    {
        $productId = (int)$product->getId();
        if (!$productId) {
            return $this;
        }
        $adapter   = $this->_getWriteAdapter();
        $subSelect = $adapter->select();

        $subSelect->from(false, array(
            'items_qty'   => new Zend_Db_Expr(
                $adapter->quoteIdentifier('q.items_qty') . ' - ' . $adapter->quoteIdentifier('qi.qty')),
            'items_count' => new Zend_Db_Expr($adapter->quoteIdentifier('q.items_count') . ' - 1')
        ))
        ->join(
            array('qi' => $this->getTable('sales/quote_item')),
            implode(' AND ', array(
                'q.entity_id = qi.quote_id',
                'qi.parent_item_id IS NULL',
                $adapter->quoteInto('qi.product_id = ?', $productId)
            )),
            array()
        );

        $updateQuery = $adapter->updateFromSelect($subSelect, array('q' => $this->getTable('sales/quote')));

        $adapter->query($updateQuery);

        return $this;
    }

    /**
     * Mark recollect contain product(s) quotes
     *
     * @param array|int|Zend_Db_Expr $productIds
     * @return Mage_Sales_Model_Resource_Quote
     */
    public function markQuotesRecollect($productIds)
    {
        $tableQuote = $this->getTable('sales/quote');
        $tableItem = $this->getTable('sales/quote_item');
        $subSelect = $this->_getReadAdapter()
            ->select()
            ->from($tableItem, array('entity_id' => 'quote_id'))
            ->where('product_id IN ( ? )', $productIds)
            ->group('quote_id');

        $select = $this->_getReadAdapter()->select()->join(
            array('t2' => $subSelect),
            't1.entity_id = t2.entity_id',
            array('trigger_recollect' => new Zend_Db_Expr('1'))
        );
        $updateQuery = $select->crossUpdateFromSelect(array('t1' => $tableQuote));
        $this->_getWriteAdapter()->query($updateQuery);

        return $this;
    }
}

