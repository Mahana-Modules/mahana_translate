<?php

namespace MahanaTranslate\Translation;

use Configuration;
use Language;

class AnAboutUsTranslator extends AbstractTranslator implements BatchTranslatorInterface
{
    /** @var array<string, bool> */
    private $fields = [
        'title' => false,
        'text' => true,
    ];

    /** @var array<string, string> */
    private $configKeys = [
        'title' => 'an_a_us_title',
        'text' => 'an_a_us_text',
    ];

    public function translate($sourceLangId, array $targetLangIds, $force = false)
    {
        $sourceValues = $this->fetchValues((int) $sourceLangId);
        if (!$this->hasSourceContent($sourceValues)) {
            return [
                'domain' => 'an_about_us',
                'translated' => 0,
                'message' => 'No About Us content found for source language.',
            ];
        }

        $sourceIso = Language::getIsoById((int) $sourceLangId);
        $totalUpdates = 0;

        foreach ($targetLangIds as $targetLangId) {
            if ((int) $targetLangId === (int) $sourceLangId) {
                continue;
            }

            $targetIso = Language::getIsoById((int) $targetLangId);
            $targetValues = $this->fetchValues((int) $targetLangId);

            foreach ($this->fields as $field => $allowHtml) {
                $sourceValue = isset($sourceValues[$field]) ? (string) $sourceValues[$field] : '';
                $currentTargetValue = isset($targetValues[$field]) ? (string) $targetValues[$field] : '';

                if (!$this->shouldTranslate($force, $currentTargetValue, $sourceValue)) {
                    continue;
                }

                if (trim($sourceValue) === '') {
                    continue;
                }

                $translations = $this->provider->translate([$sourceValue], $sourceIso, $targetIso);
                $translation = isset($translations[0]) ? (string) $translations[0] : '';
                $this->updateConfigurationLangField($field, (int) $targetLangId, $translation, $allowHtml);
                $totalUpdates++;
            }
        }

        return [
            'domain' => 'an_about_us',
            'translated' => $totalUpdates,
            'message' => sprintf('%d About Us fields updated.', $totalUpdates),
        ];
    }

    public function getTotalCount($sourceLangId)
    {
        return $this->hasSourceContent($this->fetchValues((int) $sourceLangId)) ? 1 : 0;
    }

    public function translateBatch($sourceLangId, $targetLangId, $force = false, $offset = 0, $limit = 20, array $fields = [])
    {
        if ((int) $offset > 0) {
            return [
                'domain' => 'an_about_us',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No more About Us content found for source language.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'an_about_us',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceValues = $this->fetchValues((int) $sourceLangId);
        if (!$this->hasSourceContent($sourceValues)) {
            return [
                'domain' => 'an_about_us',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No About Us content found for source language.',
            ];
        }

        $targetValues = $this->fetchValues((int) $targetLangId);
        $fieldsToTranslate = $this->fields;
        if (!empty($fields)) {
            $fieldsToTranslate = array_intersect_key($this->fields, array_flip($fields));
        }

        $sourceIso = Language::getIsoById((int) $sourceLangId);
        $targetIso = Language::getIsoById((int) $targetLangId);
        $totalUpdates = 0;

        foreach ($fieldsToTranslate as $field => $allowHtml) {
            $sourceValue = isset($sourceValues[$field]) ? (string) $sourceValues[$field] : '';
            $currentTargetValue = isset($targetValues[$field]) ? (string) $targetValues[$field] : '';

            if (!$this->shouldTranslate($force, $currentTargetValue, $sourceValue)) {
                continue;
            }

            if (trim($sourceValue) === '') {
                continue;
            }

            $translations = $this->provider->translate([$sourceValue], $sourceIso, $targetIso);
            $translation = isset($translations[0]) ? (string) $translations[0] : '';
            $this->updateConfigurationLangField($field, (int) $targetLangId, $translation, $allowHtml);
            $totalUpdates++;
        }

        return [
            'domain' => 'an_about_us',
            'processed' => 1,
            'translated' => $totalUpdates,
            'message' => sprintf('%d About Us fields updated.', $totalUpdates),
        ];
    }

    public function translateAnAboutUsField($sourceLangId, $targetLangId, $field, $force = false)
    {
        if (!isset($this->fields[$field])) {
            return [
                'domain' => 'an_about_us',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Unknown About Us field.',
            ];
        }

        if ((int) $targetLangId === (int) $sourceLangId) {
            return [
                'domain' => 'an_about_us',
                'processed' => 0,
                'translated' => 0,
                'message' => 'Target language matches source.',
            ];
        }

        $sourceValues = $this->fetchValues((int) $sourceLangId);
        $sourceValue = isset($sourceValues[$field]) ? (string) $sourceValues[$field] : '';
        if (trim($sourceValue) === '') {
            return [
                'domain' => 'an_about_us',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No fields to translate.',
            ];
        }

        $targetValues = $this->fetchValues((int) $targetLangId);
        $currentTargetValue = isset($targetValues[$field]) ? (string) $targetValues[$field] : '';
        if (!$this->shouldTranslate($force, $currentTargetValue, $sourceValue)) {
            return [
                'domain' => 'an_about_us',
                'processed' => 0,
                'translated' => 0,
                'message' => 'No fields to translate.',
            ];
        }

        $translations = $this->provider->translate(
            [$sourceValue],
            Language::getIsoById((int) $sourceLangId),
            Language::getIsoById((int) $targetLangId)
        );
        $translation = isset($translations[0]) ? (string) $translations[0] : '';
        $this->updateConfigurationLangField($field, (int) $targetLangId, $translation, (bool) $this->fields[$field]);

        return [
            'domain' => 'an_about_us',
            'processed' => 1,
            'translated' => 1,
            'message' => '1 About Us field updated.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fetchValues($langId)
    {
        $values = [];
        foreach ($this->configKeys as $field => $configKey) {
            $values[$field] = (string) Configuration::get($configKey, (int) $langId);
        }

        return $values;
    }

    private function hasSourceContent(array $sourceValues)
    {
        foreach ($this->fields as $field => $allowHtml) {
            if (trim((string) (isset($sourceValues[$field]) ? $sourceValues[$field] : '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function updateConfigurationLangField($field, $targetLangId, $value, $allowHtml = false)
    {
        if (!isset($this->configKeys[$field])) {
            return;
        }

        $configKey = $this->configKeys[$field];
        $allValues = [];
        foreach (Language::getLanguages(false) as $language) {
            $langId = (int) $language['id_lang'];
            $allValues[$langId] = (string) Configuration::get($configKey, $langId);
        }

        $allValues[(int) $targetLangId] = (string) $value;
        Configuration::updateValue($configKey, $allValues, (bool) $allowHtml);
    }
}
