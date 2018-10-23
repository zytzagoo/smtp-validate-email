<?php

namespace SMTPValidateEmail\Tests;

/**
 * Abstract base class for all test case implementations.
 *
 * @package ZWF\GoogleFontsOptimizer\Tests
 */
abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    const JIM_API_ENDPOINT = '/api/v2/jim';

    protected $saved_jim_config = [];

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

    public function restoreSavedJimConfigOrTurnOffJim()
    {
        $result = false;

        if ($this->saved_jim_config) {
            $result = $this->changeJimConfig($this->saved_jim_config);
        } else {
            // If we don't have a saved config, there's nothing to restore,
            // but we have to turn off jim since someone somewhere turned it on...
            $result = $this->disableJim();
        }

        return $result;
    }

    private function callSmtpServerApi($method, $endpoint = '/', $data = null)
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
        $result   = @file_get_contents($url, false, $context); // @codingStandardsIgnoreLine
        $response = json_decode($result, true);

        return $response;
    }

    private function disableJim()
    {
        $response = $this->callSmtpServerApi('DELETE', self::JIM_API_ENDPOINT);

        return $response;
    }

    public function makeSmtpRejectConnections()
    {
        $this->saveCurrentJimConfig();

        return $this->changeJimConfig($this->getRefusedConnectionsConfig());
    }

    public function enableJimRejectingSenders()
    {
        $this->saveCurrentJimConfig();

        return $this->changeJimConfig($this->getJimRejectSendersConfig());
    }

    private function saveCurrentJimConfig()
    {
        $cfg = $this->callSmtpServerApi('GET', self::JIM_API_ENDPOINT);
        if ($cfg) {
            $this->saved_jim_config = $cfg;
        }
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
}
