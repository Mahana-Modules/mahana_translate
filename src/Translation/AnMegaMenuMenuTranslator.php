<?php

namespace MahanaTranslate\Translation;

use DbQuery;
use Language;

class AnMegaMenuMenuTranslator extends AbstractTranslator implements BatchTranslatorInterface
{
    /** @var array<string, bool> */
    private $fields = [
        'title' => false,
    ];

    public function translate($sourceLangId, array $targetLangIds, $force = false)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anmegamenu_menus',
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anmegamenu_menus',
                'translated' => 0,
                'message' => 'No mega menu content found for source language.',
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
                $targetIndex[(int) $row['id_menu']] = $row;
            }

            foreach ($this->fields as $field => $allowHtml) {
                $texts = [];
                $indexMap = [];

                foreach ($sourceRows as $row) {
                    $key = (int) $row['id_menu'];
                    $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                    if (!$force && trim((string) $currentTargetValue) !== '') {
                        continue;
                    }

                    $value = (string) $row[$field];
                    if ($value === '') {
                        continue;
                    }

                    $this->ensureLangRow('anmegamenu_menu_lang', [
                        'id_menu' => $key,
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
                    $this->updateLangField('anmegamenu_menu_lang', ['id_menu' => $id], $targetLangId, $field, $translation, $allowHtml);
                    $totalUpdates++;
                }
            }
        }

        return [
            'domain' => 'anmegamenu_menus',
            'translated' => $totalUpdates,
            'message' => sprintf('%d mega menu fields updated.', $totalUpdates),
        ];
    }

    public function getTotalCount($sourceLangId)
    {
        $shopIds = $this->getShopIds();
        if (!empty($shopIds)) {
            $sql = new DbQuery();
            $sql->select('COUNT(DISTINCT ml.id_menu)');
            $sql->from('anmegamenu_menu_lang', 'ml');
            $sql->innerJoin('anmegamenu_menu_shop', 'ms', 'ms.id_menu = ml.id_menu');
            $sql->where('ml.id_lang = ' . (int) $sourceLangId);
            $sql->where('ms.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');

            $count = (int) $this->db->getValue($sql);
            if ($count > 0) {
                return $count;
            }
        }

        $sql = new DbQuery();
        $sql->select('COUNT(DISTINCT ml.id_menu)');
        $sql->from('anmegamenu_menu_lang', 'ml');
        $sql->where('ml.id_lang = ' . (int) $sourceLangId);

        return (int) $this->db->getValue($sql);
    }

    public function translateBatch($sourceLangId, $targetLangId, $force = false, $offset = 0, $limit = 20, array $fields = [])
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anmegamenu_menus',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'anmegamenu_menus',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds, (int) $offset, (int) $limit);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anmegamenu_menus',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No mega menu content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $targetIso = Language::getIsoById($targetLangId);
        $menuIds = array_values(array_unique(array_map(function ($row) {
            return (int) $row['id_menu'];
        }, $sourceRows)));

        $targetRows = $this->fetchRows((int) $targetLangId, $shopIds, null, null, $menuIds);
        $targetIndex = [];
        foreach ($targetRows as $row) {
            $targetIndex[(int) $row['id_menu']] = $row;
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
                $key = (int) $row['id_menu'];
                $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                if (!$force && trim((string) $currentTargetValue) !== '') {
                    continue;
                }

                $value = (string) $row[$field];
                if ($value === '') {
                    continue;
                }

                $this->ensureLangRow('anmegamenu_menu_lang', [
                    'id_menu' => $key,
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
                $this->updateLangField('anmegamenu_menu_lang', ['id_menu' => $id], $targetLangId, $field, $translation, $allowHtml);
                $totalUpdates++;
            }
        }

        return [
            'domain' => 'anmegamenu_menus',
            'processed' => count($sourceRows),
            'translated' => $totalUpdates,
            'message' => sprintf('%d mega menu fields updated.', $totalUpdates),
        ];
    }

    private function fetchRows($langId, array $shopIds, $offset = null, $limit = null, array $menuIds = [])
    {
        if (!empty($shopIds)) {
            $sql = new DbQuery();
            $sql->select('ml.id_menu, ml.title');
            $sql->from('anmegamenu_menu_lang', 'ml');
            $sql->innerJoin('anmegamenu_menu_shop', 'ms', 'ms.id_menu = ml.id_menu');
            $sql->where('ml.id_lang = ' . (int) $langId);
            $sql->where('ms.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');
            if (!empty($menuIds)) {
                $sql->where('ml.id_menu IN (' . implode(',', array_map('intval', $menuIds)) . ')');
            }
            $sql->groupBy('ml.id_menu');
            if ($limit !== null) {
                $sql->limit((int) $limit, (int) $offset);
            }

            $rows = $this->db->executeS($sql);
            if (!empty($rows)) {
                return $rows;
            }
        }

        $sql = new DbQuery();
        $sql->select('ml.id_menu, ml.title');
        $sql->from('anmegamenu_menu_lang', 'ml');
        $sql->where('ml.id_lang = ' . (int) $langId);
        if (!empty($menuIds)) {
            $sql->where('ml.id_menu IN (' . implode(',', array_map('intval', $menuIds)) . ')');
        }
        $sql->groupBy('ml.id_menu');
        if ($limit !== null) {
            $sql->limit((int) $limit, (int) $offset);
        }

        return $this->db->executeS($sql);
    }
}
