# SMTP\_Validate\_Email

[![PHP Version](https://img.shields.io/badge/php-5.6%2B-blue.svg?style=flat-square)](https://packagist.org/packages/zytzagoo/smtp-validate-email)
[![Software License](https://img.shields.io/badge/license-gpl3%2B-brightgreen.svg?style=flat-square)](LICENSE.txt)
[![Build Status](https://img.shields.io/travis/zytzagoo/smtp-validate-email.svg?style=flat-square)](https://travis-ci.org/zytzagoo/smtp-validate-email)
[![Scrutinizer Coverage](https://img.shields.io/scrutinizer/coverage/g/zytzagoo/smtp-validate-email.svg?style=flat-square)](https://scrutinizer-ci.com/g/zytzagoo/smtp-validate-email/?branch=master)

Perform email address validation/verification via SMTP.

The `SMTPValidateEmail\Validator` class retrieves MX records for the email domain and then connects to the
domain's SMTP server to try figuring out if the address really exists.

Earlier versions (before 1.0) used the `SMTP_Validate_Email` class name (and did not use namespaces and other now-common PHP features). Care has been taken to keep the old API and migrating old code should be painless. See ["Migrating to 1.0 from older versions"](#migrating-to-1.0-from-older-versions) section. Or just use/download the ancient [0.7 version](https://github.com/zytzagoo/smtp-validate-email/releases/tag/v0.7).

## Features
* Not actually sending the message, gracefully resetting the SMTP session when done
* Command-specific communication timeouts implemented per relevant RFCs
* Catch-all account detection
* Batch mode processing supported
* Logging/debugging support
* No external dependencies
* Covered with unit/functional tests

## Installation

Install via [composer](https://getcomposer.org/):

`composer require zytzagoo/smtp-validate-email --update-no-dev`

## Usage examples

### Basic example
```php
<?php

require 'vendor/autoload.php';

use SMTPValidateEmail\Validator as SmtpEmailValidator;

/**
 * Simple example
 */
$email     = 'someone@example.org';
$sender    = 'sender@example.org';
$validator = new SmtpEmailValidator($email, $sender);

// If debug mode is turned on, logged data is printed as it happens:
// $validator->debug = true;
$results   = $validator->validate();

var_dump($results);

// Get log data (log data is always collected)
$log = $validator->getLog();
var_dump($log);
```

### Multiple recipients and other details

```php
<?php

require 'vendor/autoload.php';

use SMTPValidateEmail\Validator as SmtpEmailValidator;

/**
 * Validating multiple addresses/recipients at once:
 * (checking multiple addresses belonging to the same server
 * uses a single connection)
 */
$emails    = [
    'someone@example.org',
    'someone.else@example.com'
];
$sender    = 'sender@example.org';
$validator = new SmtpEmailValidator($emails, $sender);
$results   = $validator->validate();

var_dump($results);

/**
 * The `validate()` method accepts the same parameters
 * as the constructor, so this is equivalent to the above:
 */
$emails    = [
    'someone@example.org',
    'someone.else@example.com'
];
$sender    = 'sender@example.org';
$validator = new SmtpEmailValidator();
$results   = $validator->validate($emails, $sender);

var_dump($results);
```

## Migrating to 1.0 from older versions

Earlier versions used the global `SMTP_Validate_Email` classname.
You can keep using that name in your existing code and still switch to the newer (composer-powered) version by using [aliasing/importing](http://php.net/manual/en/language.namespaces.importing.php) like this:

Require the composer package:

`composer require zytzagoo/smtp-validate-email --update-no-dev`

And then in your code:

```php
<?php

require 'vendor/autoload.php';

use SMTPValidateEmail\Validator as SMTP_Validate_Email;

// Now any old code referencing `SMTP_Validate_Email` should still work as it did earlier
```

## Development & Contributions
See the [Makefile](Makefile) and the development dependencies in [composer.json](composer.json).

Running `make` once you clone (or download) the repository gives you:

```
Usage: make [target]

[target]                       help
--------                       ----
help                           What you're currently reading
install                        Installs dev dependencies
clean                          Removes installed dev dependencies
test                           Runs tests
coverage                       Runs tests with code coverage
server-start                   Stops and starts the smtp server
server-stop                    Stops smtp server if it's running
(PIDFILE)                      Starts the smtp server
(MAILHOG)                      Downloads platform-specific mailhog binary
```

So, run `make install` to get started. Afterwards you should be able to run the tests (`make test`).

Tests are powered by `phpunit` and a local `./bin/mailhog` instance running on port 1025.
Tests requiring an SMTP server are marked as skipped (if/when the SMTP server is unavailable).

Pull requests are welcome!

In order to get your pull request merged, please follow these simple rules:

* all code submissions must pass cleanly (no errors) with `make test`
* stick to existing code style (`phpcs` is used)
* there should be no external dependencies
* if you want to add significant features/dependencies, file an issue about it first so we can discuss whether the addition makes sense for the project

## [Changelog](CHANGELOG.md)

## [License (GPL-3.0+)](LICENSE.txt)
