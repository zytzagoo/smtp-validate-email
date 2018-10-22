<?php

namespace SMTPValidateEmail\Tests;

/**
 * Abstract base class for all test case implementations.
 *
 * @package ZWF\GoogleFontsOptimizer\Tests
 */
abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    protected function isSmtpServerRunning()
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

    protected function callSmtpServerApi($method, $endpoint = '/', $data = null)
    {
        $server = 'http://0.0.0.0:8025';
        $url    = $server . $endpoint;

        $options = [
            'http' => [
                'method'  => $method,
                'content' => json_encode($data),
                'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n"
            ],
        ];

        $context  = stream_context_create($options);
        $result   = file_get_contents($url, false, $context);
        $response = json_decode($result);

        return $response;
    }

    protected function disableJim()
    {
        $response = $this->callSmtpServerApi('DELETE', '/api/v2/jim');

        return $response;
    }

    protected function enableJim($config = null)
    {
        if (null === $config) {
            $config = $this->getJimRejectSendersConfig();
        }

        $response = $this->callSmtpServerApi('POST', '/api/v2/jim', $config);

        return $response;
    }

    protected function getJimRejectSendersConfig()
    {
        $options = [
            'DisconnectChance' => 0,
            'AcceptChance' => 1,
            'LinkSpeedAffect' => 0,
            'LinkSpeedMin' => 1024,
            'LinkSpeedMax' => 10240,
            'RejectSenderChance' => 1,
            'RejectRecipientChance' => 0,
            'RejectAuthChance' => 0,
        ];

        return $options;
    }
}
