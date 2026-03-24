<?php

namespace MahanaTranslate\Translation;

use DbQuery;
use Language;

class AnblogPostTranslator extends AbstractTranslator implements BatchTranslatorInterface
{
    /** @var array<string, bool> */
    private $fields = [
        'meta_title' => false,
        'meta_description' => false,
        'meta_keywords' => false,
        'tags' => false,
        'description' => true,
        'content' => true,
    ];

    public function translate($sourceLangId, array $targetLangIds, $force = false)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anblog_posts',
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anblog_posts',
                'translated' => 0,
                'message' => 'No blog post content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $sourceIndex = [];
        foreach ($sourceRows as $row) {
            $sourceIndex[(int) $row['id_anblog_blog']] = $row;
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
                $targetIndex[(int) $row['id_anblog_blog']] = $row;
            }

            foreach ($this->fields as $field => $allowHtml) {
                $texts = [];
                $indexMap = [];

                foreach ($sourceRows as $row) {
                    $key = (int) $row['id_anblog_blog'];
                    $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                    if (!$force && trim((string) $currentTargetValue) !== '') {
                        continue;
                    }

                    $value = (string) $row[$field];
                    if ($value === '') {
                        continue;
                    }

                    $this->ensureLangRow('anblog_blog_lang', [
                        'id_anblog_blog' => (int) $row['id_anblog_blog'],
                    ], $targetLangId);

                    $texts[] = $value;
                    $indexMap[] = (int) $row['id_anblog_blog'];
                }

                if (empty($texts)) {
                    continue;
                }

                $translations = $this->provider->translate($texts, $sourceIso, $targetIso);
                foreach ($translations as $i => $translation) {
                    $id = $indexMap[$i];
                    $this->updateLangField('anblog_blog_lang', ['id_anblog_blog' => $id], $targetLangId, $field, $translation, $allowHtml);
                    if ($field === 'meta_title') {
                        $sourceRow = $sourceIndex[$id];
                        $currentSlug = isset($targetIndex[$id]) ? (string) $targetIndex[$id]['link_rewrite'] : '';
                        if ($force || trim($currentSlug) === '') {
                            $slug = $this->generateLinkRewrite($translation, $sourceRow['link_rewrite'], 'blog-post-' . $id);
                            $this->updateLangField('anblog_blog_lang', ['id_anblog_blog' => $id], $targetLangId, 'link_rewrite', $slug);
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
            'domain' => 'anblog_posts',
            'translated' => $totalUpdates,
            'message' => sprintf('%d blog post fields updated.', $totalUpdates),
        ];
    }

    public function getTotalCount($sourceLangId)
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return 0;
        }

        $sql = new DbQuery();
        $sql->select('COUNT(DISTINCT bl.id_anblog_blog)');
        $sql->from('anblog_blog_lang', 'bl');
        $sql->innerJoin('anblog_blog_shop', 'bs', 'bs.id_anblog_blog = bl.id_anblog_blog');
        $sql->where('bl.id_lang = ' . (int) $sourceLangId);
        $sql->where('bs.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');

        return (int) $this->db->getValue($sql);
    }

    public function translateBatch($sourceLangId, $targetLangId, $force = false, $offset = 0, $limit = 20, array $fields = [])
    {
        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anblog_posts',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'anblog_posts',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds, (int) $offset, (int) $limit);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anblog_posts',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No blog post content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById($sourceLangId);
        $targetIso = Language::getIsoById($targetLangId);
        $postIds = array_values(array_unique(array_map(function ($row) {
            return (int) $row['id_anblog_blog'];
        }, $sourceRows)));

        $targetRows = $this->fetchRows((int) $targetLangId, $shopIds, null, null, $postIds);
        $targetIndex = [];
        foreach ($targetRows as $row) {
            $targetIndex[(int) $row['id_anblog_blog']] = $row;
        }

        $sourceIndex = [];
        foreach ($sourceRows as $row) {
            $sourceIndex[(int) $row['id_anblog_blog']] = $row;
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
                $key = (int) $row['id_anblog_blog'];
                $currentTargetValue = isset($targetIndex[$key]) ? $targetIndex[$key][$field] : '';
                if (!$force && trim((string) $currentTargetValue) !== '') {
                    continue;
                }

                $value = (string) $row[$field];
                if ($value === '') {
                    continue;
                }

                $this->ensureLangRow('anblog_blog_lang', [
                    'id_anblog_blog' => $key,
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
                $this->updateLangField('anblog_blog_lang', ['id_anblog_blog' => $id], $targetLangId, $field, $translation, $allowHtml);
                if ($field === 'meta_title') {
                    $currentSlug = isset($targetIndex[$id]) ? (string) $targetIndex[$id]['link_rewrite'] : '';
                    if ($force || trim($currentSlug) === '') {
                        $slug = $this->generateLinkRewrite($translation, $sourceIndex[$id]['link_rewrite'], 'blog-post-' . $id);
                        $this->updateLangField('anblog_blog_lang', ['id_anblog_blog' => $id], $targetLangId, 'link_rewrite', $slug);
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
            'domain' => 'anblog_posts',
            'processed' => count($sourceRows),
            'translated' => $totalUpdates,
            'message' => sprintf('%d blog post fields updated.', $totalUpdates),
        ];
    }

    public function translateAnblogPostField($postId, $sourceLangId, $targetLangId, $field, $force = false)
    {
        if (!isset($this->fields[$field])) {
            return [
                'domain' => 'anblog_posts',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Unknown blog post field.',
            ];
        }

        $shopIds = $this->getShopIds();
        if (empty($shopIds)) {
            return [
                'domain' => 'anblog_posts',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No shop context available.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'anblog_posts',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceRows = $this->fetchRows((int) $sourceLangId, $shopIds, null, null, [(int) $postId]);
        if (empty($sourceRows)) {
            return [
                'domain' => 'anblog_posts',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No blog post content found for source language.',
            ];
        }

        $sourceRow = reset($sourceRows);
        $targetRows = $this->fetchRows((int) $targetLangId, $shopIds, null, null, [(int) $postId]);
        $targetRow = !empty($targetRows) ? reset($targetRows) : [];
        $currentTargetValue = isset($targetRow[$field]) ? (string) $targetRow[$field] : '';
        if (!$force && trim($currentTargetValue) !== '') {
            return [
                'domain' => 'anblog_posts',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No fields to translate.',
            ];
        }

        $sourceValue = (string) $sourceRow[$field];
        if ($sourceValue === '') {
            return [
                'domain' => 'anblog_posts',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No fields to translate.',
            ];
        }

        $this->ensureLangRow('anblog_blog_lang', ['id_anblog_blog' => (int) $postId], $targetLangId);

        $translations = $this->provider->translate([$sourceValue], Language::getIsoById($sourceLangId), Language::getIsoById($targetLangId));
        $translation = isset($translations[0]) ? $translations[0] : '';
        $this->updateLangField('anblog_blog_lang', ['id_anblog_blog' => (int) $postId], $targetLangId, $field, $translation, (bool) $this->fields[$field]);

        if ($field === 'meta_title') {
            $currentSlug = isset($targetRow['link_rewrite']) ? (string) $targetRow['link_rewrite'] : '';
            if ($force || trim($currentSlug) === '') {
                $slug = $this->generateLinkRewrite($translation, $sourceRow['link_rewrite'], 'blog-post-' . (int) $postId);
                $this->updateLangField('anblog_blog_lang', ['id_anblog_blog' => (int) $postId], $targetLangId, 'link_rewrite', $slug);
            }
        }

        return [
            'domain' => 'anblog_posts',
            'processed' => 1,
            'translated' => 1,
            'message' => '1 blog post field updated.',
        ];
    }

    private function fetchRows($langId, array $shopIds, $offset = null, $limit = null, array $postIds = [])
    {
        $sql = new DbQuery();
        $sql->select('bl.id_anblog_blog, bl.meta_title, bl.meta_description, bl.meta_keywords, bl.tags, bl.description, bl.content, bl.link_rewrite');
        $sql->from('anblog_blog_lang', 'bl');
        $sql->innerJoin('anblog_blog_shop', 'bs', 'bs.id_anblog_blog = bl.id_anblog_blog');
        $sql->where('bl.id_lang = ' . (int) $langId);
        $sql->where('bs.id_shop IN (' . implode(',', array_map('intval', $shopIds)) . ')');
        if (!empty($postIds)) {
            $sql->where('bl.id_anblog_blog IN (' . implode(',', array_map('intval', $postIds)) . ')');
        }
        $sql->groupBy('bl.id_anblog_blog');
        if ($limit !== null) {
            $sql->limit((int) $limit, (int) $offset);
        }

        $rows = $this->db->executeS($sql);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['id_anblog_blog']] = $row;
        }

        return array_values($indexed);
    }
}
