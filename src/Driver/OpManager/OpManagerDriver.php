<?php

namespace GlpiPlugin\Nms\Driver\OpManager;

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\Capabilities;
use GlpiPlugin\Nms\Driver\Driver;
use GlpiPlugin\Nms\Driver\UnsupportedOperation;

/**
 * OpManager brand driver. Wraps the OpManager REST API (ext-curl) behind the
 * Driver interface. listDevices()/listActiveAlarms() return the same array
 * shapes that 1.3.2's DeviceParser/AlarmParser return.
 */
final class OpManagerDriver implements Driver
{
    public const DEVICE_LIST_PATH = '/api/json/device/listDevices';
    public const ALARM_LIST_PATH  = '/api/json/alarm/listAlarms';
    public const ADD_NOTES_PATH   = '/api/json/alarm/addNotes';
    public const CLEAR_ALARM_PATH = '/api/json/alarm/clearAlarm';
    public const ACK_ALARM_PATH   = '/api/json/alarm/acknowledgeAlarm';

    /** @var callable(string, array<string, string>, array<string, string>, ?array<string, string>): array{status: int, body: string} */
    private $transport;

    public function __construct(
        private Connection $connection,
        ?callable $transport = null,
        private ?Capabilities $capabilities = null,
        private int $timeout = 30,
    ) {
        $this->transport = $transport ?? $this->curlTransport(...);
        $this->capabilities = $this->capabilities ?? new Capabilities(true, true, true, true, true, true, true, true);
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        try {
            $this->listDevices();
            return ['ok' => true, 'message' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => ResponseGuard::errorMessage(['message' => $e->getMessage()])];
        }
    }

    /** @return list<array<string, mixed>> same shape as DeviceParser::parse() */
    public function listDevices(): array
    {
        return DeviceParser::parse($this->get(self::DEVICE_LIST_PATH));
    }

    /** @return list<array<string, mixed>> same shape as AlarmParser::parse() */
    public function listActiveAlarms(): array
    {
        return AlarmParser::parse($this->get(self::ALARM_LIST_PATH, ['alertType' => 'ActiveAlarms']));
    }

    /**
     * 1.3.2 alarmByEntity(): listAlarms filtered by entity, WITHOUT the ActiveAlarms
     * filter, so a cleared alarm is still returned. Callers compare alarm_key.
     */
    public function alarmByKey(string $alarm_key, string $entity_key = ''): ?array
    {
        $entity = $entity_key !== '' ? $entity_key : $alarm_key;
        foreach (AlarmParser::parse($this->get(self::ALARM_LIST_PATH, ['entity' => $entity])) as $alarm) {
            if ($alarm['entity_key'] === $entity) {
                return $alarm;
            }
        }
        return null;
    }

    public function addNote(string $alarm_key, string $text): void
    {
        $this->checkCapability('note');
        $this->post(self::ADD_NOTES_PATH, ['entity' => $alarm_key, 'notes' => $text]);
    }

    public function acknowledge(string $alarm_key): void
    {
        $this->checkCapability('ack');
        $this->post(self::ACK_ALARM_PATH, ['entity' => $alarm_key]);
    }

    public function clear(string $alarm_key): void
    {
        $this->checkCapability('clear');
        $this->post(self::CLEAR_ALARM_PATH, ['entity' => $alarm_key]);
    }

    private function checkCapability(string $cap): void
    {
        if (!$this->capabilities->$cap) {
            throw new UnsupportedOperation("$cap not supported by {$this->connection->brand}");
        }
    }

    /**
     * @param array<string, string> $form
     */
    private function post(string $path, array $form): void
    {
        $data = json_decode($this->request($path, [], $form), true);
        if (is_array($data) && array_key_exists('error', $data)) {
            throw new ClientException(sprintf('OpManager %s refused: %s', $path, ResponseGuard::errorMessage($data['error'])));
        }
    }

    private function get(string $path, array $query = []): string
    {
        return $this->request($path, $query);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param array<string, string>|null $form POST body; null = GET
     * @return array{status: int, body: string}
     */
    private function request(string $path, array $query = [], ?array $form = null): string
    {
        if ($this->connection->url === '' || $this->connection->secret('api_key') === '') {
            throw new ClientException('OpManager URL or API key is not configured');
        }
        try {
            $response = ($this->transport)(rtrim($this->connection->url, '/') . $path, $query, ['apiKey' => $this->connection->secret('api_key')], $form);
        } catch (\Throwable $e) {
            throw new ClientException(ResponseGuard::redact(sprintf('OpManager %s failed: %s', $path, $e->getMessage())));
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ClientException(sprintf('OpManager %s answered HTTP %d', $path, $response['status']));
        }
        return $response['body'];
    }

    /**
     * Plain ext-curl transport.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param array<string, string>|null $form POST body; null = GET
     * @return array{status: int, body: string}
     */
    private function curlTransport(string $url, array $query, array $headers, ?array $form = null): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->connection->verify_tls === 1,
            CURLOPT_SSL_VERIFYHOST => $this->connection->verify_tls === 1 ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($form !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }
}
