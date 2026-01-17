<?php

namespace MahanaTranslate\Translation;

use Context;
use Db;
use Shop;
use MahanaTranslate\Provider\TranslationProviderInterface;

abstract class AbstractTranslator implements ContentTranslatorInterface
{
    /** @var TranslationProviderInterface */
    protected $provider;

    /** @var Db */
    protected $db;

    public function __construct(TranslationProviderInterface $provider)
    {
        $this->provider = $provider;
        $this->db = Db::getInstance();
    }

    /**
     * @return int[]
     */
    protected function getShopIds()
    {
        $shopIds = Shop::getContextListShopID();
        if (empty($shopIds)) {
            $contextShopId = (int) Context::getContext()->shop->id;
            if ($contextShopId) {
                $shopIds = [$contextShopId];
            }
        }

        return array_map('intval', array_unique($shopIds));
    }

    protected function ensureLangRow($table, array $identifiers, $targetLangId)
    {
        $whereParts = [];
        foreach ($identifiers as $key => $value) {
            $whereParts[] = sprintf('%s = %d', bqSQL($key), (int) $value);
        }
        $whereParts[] = 'id_lang = ' . (int) $targetLangId;
        $where = implode(' AND ', $whereParts);

        $exists = $this->db->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . bqSQL($table) . ' WHERE ' . $where);
        if ($exists) {
            return;
        }

        $data = $identifiers;
        $data['id_lang'] = (int) $targetLangId;
        $this->db->insert($table, $data, false, true, Db::INSERT_IGNORE);
    }

    protected function updateLangField($table, array $identifiers, $targetLangId, $field, $value, $allowHtml = false)
    {
        $data = [
            $field => $allowHtml ? pSQL($value, true) : pSQL($value),
        ];

        $whereParts = [];
        foreach ($identifiers as $key => $val) {
            $whereParts[] = sprintf('%s = %d', bqSQL($key), (int) $val);
        }
        $whereParts[] = 'id_lang = ' . (int) $targetLangId;

        $this->db->update(bqSQL($table), $data, implode(' AND ', $whereParts));
    }

    protected function shouldTranslate($force, $currentTargetValue, $sourceValue)
    {
        if ($force) {
            return true;
        }

        $current = trim((string) $currentTargetValue);
        if ($current === '') {
            return true;
        }

        $source = trim((string) $sourceValue);
        if ($source === '') {
            return false;
        }

        return $current === $source;
    }
}
