<?php

namespace MahanaTranslate\Translation;

use DbQuery;
use Language;

class CmsPageTranslator extends AbstractTranslator implements BatchTranslatorInterface
{
    /** @var array<string, bool> */
    private $fields = [
        'meta_title' => false,
        'meta_description' => false,
        'content' => true,
    ];

    public function translate($sourceLangId, array $targetLangIds, $force = false)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'cms_pages',
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds);
        if (empty($sourceRows)) {
            return [
                'domain' => 'cms_pages',
                'translated' => 0,
                'message' => 'No CMS page content found for source language.',
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
                $targetIndex[$row['id_cms'] . '_' . $row['id_shop']] = $row;
            }

            foreach ($this->fields as $field => $allowHtml) {
                $texts = [];
                $indexMap = [];

                foreach ($sourceRows as $row) {
                    $key = $row['id_cms'] . '_' . $row['id_shop'];
                    $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                    if (!$force && $currentTargetValue && trim((string) $currentTargetValue) !== '') {
                        continue;
                    }

                    $value = (string) $row[$field];
                    if ($value === '') {
                        continue;
                    }

                    $this->ensureLangRow('cms_lang', [
                        'id_cms' => (int) $row['id_cms'],
                        'id_shop' => (int) $row['id_shop'],
                    ], $targetLangId);

                    $texts[] = $value;
                    $indexMap[] = [
                        'id_cms' => (int) $row['id_cms'],
                        'id_shop' => (int) $row['id_shop'],
                    ];
                }

                if (empty($texts)) {
                    continue;
                }

                $translations = $this->provider->translate($texts, $sourceIso, $targetIso);
                foreach ($translations as $i => $translation) {
                    $item = $indexMap[$i];
                    $this->updateLangField('cms_lang', $item, $targetLangId, $field, $translation, $allowHtml);
                    $totalUpdates++;
                }
            }
        }

        return [
            'domain' => 'cms_pages',
            'translated' => $totalUpdates,
            'message' => sprintf('%d CMS page fields updated.', $totalUpdates),
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
        $sql->from('cms_lang', 'cl');
        $sql->where('cl.id_lang = ' . (int) $sourceLangId);
        $sql->where('cl.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');

        return (int) $this->db->getValue($sql);
    }

    public function translateBatch($sourceLangId, $targetLangId, $force = false, $offset = 0, $limit = 20, array $fields = [])
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'cms_pages',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'cms_pages',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds, (int) $offset, (int) $limit);
        if (empty($sourceRows)) {
            return [
                'domain' => 'cms_pages',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No CMS page content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $targetIso = Language::getIsoById($targetLangId);
        $cmsIds = array_values(array_unique(array_map(function ($row) {
            return (int) $row['id_cms'];
        }, $sourceRows)));

        $targetRows = $this->fetchRows((int) $targetLangId, $shopIds, null, null, $cmsIds);
        $targetIndex = [];
        foreach ($targetRows as $row) {
            $targetIndex[$row['id_cms'] . '_' . $row['id_shop']] = $row;
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
                $key = $row['id_cms'] . '_' . $row['id_shop'];
                $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                if (!$force && $currentTargetValue && trim((string) $currentTargetValue) !== '') {
                    continue;
                }

                $value = (string) $row[$field];
                if ($value === '') {
                    continue;
                }

                $this->ensureLangRow('cms_lang', [
                    'id_cms' => (int) $row['id_cms'],
                    'id_shop' => (int) $row['id_shop'],
                ], $targetLangId);

                $texts[] = $value;
                $indexMap[] = [
                    'id_cms' => (int) $row['id_cms'],
                    'id_shop' => (int) $row['id_shop'],
                ];
            }

            if (empty($texts)) {
                continue;
            }

            $translations = $this->provider->translate($texts, $sourceIso, $targetIso);
            foreach ($translations as $i => $translation) {
                $item = $indexMap[$i];
                $this->updateLangField('cms_lang', $item, $targetLangId, $field, $translation, $allowHtml);
                $totalUpdates++;
            }
        }

        return [
            'domain' => 'cms_pages',
            'processed' => count($sourceRows),
            'translated' => $totalUpdates,
            'message' => sprintf('%d CMS page fields updated.', $totalUpdates),
        ];
    }

    private function fetchRows($langId, array $shopIds, $offset = null, $limit = null, array $cmsIds = [])
    {
        $sql = new DbQuery();
        $sql->select('cl.id_cms, cl.id_shop, cl.meta_title, cl.meta_description, cl.content');
        $sql->from('cms_lang', 'cl');
        $sql->where('cl.id_lang = ' . (int) $langId);
        $sql->where('cl.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');
        if (!empty($cmsIds)) {
            $sql->where('cl.id_cms IN (' . implode(',', array_map('intval', $cmsIds)) . ')');
        }
        if ($limit !== null) {
            $sql->limit((int) $limit, (int) $offset);
        }

        return $this->db->executeS($sql);
    }
}
