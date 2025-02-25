<?php
/**
 * Configuration file for LHV API GUI
 */

// Path definitions
define("ROOT_PATH", dirname(__FILE__));
define("LOG_PATH", ROOT_PATH . "/logs");

$config = [
    // LHV Connect API configuration
    "api" => [
        "base_url" => "https://connect.prelive.lhv.eu", // API base URL
        "cert_path" => ROOT_PATH . "/certs/company.crt", // Your company certificate
        "key_path" => ROOT_PATH . "/certs/private.key", // Your company private key
        "root_ca_path" => ROOT_PATH . "/certs/LHV_test_rootca2011.cer", // LHV root CA certificate
        "client_code" => "12345678", // Your company registration code
        "client_country" => "EE", // Your company registration country (2-letter code)
        "interface_ip" => "", // Leave empty to use the server's default IP address
    ],

    // Test bank account sprovided by LHV
    "accounts" => [
        "main" => [
            "iban" => "EE697700771001690214",
            "name" => "TEST OÜ - Account One",
        ],
        "secondary" => [
            "iban" => "EE097700771001690227",
            "name" => "TEST OÜ - Account Two",
        ],
    ],
];
