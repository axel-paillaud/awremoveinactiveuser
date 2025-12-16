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

namespace Axelweb\AwRemoveInactiveUser\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axelweb\AwRemoveInactiveUser\Repository\InactiveUserRepository;
use Customer;

class InactiveUserService
{
    private InactiveUserRepository $repository;

    public function __construct(InactiveUserRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Get emails of inactive users
     *
     * @param int $inactiveDays Number of days of inactivity
     * @param int|null $shopId Shop ID (null for all shops)
     * @param int $batchSize Batch size to optimize memory usage
     *
     * @return array List of emails
     */
    public function getInactiveUsersEmails(int $inactiveDays, ?int $shopId = null, int $batchSize = 1000): array
    {
        $emails = [];
        $offset = 0;

        do {
            $batch = $this->repository->fetchInactiveUsersBatch($inactiveDays, $shopId, $batchSize, $offset);

            foreach ($batch as $row) {
                $emails[] = $row['email'];
            }

            $offset += $batchSize;
        } while (count($batch) === $batchSize);

        return $emails;
    }

    /**
     * Delete inactive users
     *
     * @param int $inactiveDays Number of days of inactivity
     * @param int|null $shopId Shop ID (null for all shops)
     * @param int $batchSize Batch size to optimize memory usage
     * @param bool $dryRun If true, only simulate deletion without actually deleting
     * @param callable|null $progressCallback Callback to report progress (receives: current, total, customerEmail)
     *
     * @return array ['deleted' => int, 'errors' => array, 'skipped' => int]
     */
    public function deleteInactiveUsers(
        int $inactiveDays,
        ?int $shopId = null,
        int $batchSize = 100,
        bool $dryRun = false,
        ?callable $progressCallback = null
    ): array {
        $deleted = 0;
        $skipped = 0;
        $errors = [];
        $offset = 0;
        $processed = 0;
        $totalCount = $this->repository->countInactiveUsers($inactiveDays, $shopId);

        do {
            $batch = $this->repository->fetchInactiveUsersBatch($inactiveDays, $shopId, $batchSize, $offset);

            foreach ($batch as $row) {
                $processed++;

                try {
                    $customer = new Customer((int) $row['id_customer']);

                    if (!$customer->id) {
                        continue;
                    }

                    // Report progress
                    if ($progressCallback) {
                        $progressCallback($processed, $totalCount, $customer->email);
                    }

                    // Check if customer has orders
                    if ($this->repository->hasOrders((int) $customer->id)) {
                        $errors[] = sprintf(
                            'Customer #%d (%s) has orders and cannot be deleted',
                            $customer->id,
                            $customer->email
                        );
                        $skipped++;
                        continue;
                    }

                    if ($dryRun) {
                        $deleted++;
                    } else {
                        if ($customer->delete()) {
                            $deleted++;
                        } else {
                            $errors[] = sprintf(
                                'Error deleting customer #%d (%s)',
                                $customer->id,
                                $customer->email
                            );
                        }
                    }
                } catch (\Exception $e) {
                    $errors[] = sprintf(
                        'Exception for customer #%d: %s',
                        $row['id_customer'],
                        $e->getMessage()
                    );
                }
            }

            // In dry-run mode, increment offset to continue through all users
            // In real mode, no need to increment because we're deleting rows
            if ($dryRun) {
                $offset += $batchSize;
            }
        } while (count($batch) === $batchSize);

        return [
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
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
        return $this->repository->countInactiveUsers($inactiveDays, $shopId);
    }
}
