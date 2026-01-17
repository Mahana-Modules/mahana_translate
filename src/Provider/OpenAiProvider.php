<?php

namespace MahanaTranslate\Provider;

class OpenAiProvider implements TranslationProviderInterface
{
    use HttpClientTrait;

    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const MAX_CHARS = 2000;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->model = (string) ($config['model'] ?? 'gpt-4o-mini');
    }

    public function translate(array $texts, string $sourceIso, string $targetIso, array $options = [])
    {
        if (!$this->apiKey) {
            throw new ProviderException('Missing OpenAI API key.');
        }

        $results = [];
        foreach ($texts as $text) {
            if ($text === '' || $text === null) {
                $results[] = '';
                continue;
            }

            $textValue = (string) $text;
            $chunks = $this->splitText($textValue, self::MAX_CHARS);
            $translatedChunks = [];
            foreach ($chunks as $chunk) {
                $payload = [
                    'model' => $this->model,
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => sprintf(
                                'You are a professional translator. Translate user content from %s to %s without changing formatting.',
                                strtoupper($sourceIso),
                                strtoupper($targetIso)
                            ),
                        ],
                        [
                            'role' => 'user',
                            'content' => (string) $chunk,
                        ],
                    ],
                ];

                $response = $this->postJson(self::ENDPOINT, [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ], $payload);

                $translatedChunks[] = $this->extractTranslation($response);
            }

            $results[] = implode('', $translatedChunks);
        }

        return $results;
    }

    private function extractTranslation(array $response)
    {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new ProviderException('OpenAI response missing generated text.');
        }

        return (string) $response['choices'][0]['message']['content'];
    }

    /**
     * @return string[]
     */
    private function splitText(string $text, int $maxLength)
    {
        if ($this->length($text) <= $maxLength) {
            return [$text];
        }

        $segments = $this->segmentText($text);
        if (empty($segments)) {
            return $this->splitByLength($text, $maxLength);
        }

        return $this->mergeSegments($segments, $maxLength);
    }

    /**
     * @return string[]
     */
    private function segmentText(string $text)
    {
        if (preg_match('/<[^>]+>/', $text)) {
            $parts = preg_split('/(<\\/p\\s*>|<br\\s*\\/?\\s*>|<\\/div\\s*>|<\\/li\\s*>|<\\/h[1-6]\\s*>|<\\/tr\\s*>)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            return $this->combineDelimiters($parts, '/^(<\\/p|<br|<\\/div|<\\/li|<\\/h[1-6]|<\\/tr)/i');
        }

        $parts = preg_split('/(\\r?\\n\\r?\\n|\\r?\\n|[.!?]\\s)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        return $this->combineDelimiters($parts, '/^(\\r?\\n\\r?\\n|\\r?\\n|[.!?]\\s)$/');
    }

    /**
     * @param string[] $parts
     *
     * @return string[]
     */
    private function combineDelimiters(array $parts, string $delimiterPattern)
    {
        $segments = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match($delimiterPattern, $part)) {
                if (empty($segments)) {
                    $segments[] = $part;
                } else {
                    $segments[count($segments) - 1] .= $part;
                }
            } else {
                $segments[] = $part;
            }
        }

        return $segments;
    }

    /**
     * @param string[] $segments
     *
     * @return string[]
     */
    private function mergeSegments(array $segments, int $maxLength)
    {
        $chunks = [];
        $current = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($this->length($segment) > $maxLength) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks = array_merge($chunks, $this->splitByLength($segment, $maxLength));
                continue;
            }

            $candidate = $current . $segment;
            if ($current !== '' && $this->length($candidate) > $maxLength) {
                $chunks[] = $current;
                $current = $segment;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @return string[]
     */
    private function splitByLength(string $text, int $maxLength)
    {
        $chunks = [];
        $length = $this->length($text);
        for ($offset = 0; $offset < $length; $offset += $maxLength) {
            $chunks[] = $this->substring($text, $offset, $maxLength);
        }

        return $chunks;
    }

    private function length(string $text)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    }

    private function substring(string $text, int $start, int $length)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, $start, $length, 'UTF-8');
        }

        return substr($text, $start, $length);
    }
}
