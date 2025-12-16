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

class GetInactiveUsersEmailsCommand extends Command
{
    protected static $defaultName = 'awremoveinactiveuser:get-emails';

    private InactiveUserService $service;

    public function __construct(InactiveUserService $service)
    {
        $this->service = $service;
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultDir = _PS_ROOT_DIR_ . '/var/modules/awremoveinactiveuser';
        $defaultName = 'inactive_users_emails.csv';

        $this
            ->setDescription('Get emails of inactive customers.')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Number of days of inactivity', 365)
            ->addOption('shop', 's', InputOption::VALUE_REQUIRED, 'Shop ID (default: all shops)')
            ->addOption('batch', 'b', InputOption::VALUE_REQUIRED, 'Batch size for memory optimization', 1000)
            ->addOption('out-dir', null, InputOption::VALUE_REQUIRED, 'Output directory', $defaultDir)
            ->addOption('out-name', null, InputOption::VALUE_REQUIRED, 'Output filename (e.g. emails.csv)', $defaultName)
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: csv|json|txt', 'csv')
            ->addOption('display', null, InputOption::VALUE_NONE, 'Display emails in console instead of saving to file')
            ->setHelp(
                'This command retrieves emails of customers who have been inactive for X days.' . PHP_EOL .
                'By default, emails are saved to var/modules/awremoveinactiveuser/' . PHP_EOL .
                PHP_EOL .
                'Examples:' . PHP_EOL .
                '  php bin/console awremoveinactiveuser:get-emails --days=365' . PHP_EOL .
                '  php bin/console awremoveinactiveuser:get-emails --days=730 --shop=1' . PHP_EOL .
                '  php bin/console awremoveinactiveuser:get-emails --days=365 --out-name=my_emails.csv' . PHP_EOL .
                '  php bin/console awremoveinactiveuser:get-emails --days=365 --display'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $days = (int) $input->getOption('days');
            $shopId = $input->getOption('shop') ? (int) $input->getOption('shop') : null;
            $batchSize = (int) $input->getOption('batch');
            $outDir = (string) $input->getOption('out-dir');
            $outName = (string) $input->getOption('out-name');
            $format = (string) $input->getOption('format');
            $displayOnly = (bool) $input->getOption('display');

            // Validate days
            if ($days < 1) {
                $output->writeln('<error>Days must be greater than 0.</error>');

                return $this->exitFailure();
            }

            $output->writeln(sprintf('<info>Fetching inactive users for %d days...</info>', $days));

            // Count first
            $count = $this->service->countInactiveUsers($days, $shopId);
            $output->writeln(sprintf('<info>Found %d inactive users.</info>', $count));

            if ($count === 0) {
                $output->writeln('<comment>No inactive users found.</comment>');

                return $this->exitSuccess();
            }

            // Get emails
            $emails = $this->service->getInactiveUsersEmails($days, $shopId, $batchSize);

            // Output
            if ($displayOnly) {
                $this->displayEmails($emails, $output, $format);
            } else {
                $outputFile = rtrim($outDir, '/') . '/' . $outName;
                $this->writeToFile($emails, $outputFile, $format);
                $output->writeln(sprintf('<info>Emails exported to: %s</info>', $outputFile));
            }

            $output->writeln(sprintf('<info>Total: %d emails</info>', count($emails)));

            return $this->exitSuccess();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return $this->exitFailure();
        }
    }

    /**
     * Display emails in console
     */
    private function displayEmails(array $emails, OutputInterface $output, string $format): void
    {
        switch ($format) {
            case 'json':
                $output->writeln(json_encode($emails, JSON_PRETTY_PRINT));
                break;
            case 'csv':
                foreach ($emails as $email) {
                    $output->writeln($email);
                }
                break;
            default:
                foreach ($emails as $email) {
                    $output->writeln($email);
                }
                break;
        }
    }

    /**
     * Write emails to file
     */
    private function writeToFile(array $emails, string $filePath, string $format): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        switch ($format) {
            case 'json':
                file_put_contents($filePath, json_encode($emails, JSON_PRETTY_PRINT));
                break;
            case 'csv':
                $handle = fopen($filePath, 'w');
                fputcsv($handle, ['email']);
                foreach ($emails as $email) {
                    fputcsv($handle, [$email]);
                }
                fclose($handle);
                break;
            default:
                file_put_contents($filePath, implode(PHP_EOL, $emails));
                break;
        }
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
