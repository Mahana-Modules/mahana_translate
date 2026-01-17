<?php

namespace MahanaTranslate\Provider;

use Configuration;

class ProviderFactory
{
    /**
     * @return TranslationProviderInterface
     */
    public function create(string $provider)
    {
        switch ($provider) {
            case 'openai':
                return new OpenAiProvider([
                    'api_key' => Configuration::get('MAHANA_TRANSLATE_OPENAI_KEY'),
                    'model' => Configuration::get('MAHANA_TRANSLATE_OPENAI_MODEL'),
                ]);
            case 'google':
                return new GoogleTranslateProvider([
                    'api_key' => Configuration::get('MAHANA_TRANSLATE_GOOGLE_KEY'),
                    'project' => Configuration::get('MAHANA_TRANSLATE_GOOGLE_PROJECT'),
                ]);
            default:
                throw new ProviderException(sprintf('Unsupported provider "%s".', $provider));
        }
    }
}
