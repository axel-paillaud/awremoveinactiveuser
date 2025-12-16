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

namespace Axelweb\AwRemoveInactiveUser\Command;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Axelweb\AwRemoveInactiveUser\Service\InactiveUserService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RemoveInactiveUsersCommand extends Command
{
    protected static $defaultName = 'awremoveinactiveuser:remove';

    private InactiveUserService $service;

    public function __construct(InactiveUserService $service)
    {
        $this->service = $service;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Remove inactive customers (GDPR compliance).')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Number of days of inactivity', 365)
            ->addOption('shop', 's', InputOption::VALUE_REQUIRED, 'Shop ID (default: all shops)')
            ->addOption('batch', 'b', InputOption::VALUE_REQUIRED, 'Batch size for memory optimization', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate deletion without actually deleting')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->setHelp(
                'This command deletes customers who have been inactive for X days (GDPR compliance).' . PHP_EOL .
                'Customers with orders will be skipped.' . PHP_EOL .
                PHP_EOL .
                'Examples:' . PHP_EOL .
                '  php bin/console awremoveinactiveuser:remove --days=365 --dry-run' . PHP_EOL .
                '  php bin/console awremoveinactiveuser:remove --days=730 --shop=1 --force' . PHP_EOL .
                '  php bin/console awremoveinactiveuser:remove --days=1095'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $days = (int) $input->getOption('days');
            $shopId = $input->getOption('shop') ? (int) $input->getOption('shop') : null;
            $batchSize = (int) $input->getOption('batch');
            $dryRun = (bool) $input->getOption('dry-run');
            $force = (bool) $input->getOption('force');

            // Validate days
            if ($days < 1) {
                $output->writeln('<error>Days must be greater than 0.</error>');

                return $this->exitFailure();
            }

            // Safety check: minimum 180 days
            if ($days < 180 && !$dryRun) {
                $output->writeln('<error>For safety reasons, minimum inactivity period is 180 days.</error>');
                $output->writeln('<comment>Use --dry-run to test with fewer days.</comment>');

                return $this->exitFailure();
            }

            $output->writeln(sprintf('<info>Checking inactive users for %d days...</info>', $days));

            // Count first
            $count = $this->service->countInactiveUsers($days, $shopId);

            if ($count === 0) {
                $output->writeln('<comment>No inactive users found.</comment>');

                return $this->exitSuccess();
            }

            $output->writeln(sprintf('<comment>Found %d inactive users to delete.</comment>', $count));

            if ($dryRun) {
                $output->writeln('<info>DRY RUN MODE: No users will be actually deleted.</info>');
            }

            // Confirmation
            if (!$force && !$dryRun) {
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion(
                    sprintf(
                        '<question>Are you sure you want to delete %d inactive users? (yes/no) [no]: </question>',
                        $count
                    ),
                    false
                );

                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln('<comment>Operation cancelled.</comment>');

                    return $this->exitSuccess();
                }
            }

            // Delete users with progress reporting
            $output->writeln('<info>Processing deletion...</info>');
            
            $lastPercent = -1;
            $startTime = time();
            
            $progressCallback = function ($current, $total, $email) use ($output, &$lastPercent, $startTime) {
                $percent = floor(($current / $total) * 100);
                
                // Display progress every 5% or every 100 users
                if ($percent !== $lastPercent && ($percent % 5 === 0 || $current % 100 === 0)) {
                    $elapsed = time() - $startTime;
                    $estimatedTotal = ($current > 0) ? ($elapsed / $current) * $total : 0;
                    $remaining = max(0, $estimatedTotal - $elapsed);
                    
                    $output->writeln(sprintf(
                        '<comment>[%d%%] %d/%d processed | Elapsed: %s | Remaining: ~%s | Current: %s</comment>',
                        $percent,
                        $current,
                        $total,
                        $this->formatTime($elapsed),
                        $this->formatTime($remaining),
                        substr($email, 0, 30)
                    ));
                    
                    $lastPercent = $percent;
                }
            };
            
            $result = $this->service->deleteInactiveUsers($days, $shopId, $batchSize, $dryRun, $progressCallback);

            // Display results
            if ($dryRun) {
                $output->writeln(sprintf('<info>Would delete: %d users</info>', $result['deleted']));
            } else {
                $output->writeln(sprintf('<info>Deleted: %d users</info>', $result['deleted']));
            }

            if ($result['skipped'] > 0) {
                $output->writeln(sprintf('<comment>Skipped: %d users (have orders)</comment>', $result['skipped']));
            }

            if (!empty($result['errors'])) {
                $output->writeln(sprintf('<error>Errors: %d</error>', count($result['errors'])));
                foreach ($result['errors'] as $error) {
                    $output->writeln('<error>  - ' . $error . '</error>');
                }
            }

            $output->writeln('<info>Operation completed successfully.</info>');

            return $this->exitSuccess();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return $this->exitFailure();
        }
    }

    /**
     * Format time in seconds to human readable format
     *
     * @param int $seconds
     *
     * @return string
     */
    private function formatTime(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;

        if ($minutes < 60) {
            return sprintf('%dm %ds', $minutes, $seconds);
        }

        $hours = floor($minutes / 60);
        $minutes = $minutes % 60;

        return sprintf('%dh %dm', $hours, $minutes);
    }

    /**
     * Fallback for Symfony 4.4 - 6.x compatibility
     */
    private function exitSuccess(): int
    {
        return \defined('\Symfony\Component\Console\Command\Command::SUCCESS') ? Command::SUCCESS : 0;
    }

    /**
     * Fallback for Symfony 4.4 - 6.x compatibility
     */
    private function exitFailure(): int
    {
        return \defined('\Symfony\Component\Console\Command\Command::FAILURE') ? Command::FAILURE : 1;
    }
}
