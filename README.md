# LHV Connect API GUI

A simple developer interface for testing and interacting with the [LHV Connect API](https://www.lhv.ee/en/connect).
LHV Connect API technical documentation can be found [here](https://partners.lhv.ee/en/connect/).

This is mostly written by Anthropic's Claude AI.

## IMPORTANT
- DO NOT expose the interface to the public internet.
- Ensure proper access controls for the interface.
- NEVER try to connect it to production LHV Connect API.
- This tool is intended for development/testing only

## Overview

This tool provides a web-based interface for developers to:
- View account balances
- Make test transfers between accounts
- View account statements and transaction history

## Requirements

- PHP 7.4+ or PHP 8.0+
- SSL certificates for API authentication
- Composer for dependency management

## Installation

1. Clone this repository
2. Install dependencies:
   ```
   composer install
   ```
3. Place your certificates in the `certs/` directory:
   - `company.crt` - Your company certificate
   - `private.key` - Your company private key
   - `LHV_test_rootca2011.cer` - LHV root CA certificate

4. Create your configuration file based on the sample:
   ```
   cp config-sample.php config.php
   ```

5. Edit `config.php` with your specific settings

6. Make sure the logs directory exists and is writable:
   ```
   mkdir -p logs
   chmod 755 logs
   ```

## Screenshots

![Main view](https://github.com/kempu/lhv-api-gui/blob/main/assets/screenshots/view-1.png?raw=true)

![Transactions view](https://github.com/kempu/lhv-api-gui/blob/main/assets/screenshots/view-1.png?raw=true)

---

Developed by [Klemens Arro](https://klemens.ee) with Anthropic's Claude AI.
