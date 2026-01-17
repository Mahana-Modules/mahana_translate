<?php

namespace MahanaTranslate\Translation;

use DbQuery;
use Language;

class CmsPageTranslator extends AbstractTranslator
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

    private function fetchRows($langId, array $shopIds)
    {
        $sql = new DbQuery();
        $sql->select('cl.id_cms, cl.id_shop, cl.meta_title, cl.meta_description, cl.content');
        $sql->from('cms_lang', 'cl');
        $sql->where('cl.id_lang = ' . (int) $langId);
        $sql->where('cl.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');

        return $this->db->executeS($sql);
    }
}
