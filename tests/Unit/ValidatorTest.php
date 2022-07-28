<?php declare(strict_types=1);

namespace SMTPValidateEmail\Tests\Unit;

use SMTPValidateEmail\Tests\TestCase;
use SMTPValidateEmail\Validator;

use function setlocale;

/**
 * Test cases for the Validator class.
 */
class ValidatorTest extends TestCase
{
    /**
     * Test exceptions exist and are throwable
     */
    public function testExceptions(): void
    {
        $ns = '\\SMTPValidateEmail\\Exceptions';
        $exceptions = [
            'Exception',
            'NoConnection',
            'NoHelo',
            'NoMailFrom',
            'NoResponse',
            'NoTimeout',
            'NoTLS',
            'SendFailed',
            'Timeout',
            'UnexpectedResponse'
        ];
        foreach ($exceptions as $name) {
            $exceptionClassName = $ns . '\\' . $name;

            $this->expectException($exceptionClassName);
            throw new $exceptionClassName();
        }
    }

    public function testOldStyleMethods(): void
    {
        $instance = new Validator();
        $this->assertSame([], $instance->get_log(), 'old get_log() method still works');

        $instance->log[] = 'test';
        $this->assertContains('test', $instance->log, 'log contains `test`');

        $instance->clear_log();
        $this->assertSame([], $instance->log, 'log is cleared via old clear_log() method call');
        $this->assertNotContains('test', $instance->log, '`test` does not exist in log any more');

        // Unknown methods trigger E_USER_ERROR
        $this->expectError();
        $instance->undefined_method();
    }

    public function testGetResults(): void
    {
        $inst = new Validator();
        $results1 = $inst->get_results(); // test old style method call while at it
        $results2 = $inst->getResults(false);

        $this->assertArrayHasKey('domains', $results1, '`domains` key is missing.');
        $this->assertArrayNotHasKey('domains', $results2, '`domains` key present, but it should not be.');
        $this->assertSame([], $results2, 'getResults() is not empty');
    }

    public function testSomeSettersGetters(): void
    {
        $inst = new Validator();

        // Defaults
        $this->assertSame(25, $inst->getConnectPort());
        $this->assertSame(10, $inst->getConnectTimeout());
        $this->assertFalse($inst->isCatchAllEnabled());
        $this->assertTrue($inst->getCatchAllValidity());

        $inst->setConnectPort(12345);
        $this->assertSame(12345, $inst->getConnectPort());

        $inst->setConnectTimeout(1);
        $this->assertSame(1, $inst->getConnectTimeout());

        $inst->enableCatchAllTest();
        $this->assertTrue($inst->isCatchAllEnabled());

        $inst->disableCatchAllTest();
        $this->assertFalse($inst->isCatchAllEnabled());

        $inst->setCatchAllValidity(false);
        $this->assertFalse($inst->getCatchAllValidity());
    }

    public function testBindAddressParsingSettingAndGetting(): void
    {
        $inst = new Validator();

        $testcases = [
            '0' => '0:0',
            '0:0' => '0:0',
            '0:7000' => '0:7000',
            '127.0.0.1' => '127.0.0.1:0',
            '[::]' => '[::]:0',
            '[::]:0' => '[::]:0',
            '[2001:db8::1]:7000' => '[2001:db8::1]:7000',
            '[2001:569:be89:6200:8936:7907:fcf1:961b]' => '[2001:569:be89:6200:8936:7907:fcf1:961b]:0',
            // Allowing ipv6 without port and brackets for parity with what is allowed with ipv4
            '2001:569:be89:6200:8936:7907:fcf1:961b' => '[2001:569:be89:6200:8936:7907:fcf1:961b]:0',
        ];

        foreach ($testcases as $bindAddress => $expectedResult) {
            // Php casts array keys internally: https://www.php.net/manual/en/language.types.array.php so we undo it
            if (is_int($bindAddress)) {
                $bindAddress = (string) $bindAddress;
            }

            $inst->setBindAddress($bindAddress);
            $this->assertEquals($expectedResult, $inst->getBindAddress());
        }
    }

    public function testDebugPrint(): void
    {
        $inst = new Validator('test@non-existing.domain.', 'sender@localhost');
        $inst->debug = true;
        $inst->validate();
        $this->expectOutputRegex('/connecting to non-existing.domain.:25/i');
        $this->expectOutputRegex('/unable to connect\. exception caught:/i');
    }

    public function testSendNoopsOption(): void
    {
        $inst = new Validator();

        // Sending NOOPs by default.
        $this->assertTrue($inst->sendingNoops());

        // Testing that getter/setter works...
        $inst->sendNoops(false);
        $this->assertFalse($inst->sendingNoops());
        $inst->sendNoops(true);
        $this->assertTrue($inst->sendingNoops());
    }

    public function testGetLogDateDoesNotExplodeOnFrLocale(): void
    {
        // Get current and set new locale (hopefully)
        $currentLocale = setlocale(LC_NUMERIC, '0');
        // \setlocale(LC_ALL, 'fr_FR.UTF-8');
        setlocale(LC_NUMERIC, 'fr_FR.UTF-8');

        $inst = new Validator();
        $date = $inst->getLogDate();

        $this->assertIsString($date);
        $this->assertNotEmpty($date);

        // Restore locale to what it was (hopefully)
        setlocale(LC_NUMERIC, $currentLocale);
    }
}
