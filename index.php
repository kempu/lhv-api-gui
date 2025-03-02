<?php
/**
 * LHV Test Interface
 *
 * Web interface for testing LHV Connect API integration:
 * - View account balances
 * - Make test transfers
 * - View account statements
 * - Monitor API connectivity
 */

// Show errors
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);

require_once "config.php";
require_once "vendor/autoload.php";
require_once "lhv-connect-api.php";

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class LHVTestInterface
{
    private $logger;
    private $lhvClient;
    private $config;
    private $accounts;

    public function __construct($config)
    {
        $this->initializeLogger();
        $this->loadConfig($config);
        $this->initializeLHVClient();
    }

    private function initializeLogger()
    {
        $logFormat = "[%datetime%] %level_name%: %message% %context%\n";
        $formatter = new LineFormatter($logFormat);

        $this->logger = new Logger("lhv_test_interface");

        // File handler with rotation
        $fileHandler = new RotatingFileHandler(
            LOG_PATH . "/lhv_test_interface.log",
            30,
            Logger::DEBUG
        );
        $fileHandler->setFormatter($formatter);
        $this->logger->pushHandler($fileHandler);
    }

    private function loadConfig($config)
    {
        $this->config = $config["api"];
        $this->accounts = $config["accounts"];
    }

    private function initializeLHVClient()
    {
        $this->lhvClient = new LHVConnectClient($this->config, $this->logger);
    }

    public function handleRequest()
    {
        $action = $_POST["action"] ?? "";

        try {
            switch ($action) {
                case "getBalance":
                    return $this->getAccountBalance($_POST["account"]);

                case "getTransactions":
                    return $this->getAccountTransactions(
                        $_POST["account"],
                        $_POST["startDate"] ??
                            date("Y-m-d", strtotime("-30 days")),
                        $_POST["endDate"] ?? date("Y-m-d")
                    );

                case "makeTransfer":
                    return $this->makeTransfer(
                        $_POST["fromAccount"],
                        $_POST["toAccount"],
                        $_POST["amount"],
                        $_POST["reference"] ?? "",
                        $_POST["description"] ?? ""
                    );

                case "checkConnectivity":
                    return $this->checkAPIConnectivity();

                default:
                    return [
                        "success" => false,
                        "error" => "Invalid action",
                    ];
            }
        } catch (Exception $e) {
            $this->logger->error(
                "Error handling request: " . $e->getMessage(),
                [
                    "action" => $action,
                    "exception" => $e,
                ]
            );

            return [
                "success" => false,
                "error" => $e->getMessage(),
            ];
        }
    }

    private function getAccountBalance($accountId)
    {
        if (!isset($this->accounts[$accountId])) {
            throw new Exception("Invalid account ID");
        }

        $iban = $this->accounts[$accountId]["iban"];

        // Use LHV API to get balance
        $balanceData = $this->lhvClient->getAccountBalance($iban);

        if (!$balanceData) {
            throw new Exception("Failed to get balance data");
        }
        return [
            "success" => true,
            "balance" => $balanceData["balance"] ?? 0,
            "available" => $balanceData["available"] ?? 0,
            "currency" => $balanceData["currency"] ?? "EUR",
        ];
    }

    private function getAccountTransactions($accountId, $startDate, $endDate)
    {
        if (!isset($this->accounts[$accountId])) {
            throw new Exception("Invalid account ID");
        }

        $iban = $this->accounts[$accountId]["iban"];

        // Debug logging of parameters
        $this->logger->debug("Transaction request parameters", [
            "accountId" => $accountId,
            "iban" => $iban,
            "startDate" => $startDate,
            "endDate" => $endDate,
        ]);

        // Make sure dates are properly formatted
        if (
            empty($startDate) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)
        ) {
            $startDate = date("Y-m-d", strtotime("-30 days"));
            $this->logger->info(
                "Invalid start date format, using default: " . $startDate
            );
        }

        if (empty($endDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $endDate = date("Y-m-d");
            $this->logger->info(
                "Invalid end date format, using default: " . $endDate
            );
        }

        // Use LHV API to get transactions
        $transactionsData = $this->lhvClient->getAccountTransactions(
            $iban,
            $startDate,
            $endDate
        );

        if (!$transactionsData) {
            throw new Exception("Failed to get transactions data");
        }

        $this->logger->debug("Transaction data received", [
            "count" => count($transactionsData["entries"] ?? []),
        ]);

        return [
            "success" => true,
            "transactions" => $transactionsData["entries"] ?? [],
        ];
    }

    private function makeTransfer(
        $fromAccountId,
        $toAccountId,
        $amount,
        $reference = "",
        $description = ""
    ) {
        if (
            !isset($this->accounts[$fromAccountId]) ||
            !isset($this->accounts[$toAccountId])
        ) {
            throw new Exception("Invalid account ID");
        }

        if ($amount <= 0) {
            throw new Exception("Invalid amount");
        }

        // Prepare payment request
        $payment = [
            "debtorIBAN" => $this->accounts[$fromAccountId]["iban"],
            "creditorIBAN" => $this->accounts[$toAccountId]["iban"],
            "amount" => number_format($amount, 2, ".", ""),
            "currency" => "EUR",
            "requestedExecutionDate" => date("Y-m-d"),
        ];

        if ($reference) {
            $payment["reference"] = $reference;
        }

        if ($description) {
            $payment["description"] = $description;
        }

        // Initiate payment
        $result = $this->lhvClient->initiateTransfer($payment);

        if (!isset($result["paymentId"])) {
            throw new Exception("Failed to initiate payment");
        }

        // Confirm payment
        $confirmResult = $this->lhvClient->confirmTransfer(
            $result["paymentId"]
        );

        if ($confirmResult["status"] !== "ACCEPTED") {
            throw new Exception(
                "Payment confirmation failed: " .
                    ($confirmResult["message"] ?? "Unknown error")
            );
        }

        return [
            "success" => true,
            "paymentId" => $result["paymentId"],
            "status" => $confirmResult["status"],
        ];
    }

    private function checkAPIConnectivity()
    {
        try {
            // Try to get message count as connectivity test
            $response = $this->lhvClient->getMessageCount();

            return [
                "success" => true,
                "messageCount" => $response,
                "interface" => $this->config["interface_ip"],
            ];
        } catch (Exception $e) {
            return [
                "success" => false,
                "error" => $e->getMessage(),
                "interface" => $this->config["interface_ip"],
            ];
        }
    }

    public function renderPage()
    {
        // Initial API connectivity check
        $apiStatus = $this->checkAPIConnectivity();

        header("Content-Type: text/html; charset=utf-8");
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LHV API GUI</title>
    <!-- Add Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Add Feather Icons -->
    <script src="https://unpkg.com/feather-icons"></script>
</head>
<body class="bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <header class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">LHV API GUI</h1>
                    <p class="mt-1 text-sm text-gray-500">Test environment for LHV Connect API integration</p>
                </div>
                <div class="text-sm text-gray-500">
                    <?php if (!empty($this->config["interface_ip"])): ?>
                        Using IP: <?php echo htmlspecialchars(
                            $this->config["interface_ip"]
                        ); ?>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- Account Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8" id="accountsSection">
            <?php foreach ($this->accounts as $id => $account): ?>
            <div class="account-card bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="p-6">
                    <h5 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars(
                        $account["name"]
                    ); ?></h5>
                    <p class="text-sm text-gray-500 mt-1">
                        <strong>IBAN:</strong> <?php echo htmlspecialchars(
                            $account["iban"]
                        ); ?>
                    </p>
                    <div class="mt-4 p-4 bg-gray-50 rounded-lg balance-info relative">
                        Loading balance...
                        <div class="loading-spinner absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center hidden">
                            <svg class="animate-spin h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4 flex space-x-3">
                        <button class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 refresh-balance"
                                data-account="<?php echo $id; ?>">
                            <i data-feather="refresh-cw" class="h-4 w-4 mr-2"></i>
                            Refresh Balance
                        </button>
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 view-transactions"
                                data-account="<?php echo $id; ?>">
                            View Transactions
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Transfer Form -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h5 class="text-lg font-medium text-gray-900">Make Test Transfer</h5>
            </div>
            <div class="p-6">
                <form id="transferForm">
                    <input type="hidden" name="action" value="makeTransfer">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">From Account</label>
                            <select name="fromAccount" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md" required>
                                <?php foreach (
                                    $this->accounts
                                    as $id => $account
                                ): ?>
                                <option value="<?php echo $id; ?>">
                                    <?php echo htmlspecialchars(
                                        $account["name"]
                                    ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">To Account</label>
                            <select name="toAccount" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-border-500 sm:text-sm rounded-md" required>
                                <?php foreach (
                                    $this->accounts
                                    as $id => $account
                                ): ?>
                                <option value="<?php echo $id; ?>">
                                    <?php echo htmlspecialchars(
                                        $account["name"]
                                    ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Amount (EUR)</label>
                            <input type="number" name="amount" class="mt-1 block w-full pl-3 pr-3 py-2 text-base border border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md" required min="0.01" step="0.01">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Reference Number</label>
                            <input type="text" name="reference" class="mt-1 block w-full pl-3 pr-3 py-2 text-base border border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                            <input type="text" name="description" class="mt-1 block w-full pl-3 pr-3 py-2 text-base border border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                        </div>
                    </div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Make Transfer
                    </button>
                </form>
            </div>
        </div>

        <div class="text-right text-sm text-gray-500 mt-4 pr-4">
            With ❤ by <a href="https://klemens.ee" target="_blank">Klemens Arro</a>
        </div>

        <!-- Transactions Panel -->
        <div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full opacity-0 pointer-events-none transition-opacity duration-300" id="transactionsPanel">
            <div class="relative top-20 mx-auto p-5 border w-full max-w-7xl shadow-lg rounded-md bg-white">
                <!-- Spinner overlay, hidden by default -->
                <div class="loading-spinner hidden absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center">
                    <svg class="animate-spin h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10"
                            stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0
                            5.373 0 12h4zm2 5.291A7.962 7.962 0
                            014 12H0c0 3.042 1.135 5.824 3
                            7.938l3-2.647z"/>
                    </svg>
                </div>
                <div class="flex justify-between items-center mb-4">
                    <h5 class="text-lg font-medium text-gray-900">Transaction History</h5>
                    <button onclick="hideTransactions()" class="text-gray-400 hover:text-gray-500">
                        <i data-feather="x"></i>
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                        <input type="date" id="startDate" class="block w-full rounded-md pt-1 pl-2 pr-2 border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                               value="<?php echo date(
                                   "Y-m-d",
                                   strtotime("-30 days")
                               ); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                        <input type="date" id="endDate" class="block w-full rounded-md pt-1 pl-2 pr-2 border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                               value="<?php echo date("Y-m-d"); ?>">
                    </div>
                    <div class="flex items-end">
                        <button onclick="refreshTransactions()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            Refresh
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                            </tr>
                        </thead>
                        <tbody id="transactionsList" class="bg-white divide-y divide-gray-200">
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                    Select date range and click Refresh
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- API Status -->
        <div class="fixed bottom-4 right-4">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3">
                <div class="flex items-center space-x-2">
                    <div class="status-indicator">
                        <?php if ($apiStatus["success"]): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                API Connected
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                API Error
                            </span>
                        <?php endif; ?>
                    </div>
                    <button onclick="checkConnectivity()" class="text-gray-400 hover:text-gray-500">
                        <i data-feather="refresh-cw" class="h-4 w-4"></i>
                    </button>
                </div>
                <?php if (!$apiStatus["success"]): ?>
                    <small class="block mt-1 text-xs text-red-600"><?php echo htmlspecialchars(
                        $apiStatus["error"]
                    ); ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="alert fixed top-4 right-4 max-w-sm w-full shadow-lg rounded-lg pointer-events-auto overflow-hidden hidden">
        <div class="p-4">
            <div class="flex items-center">
            <div class="flex-shrink-0">
                <svg class="alert-icon h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                </svg>
            </div>
            <div class="ml-3 w-0 flex-1">
                <p class="text-sm font-medium text-gray-900 alert-message"></p>
            </div>
            <div class="ml-4 flex-shrink-0 flex">
                <button class="rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <span class="sr-only">Close</span>
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
                </button>
            </div>
            </div>
        </div>
    </div>

    <script src="assets/app.js"></script>
    <script>
        feather.replace();
    </script>
</body>
</html><?php
    }
}

// Run the interface if called directly
if (basename(__FILE__) === basename($_SERVER["SCRIPT_NAME"])) {
    $interface = new LHVTestInterface($config);

    if (!empty($_POST)) {
        header("Content-Type: application/json");
        echo json_encode($interface->handleRequest());
    } else {
        $interface->renderPage();
    }
}
