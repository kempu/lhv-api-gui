<?php
/**
 * LHV Connect API Client
 * Compliant with LHV Connect API Specification
 */
class LHVConnectClient
{
    private $config;
    private $logger;
    private const MESSAGE_POLL_TIMEOUT = 30; // seconds
    private const MESSAGE_POLL_INTERVAL = 1; // seconds
    private $lastResponseHeaders = [];
    private $lastHttpCode;

    /**
     * Constructor
     *
     * @param array $config Configuration array
     * @param object $logger Logging object
     */
    public function __construct($config, $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Get message count
     *
     * @return int Number of pending messages
     */
    public function getMessageCount()
    {
        try {
            $response = $this->sendRequest("GET", "/messages/count");
            $data = json_decode($response, true);
            return isset($data["count"]) ? (int) $data["count"] : 0;
        } catch (Exception $e) {
            $this->logger->error(
                "Error getting message count: " . $e->getMessage()
            );
            return 0;
        }
    }

    /**
     * Get list of messages
     *
     * @param int $limit Number of messages to retrieve
     * @return array List of messages
     */
    public function getMessagesList($limit = 10)
    {
        try {
            $url = "/messages" . ($limit > 0 ? "?limit=" . intval($limit) : "");
            $response = $this->sendRequest("GET", $url);
            return json_decode($response, true);
        } catch (Exception $e) {
            $this->logger->error(
                "Error getting messages list: " . $e->getMessage()
            );
            return ["messages" => []];
        }
    }

    /**
     * Retrieve a specific message
     *
     * @param string $messageId Message identifier
     * @return string XML message content
     */
    public function getMessage($messageId)
    {
        return $this->sendRequest("GET", "/messages/" . $messageId);
    }

    /**
     * Delete a message with improved error handling
     */
    public function deleteMessage($messageId)
    {
        try {
            $response = $this->sendRequest("DELETE", "/messages/" . $messageId);

            // Check for 404 response indicating message already deleted
            if ($this->lastHttpCode === 404) {
                $this->logger->info("Message already deleted: " . $messageId);
                return true;
            }

            // Handle empty response for successful deletion
            if (empty($response) && $this->lastHttpCode === 200) {
                return true;
            }

            throw new Exception("Unexpected response deleting message");
        } catch (Exception $e) {
            $this->logger->error("Error deleting message: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get account balance with improved error handling
     *
     * @param string $iban Account IBAN
     * @return array Balance information
     */
    public function getAccountBalance($iban)
    {
        try {
            // Create proper account report request XML
            $xml = $this->createAccountReportRequest(
                $iban,
                "camt.052.001.06",
                date("Y-m-d"),
                date("Y-m-d")
            );

            // Log request XML for debugging
            $this->logger->debug("Account balance request XML", [
                "xml" => $xml,
            ]);

            // Submit balance request
            $response = $this->sendRequest(
                "POST",
                "/account-balance",
                $xml,
                "xml"
            );

            // Get the request ID to track this specific request
            $requestId = $this->getResponseHeader("Message-Request-Id");
            if (!$requestId) {
                $this->logger->warning(
                    "No request ID received for account balance"
                );
            }

            // Wait for and process response, matching with our request ID
            $response = $this->waitForResponseMessage(
                "ACCOUNT_BALANCE",
                null,
                $requestId
            );

            // Log response for debugging
            $this->logger->debug("Account balance response", [
                "response" => $response,
            ]);

            // Parse response XML - safely handling empty responses
            if (empty($response)) {
                $this->logger->warning(
                    "Empty response received when getting account balance"
                );
                return [
                    "balance" => 0,
                    "available" => 0,
                    "currency" => "EUR",
                ];
            }

            // Check if response is valid XML before parsing
            libxml_use_internal_errors(true);
            $xmlResponse = simplexml_load_string($response);
            if ($xmlResponse === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $this->logger->error("Failed to parse XML response", [
                    "errors" => $errors,
                    "response" => substr($response, 0, 1000), // Log first 1000 chars only
                ]);
                return [
                    "balance" => 0,
                    "available" => 0,
                    "currency" => "EUR",
                ];
            }

            $balances = [];

            // Register namespaces if present
            $ns = $xmlResponse->getNamespaces(true);
            $prefix = "";
            if (!empty($ns) && isset($ns[""])) {
                $xmlResponse->registerXPathNamespace("ns", $ns[""]);
                $prefix = "ns:";
            }

            // Extract balance information using XPath to handle namespaces
            if (isset($xmlResponse->BkToCstmrAcctRpt->Rpt)) {
                foreach ($xmlResponse->BkToCstmrAcctRpt->Rpt as $report) {
                    $currency = (string) $report->Acct->Ccy;
                    foreach ($report->Bal as $balance) {
                        $type = (string) $balance->Tp->CdOrPrtry->Cd;
                        $amount = (float) $balance->Amt;
                        $indicator = (string) $balance->CdtDbtInd;

                        if ($type === "ITBD" || $type === "ITAV") {
                            $balances[$type] = [
                                "amount" =>
                                    $indicator === "DBIT" ? -$amount : $amount,
                                "currency" => $currency,
                            ];
                        }
                    }
                }
            }

            return [
                "balance" => $balances["ITBD"]["amount"] ?? 0,
                "available" => $balances["ITAV"]["amount"] ?? 0,
                "currency" => $balances["ITBD"]["currency"] ?? "EUR",
            ];
        } catch (Exception $e) {
            $this->logger->error(
                "Error getting account balance: " . $e->getMessage(),
                ["exception" => $e]
            );
            return [
                "balance" => 0,
                "available" => 0,
                "currency" => "EUR",
            ];
        }
    }

    /**
     * Get account transactions with improved parsing
     *
     * @param string $iban Account IBAN
     * @param string|null $startDate Start date for transactions
     * @param string|null $endDate End date for transactions
     * @return array Transaction list
     */
    public function getAccountTransactions(
        $iban,
        $startDate = null,
        $endDate = null
    ) {
        try {
            $startTime = microtime(true);

            // Log the start of the transaction request with key details
            $this->logger->debug("TRANSACTION REQUEST STARTED", [
                "accountId" => array_search(
                    $iban,
                    array_column(
                        array_map(function ($account) {
                            return ["iban" => $account["iban"]];
                        }, array_values($this->config["accounts"] ?? [])),
                        "iban"
                    )
                ),
                "requestedAccount" =>
                    "Account for IBAN " .
                    substr($iban, 0, 4) .
                    "..." .
                    substr($iban, -4),
                "startDate" => $startDate,
                "endDate" => $endDate,
                "available_accounts" => array_keys(
                    $this->config["accounts"] ?? []
                ),
            ]);

            $this->logger->debug("Using IBAN for transaction request", [
                "accountId" => array_search(
                    $iban,
                    array_column(
                        array_map(function ($account) {
                            return ["iban" => $account["iban"]];
                        }, array_values($this->config["accounts"] ?? [])),
                        "iban"
                    )
                ),
                "iban" => $iban,
            ]);

            // Submit statement request
            $xml = $this->createAccountReportRequest(
                $iban,
                "camt.053.001.02",
                $startDate,
                $endDate
            );

            // Log request XML for debugging
            $this->logger->debug("Account statement request XML", [
                "xml" => $xml,
            ]);

            // Send the request and get the response with request ID
            $this->sendRequest("POST", "/account-statement", $xml, "xml");

            // Get the request ID to track this specific request
            $requestId = $this->getResponseHeader("Message-Request-Id");
            if (!$requestId) {
                $this->logger->warning(
                    "No request ID received for account statement"
                );
            }

            // Wait for and process response, matching with our request ID
            $response = $this->waitForResponseMessage(
                "ACCOUNT_STATEMENT",
                60,
                $requestId
            ); // Longer timeout for statements

            // Log response for debugging
            $this->logger->debug("Account statement response received", [
                "size" => strlen($response),
            ]);

            // Initialize transactions array to prevent the NULL issue
            $transactions = [];

            // Check if response is valid before parsing
            if (empty($response)) {
                $this->logger->warning(
                    "Empty response received when getting account transactions"
                );
                return ["entries" => $transactions];
            }

            // Safely parse XML
            libxml_use_internal_errors(true);
            $xmlResponse = simplexml_load_string($response);
            if ($xmlResponse === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $this->logger->error(
                    "Failed to parse transactions XML response",
                    [
                        "errors" => $errors,
                        "response" => substr($response, 0, 1000), // Log first 1000 chars only
                    ]
                );
                return ["entries" => $transactions];
            }

            $currentBalance = 0;

            // Get the correct namespace if present
            $ns = $xmlResponse->getNamespaces(true);
            $mainNs =
                $ns[""] ?? "urn:iso:std:iso:20022:tech:xsd:camt.053.001.02";

            // Register namespace if needed
            if (!empty($ns) && isset($ns[""])) {
                $xmlResponse->registerXPathNamespace("ns", $ns[""]);
                // Parse statements with namespace
                $stmts = $xmlResponse->xpath("//ns:Stmt");
            } else {
                // Parse statements without namespace
                $stmts = $xmlResponse->xpath("//Stmt");
            }

            foreach ($stmts as $stmt) {
                // Get opening balance
                if (isset($stmt->Bal)) {
                    foreach ($stmt->Bal as $bal) {
                        if ((string) $bal->Tp->CdOrPrtry->Cd === "OPBD") {
                            $amount = (float) $bal->Amt;
                            $indicator = (string) $bal->CdtDbtInd;
                            $currentBalance =
                                $indicator === "DBIT" ? -$amount : $amount;
                            break;
                        }
                    }
                }

                // Process each entry
                if (isset($stmt->Ntry)) {
                    foreach ($stmt->Ntry as $entry) {
                        $amount = (float) $entry->Amt;
                        $indicator = (string) $entry->CdtDbtInd;

                        // Adjust amount based on credit/debit indicator
                        $processedAmount =
                            $indicator === "DBIT" ? -$amount : $amount;

                        // Update running balance
                        $currentBalance += $processedAmount;

                        // Get transaction details
                        $details = null;
                        if (isset($entry->NtryDtls->TxDtls)) {
                            $details = $entry->NtryDtls->TxDtls;
                        }

                        // Build transaction record
                        $transaction = [
                            "bookingDate" => (string) $entry->BookgDt->Dt,
                            "valueDate" =>
                                (string) ($entry->ValDt->DtTm ??
                                    $entry->BookgDt->Dt),
                            "amount" => $processedAmount,
                            "currency" => (string) $entry->Amt["Ccy"],
                            "type" => $this->getTransactionType($entry),
                            "status" => (string) $entry->Sts,
                            "reference" => (string) ($entry->AcctSvcrRef ?? ""),
                            "description" => $this->getTransactionDescription(
                                $entry
                            ),
                            "balance" => $currentBalance,
                        ];

                        // Add counterparty information if available
                        if ($details) {
                            if (
                                $indicator === "DBIT" &&
                                isset($details->RltdPties->Cdtr)
                            ) {
                                $transaction["counterparty"] = [
                                    "name" =>
                                        (string) $details->RltdPties->Cdtr->Nm,
                                    "account" =>
                                        (string) ($details->RltdPties->CdtrAcct
                                            ->Id->IBAN ?? ""),
                                ];
                            } elseif (
                                $indicator === "CRDT" &&
                                isset($details->RltdPties->Dbtr)
                            ) {
                                $transaction["counterparty"] = [
                                    "name" =>
                                        (string) $details->RltdPties->Dbtr->Nm,
                                    "account" =>
                                        (string) ($details->RltdPties->DbtrAcct
                                            ->Id->IBAN ?? ""),
                                ];
                            }
                        }

                        $transactions[] = $transaction;
                    }
                }
            }

            // Most recent transactions first
            $sortedTransactions = !empty($transactions)
                ? array_reverse($transactions)
                : [];

            // Log completion with stats
            $execTime = number_format(microtime(true) - $startTime, 2);
            $this->logger->debug("API call completed", [
                "accountId" => array_search(
                    $iban,
                    array_column(
                        array_map(function ($account) {
                            return ["iban" => $account["iban"]];
                        }, array_values($this->config["accounts"] ?? [])),
                        "iban"
                    )
                ),
                "iban" => $iban,
                "elapsedTime" => "$execTime seconds",
                "transactionCount" => count($sortedTransactions),
                "firstTransaction" => !empty($sortedTransactions)
                    ? [
                        "date" => $sortedTransactions[0]["bookingDate"],
                        "amount" => $sortedTransactions[0]["amount"],
                        "type" => $sortedTransactions[0]["type"],
                    ]
                    : "none",
            ]);

            return [
                "entries" => $sortedTransactions,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                "Error getting account transactions: " . $e->getMessage(),
                ["exception" => $e]
            );
            return ["entries" => []];
        }
    }

    /**
     * Get human-readable transaction type
     */
    private function getTransactionType($entry)
    {
        if (isset($entry->BkTxCd->Domn)) {
            $domain = (string) $entry->BkTxCd->Domn->Cd;
            $family = (string) $entry->BkTxCd->Domn->Fmly->Cd;
            $subFamily = (string) $entry->BkTxCd->Domn->Fmly->SubFmlyCd;

            // Map common transaction types
            switch ("$domain.$family.$subFamily") {
                case "PMNT.ICDT.OTHR":
                    return "Internal Transfer";
                case "PMNT.RCDT.OTHR":
                    return "Received Transfer";
                case "PMNT.IRCT.STDO":
                    return "Standing Order";
                case "PMNT.CCRD.POSD":
                    return "Card Payment";
                default:
                    return "$domain.$family.$subFamily";
            }
        }
        return "Unknown";
    }

    /**
     * Get transaction description from various possible fields
     */
    private function getTransactionDescription($entry)
    {
        // Check various possible locations for description
        $descriptions = [];

        if (isset($entry->NtryDtls->TxDtls->RmtInf->Ustrd)) {
            foreach ($entry->NtryDtls->TxDtls->RmtInf->Ustrd as $desc) {
                $descriptions[] = (string) $desc;
            }
        }

        if (isset($entry->AddtlNtryInf)) {
            $descriptions[] = (string) $entry->AddtlNtryInf;
        }

        // Combine all descriptions
        return implode(" ", array_filter($descriptions));
    }

    /**
     * Initiate a transfer
     *
     * @param array $payment Payment details
     * @return array Transfer initiation response
     */
    public function initiateTransfer($payment)
    {
        try {
            // Validate required fields
            if (
                empty($payment["debtorIBAN"]) ||
                empty($payment["creditorIBAN"]) ||
                empty($payment["amount"])
            ) {
                throw new Exception("Missing required payment details");
            }

            // Create payment XML
            $xml = $this->createPaymentInitiationXML($payment);

            // Log the request XML for debugging
            $this->logger->debug("Payment request XML", ["xml" => $xml]);

            // Submit payment
            $response = $this->sendRequest("POST", "/payment", $xml, "xml");

            // Get request ID from response headers
            $requestId = $this->getResponseHeader("Message-Request-Id");
            if (!$requestId) {
                throw new Exception(
                    "No request ID received from payment initiation"
                );
            }

            $this->logger->info(
                "Payment initiated with request ID: " . $requestId
            );

            // Wait for payment status message
            $status = [
                "paymentId" => uniqid("PMT"),
                "status" => "PENDING",
                "message" => "Payment is being processed",
                "requestId" => $requestId,
            ];

            // Try to get initial payment response
            $response = $this->waitForResponseMessage("PAYMENT", 10);

            if ($response) {
                // Check if response is valid before parsing
                if (empty($response)) {
                    $this->logger->warning(
                        "Empty response received when processing payment"
                    );
                    return $status;
                }

                // Safely parse XML
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($response);
                if ($xml === false) {
                    $errors = libxml_get_errors();
                    libxml_clear_errors();
                    $this->logger->error(
                        "Failed to parse payment XML response",
                        [
                            "errors" => $errors,
                            "response" => substr($response, 0, 1000), // Log first 1000 chars only
                        ]
                    );
                    return $status;
                }

                if (isset($xml->CstmrPmtStsRpt)) {
                    $report = $xml->CstmrPmtStsRpt;

                    if (isset($report->OrgnlPmtInfAndSts)) {
                        foreach ($report->OrgnlPmtInfAndSts as $pmtInf) {
                            if (isset($pmtInf->TxInfAndSts)) {
                                foreach ($pmtInf->TxInfAndSts as $txInf) {
                                    $status["paymentId"] =
                                        (string) ($txInf->OrgnlInstrId ??
                                            $status["paymentId"]);
                                    $status["status"] =
                                        (string) ($txInf->TxSts ?? "PENDING");

                                    if (isset($txInf->StsRsnInf->AddtlInf)) {
                                        $status["message"] =
                                            (string) $txInf->StsRsnInf
                                                ->AddtlInf;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $status;
        } catch (Exception $e) {
            $this->logger->error(
                "Error initiating transfer: " . $e->getMessage(),
                ["exception" => $e]
            );
            return [
                "paymentId" => null,
                "status" => "FAILED",
                "message" => $e->getMessage(),
            ];
        }
    }

    /**
     * Confirm a transfer
     *
     * @param string $paymentId Payment identifier
     * @return array Confirmation response
     */
    public function confirmTransfer($paymentId)
    {
        try {
            // For LHV, confirmation is part of the initial payment process
            // But we should still check for subsequent status updates
            $response = $this->waitForResponseMessage("PAYMENT", 5);

            $status = [
                "status" => "ACCEPTED",
                "message" => "Payment processing",
            ];

            if ($response) {
                if (empty($response)) {
                    return $status;
                }

                // Safely parse XML
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($response);
                if ($xml === false) {
                    $errors = libxml_get_errors();
                    libxml_clear_errors();
                    $this->logger->error("Failed to parse XML response", [
                        "errors" => $errors,
                        "response" => substr($response, 0, 1000), // Log first 1000 chars only
                    ]);
                    return $status;
                }

                if (isset($xml->CstmrPmtStsRpt)) {
                    $report = $xml->CstmrPmtStsRpt;

                    if (isset($report->OrgnlPmtInfAndSts->TxInfAndSts->TxSts)) {
                        $txStatus =
                            (string) $report->OrgnlPmtInfAndSts->TxInfAndSts
                                ->TxSts;
                        $status["status"] = $txStatus;

                        if (
                            $txStatus === "RJCT" &&
                            isset(
                                $report->OrgnlPmtInfAndSts->TxInfAndSts
                                    ->StsRsnInf->AddtlInf
                            )
                        ) {
                            $status["message"] =
                                (string) $report->OrgnlPmtInfAndSts->TxInfAndSts
                                    ->StsRsnInf->AddtlInf;
                        }
                    }
                }
            }

            return $status;
        } catch (Exception $e) {
            $this->logger->warning(
                "Error in confirm transfer: " . $e->getMessage()
            );
            return [
                "status" => "UNKNOWN",
                "message" =>
                    "Payment status unknown due to error: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Get response header
     */
    private function getResponseHeader($name)
    {
        return $this->lastResponseHeaders[$name] ?? null;
    }

    /**
     * Wait for and process response message with improved request matching
     *
     * @param string $expectedType Expected message type
     * @param int $timeout Timeout in seconds
     * @param string $requestId Optional request ID to match with the response
     * @return string|null Response content or null if no message found
     */
    private function waitForResponseMessage(
        $expectedType = null,
        $timeout = null,
        $requestId = null
    ) {
        $timeout = $timeout ?? self::MESSAGE_POLL_TIMEOUT;
        $startTime = time();

        $this->logger->info("Waiting for response message", [
            "expectedType" => $expectedType,
            "requestId" => $requestId,
            "timeout" => $timeout,
        ]);

        while (time() - $startTime < $timeout) {
            try {
                // First check if there are any messages
                $messageCount = $this->getMessageCount();

                $this->logger->debug("Message count check", [
                    "count" => $messageCount,
                    "elapsedTime" => time() - $startTime,
                    "timeout" => $timeout,
                ]);

                if ($messageCount === 0) {
                    sleep(self::MESSAGE_POLL_INTERVAL);
                    continue;
                }

                // Get all messages instead of just the first one
                $messagesList = $this->getMessagesList(50);

                $this->logger->debug("Messages list retrieved", [
                    "messageCount" => count($messagesList["messages"] ?? []),
                    "messages" => array_map(function ($msg) {
                        return [
                            "id" => $msg["messageResponseId"] ?? "",
                            "type" => $msg["messageResponseType"] ?? "",
                            "requestId" => $msg["messageRequestId"] ?? "",
                            "createdTime" => $msg["messageCreatedTime"] ?? "",
                        ];
                    }, $messagesList["messages"] ?? []),
                ]);

                if (empty($messagesList["messages"])) {
                    sleep(self::MESSAGE_POLL_INTERVAL);
                    continue;
                }

                // Find messages of the expected type and matching requestId if provided
                $matchingMessages = array_filter(
                    $messagesList["messages"],
                    function ($message) use ($expectedType, $requestId) {
                        $typeMatch =
                            !$expectedType ||
                            $message["messageResponseType"] === $expectedType;

                        // If requestId is provided, also check that it matches
                        $requestMatch =
                            !$requestId ||
                            ($message["messageRequestId"] ?? "") === $requestId;

                        return $typeMatch && $requestMatch;
                    }
                );

                if (empty($matchingMessages)) {
                    $this->logger->debug("No matching messages found", [
                        "expectedType" => $expectedType,
                        "requestId" => $requestId,
                        "availableTypes" => array_column(
                            $messagesList["messages"],
                            "messageResponseType"
                        ),
                        "availableRequestIds" => array_column(
                            $messagesList["messages"],
                            "messageRequestId"
                        ),
                    ]);
                    sleep(self::MESSAGE_POLL_INTERVAL);
                    continue;
                }

                // Process the first matching message
                $message = reset($matchingMessages);
                $messageId = $message["messageResponseId"];

                $this->logger->info("Processing matching message", [
                    "messageId" => $messageId,
                    "type" => $message["messageResponseType"],
                    "requestId" => $message["messageRequestId"] ?? "",
                ]);

                // Retrieve message content
                $response = $this->getMessage($messageId);

                // Delete the message
                try {
                    $this->deleteMessage($messageId);
                } catch (Exception $e) {
                    $this->logger->warning("Failed to delete message", [
                        "messageId" => $messageId,
                        "error" => $e->getMessage(),
                    ]);
                }

                return $response;
            } catch (Exception $e) {
                $this->logger->error("Error polling for messages", [
                    "exception" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                    "elapsedTime" => time() - $startTime,
                ]);

                sleep(self::MESSAGE_POLL_INTERVAL);
            }
        }

        $this->logger->warning("Timeout waiting for response message", [
            "expectedType" => $expectedType,
            "requestId" => $requestId,
            "timeout" => $timeout,
        ]);

        return null;
    }

    /**
     * Send API request with improved error handling and logging
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param string|null $data Request body
     * @param string $contentType Content type (json|xml)
     * @return string Response content
     * @throws Exception On request failure
     */
    private function sendRequest(
        $method,
        $endpoint,
        $data = null,
        $contentType = "json"
    ) {
        $fullUrl = $this->config["base_url"] . $endpoint;
        $curl = curl_init($fullUrl);

        // Store headers in memory
        $this->lastResponseHeaders = [];

        // Prepare request headers
        $headers = [
            "Client-Code: " . $this->config["client_code"],
            "Client-Country: " . $this->config["client_country"],
        ];

        // Set content type headers
        if ($contentType === "json") {
            $headers[] = "Content-Type: application/json";
            $headers[] = "Accept: application/json";
        } else {
            $headers[] = "Content-Type: application/xml";
            $headers[] = "Accept: application/xml";
        }

        $this->logger->debug("Sending request", [
            "method" => $method,
            "url" => $fullUrl,
            "contentType" => $contentType,
            "headers" => $headers,
            "dataLength" => $data ? strlen($data) : 0,
        ]);

        // Detailed logging of request data (without exposing sensitive information)
        if ($data && strlen($data) < 2000) {
            $this->logger->debug("Request data", [
                "data" => $data,
            ]);
        }

        // Set up CURL options
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSLCERT => $this->config["cert_path"],
            CURLOPT_SSLKEY => $this->config["key_path"],
            CURLOPT_CAINFO => $this->config["root_ca_path"],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30, // Connection timeout
            CURLOPT_VERBOSE => false, // Set to true for more detailed curl debugging
            // Capture response headers
            CURLOPT_HEADERFUNCTION => function ($curl, $header) {
                $len = strlen($header);
                $header = explode(":", $header, 2);
                if (count($header) < 2) {
                    return $len;
                }
                $this->lastResponseHeaders[trim($header[0])] = trim($header[1]);
                return $len;
            },
        ];

        // Add interface IP if specified
        if (!empty($this->config["interface_ip"])) {
            $options[CURLOPT_INTERFACE] = $this->config["interface_ip"];
        }

        // Add request body if provided
        if ($data !== null) {
            $options[CURLOPT_POSTFIELDS] = $data;
        }

        curl_setopt_array($curl, $options);

        // Execute request with retry logic
        $maxRetries = 2;
        $retryCount = 0;
        $response = null;

        while ($retryCount <= $maxRetries) {
            // Execute request
            $response = curl_exec($curl);

            // Get HTTP status code
            $this->lastHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            // Log detailed request information
            $this->logger->debug("API Response Details", [
                "endpoint" => $endpoint,
                "httpCode" => $this->lastHttpCode,
                "responseSize" => is_string($response) ? strlen($response) : 0,
                "headers" => $this->lastResponseHeaders,
            ]);

            // Check for CURL errors
            if (curl_errno($curl)) {
                $error = curl_error($curl);
                $errorNum = curl_errno($curl);

                $this->logger->error("cURL error", [
                    "errorNumber" => $errorNum,
                    "errorMessage" => $error,
                    "retryCount" => $retryCount,
                ]);

                // Retry on connection errors
                if (
                    in_array($errorNum, [
                        CURLE_COULDNT_CONNECT,
                        CURLE_OPERATION_TIMEOUTED,
                    ]) &&
                    $retryCount < $maxRetries
                ) {
                    $retryCount++;
                    sleep(2 * $retryCount); // Exponential backoff
                    continue;
                }

                curl_close($curl);
                throw new Exception("cURL error {$errorNum}: {$error}");
            }

            // Retry on 5xx server errors
            if ($this->lastHttpCode >= 500 && $retryCount < $maxRetries) {
                $this->logger->warning("Server error, retrying", [
                    "httpCode" => $this->lastHttpCode,
                    "retryCount" => $retryCount,
                ]);
                $retryCount++;
                sleep(2 * $retryCount); // Exponential backoff
                continue;
            }

            // If we reached here, we either succeeded or encountered a non-retryable error
            break;
        }

        curl_close($curl);

        // Special handling for 404 on message deletion
        if (
            $this->lastHttpCode === 404 &&
            $method === "DELETE" &&
            strpos($endpoint, "/messages/") === 0
        ) {
            $this->logger->info("Message already deleted", [
                "endpoint" => $endpoint,
            ]);
            return "";
        }

        // Return empty string for expected empty responses
        if (
            empty($response) &&
            ($this->lastHttpCode === 202 ||
                $this->lastHttpCode === 204 ||
                ($this->lastHttpCode === 200 && $method === "DELETE"))
        ) {
            return "";
        }

        // Check for empty response
        if (
            empty($response) &&
            $this->lastHttpCode !== 202 &&
            $this->lastHttpCode !== 204
        ) {
            $this->logger->warning("Empty response received", [
                "endpoint" => $endpoint,
                "httpCode" => $this->lastHttpCode,
            ]);
            throw new Exception("Empty response received from API");
        }

        // Handle error responses
        if ($this->lastHttpCode >= 400) {
            $this->logger->error("HTTP error response", [
                "endpoint" => $endpoint,
                "httpCode" => $this->lastHttpCode,
                "responseBody" => $response,
            ]);
            throw new Exception(
                "HTTP error {$this->lastHttpCode}: {$response}"
            );
        }

        return $response;
    }

    /**
     * Create XML request for account report
     *
     * @param string $iban Account IBAN
     * @param string $messageType Type of report (camt.053 or camt.052)
     * @param string|null $startDate Start date
     * @param string|null $endDate End date
     * @return string XML request
     */
    private function createAccountReportRequest(
        $iban,
        $messageType,
        $startDate = null,
        $endDate = null
    ) {
        $xml = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
                '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.060.001.03"></Document>'
        );

        $acctRptgReq = $xml->addChild("AcctRptgReq");

        // Group Header
        $grpHdr = $acctRptgReq->addChild("GrpHdr");
        $grpHdr->addChild("MsgId", uniqid("LHV"));
        $grpHdr->addChild("CreDtTm", date("Y-m-d\TH:i:s"));

        // Reporting Request
        $rptgReq = $acctRptgReq->addChild("RptgReq");
        $rptgReq->addChild("ReqdMsgNmId", $messageType);

        // Account
        $acct = $rptgReq->addChild("Acct");
        $acctId = $acct->addChild("Id");
        $acctId->addChild("IBAN", $iban);

        // Account Owner (required before RptgPrd)
        $acctOwnr = $rptgReq->addChild("AcctOwnr");
        $pty = $acctOwnr->addChild("Pty");

        // Reporting Period
        $rptgPrd = $rptgReq->addChild("RptgPrd");
        $frToDt = $rptgPrd->addChild("FrToDt");
        $frToDt->addChild(
            "FrDt",
            $startDate ?: date("Y-m-d", strtotime("-30 days"))
        );
        $frToDt->addChild("ToDt", $endDate ?: date("Y-m-d"));

        // Time range
        $frToTm = $rptgPrd->addChild("FrToTm");
        $frToTm->addChild("FrTm", "00:00:00");
        $frToTm->addChild("ToTm", "23:59:59");

        $rptgPrd->addChild("Tp", "ALLL");

        // For balance requests, add the PAYMENT_LIMITS
        if ($messageType === "camt.052.001.06") {
            $reqdBalTp = $rptgReq->addChild("ReqdBalTp");
            $cdOrPrtry = $reqdBalTp->addChild("CdOrPrtry");
            $cdOrPrtry->addChild("Prtry", "PAYMENT_LIMITS");
        } else {
            $reqdBalTp = $rptgReq->addChild("ReqdBalTp");
            $cdOrPrtry = $reqdBalTp->addChild("CdOrPrtry");
            $cdOrPrtry->addChild("Prtry", "DATE");
        }

        return $xml->asXML();
    }

    /**
     * Create XML for payment initiation following ISO20022 specification
     *
     * @param array $payment Payment details
     * @return string XML payment initiation request
     */
    private function createPaymentInitiationXML($payment)
    {
        $xml = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
                '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.09"></Document>'
        );

        $pmtInitn = $xml->addChild("CstmrCdtTrfInitn");

        // Group Header
        $grpHdr = $pmtInitn->addChild("GrpHdr");
        $grpHdr->addChild("MsgId", uniqid("MSG"));
        $grpHdr->addChild("CreDtTm", date("Y-m-d\TH:i:s"));
        $grpHdr->addChild("NbOfTxs", "1");
        $grpHdr->addChild(
            "CtrlSum",
            number_format($payment["amount"], 2, ".", "")
        );
        $initgPty = $grpHdr->addChild("InitgPty");
        $initgPty->addChild(
            "Nm",
            $payment["debtorName"] ?? "LHV Connect Client"
        );

        // Payment Information
        $pmtInf = $pmtInitn->addChild("PmtInf");
        $pmtInf->addChild("PmtInfId", uniqid("PMT"));
        $pmtInf->addChild("PmtMtd", "TRF");
        $pmtInf->addChild("BtchBookg", "false");
        $pmtInf->addChild("NbOfTxs", "1");
        $pmtInf->addChild(
            "CtrlSum",
            number_format($payment["amount"], 2, ".", "")
        );

        // Payment Type Info
        $pmtTpInf = $pmtInf->addChild("PmtTpInf");
        $svcLvl = $pmtTpInf->addChild("SvcLvl");
        // Let the bank select best scheme (INST, SEPA, SWIFT)
        $svcLvl->addChild("Prtry", "ALL");

        // Requested Execution Date
        $pmtInf->addChild("ReqdExctnDt")->addChild("Dt", date("Y-m-d"));

        // Debtor Information
        $dbtr = $pmtInf->addChild("Dbtr");
        $dbtr->addChild("Nm", $payment["debtorName"] ?? "LHV Connect Client");

        // Add postal address for Debtor
        $dbtrPstlAdr = $dbtr->addChild("PstlAdr");
        $dbtrPstlAdr->addChild("TwnNm", "Tallinn");
        $dbtrPstlAdr->addChild("Ctry", "EE");

        // Debtor Account
        $dbtrAcct = $pmtInf->addChild("DbtrAcct");
        $dbtrAcctId = $dbtrAcct->addChild("Id");
        $dbtrAcctId->addChild("IBAN", $payment["debtorIBAN"]);

        // Debtor Agent (Bank)
        $dbtrAgt = $pmtInf->addChild("DbtrAgt");
        $finInstnId = $dbtrAgt->addChild("FinInstnId");
        $finInstnId->addChild("BICFI", "LHVBEE22");

        // Credit Transfer Transaction
        $cdtTrfTxInf = $pmtInf->addChild("CdtTrfTxInf");

        // Payment ID
        $pmtId = $cdtTrfTxInf->addChild("PmtId");
        $pmtId->addChild("InstrId", uniqid("INSTR"));
        $pmtId->addChild("EndToEndId", $payment["reference"] ?? uniqid("E2E"));

        // Payment Type Info at transaction level
        $txPmtTpInf = $cdtTrfTxInf->addChild("PmtTpInf");
        $txSvcLvl = $txPmtTpInf->addChild("SvcLvl");
        $txSvcLvl->addChild("Prtry", "ALL");

        // Amount
        $amt = $cdtTrfTxInf->addChild("Amt");
        $instdAmt = $amt->addChild(
            "InstdAmt",
            number_format($payment["amount"], 2, ".", "")
        );
        $instdAmt->addAttribute("Ccy", $payment["currency"] ?? "EUR");

        // Charge Bearer
        $cdtTrfTxInf->addChild("ChrgBr", "SLEV");

        // Creditor Agent (Bank) - optional for internal transfers
        if (!empty($payment["creditorBIC"])) {
            $cdtrAgt = $cdtTrfTxInf->addChild("CdtrAgt");
            $cdtrFinInstnId = $cdtrAgt->addChild("FinInstnId");
            $cdtrFinInstnId->addChild("BICFI", $payment["creditorBIC"]);
        }

        // Creditor Information
        $cdtr = $cdtTrfTxInf->addChild("Cdtr");
        $cdtr->addChild("Nm", $payment["creditorName"] ?? "Payment Recipient");

        // Add postal address for Creditor
        $cdtrPstlAdr = $cdtr->addChild("PstlAdr");
        $cdtrPstlAdr->addChild("TwnNm", "Tallinn");
        $cdtrPstlAdr->addChild("Ctry", "EE");

        // Creditor Account
        $cdtrAcct = $cdtTrfTxInf->addChild("CdtrAcct");
        $cdtrAcctId = $cdtrAcct->addChild("Id");
        $cdtrAcctId->addChild("IBAN", $payment["creditorIBAN"]);

        // Remittance Information
        if (!empty($payment["description"]) || !empty($payment["reference"])) {
            $rmtInf = $cdtTrfTxInf->addChild("RmtInf");

            if (!empty($payment["description"])) {
                $rmtInf->addChild(
                    "Ustrd",
                    substr($payment["description"], 0, 140)
                );
            }

            if (!empty($payment["reference"])) {
                $strd = $rmtInf->addChild("Strd");
                $cdtrRefInf = $strd->addChild("CdtrRefInf");
                $tp = $cdtrRefInf->addChild("Tp");
                $cdOrPrtry = $tp->addChild("CdOrPrtry");
                $cdOrPrtry->addChild("Cd", "SCOR");
                $cdtrRefInf->addChild("Ref", $payment["reference"]);
            }
        }

        return $xml->asXML();
    }
}
