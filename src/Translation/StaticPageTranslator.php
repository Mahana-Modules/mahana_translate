<?php

namespace MahanaTranslate\Translation;

class StaticPageTranslator extends AbstractTranslator
{
    public function translate($sourceLangId, array $targetLangIds, $force = false)
    {
        return [
            'domain' => 'static_pages',
            'translated' => 0,
            'message' => 'Static block translation not yet implemented.',
        ];
    }
}
