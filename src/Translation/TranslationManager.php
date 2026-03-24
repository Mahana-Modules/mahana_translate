<?php

namespace MahanaTranslate\Translation;

use MahanaTranslate\Provider\ProviderException;
use MahanaTranslate\Provider\TranslationProviderInterface;
use MahanaTranslate\Translation\BatchTranslatorInterface;

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

    /**
     * @param string[] $domains
     * @param int $sourceLangId
     *
     * @return array<string, int>
     */
    public function getTotals(array $domains, $sourceLangId)
    {
        $totals = [];
        foreach ($domains as $domain) {
            $translator = $this->buildTranslator($domain);
            if ($translator instanceof BatchTranslatorInterface) {
                $totals[$domain] = (int) $translator->getTotalCount($sourceLangId);
            } else {
                $totals[$domain] = 0;
            }
        }

        return $totals;
    }

    /**
     * @param string $domain
     * @param int $sourceLangId
     * @param int $targetLangId
     * @param bool $force
     * @param int $offset
     * @param int $limit
     *
     * @return array<string, mixed>
     */
    public function translateBatch($domain, $sourceLangId, $targetLangId, $force = false, $offset = 0, $limit = 20, array $fields = [])
    {
        $translator = $this->buildTranslator($domain);
        if (!$translator || !($translator instanceof BatchTranslatorInterface)) {
            return [
                'domain' => $domain,
                'processed' => 0,
                'translated' => 0,
                'message' => sprintf('No batch translator available for domain "%s".', $domain),
            ];
        }

        return $translator->translateBatch($sourceLangId, $targetLangId, $force, $offset, $limit, $fields);
    }

    /**
     * @param int $productId
     * @param int $sourceLangId
     * @param int $targetLangId
     * @param string $field
     *
     * @return array<string, mixed>
     */
    public function translateProductField($productId, $sourceLangId, $targetLangId, $field, $force = false)
    {
        $translator = new ProductTranslator($this->provider);

        return $translator->translateProductField($productId, $sourceLangId, $targetLangId, $field, $force);
    }

    /**
     * @param int $categoryId
     * @param int $sourceLangId
     * @param int $targetLangId
     * @param string $field
     *
     * @return array<string, mixed>
     */
    public function translateCategoryField($categoryId, $sourceLangId, $targetLangId, $field, $force = false)
    {
        $translator = new CategoryTranslator($this->provider);

        return $translator->translateCategoryField($categoryId, $sourceLangId, $targetLangId, $field, $force);
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
            case 'anblog_posts':
                return new AnblogPostTranslator($this->provider);
            case 'anblog_categories':
                return new AnblogCategoryTranslator($this->provider);
            case 'anmegamenu_menus':
                return new AnMegaMenuMenuTranslator($this->provider);
            case 'anmegamenu_tabs':
                return new AnMegaMenuTabTranslator($this->provider);
            case 'anmegamenu_content':
                return new AnMegaMenuContentTranslator($this->provider);
            default:
                return null;
        }
    }

    public function translateAnblogPostField($postId, $sourceLangId, $targetLangId, $field, $force = false)
    {
        $translator = new AnblogPostTranslator($this->provider);

        return $translator->translateAnblogPostField($postId, $sourceLangId, $targetLangId, $field, $force);
    }

    public function translateAnblogCategoryField($categoryId, $sourceLangId, $targetLangId, $field, $force = false)
    {
        $translator = new AnblogCategoryTranslator($this->provider);

        return $translator->translateAnblogCategoryField($categoryId, $sourceLangId, $targetLangId, $field, $force);
    }
}
