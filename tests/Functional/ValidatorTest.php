<?php declare(strict_types=1);

namespace SMTPValidateEmail\Tests\Functional;

use SMTPValidateEmail\Exceptions\Exception;
use SMTPValidateEmail\Exceptions\NoConnection;
use SMTPValidateEmail\Validator;
use SMTPValidateEmail\Tests\TestCase;
use function array_pop;

/**
 * Functional tests for Validator class.
 */
class ValidatorTest extends TestCase
{
    public const CONNECT_PORT = 1025;

    public function testNoConnIsValid(): void
    {
        // Testing no connection, so using a non-existent host on .localhost
        $uniq    = uniqid();
        $host    = 'localhost';
        $email   = 'test@' . $uniq . '.' . $host . '.'; // Malformed on purpose, so MX resolution bombs very early
        $timeout = 1;

        // Default should be false, and there's no smtp on localhost:25
        $inst = new Validator($email, 'sender@localhost');
        $inst->setConnectTimeout($timeout);
        $this->assertFalse($inst->no_conn_is_valid);
        $results = $inst->validate();
        $this->assertFalse($results[$email]);

        $log       = $inst->getLog();
        $last_line = array_pop($log);
        $needle    = 'Unable to connect. Exception caught: Cannot open a connection to remote host';
        $this->assertStringContainsString($needle, $last_line);

        // When changed, it should change the returned result
        $inst->no_conn_is_valid = true;

        $results = $inst->validate();
        $this->assertTrue($results[$email]);
    }

    public function testBindAddressWorking(): void
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        $this->disableJim(); // Prevent random misbehavior

        $email = 'test@localhost';
        $inst = new Validator($email, 'hello@localhost');
        $inst->setConnectTimeout(1);
        $inst->setConnectPort(self::CONNECT_PORT);

        $bindPort = self::CONNECT_PORT + 1000;
        $bindAddress = '127.0.0.1:' . $bindPort;

        $inst->setBindAddress($bindAddress);
        $inst->validate();

        // Assert smtp server's log file shows communication happened on configured $bindAddress
        $logFileContents = file_get_contents('/tmp/mailhog.log');
        $this->assertStringContainsString('[SMTP ' . $bindAddress . ']', $logFileContents);
    }

    /**
     * Requires having the smtp-server running locally, configured to
     * reject any sender or only allow a certain sender (not much difference really).
     */
    public function testNoCommIsValidWithLocalSmtpRejectingOurSender(): void
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
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
        $this->disableJim();
    }

    public function testValidSenderWithLocalSmtp(): void
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

    public function testCatchAllConsideredValid(): void
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

    public function testCatchAllConsideredInvalid(): void
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

    public function testRandomDomainWithNoMxRecords(): void
    {
        $uniq  = uniqid();
        $host  = $uniq . '.com';
        $email = 'test@' . $uniq . '.' . $host . '.'; // Dot in the end to bomb MX query very quickly

        // Default no_conn_is_valid should be false
        $inst = new Validator($email, 'hello@localhost');
        $this->assertFalse($inst->no_conn_is_valid);
        $results = $inst->validate();
        $this->assertFalse($results[$email]);

        $log       = $inst->getLog();
        $last_line = array_pop($log);
        $needle    = 'Unable to connect. Exception caught: Cannot open a connection to remote host';
        $this->assertStringContainsString($needle, $last_line);
    }

    public function testIssue35(): void
    {
        // Email on a non-existing domain taking forever and/or "crashing".

        // Prevent random misbehavior
        if ($this->isSmtpServerRunning()) {
            $this->disableJim();
        }

        // Note: the trailing period on a non-existing domain name makes the MX
        // query fail fast as opposed to the DNS resolver doing relative-to-absolute-fqdn appends/checks
        // with common suffixes etc. (which all fail in the end anyway).
        $email = 'blabla@blablabla.bla';
        $inst = new Validator($email, 'hello@localhost');
        $results = $inst->validate();
        $this->assertFalse($results[$email]);

        $log = $inst->getLog();
        $last_line = array_pop($log);
        $needle = 'Unable to connect. Exception caught: Cannot open a connection to remote host';
        $this->assertStringContainsString($needle, $last_line);
    }

    public function testNoopsSentByDefault(): void
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
        $this->assertMatchesRegularExpression('/NOOP/', implode('', $log));
    }

    public function testNoopsDisabled(): void
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
        $this->assertDoesNotMatchRegularExpression('/NOOP/', implode('', $log));
    }

    public function testRejectedConnection(): void
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        $this->expectException(NoConnection::class);

        // Configure Jim how we need it
        $this->makeSmtpRejectConnections();

        $test = static function () {
            $email = 'chaos@localhost';
            $inst  = new Validator($email, 'alice@localhost');
            $inst->setConnectTimeout(1);
            $inst->setConnectPort(self::CONNECT_PORT);
            $results = $inst->validate();
        };

        $test();

        // Disable jim when done
        $this->disableJim();
    }

    public function testDisconnections(): void
    {
        if (!$this->isSmtpServerRunning()) {
            $this->markTestSkipped('smtp server not running.');
        }

        // Hopefully we hit at least one of our exceptions...
        $this->expectException(Exception::class);

        // Configure Jim how we need it
        $this->makeSmtpRandomlyDisconnect();

        $test = static function () {
            $email = 'disconnector@localhost';
            $inst  = new Validator($email, 'checker@localhost');
            $inst->setConnectTimeout(1);
            $inst->setConnectPort(self::CONNECT_PORT);
            $results = $inst->validate();
        };

        for ($i = 0; $i <= 1000; $i++) {
            $test();
        }

        // Disable jim when done
        $this->disableJim();
    }
}
