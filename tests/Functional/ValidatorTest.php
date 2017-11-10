<?php

namespace SMTPValidateEmail\Tests\Functional;

use SMTPValidateEmail\Validator;
use SMTPValidateEmail\Tests\TestCase;
use SMTPValidateEmail\Tests\SmtpSinkServerProcess;

/**
 * Functional tests for Validator class.
 */
class ValidatorTest extends TestCase
{
    public function testNoConnIsValid()
    {
        // Testing no connection, so using a non-existent host on .localhost
        $uniq    = uniqid();
        $host    = 'localhost';
        $email   = 'test@' . $uniq . '.' . $host;
        $timeout = 1;

        // Default should be false
        $inst = new Validator($email, 'sender@localhost');
        $inst->setConnectTimeout($timeout);
        $this->assertFalse($inst->no_conn_is_valid);
        $results = $inst->validate();
        $this->assertFalse($results[$email]);

        $log       = $inst->getLog();
        $last_line = \array_pop($log);
        $needle    = 'Unable to connect. Exception caught: Cannot open a connection to remote host';
        $this->assertContains($needle, $last_line);

        // When changed, it should change the returned result
        $inst->no_conn_is_valid = true;

        $results = $inst->validate();
        $this->assertTrue($results[$email]);
    }

    /**
     * Requires having node-smtp-sink (`npm install smtp-sink`) locally.
     * It needs to be ran with -w switch: `smtp-sink -w allowed-sender@example.org`
     */
    public function testNoCommIsValidWithLocalSmtpSinkWhitelisted()
    {
        // Mark skipped if smtp-sink is not running
        if (!$this->isSmtpSinkRunning()) {
            $this->markTestSkipped('smtp-sink is not running.');
        }

        $email = 'test@localhost';

        $inst = new Validator($email, 'not-allowed@example.org');
        $inst->setConnectPort(1025);
        $inst->setConnectTimeout(1);
        $this->assertFalse($inst->no_comm_is_valid);
        $results = $inst->validate();
        $this->assertFalse($results[$email]);

        $inst->no_comm_is_valid = true;
        $results                = $inst->validate($email, 'not-allowed@example.org');
        $this->assertTrue($results[$email]);
    }

    public function testValidSenderWithLocalSmtpSinkWhitelisted()
    {
        // Mark skipped if smtp-sink is not running
        if (!$this->isSmtpSinkRunning()) {
            $this->markTestSkipped('smtp-sink is not running.');
        }

        $email = 'test@localhost';

        $inst = new Validator($email, 'allowed-sender@example.org');
        $inst->setConnectTimeout(1);
        $inst->setConnectPort(1025);
        $results = $inst->validate();

        $this->assertTrue($results[$email]);
    }

    public function testCatchAllConsideredValid()
    {
        // Mark skipped if smtp-sink is not running
        if (!$this->isSmtpSinkRunning()) {
            $this->markTestSkipped('smtp-sink is not running.');
        }

        $emails = [
            'user@localhost',
            'tester@localhost'
        ];

        $inst = new Validator($emails, 'allowed-sender@example.org');
        $inst->setConnectTimeout(1);
        $inst->setConnectPort(1025);
        $inst->enableCatchAllTest();

        $results = $inst->validate();
        foreach ($emails as $email) {
            $this->assertTrue($results[$email]);
        }
    }

    public function testCatchAllConsideredInvalid()
    {
        // Mark skipped if smtp-sink is not running
        if (!$this->isSmtpSinkRunning()) {
            $this->markTestSkipped('smtp-sink is not running.');
        }

        $email = 'test@localhost';

        $inst = new Validator($email, 'allowed-sender@example.org');
        $inst->setConnectTimeout(1);
        $inst->setConnectPort(1025);
        $inst->enableCatchAllTest();

        // If a catch-all is detected, the results are not considered valid
        $inst->setCatchAllValidity(false);

        $results = $inst->validate();
        $this->assertFalse($results[$email]);
    }
}
