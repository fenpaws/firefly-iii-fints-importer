<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

use App\FinTsFactory;
use App\TanHandler;
use App\TransactionsToFireflySender;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use App\Step;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/public/html');
$twig   = new \Twig\Environment($loader);

$request = Request::createFromGlobals();

$current_step = new Step($request->request->get("step", Step::STEP1_COLLECTING_DATA));

$session = new Session();
$session->start();


switch ((string)$current_step) {
    case Step::STEP1_COLLECTING_DATA:
        echo $twig->render(
            'collecting-data.twig',
            array(
                'next_step' => Step::STEP2_LOGIN
            ));
        break;
    case Step::STEP2_LOGIN:
        $session->invalidate();
        $session->set('bank_username', $request->request->get('bank_username'));
        // Hm, this most likely stores the password on disk somewhere. Could we at least scramble it a bit?
        $session->set('bank_password', $request->request->get('bank_password'));
        $session->set('bank_url', $request->request->get('bank_url'));
        $session->set('bank_code', $request->request->get('bank_code'));
        $session->set('bank_2fa', $request->request->get('bank_2fa'));
        $session->set('firefly_url', $request->request->get('firefly_url'));
        $session->set('firefly_access_token', $request->request->get('firefly_access_token'));

        $fin_ts        = FinTsFactory::create_from_session($session);
        $login_handler = new TanHandler(
            function () {
                global $fin_ts;
                return $fin_ts->login();
            },
            'login-action',
            $session,
            $twig,
            $fin_ts,
            $current_step
        );
        if ($login_handler->needs_tan()) {
            $login_handler->pose_and_render_tan_challenge();
        } else {
            echo $twig->render(
                'skip-form.twig',
                array(
                    'next_step' => Step::STEP3_CHOOSE_ACCOUNT,
                    'message' => "The connection to both your bank and your Firefly III instance could be established."
                )
            );
        }
        $session->set('persistedFints', $fin_ts->persist());
        break;
    case Step::STEP3_CHOOSE_ACCOUNT:
        $fin_ts                = FinTsFactory::create_from_session($session);
        $list_accounts_handler = new TanHandler(
            function () {
                global $fin_ts;
                $get_sepa_accounts = \Fhp\Action\GetSEPAAccounts::create();
                $fin_ts->execute($get_sepa_accounts);
                return $get_sepa_accounts;
            },
            'list-accounts',
            $session,
            $twig,
            $fin_ts,
            $current_step
        );
        if ($list_accounts_handler->needs_tan()) {
            $list_accounts_handler->pose_and_render_tan_challenge();
        } else {
            $bank_accounts            = $list_accounts_handler->get_finished_action()->getAccounts();
            $firefly_accounts_request = new GetAccountsRequest($session->get('firefly_url'), $session->get('firefly_access_token'));
            $firefly_accounts_request->setType(GetAccountsRequest::ASSET);
            $firefly_accounts = $firefly_accounts_request->get();
            echo $twig->render(
                'choose-account.twig',
                array(
                    'next_step' => Step::STEP4_GET_IMPORT_DATA,
                    'bank_accounts' => $bank_accounts,
                    'firefly_accounts' => $firefly_accounts,
                    'default_from_date' => new \DateTime('now - 1 month'),
                    'default_to_date' => new \DateTime('now')
                )
            );
            $session->set('accounts', serialize($bank_accounts));
        }
        $session->set('persistedFints', $fin_ts->persist());
        break;
    case Step::STEP4_GET_IMPORT_DATA:
        $fin_ts   = FinTsFactory::create_from_session($session);
        $accounts = unserialize($session->get('accounts'));
        $soa_handler = new TanHandler(
            function () {
                global $fin_ts, $request, $accounts, $session;
                assert($request->request->has('bank_account'));
                assert($request->request->has('firefly_account'));
                assert($request->request->has('date_from'));
                assert($request->request->has('date_to'));
                $bank_account = $accounts[intval($request->request->get('bank_account'))];
                $from         = new \DateTime($request->request->get('date_from'));
                $to           = new \DateTime($request->request->get('date_to'));
                $session->set('firefly_account', $request->request->get('firefly_account'));
                $get_statement = \Fhp\Action\GetStatementOfAccount::create($bank_account, $from, $to);
                $fin_ts->execute($get_statement);
                return $get_statement;
            },
            'soa',
            $session,
            $twig,
            $fin_ts,
            $current_step
        );
        if ($soa_handler->needs_tan()) {
            $soa_handler->pose_and_render_tan_challenge();
        } else {
            /** @var \Fhp\Model\StatementOfAccount\StatementOfAccount $soa */
            $soa          = $soa_handler->get_finished_action()->getStatement();
            $transactions = \App\StatementOfAccountHelper::get_all_transactions($soa);
            echo $twig->render(
                'show-transactions.twig',
                array(
                    'transactions' => $transactions,
                    'next_step' => Step::STEP5_RUN_IMPORT
                )
            );
            $session->set('transactions_to_import', serialize($transactions));
            $session->set('num_transactions_processed', 0);
            $session->set('import_messages', serialize(array()));
        }
        $session->set('persistedFints', $fin_ts->persist());
        break;
    case Step::STEP5_RUN_IMPORT:
        $num_transactions_to_import_at_once = 5;
        assert($session->has('transactions_to_import'));
        assert($session->has('num_transactions_processed'));
        assert($session->has('import_messages'));
        assert($session->has('firefly_account'));
        $transactions                = unserialize($session->get('transactions_to_import'));
        $num_transactions_processed  = $session->get('num_transactions_processed');
        $import_messages             = unserialize($session->get('import_messages'));
        $transactions_to_process_now = array_slice($transactions, $num_transactions_processed, $num_transactions_to_import_at_once);
        if (empty($transactions_to_process_now)) {
            echo $twig->render(
                'done.twig',
                array(
                    'import_messages' => $import_messages,
                    'total_num_transactions' => count($transactions)
                )
            );
            $session->invalidate();
        } else {
            $num_transactions_processed += count($transactions_to_process_now);
            $sender                     = new TransactionsToFireflySender(
                $transactions_to_process_now,
                $session->get('firefly_url'),
                $session->get('firefly_access_token'),
                $session->get('firefly_account')
            );
            $result                     = $sender->send_transactions();
            if (is_array($result)) {
                $import_messages = array_merge($import_messages, $result);
            }

            $session->set('num_transactions_processed', $num_transactions_processed);
            $session->set('import_messages', serialize($import_messages));

            echo $twig->render(
                'import-progress.twig',
                array(
                    'num_transactions_processed' => $num_transactions_processed,
                    'total_num_transactions' => count($transactions),
                    'next_step' => Step::STEP5_RUN_IMPORT
                )
            );
        }
        break;

    default:
        echo "Unknown step $current_step";
        break;
}
