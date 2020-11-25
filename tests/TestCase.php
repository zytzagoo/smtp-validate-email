<?php

namespace SMTPValidateEmail\Tests;

/**
 * Abstract base class for all test case implementations.
 *
 * @package ZWF\GoogleFontsOptimizer\Tests
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    const JIM_API_ENDPOINT = '/api/v2/jim';

    protected function isSmtpServerRunning()
    {
        $running = false;

        // @codingStandardsIgnoreLine
        $fp = /** @scrutinizer ignore-unhandled */ @fsockopen('localhost', static::CONNECT_PORT);
        if (false !== $fp) {
            $running = true;
            fclose($fp);
        }

        return $running;
    }

    private function callSmtpServerApi($method, $endpoint = '/', $data = null)
    {
        $server = 'http://127.0.0.1:8025';
        $url    = $server . $endpoint;

        $options = [
            'http' => [
                'method'  => $method,
                'content' => json_encode($data),
                'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($options);
        $result  = file_get_contents($url, false, $context);
        // var_dump($http_response_header, $result);
        $response = json_decode($result, true);

        return $response;
    }

    protected function disableJim()
    {
        $response = $this->callSmtpServerApi('DELETE', self::JIM_API_ENDPOINT);

        return $response;
    }

    public function makeSmtpRandomlyDisconnect()
    {
        return $this->changeJimConfig($this->getDisconnectConfig());
    }

    public function makeSmtpRejectConnections()
    {
        return $this->changeJimConfig($this->getRefusedConnectionsConfig());
    }

    public function enableJimRejectingSenders()
    {
        return $this->changeJimConfig($this->getJimRejectSendersConfig());
    }

    private function changeJimConfig(array $config)
    {
        // Checking if Jim is running already
        $running = $this->callSmtpServerApi('GET', self::JIM_API_ENDPOINT);
        if ($running) {
            // Jim is there, we need to re-configure it using PUT
            $method = 'PUT';
        } else {
            // Not here yet, invite him by POSTing the config we want
            $method = 'POST';
        }

        $response = $this->callSmtpServerApi($method, self::JIM_API_ENDPOINT, $config);

        return $response;
    }

    private function getJimRejectSendersConfig()
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

    private function getRefusedConnectionsConfig()
    {
        $options = [
            'DisconnectChance' => 1,
            'AcceptChance' => 0,
            'LinkSpeedAffect' => 0,
            'LinkSpeedMin' => 1024,
            'LinkSpeedMax' => 10240,
            'RejectSenderChance' => 0,
            'RejectRecipientChance' => 0,
            'RejectAuthChance' => 0,
        ];

        return $options;
    }

    private function getDisconnectConfig()
    {
        $options = [
            'DisconnectChance' => 0.9,
            'AcceptChance' => 0.5,
            'LinkSpeedAffect' => 0,
            'LinkSpeedMin' => 1024,
            'LinkSpeedMax' => 10240,
            'RejectSenderChance' => 0.5,
            'RejectRecipientChance' => 0.5,
            'RejectAuthChance' => 0.5,
        ];

        return $options;
    }
}
