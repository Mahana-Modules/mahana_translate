<?php

namespace MahanaTranslate\Translation;

interface BatchTranslatorInterface
{
    /**
     * @param int $sourceLangId
     *
     * @return int
     */
    public function getTotalCount($sourceLangId);

    /**
     * @param int $sourceLangId
     * @param int $targetLangId
     * @param bool $force
     * @param int $offset
     * @param int $limit
     *
     * @return array<string, mixed>
     */
    public function translateBatch($sourceLangId, $targetLangId, $force = false, $offset = 0, $limit = 20, array $fields = []);
}
