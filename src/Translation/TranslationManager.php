<?php

namespace MahanaTranslate\Translation;

use MahanaTranslate\Provider\ProviderException;
use MahanaTranslate\Provider\TranslationProviderInterface;

class TranslationManager
{
    /** @var TranslationProviderInterface */
    private $provider;

    public function __construct(TranslationProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * @param string[] $domains
     * @param int $sourceLangId
     * @param int[] $targetLangIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function translate(array $domains, $sourceLangId, array $targetLangIds, $force = false)
    {
        $reports = [];
        foreach ($domains as $domain) {
            $translator = $this->buildTranslator($domain);
            if (!$translator) {
                $reports[] = [
                    'domain' => $domain,
                    'translated' => 0,
                    'message' => sprintf('No translator available for domain "%s".', $domain),
                ];
                continue;
            }

            try {
                $reports[] = $translator->translate($sourceLangId, $targetLangIds, $force);
            } catch (ProviderException $exception) {
                $reports[] = [
                    'domain' => $domain,
                    'translated' => 0,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $reports;
    }

    private function buildTranslator($domain)
    {
        switch ($domain) {
            case 'products':
                return new ProductTranslator($this->provider);
            case 'categories':
                return new CategoryTranslator($this->provider);
            case 'cms_pages':
                return new CmsPageTranslator($this->provider);
            case 'static_pages':
                return new StaticPageTranslator($this->provider);
            default:
                return null;
        }
    }
}
