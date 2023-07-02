<?php

namespace App;

use App\Entities\ProxyTopic;
use App\Utilities\Logger;
use PhpMqtt\Client\Exceptions\ConfigurationInvalidException;
use PhpMqtt\Client\Exceptions\ConnectingToBrokerFailedException;
use PhpMqtt\Client\Exceptions\DataTransferException;
use PhpMqtt\Client\Exceptions\InvalidMessageException;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\Exceptions\ProtocolNotSupportedException;
use PhpMqtt\Client\Exceptions\ProtocolViolationException;
use PhpMqtt\Client\Exceptions\RepositoryException;
use PhpMqtt\Client\MqttClient;

class Fade
{
    use Logger;

    /**
     * Current known brightness level. null when first booted and no events received to set the value.
     */
    private ?int $brightness = null;

    /**
     * Current known power state. null when first booted and no events received to set the value.
     */
    private ?bool $power = null;

    /**
     * Timestamp with the last time the brightness was updated.
     */
    private ?int $brightnessChangedAt = null;

    /**
     * Instance of the MqttClient with broker connection.
     */
    private MqttClient $mqtt;

    /**
     * Instance of the ProxyTopic class with details for the power topic and proxy.
     */
    private ProxyTopic $powerTopic;

    /**
     * Instance of the ProxyTopic class with details for the brightness topic and proxy.
     */
    private ProxyTopic $brightnessTopic;

    /**
     * Brightness statistic topic name, brightness in UI should be retrieved from here to avoid slider showing fading.
     */
    private string $brightnessStatisticTopic;

    /**
     * Fade constructor.
     *
     * @throws ProtocolNotSupportedException
     */
    public function __construct()
    {
        $this->mqtt = new MqttClient(
            getenv('MQTT_BROKER_HOSTNAME'),
            getenv('MQTT_BROKER_PORT'),
            getenv('MQTT_CLIENT_ID')
        );

        $this->powerTopic = new ProxyTopic(
            getenv('POWER_COMMAND_TOPIC_ORIGINAL'),
            getenv('POWER_COMMAND_TOPIC_PROXY')
        );

        $this->brightnessTopic = new ProxyTopic(
            getenv('BRIGHTNESS_COMMAND_TOPIC_ORIGINAL'),
            getenv('BRIGHTNESS_COMMAND_TOPIC_PROXY')
        );

        $this->brightnessStatisticTopic = getenv('BRIGHTNESS_STATISTIC_TOPIC');
    }

    /**
     * Connect to MQTT, listen for events and republish with fade values.
     *
     * @throws ConfigurationInvalidException
     * @throws ConnectingToBrokerFailedException
     * @throws DataTransferException
     * @throws InvalidMessageException
     * @throws MqttClientException
     * @throws ProtocolViolationException
     * @throws RepositoryException
     */
    public function main(): int
    {
        $this->mqtt->connect();

        $this->mqtt->subscribe(
            $this->powerTopic->proxy,
            function ($topic, $message) {
                $power = $message === 'true';
                $this->log('Received Power: '.($power ? 'On' : 'Off'));

                if ($this->brightnessChangedAt && $this->brightnessChangedAt >= (time() - 2)) {
                    $this->log('Brightness changed recently, skipping power publish.');

                    return;
                }

                if ($power === $this->power) {
                    return;
                }

                if ($power) {
                    $this->fade(0, 100);

                    return;
                }

                $this->brightness !== null
                    ? $this->fade($this->brightness, 0)
                    : $this->off();
            }
        );

        $this->mqtt->subscribe(
            $this->brightnessTopic->proxy,
            function ($topic, int $message) {
                $this->log('Received Brightness: '.$message);

                if ($this->brightness === $message) {
                    return;
                }

                if ($this->brightness !== null) {
                    $this->fade($this->brightness, $message);

                    return;
                }

                $this->setBrightnessStat($message);
                $this->setBrightness($message);
                $this->brightness = $message;
            }
        );

        $this->log('Subscribed');

        $this->mqtt->loop();
        $this->mqtt->disconnect();

        return 0;
    }

    /**
     * Publish an event to switch off the lights and update the local state.
     *
     * @throws DataTransferException
     * @throws RepositoryException
     */
    private function off(): void
    {
        $this->log('Published Power: Off');
        $this->mqtt->publish($this->powerTopic->original, 'OFF');
        $this->brightness = 0;
        $this->power = false;
    }

    /**
     * Publish an event to set the brightness and update the power state.
     *
     * @return void
     *
     * @throws DataTransferException
     * @throws RepositoryException
     */
    private function setBrightness(int $value)
    {
        $this->mqtt->publish($this->brightnessTopic->original, $value);
        $this->log('Published Brightness: '.$value);
        $this->brightnessChangedAt = time();

        $this->power = $value > 1;
    }

    /**
     * Publish an event with the set brightness level. This can be used separately to brightness to avoid the UI showing
     * brightness fading.
     *
     * @throws DataTransferException
     * @throws RepositoryException
     */
    private function setBrightnessStat(int $value): void
    {
        $this->mqtt->publish($this->brightnessStatisticTopic, $value);
    }

    /**
     * Fade from the $from value to the $to value in either direction.
     *
     * @throws DataTransferException
     * @throws RepositoryException
     */
    private function fade(int $from, int $to): void
    {
        $this->setBrightnessStat($to);
        $this->log("Fading: $from to $to");

        $step = 2;
        $step = ($from < $to) ? $step : $step * -1;

        while ($from !== $to) {
            $from += $step;

            if ($step > 0 && $from > $to) {
                $from = $to;
            } elseif ($step < 0 && $from < $to) {
                $from = $to;
            }

            $this->setBrightness($from);
        }

        if ($to === 0) {
            $this->off();
        }

        $this->brightness = $to;
    }
}
