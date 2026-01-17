<?php

namespace MahanaTranslate\Provider;

class GoogleTranslateProvider implements TranslationProviderInterface
{
    use HttpClientTrait;

    private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $project;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->project = (string) ($config['project'] ?? '');
    }

    public function translate(array $texts, string $sourceIso, string $targetIso, array $options = [])
    {
        if (!$this->apiKey) {
            throw new ProviderException('Missing Google Translate API key.');
        }

        $results = array_fill(0, count($texts), '');
        $payloadTexts = [];
        $map = [];

        foreach ($texts as $index => $text) {
            if ($text === '' || $text === null) {
                $results[$index] = '';
                continue;
            }

            $payloadTexts[] = $text;
            $map[] = $index;
        }

        if (!empty($payloadTexts)) {
            $payload = [
                'q' => $payloadTexts,
                'source' => strtolower($sourceIso),
                'target' => strtolower($targetIso),
                'format' => 'text',
            ];

            if ($this->project) {
                $payload['model'] = $this->project;
            }

            $response = $this->postJson($this->buildUrl(), [], $payload);
            $translations = $this->extractTranslations($response);

            foreach ($translations as $i => $translation) {
                $targetIndex = $map[$i] ?? null;
                if ($targetIndex !== null) {
                    $results[$targetIndex] = $translation;
                }
            }
        }

        return $results;
    }

    private function buildUrl()
    {
        return self::ENDPOINT . '?key=' . urlencode($this->apiKey);
    }

    private function extractTranslations(array $response)
    {
        if (!isset($response['data']['translations']) || !is_array($response['data']['translations'])) {
            throw new ProviderException('Google Translate response missing translations.');
        }

        $results = [];
        foreach ($response['data']['translations'] as $translation) {
            $results[] = (string) ($translation['translatedText'] ?? '');
        }

        return $results;
    }
}
