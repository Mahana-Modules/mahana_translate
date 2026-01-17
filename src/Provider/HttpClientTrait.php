<?php

namespace MahanaTranslate\Provider;

trait HttpClientTrait
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     */
    protected function postJson(string $url, array $headers, array $payload): array
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
        ], $headers);

        $rawHeaders = [];
        foreach ($headers as $key => $value) {
            $rawHeaders[] = $key . ': ' . $value;
        }

        $ch = curl_init($url);
        if (!$ch) {
            throw new ProviderException('Unable to initialize HTTP request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $rawHeaders,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new ProviderException(sprintf('HTTP request failed: %s', $error ?: 'unknown error'));
        }

        $decoded = json_decode($response, true);
        if ($status >= 400) {
            $message = is_array($decoded) && isset($decoded['error'])
                ? (is_array($decoded['error']) ? ($decoded['error']['message'] ?? '') : (string) $decoded['error'])
                : 'Unknown API error';

            throw new ProviderException(sprintf('API responded with error (%d): %s', $status, $message));
        }

        if (!is_array($decoded)) {
            throw new ProviderException('Unexpected API response format.');
        }

        return $decoded;
    }
}
