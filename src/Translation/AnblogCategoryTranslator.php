<?php

namespace MahanaTranslate\Translation;

use DbQuery;
use Language;

class AnblogCategoryTranslator extends AbstractTranslator implements BatchTranslatorInterface
{
    /** @var array<string, bool> */
    private $fields = [
        'title' => false,
        'content_text' => true,
        'meta_title' => false,
        'meta_description' => false,
        'meta_keywords' => false,
    ];

    public function translate($sourceLangId, array $targetLangIds, $force = false)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anblog_categories',
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anblog_categories',
                'translated' => 0,
                'message' => 'No blog category content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $sourceIndex = [];
        foreach ($sourceRows as $row) {
            $sourceIndex[(int) $row['id_anblogcat']] = $row;
        }
        $totalUpdates = 0;

        foreach ($targetLangIds as $targetLangId) {
            if ((int) $targetLangId === (int) $sourceLangId) {
                continue;
            }

            $targetIso = Language::getIsoById($targetLangId);
            $targetRows = $this->fetchRows((int) $targetLangId, $shopIds);
            $targetIndex = [];
            foreach ($targetRows as $row) {
                $targetIndex[(int) $row['id_anblogcat']] = $row;
            }

            foreach ($this->fields as $field => $allowHtml) {
                $texts = [];
                $indexMap = [];

                foreach ($sourceRows as $row) {
                    $key = (int) $row['id_anblogcat'];
                    $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                    if (!$force && trim((string) $currentTargetValue) !== '') {
                        continue;
                    }

                    $value = (string) $row[$field];
                    if ($value === '') {
                        continue;
                    }

                    $this->ensureLangRow('anblogcat_lang', [
                        'id_anblogcat' => $key,
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
                    $this->updateLangField('anblogcat_lang', ['id_anblogcat' => $id], $targetLangId, $field, $translation, $allowHtml);
                    if ($field === 'title') {
                        $sourceRow = $sourceIndex[$id];
                        $currentSlug = isset($targetIndex[$id]) ? (string) $targetIndex[$id]['link_rewrite'] : '';
                        if ($force || trim($currentSlug) === '') {
                            $slug = $this->generateLinkRewrite($translation, $sourceRow['link_rewrite'], 'blog-category-' . $id);
                            $this->updateLangField('anblogcat_lang', ['id_anblogcat' => $id], $targetLangId, 'link_rewrite', $slug);
                            if (!isset($targetIndex[$id])) {
                                $targetIndex[$id] = ['link_rewrite' => $slug];
                            } else {
                                $targetIndex[$id]['link_rewrite'] = $slug;
                            }
                        }
                    }
                    $totalUpdates++;
                }
            }
        }

        return [
            'domain' => 'anblog_categories',
            'translated' => $totalUpdates,
            'message' => sprintf('%d blog category fields updated.', $totalUpdates),
        ];
    }

    public function getTotalCount($sourceLangId)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return 0;
        }

        $sql = new DbQuery();
        $sql->select('COUNT(DISTINCT cl.id_anblogcat)');
        $sql->from('anblogcat_lang', 'cl');
        $sql->innerJoin('anblogcat_shop', 'cs', 'cs.id_anblogcat = cl.id_anblogcat');
        $sql->where('cl.id_lang = ' . (int) $sourceLangId);
        $sql->where('cs.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');

        return (int) $this->db->getValue($sql);
    }

    public function translateBatch($sourceLangId, $targetLangId, $force = false, $offset = 0, $limit = 20, array $fields = [])
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anblog_categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'anblog_categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds, (int) $offset, (int) $limit);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anblog_categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No blog category content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $targetIso = Language::getIsoById($targetLangId);
        $categoryIds = array_values(array_unique(array_map(function ($row) {
            return (int) $row['id_anblogcat'];
        }, $sourceRows)));

        $targetRows = $this->fetchRows((int) $targetLangId, $shopIds, null, null, $categoryIds);
        $targetIndex = [];
        foreach ($targetRows as $row) {
            $targetIndex[(int) $row['id_anblogcat']] = $row;
        }

        $sourceIndex = [];
        foreach ($sourceRows as $row) {
            $sourceIndex[(int) $row['id_anblogcat']] = $row;
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
                $key = (int) $row['id_anblogcat'];
                $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                if (!$force && trim((string) $currentTargetValue) !== '') {
                    continue;
                }

                $value = (string) $row[$field];
                if ($value === '') {
                    continue;
                }

                $this->ensureLangRow('anblogcat_lang', [
                    'id_anblogcat' => $key,
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
                $this->updateLangField('anblogcat_lang', ['id_anblogcat' => $id], $targetLangId, $field, $translation, $allowHtml);
                if ($field === 'title') {
                    $currentSlug = isset($targetIndex[$id]) ? (string) $targetIndex[$id]['link_rewrite'] : '';
                    if ($force || trim($currentSlug) === '') {
                        $slug = $this->generateLinkRewrite($translation, $sourceIndex[$id]['link_rewrite'], 'blog-category-' . $id);
                        $this->updateLangField('anblogcat_lang', ['id_anblogcat' => $id], $targetLangId, 'link_rewrite', $slug);
                        if (!isset($targetIndex[$id])) {
                            $targetIndex[$id] = ['link_rewrite' => $slug];
                        } else {
                            $targetIndex[$id]['link_rewrite'] = $slug;
                        }
                    }
                }
                $totalUpdates++;
            }
        }

        return [
            'domain' => 'anblog_categories',
            'processed' => count($sourceRows),
            'translated' => $totalUpdates,
            'message' => sprintf('%d blog category fields updated.', $totalUpdates),
        ];
    }

    public function translateAnblogCategoryField($categoryId, $sourceLangId, $targetLangId, $field, $force = false)
    {
        if (!isset($this->fields[$field])) {
            return [
                'domain' => 'anblog_categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Unknown blog category field.',
            ];
        }

        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anblog_categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'anblog_categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds, null, null, [(int) $categoryId]);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anblog_categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No blog category content found for source language.',
            ];
        }

        $sourceRow = reset($sourceRows);
        $targetRows = $this->fetchRows((int) $targetLangId, $shopIds, null, null, [(int) $categoryId]);
        $targetRow = !empty($targetRows) ? reset($targetRows) : [];
        $currentTargetValue = isset($targetRow[$field]) ? (string) $targetRow[$field] : '';
        if (!$force && trim((string) $currentTargetValue) !== '') {
            return [
                'domain' => 'anblog_categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No fields to translate.',
            ];
        }

        $sourceValue = (string) $sourceRow[$field];
        if ($sourceValue === '') {
            return [
                'domain' => 'anblog_categories',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No fields to translate.',
            ];
        }

        $this->ensureLangRow('anblogcat_lang', ['id_anblogcat' => (int) $categoryId], $targetLangId);

        $translations = $this->provider->translate([$sourceValue], Language::getIsoById($sourceLangId), Language::getIsoById($targetLangId));
        $translation = isset($translations[0]) ? $translations[0] : '';
        $this->updateLangField('anblogcat_lang', ['id_anblogcat' => (int) $categoryId], $targetLangId, $field, $translation, (bool) $this->fields[$field]);

        if ($field === 'title') {
            $currentSlug = isset($targetRow['link_rewrite']) ? (string) $targetRow['link_rewrite'] : '';
            if ($force || trim($currentSlug) === '') {
                $slug = $this->generateLinkRewrite($translation, $sourceRow['link_rewrite'], 'blog-category-' . (int) $categoryId);
                $this->updateLangField('anblogcat_lang', ['id_anblogcat' => (int) $categoryId], $targetLangId, 'link_rewrite', $slug);
            }
        }

        return [
            'domain' => 'anblog_categories',
            'processed' => 1,
            'translated' => 1,
            'message' => '1 blog category field updated.',
        ];
    }

    private function fetchRows($langId, array $shopIds, $offset = null, $limit = null, array $categoryIds = [])
    {
        $sql = new DbQuery();
        $sql->select('cl.id_anblogcat, cl.title, cl.content_text, cl.meta_title, cl.meta_description, cl.meta_keywords, cl.link_rewrite');
        $sql->from('anblogcat_lang', 'cl');
        $sql->innerJoin('anblogcat_shop', 'cs', 'cs.id_anblogcat = cl.id_anblogcat');
        $sql->where('cl.id_lang = ' . (int) $langId);
        $sql->where('cs.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');
        if (!empty($categoryIds)) {
            $sql->where('cl.id_anblogcat IN (' . implode(',', array_map('intval', $categoryIds)) . ')');
        }
        $sql->groupBy('cl.id_anblogcat');
        if ($limit !== null) {
            $sql->limit((int) $limit, (int) $offset);
        }

        $rows = $this->db->executeS($sql);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['id_anblogcat']] = $row;
        }

        return array_values($indexed);
    }
}
