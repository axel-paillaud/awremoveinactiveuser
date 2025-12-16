<?php
/**
 * 2007-2025 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    Axelweb <contact@axelweb.fr>
 * @copyright 2007-2025 Axelweb
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of Axelweb
 */

namespace Axelweb\AwRemoveInactiveUser\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use DbQuery;

class InactiveUserRepository
{
    /**
     * Fetch a batch of inactive users
     *
     * @param int $inactiveDays Number of days of inactivity
     * @param int|null $shopId Shop ID (null for all shops)
     * @param int $limit Number of results
     * @param int $offset Offset for pagination
     *
     * @return array
     */
    public function fetchInactiveUsersBatch(int $inactiveDays, ?int $shopId, int $limit, int $offset): array
    {
        $dateLimit = date('Y-m-d H:i:s', strtotime("-{$inactiveDays} days"));

        $query = new DbQuery();
        $query->select('c.id_customer, c.email, c.firstname, c.lastname, c.date_add');
        $query->from('customer', 'c');
        $query->where('c.date_add < "' . pSQL($dateLimit) . '"');
        $query->where('c.deleted = 0');
        $query->where('c.is_guest = 0');

        // Exclude customers with recent connections
        $query->where('NOT EXISTS (
            SELECT 1 
            FROM ' . _DB_PREFIX_ . 'connections con 
            WHERE con.id_customer = c.id_customer 
            AND con.date_add >= "' . pSQL($dateLimit) . '"
        )');

        // Exclude customers with recent orders
        $query->where('NOT EXISTS (
            SELECT 1 
            FROM ' . _DB_PREFIX_ . 'orders o 
            WHERE o.id_customer = c.id_customer 
            AND o.date_add >= "' . pSQL($dateLimit) . '"
        )');

        if ($shopId) {
            $query->innerJoin('customer_shop', 'cs', 'c.id_customer = cs.id_customer');
            $query->where('cs.id_shop = ' . (int) $shopId);
        }

        $query->orderBy('c.id_customer ASC');
        $query->limit($limit, $offset);

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query) ?: [];
    }

    /**
     * Count inactive users
     *
     * @param int $inactiveDays Number of days of inactivity
     * @param int|null $shopId Shop ID (null for all shops)
     *
     * @return int
     */
    public function countInactiveUsers(int $inactiveDays, ?int $shopId = null): int
    {
        $dateLimit = date('Y-m-d H:i:s', strtotime("-{$inactiveDays} days"));

        $query = new DbQuery();
        $query->select('COUNT(DISTINCT c.id_customer)');
        $query->from('customer', 'c');
        $query->where('c.date_add < "' . pSQL($dateLimit) . '"');
        $query->where('c.deleted = 0');
        $query->where('c.is_guest = 0');

        // Exclude customers with recent connections
        $query->where('NOT EXISTS (
            SELECT 1 
            FROM ' . _DB_PREFIX_ . 'connections con 
            WHERE con.id_customer = c.id_customer 
            AND con.date_add >= "' . pSQL($dateLimit) . '"
        )');

        // Exclude customers with recent orders
        $query->where('NOT EXISTS (
            SELECT 1 
            FROM ' . _DB_PREFIX_ . 'orders o 
            WHERE o.id_customer = c.id_customer 
            AND o.date_add >= "' . pSQL($dateLimit) . '"
        )');

        if ($shopId) {
            $query->innerJoin('customer_shop', 'cs', 'c.id_customer = cs.id_customer');
            $query->where('cs.id_shop = ' . (int) $shopId);
        }

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query);
    }

    /**
     * Check if a customer has orders
     *
     * @param int $customerId Customer ID
     *
     * @return bool
     */
    public function hasOrders(int $customerId): bool
    {
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('orders');
        $query->where('id_customer = ' . (int) $customerId);

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query) > 0;
    }
}
