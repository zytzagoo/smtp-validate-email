<?php

namespace SMTPValidateEmail\Tests\Unit;

use \SMTPValidateEmail\Tests\TestCase;
use \SMTPValidateEmail\Validator;

/**
 * Test cases for the Validator class.
 */
class ValidatorTest extends TestCase
{
    /**
     * Test exceptions exist and are throwable
     */
    public function testExceptions()
    {
        $ns         = '\\SMTPValidateEmail\\Exceptions';
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
            $fqcn = $ns . '\\' . $name;
            $this->expectException($fqcn);
            $exc = new $fqcn();
            throw $exc;
        }
    }

    public function testOldStyleMethods()
    {
        $instance = new Validator();
        $this->assertSame([], $instance->get_log(), 'old get_log() method still works');

        $instance->log[] = 'test';
        $this->assertContains('test', $instance->log, 'log contains `test`');

        $instance->clear_log();
        $this->assertSame([], $instance->log, 'log is cleared via old clear_log() method call');
        $this->assertNotContains('test', $instance->log, '`test` does not exist in log any more');

        // Unknown methods trigger E_USER_ERROR
        $this->expectException(\PHPUnit_Framework_Error::class);
        $instance->undefined_method();
    }

    public function testGetResults()
    {
        $inst     = new Validator();
        $results1 = $inst->get_results(); // test old style method call while at it
        $results2 = $inst->getResults(false);

        $this->assertArrayHasKey('domains', $results1, '`domains` key is missing.');
        $this->assertArrayNotHasKey('domains', $results2, '`domains` key present, but it shouldnt be.');
        $this->assertSame([], $results2, 'getResults() is not empty');
    }

    public function testSetEmails()
    {
        $emails   = [
            'email@example.org',
            'some@some-other-example.org'
        ];
        $expected = [
            'example.org' => ['email'],
            'some-other-example.org' => ['some']
        ];

        $inst = new Validator($emails);
        $this->assertAttributeEquals($expected, 'domains', $inst);
        $inst->setEmails([]);
        $this->assertAttributeEquals([], 'domains', $inst);
        $inst->setEmails($emails);
        $this->assertAttributeEquals($expected, 'domains', $inst);

        // Test setEmails with a single string
        $inst->setEmails('test@example.org');
        $this->assertAttributeEquals(['example.org' => ['test']], 'domains', $inst);
    }

    public function testSetSender()
    {
        $inst = new Validator();
        $this->assertAttributeEquals('user', 'from_user', $inst);
        $this->assertAttributeEquals('localhost', 'from_domain', $inst);

        $inst = new Validator([], 'email@example.org');
        $this->assertAttributeEquals('email', 'from_user', $inst);
        $this->assertAttributeEquals('example.org', 'from_domain', $inst);
    }

    public function testValidateMethodWithEmptyParams()
    {
        $inst = new Validator();
        $inst->validate();
        $this->assertAttributeEquals('user', 'from_user', $inst);
        $this->assertAttributeEquals('localhost', 'from_domain', $inst);
    }

    public function testSomeSettersGetters()
    {
        $inst = new Validator();

        // Defaults
        $this->assertSame(25, $inst->getConnectPort());
        $this->assertSame(10, $inst->getConnectTimeout());
        $this->assertFalse($inst->isCatchAllEnabled());
        $this->assertTrue($inst->getCatchAllValidity());

        $inst->setConnectPort(1025);
        $this->assertSame(1025, $inst->getConnectPort());

        $inst->setConnectTimeout(1);
        $this->assertSame(1, $inst->getConnectTimeout());

        $inst->enableCatchAllTest();
        $this->assertTrue($inst->isCatchAllEnabled());

        $inst->disableCatchAllTest();
        $this->assertFalse($inst->isCatchAllEnabled());

        $inst->setCatchAllValidity(false);
        $this->assertFalse($inst->getCatchAllValidity());
    }

    public function testDebugPrint()
    {
        $inst        = new Validator('test@invalid.localhost', 'sender@localhost');
        $inst->debug = true;
        $results     = $inst->validate();
        $this->expectOutputRegex('/connecting to invalid.localhost:25/i');
        $this->expectOutputRegex('/unable to connect\. exception caught:/i');
    }
}
