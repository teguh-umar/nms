<?php

namespace GlpiPlugin\Nms\Driver\Zabbix;

/**
 * Minimal Zabbix JSON-RPC client (transport layer for the Zabbix driver).
 *
 * Auth by version (Zabbix API docs): API tokens exist since 5.4; the
 * "Authorization: Bearer" header is accepted from 6.4; the body "auth"
 * property was removed in 7.2. So from HEADER_AUTH_FROM the header is used
 * (works through the newest release), below it the body "auth" property.
 * apiinfo.version and user.login are always sent without any auth.
 *
 * The transport is injected: callable(string $url, string $body,
 * array<string,string> $headers): array{status: int, body: string}.
 */
final class JsonRpc
{
    public const HEADER_AUTH_FROM = '6.4';
    public const ENDPOINT_FILE    = 'api_jsonrpc.php';

    private string $url;
    private string $token;
    private string $username;
    private string $password;

    /** @var callable(string, string, array<string, string>): array{status: int, body: string} */
    private $transport;

    private int $nextId = 1;
    private ?string $version = null;
    private ?string $session = null;

    public function __construct(string $url, #[\SensitiveParameter] string $token, string $username, #[\SensitiveParameter] string $password, callable $transport)
    {
        $this->url       = self::endpoint($url);
        $this->token     = $token;
        $this->username  = $username;
        $this->password  = $password;
        $this->transport = $transport;
    }

    public static function endpoint(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (str_ends_with($url, '/' . self::ENDPOINT_FILE)) {
            return $url;
        }
        return $url . '/' . self::ENDPOINT_FILE;
    }

    public function authMode(): string
    {
        return $this->token !== '' ? 'token' : 'login';
    }

    public function version(): string
    {
        if ($this->version === null) {
            $result = $this->send('apiinfo.version', [], null);
            if (!is_string($result) || $result === '') {
                throw new ZabbixException('Zabbix returned no version');
            }
            $this->version = $result;
        }
        return $this->version;
    }

    /** @return mixed the JSON-RPC "result" */
    public function call(string $method, array $params): mixed
    {
        // apiinfo.version always goes first on the wire, in token and login mode.
        $this->version();
        return $this->send($method, $params, $this->credential());
    }

    /** Ends a login session; a no-op for token mode or when never logged in. */
    public function logout(): void
    {
        if ($this->authMode() === 'token' || $this->session === null) {
            return;
        }
        $session       = $this->session;
        $this->session = null;
        try {
            $this->send('user.logout', [], $session);
        } catch (ZabbixException $e) {
            // Best effort: an expired session is not an error worth surfacing.
        }
    }

    private function credential(): string
    {
        if ($this->authMode() === 'token') {
            return $this->token;
        }
        if ($this->session === null) {
            $result = $this->send('user.login', ['username' => $this->username, 'password' => $this->password], null);
            if (!is_string($result) || $result === '') {
                throw new ZabbixException('Zabbix login returned no session');
            }
            $this->session = $result;
        }
        return $this->session;
    }

    private function send(string $method, array $params, #[\SensitiveParameter] ?string $credential): mixed
    {
        // Resolve the version first so request ids follow the order on the wire.
        $useHeader = $credential !== null && version_compare($this->version(), self::HEADER_AUTH_FROM, '>=');
        $request = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params === [] ? new \stdClass() : $params,
            'id'      => $this->nextId++,
        ];
        $headers = ['Content-Type' => 'application/json-rpc'];

        if ($credential !== null) {
            if ($useHeader) {
                $headers['Authorization'] = 'Bearer ' . $credential;
            } else {
                $request['auth'] = $credential;
            }
        }

        $body = json_encode($request);
        if ($body === false) {
            throw new ZabbixException('Zabbix request could not be encoded');
        }

        try {
            $response = ($this->transport)($this->url, $body, $headers);
        } catch (\Throwable $e) {
            // No previous exception: its message and trace args hold the raw secrets.
            throw new ZabbixException($this->redact('Zabbix request failed: ' . $e->getMessage()));
        }

        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new ZabbixException(sprintf('Zabbix HTTP error %d', $status));
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new ZabbixException('Zabbix returned a non-JSON response');
        }
        if (isset($decoded['error'])) {
            throw new ZabbixException($this->redact($this->describeError($decoded['error'])));
        }
        if (!array_key_exists('result', $decoded)) {
            throw new ZabbixException('Zabbix response has no result');
        }
        return $decoded['result'];
    }

    private function describeError(mixed $error): string
    {
        if (!is_array($error)) {
            return 'Zabbix error: ' . (is_scalar($error) ? (string) $error : 'unknown');
        }
        $code    = is_scalar($error['code'] ?? null) ? (string) $error['code'] : '?';
        $message = is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'no message';
        $data    = is_scalar($error['data'] ?? null) ? (string) $error['data'] : '';
        return sprintf('Zabbix error %s: %s%s', $code, $message, $data !== '' ? ' ' . $data : '');
    }

    /** Scrub the token, password and session so they never leak in a message. */
    private function redact(string $text): string
    {
        foreach ([$this->token, $this->password, (string) $this->session] as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }
        return $text;
    }
}
