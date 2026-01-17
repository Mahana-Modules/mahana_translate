<?php

namespace MahanaTranslate\Translation;

use DbQuery;
use Language;

class ProductTranslator extends AbstractTranslator
{
    /** @var array<string, bool> */
    private $fields = [
        'name' => false,
        'description_short' => true,
        'description' => true,
        'meta_title' => false,
        'meta_description' => false,
    ];

    public function translate($sourceLangId, array $targetLangIds, $force = false)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'products',
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds);
        if (empty($sourceRows)) {
            return [
                'domain' => 'products',
                'translated' => 0,
                'message' => 'No product content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $totalUpdates = 0;

        foreach ($targetLangIds as $targetLangId) {
            if ($targetLangId === $sourceLangId) {
                continue;
            }
            $targetIso = Language::getIsoById($targetLangId);
            $targetRows = $this->fetchRows((int) $targetLangId, $shopIds);
            $targetIndex = [];
            foreach ($targetRows as $row) {
                $targetIndex[$row['id_product'] . '_' . $row['id_shop']] = $row;
            }

            foreach ($this->fields as $field => $allowHtml) {
                $texts = [];
                $indexMap = [];

                foreach ($sourceRows as $row) {
                    $key = $row['id_product'] . '_' . $row['id_shop'];
                    $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                    if (!$force && $currentTargetValue && trim((string) $currentTargetValue) !== '') {
                        continue;
                    }
                    $value = (string) $row[$field];
                    if ($value === '') {
                        continue;
                    }

                    $this->ensureLangRow('product_lang', [
                        'id_product' => (int) $row['id_product'],
                        'id_shop' => (int) $row['id_shop'],
                    ], $targetLangId);

                    $texts[] = $value;
                    $indexMap[] = [
                        'id_product' => (int) $row['id_product'],
                        'id_shop' => (int) $row['id_shop'],
                    ];
                }

                if (empty($texts)) {
                    continue;
                }

                $translations = $this->provider->translate($texts, $sourceIso, $targetIso);
                foreach ($translations as $i => $translation) {
                    $item = $indexMap[$i];
                    $this->updateLangField('product_lang', $item, $targetLangId, $field, $translation, $allowHtml);
                    $totalUpdates++;
                }
            }
        }

        return [
            'domain' => 'products',
            'translated' => $totalUpdates,
            'message' => sprintf('%d product fields updated.', $totalUpdates),
        ];
    }

    private function fetchRows($langId, array $shopIds)
    {
        $sql = new \DbQuery();
        $sql->select('pl.id_product, pl.id_shop, pl.name, pl.description_short, pl.description, pl.meta_title, pl.meta_description');
        $sql->from('product_lang', 'pl');
        $sql->where('pl.id_lang = ' . (int) $langId);
        $sql->where('pl.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');

        return $this->db->executeS($sql);
    }
}
