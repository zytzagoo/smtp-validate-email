<?php

namespace SMTPValidateEmail\Tests;

/**
 * Abstract base class for all test case implementations.
 *
 * @package ZWF\GoogleFontsOptimizer\Tests
 */
abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    protected function isSmtpSinkRunning()
    {
        $running = false;

        // @codingStandardsIgnoreLine
        $fp = /** @scrutinizer ignore-unhandled */ @fsockopen('localhost', 1025);
        if (false !== $fp) {
            $running = true;
            fclose($fp);
        }

        return $running;
    }
}
