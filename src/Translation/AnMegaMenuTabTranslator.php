<?php

namespace MahanaTranslate\Translation;

use DbQuery;
use Language;

class AnMegaMenuTabTranslator extends AbstractTranslator implements BatchTranslatorInterface
{
    /** @var array<string, bool> */
    private $fields = [
        'title' => false,
        'label' => false,
    ];

    public function translate($sourceLangId, array $targetLangIds, $force = false)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anmegamenu_tabs',
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anmegamenu_tabs',
                'translated' => 0,
                'message' => 'No mega menu tab content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $totalUpdates = 0;

        foreach ($targetLangIds as $targetLangId) {
            if ((int) $targetLangId === (int) $sourceLangId) {
                continue;
            }

            $targetIso = Language::getIsoById($targetLangId);
            $targetRows = $this->fetchRows((int) $targetLangId, $shopIds);
            $targetIndex = [];
            foreach ($targetRows as $row) {
                $targetIndex[(int) $row['id_tab']] = $row;
            }

            foreach ($this->fields as $field => $allowHtml) {
                $texts = [];
                $indexMap = [];

                foreach ($sourceRows as $row) {
                    $key = (int) $row['id_tab'];
                    $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                    if (!$force && trim((string) $currentTargetValue) !== '') {
                        continue;
                    }

                    $value = (string) $row[$field];
                    if ($value === '') {
                        continue;
                    }

                    $this->ensureLangRow('anmegamenu_tabs_lang', [
                        'id_tab' => $key,
                    ], $targetLangId);

                    $texts[] = $value;
                    $indexMap[] = $key;
                }

                if (empty($texts)) {
                    continue;
                }

                $translations = $this->provider->translate($texts, $sourceIso, $targetIso);
                foreach ($translations as $i => $translation) {
                    $id = $indexMap[$i];
                    $this->updateLangField('anmegamenu_tabs_lang', ['id_tab' => $id], $targetLangId, $field, $translation, $allowHtml);
                    $totalUpdates++;
                }
            }
        }

        return [
            'domain' => 'anmegamenu_tabs',
            'translated' => $totalUpdates,
            'message' => sprintf('%d mega menu tab fields updated.', $totalUpdates),
        ];
    }

    public function getTotalCount($sourceLangId)
    {
        $shopIds = $this->getShopIds();
        if (!empty($shopIds)) {
            $sql = new DbQuery();
            $sql->select('COUNT(DISTINCT tl.id_tab)');
            $sql->from('anmegamenu_tabs_lang', 'tl');
            $sql->innerJoin('anmegamenu_tabs_shop', 'ts', 'ts.id_tab = tl.id_tab');
            $sql->where('tl.id_lang = ' . (int) $sourceLangId);
            $sql->where('ts.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');

            $count = (int) $this->db->getValue($sql);
            if ($count > 0) {
                return $count;
            }
        }

        $sql = new DbQuery();
        $sql->select('COUNT(DISTINCT tl.id_tab)');
        $sql->from('anmegamenu_tabs_lang', 'tl');
        $sql->where('tl.id_lang = ' . (int) $sourceLangId);

        return (int) $this->db->getValue($sql);
    }

    public function translateBatch($sourceLangId, $targetLangId, $force = false, $offset = 0, $limit = 20, array $fields = [])
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anmegamenu_tabs',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'anmegamenu_tabs',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds, (int) $offset, (int) $limit);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anmegamenu_tabs',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No mega menu tab content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $targetIso = Language::getIsoById($targetLangId);
        $tabIds = array_values(array_unique(array_map(function ($row) {
            return (int) $row['id_tab'];
        }, $sourceRows)));

        $targetRows = $this->fetchRows((int) $targetLangId, $shopIds, null, null, $tabIds);
        $targetIndex = [];
        foreach ($targetRows as $row) {
            $targetIndex[(int) $row['id_tab']] = $row;
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
                $key = (int) $row['id_tab'];
                $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                if (!$force && trim((string) $currentTargetValue) !== '') {
                    continue;
                }

                $value = (string) $row[$field];
                if ($value === '') {
                    continue;
                }

                $this->ensureLangRow('anmegamenu_tabs_lang', [
                    'id_tab' => $key,
                ], $targetLangId);

                $texts[] = $value;
                $indexMap[] = $key;
            }

            if (empty($texts)) {
                continue;
            }

            $translations = $this->provider->translate($texts, $sourceIso, $targetIso);
            foreach ($translations as $i => $translation) {
                $id = $indexMap[$i];
                $this->updateLangField('anmegamenu_tabs_lang', ['id_tab' => $id], $targetLangId, $field, $translation, $allowHtml);
                $totalUpdates++;
            }
        }

        return [
            'domain' => 'anmegamenu_tabs',
            'processed' => count($sourceRows),
            'translated' => $totalUpdates,
            'message' => sprintf('%d mega menu tab fields updated.', $totalUpdates),
        ];
    }

    private function fetchRows($langId, array $shopIds, $offset = null, $limit = null, array $tabIds = [])
    {
        if (!empty($shopIds)) {
            $sql = new DbQuery();
            $sql->select('tl.id_tab, tl.title, tl.label');
            $sql->from('anmegamenu_tabs_lang', 'tl');
            $sql->innerJoin('anmegamenu_tabs_shop', 'ts', 'ts.id_tab = tl.id_tab');
            $sql->where('tl.id_lang = ' . (int) $langId);
            $sql->where('ts.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');
            if (!empty($tabIds)) {
                $sql->where('tl.id_tab IN (' . implode(',', array_map('intval', $tabIds)) . ')');
            }
            $sql->groupBy('tl.id_tab');
            if ($limit !== null) {
                $sql->limit((int) $limit, (int) $offset);
            }

            $rows = $this->db->executeS($sql);
            if (!empty($rows)) {
                return $rows;
            }
        }

        $sql = new DbQuery();
        $sql->select('tl.id_tab, tl.title, tl.label');
        $sql->from('anmegamenu_tabs_lang', 'tl');
        $sql->where('tl.id_lang = ' . (int) $langId);
        if (!empty($tabIds)) {
            $sql->where('tl.id_tab IN (' . implode(',', array_map('intval', $tabIds)) . ')');
        }
        $sql->groupBy('tl.id_tab');
        if ($limit !== null) {
            $sql->limit((int) $limit, (int) $offset);
        }

        return $this->db->executeS($sql);
    }
}
