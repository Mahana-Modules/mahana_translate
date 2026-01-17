<?php

namespace MahanaTranslate\Provider;

class OpenAiProvider implements TranslationProviderInterface
{
    use HttpClientTrait;

    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

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
                        'content' => (string) $text,
                    ],
                ],
            ];

            $response = $this->postJson(self::ENDPOINT, [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ], $payload);

            $results[] = $this->extractTranslation($response);
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
}
