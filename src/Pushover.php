<?php
namespace ZoteroBridge;

/** Sends failure alerts without exposing Pushover credentials in logs or errors. */
final class Pushover
{
    public static function configured()
    {
        return self::environment('PUSHOVER_APP_TOKEN') !== '' && self::environment('PUSHOVER_USER_KEY') !== '';
    }

    /** Optional transport callback keeps notification behavior testable offline. */
    public static function send($title, $message, $transport = null)
    {
        $token = self::environment('PUSHOVER_APP_TOKEN');
        $user = self::environment('PUSHOVER_USER_KEY');
        if ($token === '' || $user === '') return false;

        $body = http_build_query([
            'token' => $token,
            'user' => $user,
            'title' => substr((string)$title, 0, 250),
            'message' => substr((string)$message, 0, 1024),
            'priority' => '0',
        ], '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $transport
                ? $transport('https://api.pushover.net/1/messages.json', $body)
                : self::request($body);
            if (!is_array($response) || (int)($response['status'] ?? 0) !== 200) return false;
            $data = json_decode((string)($response['body'] ?? ''), true);
            return is_array($data) && (int)($data['status'] ?? 0) === 1;
        } catch (\Throwable $error) {
            return false;
        }
    }

    private static function environment($name)
    {
        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    }

    private static function request($body)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nConnection: close\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        error_clear_last();
        $responseBody = @file_get_contents('https://api.pushover.net/1/messages.json', false, $context, 0, 1048577);
        if (!is_string($responseBody) || strlen($responseBody) > 1048576) return ['status' => 0, 'body' => ''];
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        }
        return ['status' => $status, 'body' => $responseBody];
    }
}
