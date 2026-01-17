<?php

namespace MahanaTranslate\Provider;

interface TranslationProviderInterface
{
    /**
     * @param string[] $texts
     * @param array<string, mixed> $options
     *
     * @return string[]
     */
    public function translate(array $texts, string $sourceIso, string $targetIso, array $options = []);
}
