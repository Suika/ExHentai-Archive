<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * HTTP client for the running app (nginx on port 6767 -> php-fpm pools).
 */
final class Api
{
    public const BASE = 'http://127.0.0.1:6767';

    /**
     * Calls api.php and returns the decoded JSON response ("ret", "data"/"message").
     */
    public static function call(string $action, array $params = array(), string $method = 'GET'): array
    {
        $response = self::request($action, $params, $method);
        $json = json_decode($response['body'], true);
        Assert::assertIsArray($json, "api.php?action=$action did not return JSON (HTTP {$response['status']}):\n"
            .substr($response['body'], 0, 2000));

        return $json;
    }

    /** Calls api.php and returns "data", failing the test when "ret" is not true. */
    public static function data(string $action, array $params = array(), string $method = 'GET')
    {
        $json = self::call($action, $params, $method);
        Assert::assertTrue($json['ret'], "api.php?action=$action failed: ".($json['message'] ?? ''));

        return $json['data'];
    }

    /**
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public static function request(string $action, array $params = array(), string $method = 'GET'): array
    {
        $params = array('action' => $action) + $params;
        if ($method === 'GET') {
            return self::get('/api.php?'.http_build_query($params));
        }

        return self::send(self::BASE.'/api.php', array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params)));
    }

    public static function get(string $path): array
    {
        return self::send(self::BASE.$path, array());
    }

    private static function send(string $url, array $options): array
    {
        $headers = array();
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ));
        $body = curl_exec($ch);
        if ($body === false) {
            Assert::fail("Request to $url failed: ".curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return array('status' => $status, 'headers' => $headers, 'body' => $body);
    }
}
