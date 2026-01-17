<?php

namespace MahanaTranslate\Translation;

use DbQuery;
use Language;

class CategoryTranslator extends AbstractTranslator implements BatchTranslatorInterface
{
    /** @var array<string, bool> */
    private $fields = [
        'name' => false,
        'description' => true,
        'meta_title' => false,
        'meta_description' => false,
    ];

    public function translate($sourceLangId, array $targetLangIds, $force = false)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'categories',
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds);
        if (empty($sourceRows)) {
            return [
                'domain' => 'categories',
                'translated' => 0,
                'message' => 'No category content found for source language.',
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
                $targetIndex[$row['id_category'] . '_' . $row['id_shop']] = $row;
            }

            foreach ($this->fields as $field => $allowHtml) {
                $texts = [];
                $indexMap = [];

                foreach ($sourceRows as $row) {
                    $key = $row['id_category'] . '_' . $row['id_shop'];
                    $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                    if (!$force && $currentTargetValue && trim((string) $currentTargetValue) !== '') {
                        continue;
                    }
                    $value = (string) $row[$field];
                    if ($value === '') {
                        continue;
                    }

                    $this->ensureLangRow('category_lang', [
                        'id_category' => (int) $row['id_category'],
                        'id_shop' => (int) $row['id_shop'],
                    ], $targetLangId);

                    $texts[] = $value;
                    $indexMap[] = [
                        'id_category' => (int) $row['id_category'],
                        'id_shop' => (int) $row['id_shop'],
                    ];
                }

                if (empty($texts)) {
                    continue;
                }

                $translations = $this->provider->translate($texts, $sourceIso, $targetIso);
                foreach ($translations as $i => $translation) {
                    $item = $indexMap[$i];
                    $this->updateLangField('category_lang', $item, $targetLangId, $field, $translation, $allowHtml);
                    $totalUpdates++;
                }
            }
        }

        return [
            'domain' => 'categories',
            'translated' => $totalUpdates,
            'message' => sprintf('%d category fields updated.', $totalUpdates),
        ];
    }

    public function getTotalCount($sourceLangId)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return 0;
        }

        $sql = new DbQuery();
        $sql->select('COUNT(*)');
        $sql->from('category_lang', 'cl');
        $sql->where('cl.id_lang = ' . (int) $sourceLangId);
        $sql->where('cl.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');

        return (int) $this->db->getValue($sql);
    }

    public function translateBatch($sourceLangId, $targetLangId, $force = false, $offset = 0, $limit = 20, array $fields = [])
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds, (int) $offset, (int) $limit);
        if (empty($sourceRows)) {
            return [
                'domain' => 'categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No category content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $targetIso = Language::getIsoById($targetLangId);
        $categoryIds = array_values(array_unique(array_map(function ($row) {
            return (int) $row['id_category'];
        }, $sourceRows)));

        $targetRows = $this->fetchRows((int) $targetLangId, $shopIds, null, null, $categoryIds);
        $targetIndex = [];
        foreach ($targetRows as $row) {
            $targetIndex[$row['id_category'] . '_' . $row['id_shop']] = $row;
        }

        $totalUpdates = 0;
        $fieldsToTranslate = $this->fields;
        if (!empty($fields)) {
            $fieldsToTranslate = array_intersect_key($this->fields, array_flip($fields));
        }

        foreach ($fieldsToTranslate as $field => $allowHtml) {
            $texts = [];
            $indexMap = [];

            foreach ($sourceRows as $row) {
                $key = $row['id_category'] . '_' . $row['id_shop'];
                $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                if (!$force && $currentTargetValue && trim((string) $currentTargetValue) !== '') {
                    continue;
                }
                $value = (string) $row[$field];
                if ($value === '') {
                    continue;
                }

                $this->ensureLangRow('category_lang', [
                    'id_category' => (int) $row['id_category'],
                    'id_shop' => (int) $row['id_shop'],
                ], $targetLangId);

                $texts[] = $value;
                $indexMap[] = [
                    'id_category' => (int) $row['id_category'],
                    'id_shop' => (int) $row['id_shop'],
                ];
            }

            if (empty($texts)) {
                continue;
            }

            $translations = $this->provider->translate($texts, $sourceIso, $targetIso);
            foreach ($translations as $i => $translation) {
                $item = $indexMap[$i];
                $this->updateLangField('category_lang', $item, $targetLangId, $field, $translation, $allowHtml);
                $totalUpdates++;
            }
        }

        return [
            'domain' => 'categories',
            'processed' => count($sourceRows),
            'translated' => $totalUpdates,
            'message' => sprintf('%d category fields updated.', $totalUpdates),
        ];
    }

    private function fetchRows($langId, array $shopIds, $offset = null, $limit = null, array $categoryIds = [])
    {
        $sql = new DbQuery();
        $sql->select('cl.id_category, cl.id_shop, cl.name, cl.description, cl.meta_title, cl.meta_description');
        $sql->from('category_lang', 'cl');
        $sql->where('cl.id_lang = ' . (int) $langId);
        $sql->where('cl.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');
        if (!empty($categoryIds)) {
            $sql->where('cl.id_category IN (' . implode(',', array_map('intval', $categoryIds)) . ')');
        }
        if ($limit !== null) {
            $sql->limit((int) $limit, (int) $offset);
        }

        return $this->db->executeS($sql);
    }
}
