# SMTP\_Validate\_Email

Perform email address validation/verification via SMTP.

The class retrieves MX records for the email domain and then connects to the
domain's SMTP server to try figuring out if the address really exists.

### Some features (see the source for more)

* Not really sending a message, gracefully resetting the session when done
* Command-specific communication timeouts implemented per relevant RFCs
* Catch-all account detection
* Batch mode processing supported
* MX query support on Windows without requiring any PEAR packages
* Logging and debugging support

### Basic example
```php
<?php

require('smtp-validate-email.php');

$from = 'a-happy-camper@campspot.net'; // for SMTP FROM:<> command
$email = 'someone@somewhere.net';

$validator = new SMTP_Validate_Email($email, $from);
$smtp_results = $validator->validate();

var_dump($smtp_results);
```

### Array usage
The class supports passing an array of addresses in the constructor or to the
`validate()` method. Checking multiple addresses on the same server uses
a single connection.
```php
<?php

require('smtp-validate-email.php');

$from = 'a-happy-camper@campspot.net'; // for SMTP FROM:<> command
$emails = array(
    'someone@somewhere.net',
    'some-other@somewhere-else.net',
    'someone@example.com',
    'someone-else@example.com'
);

$validator = new SMTP_Validate_Email($emails, $from);
$smtp_results = $validator->validate();

// or passing to the validate() method
// $validator = new SMTP_Validate_Email();
// $smtp_results = $validator->validate($emails, $from);

var_dump($smtp_results);
```
