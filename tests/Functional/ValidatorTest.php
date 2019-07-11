<?php

namespace SMTPValidateEmail\Tests\Functional;

use SMTPValidateEmail\Exceptions\NoConnection;
use SMTPValidateEmail\Validator;
use SMTPValidateEmail\Tests\TestCase;

/**
 * Functional tests for Validator class.
 */
class ValidatorTest extends TestCase
{
    const CONNECT_PORT = 1025;

    public function testNoConnIsValid()
    {
        // Testing no connection, so using a non-existent host on .localhost
        $uniq    = uniqid();
        $host    = 'localhost';
        $email   = 'test@' . $uniq . '.' . $host;
        $timeout = 1;

        // Default should be false, and there's no smtp on localhost:25
        $inst = new Validator($email, 'sender@localhost');
        $inst->setConnectTimeout($timeout);
        $this->assertFalse($inst->no_conn_is_valid);
        $results = $inst->validate();
        $this->assertFalse($results[$email]);

        $log       = $inst->getLog();
        $last_line = \array_pop($log);
        $needle    = 'Unable to connect. Exception caught: Cannot open a connection to remote host';
        $this->assertStringContainsString($needle, $last_line);

        // When changed, it should change the returned result
        $inst->no_conn_is_valid = true;

        $results = $inst->validate();
        $this->assertTrue($results[$email]);
    }

    /**
     * Requires having the smtp-server running locally, configured to
     * reject any sender or only allow a certain sender (not much difference really).
     */
    public function testNoCommIsValidWithLocalSmtpRejectingOurSender()
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('SMTP server not running.');
        }

        // Re-configure mailhog to reject any sender we give it.
        $this->enableJimRejectingSenders();

        $email  = 'test@localhost';
        $sender = 'not-allowed@example.org';

        $inst = new Validator($email, $sender);
        $inst->setConnectPort(self::CONNECT_PORT);
        $inst->setConnectTimeout(1);
        $this->assertFalse($inst->no_comm_is_valid);
        $results = $inst->validate();
        $this->assertFalse($results[$email]);

        $inst->no_comm_is_valid = true;
        $results                = $inst->validate($email, $sender);
        $this->assertTrue($results[$email]);

        // Turns off smtp server re-configuration done at the beginning...
        $this->restoreSavedJimConfigOrTurnOffJim();
    }

    public function testValidSenderWithLocalSmtp()
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        $email = 'test@localhost';

        $inst = new Validator($email, 'allowed-sender@example.org');
        $inst->setConnectTimeout(1);
        $inst->setConnectPort(self::CONNECT_PORT);
        $results = $inst->validate();

        $this->assertTrue($results[$email]);
    }

    public function testCatchAllConsideredValid()
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        $emails = [
            'user@localhost',
            'tester@localhost',
        ];

        $inst = new Validator($emails, 'allowed-sender@example.org');
        $inst->setConnectTimeout(1);
        $inst->setConnectPort(self::CONNECT_PORT);
        $inst->enableCatchAllTest();

        $results = $inst->validate();
        foreach ($emails as $email) {
            $this->assertTrue($results[$email]);
        }
    }

    public function testCatchAllConsideredInvalid()
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        $email = 'test@localhost';

        $inst = new Validator($email, 'allowed-sender@example.org');
        $inst->setConnectTimeout(1);
        $inst->setConnectPort(self::CONNECT_PORT);
        $inst->enableCatchAllTest();

        // If a catch-all is detected, the results are not considered valid
        $inst->setCatchAllValidity(false);

        $results = $inst->validate();
        $this->assertFalse($results[$email]);
    }

    public function testRandomDomainWithNoMxRecords()
    {
        $uniq  = uniqid();
        $host  = $uniq . '.com';
        $email = 'test@' . $uniq . '.' . $host;

        // Default no_conn_is_valid should be false
        $inst = new Validator($email, 'hello@localhost');
        $this->assertFalse($inst->no_conn_is_valid);
        $results = $inst->validate();
        $this->assertFalse($results[$email]);

        $log       = $inst->getLog();
        $last_line = \array_pop($log);
        $needle    = 'Unable to connect. Exception caught: Cannot open a connection to remote host';
        $this->assertStringContainsString($needle, $last_line);
    }

    public function testIssue35()
    {
        $email   = 'blabla@blablabla.bla';
        $inst    = new Validator($email, 'hello@localhost');
        $results = $inst->validate();
        $this->assertFalse($results[$email]);

        $log       = $inst->getLog();
        $last_line = \array_pop($log);
        $needle    = 'Unable to connect. Exception caught: Cannot open a connection to remote host';
        $this->assertStringContainsString($needle, $last_line);
    }

    public function testNoopsSentByDefault()
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        $email = 'test@localhost';

        $inst = new Validator($email, 'allowed-sender@example.org');
        $inst->setConnectTimeout(1);
        $inst->setConnectPort(self::CONNECT_PORT);
        $inst->validate();
        $log = $inst->getLog();
        $this->assertRegexp('/NOOP/', implode('', $log));
    }

    public function testNoopsDisabled()
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        $email = 'test@localhost';

        $inst = new Validator($email, 'allowed-sender@example.org');
        $inst->setConnectTimeout(1);
        $inst->setConnectPort(self::CONNECT_PORT);
        $inst->sendNoops(false);
        $inst->validate();
        $log = $inst->getLog();
        $this->assertNotRegexp('/NOOP/', implode('', $log));
    }

    public function testRejectedConnection()
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        $this->expectException(NoConnection::class);

        $this->makeSmtpRejectConnections();

        $test = function () {
            $email = 'chaos@localhost';
            $inst  = new Validator($email, 'alice@localhost');
            $inst->setConnectTimeout(1);
            $inst->setConnectPort(self::CONNECT_PORT);
            $results = $inst->validate();
        };

        $test();

        // Turns off smtp server re-configuration done at the beginning...
        $this->restoreSavedJimConfigOrTurnOffJim();
    }

    public function testDisconnections()
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        // Hopefully we hit at least one of our exceptions...
        $this->expectException(\SMTPValidateEmail\Exceptions\Exception::class);

        $this->makeSmtpRandomlyDisconnect();

        $test = function () {
            $email = 'disconnector@localhost';
            $inst  = new Validator($email, 'checker@localhost');
            $inst->setConnectTimeout(1);
            $inst->setConnectPort(self::CONNECT_PORT);
            $results = $inst->validate();
        };

        for ($i = 0; $i <= 1000; $i++) {
            $test();
        }

        // Turns off smtp server re-configuration done at the beginning...
        $this->restoreSavedJimConfigOrTurnOffJim();
    }
}
