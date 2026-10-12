<?php
/**
 * ============================================================
 * VendoLink — Sales & Transactions Controller
 * ============================================================
 *
 * Handles transaction management and development/test simulations.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Transaction.php';

class SalesController
{
    public function handle(): void
    {
        csrf_verify();
        $action = $_POST['action'] ?? '';
        $transaction = new Transaction();

        try {
            if ($action === 'dev_record') {
                $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
                $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
                $status = trim($_POST['status'] ?? 'completed');

                if (!$serviceId || $amount === false || $amount <= 0) {
                    throw new InvalidArgumentException('Please provide a valid service and coin amount.');
                }

                $txId = $transaction->record($serviceId, (float) $amount, $status);
                log_activity('DEV_TRANSACTION', "Recorded development test transaction #{$txId} (₱{$amount}, {$status}).");
                flash('success', "Test transaction #{$txId} recorded with configuration snapshot.");
            } elseif ($action === 'dev_seed') {
                $services = $transaction->getServices();
                $seededCount = 0;
                foreach ($services as $svc) {
                    if ((int) $svc['is_enabled']) {
                        $coinVal = (float) $svc['coin_value'];
                        $transaction->record((int) $svc['id'], $coinVal, 'completed');
                        $transaction->record((int) $svc['id'], $coinVal * 2, 'completed');
                        $transaction->record((int) $svc['id'], $coinVal, 'active');
                        $seededCount += 3;
                    }
                }
                log_activity('DEV_SEED_TRANSACTIONS', "Generated {$seededCount} sample test transactions for development verification.");
                flash('success', "{$seededCount} sample test transactions created for verification.");
            } else {
                throw new InvalidArgumentException('Invalid sales action.');
            }
        } catch (InvalidArgumentException $e) {
            flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[VendoLink][Sales] ' . $e->getMessage());
            flash('danger', 'Could not process the transaction request: ' . $e->getMessage());
        }

        redirect('dashboard/sales.php');
    }
}
