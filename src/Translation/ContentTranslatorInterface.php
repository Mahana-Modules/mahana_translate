<?php

namespace MahanaTranslate\Translation;

interface ContentTranslatorInterface
{
    /**
     * @param int $sourceLangId
     * @param int[] $targetLangIds
     *
     * @return array<string, mixed> Summary data for UI feedback.
     */
    public function translate($sourceLangId, array $targetLangIds, $force = false);
}
