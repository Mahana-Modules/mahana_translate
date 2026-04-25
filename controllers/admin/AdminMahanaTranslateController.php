<?php

require_once _PS_MODULE_DIR_ . 'mahana_translate/src/autoload.php';

use MahanaTranslate\Provider\ProviderException;
use MahanaTranslate\Translation\TranslationManager;
use PrestaShop\PrestaShop\Core\Feature\TokenInUrls;

class AdminMahanaTranslateController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->module = Module::getInstanceByName('mahana_translate');
        parent::__construct();
    }

    public function ajaxProcessRunTranslationJob()
    {
        $this->validateToken();
        if (!$this->module instanceof Mahana_Translate) {
            $this->jsonError($this->module ? $this->module->l('Module not loaded.', 'AdminMahanaTranslateController') : 'Module not loaded.');
        }

        $sourceLangId = (int) Tools::getValue('source_lang');
        $targetLangIds = array_map('intval', (array) Tools::getValue('target_lang', []));
        $targetLangIds = array_values(array_unique(array_filter($targetLangIds)));
        $domains = (array) Tools::getValue('domains', []);
        $force = (bool) Tools::getValue('force', false);

        $errors = [];
        if ($sourceLangId <= 0) {
            $errors[] = $this->module->l('Select a source language.', 'AdminMahanaTranslateController');
        }

        if (empty($targetLangIds)) {
            $errors[] = $this->module->l('Select at least one target language.', 'AdminMahanaTranslateController');
        }

        if (!empty($targetLangIds) && in_array($sourceLangId, $targetLangIds, true)) {
            $targetLangIds = array_values(array_filter($targetLangIds, function ($langId) use ($sourceLangId) {
                return $langId !== $sourceLangId;
            }));
        }

        if (empty($targetLangIds)) {
            $errors[] = $this->module->l('Target languages must be different from the source.', 'AdminMahanaTranslateController');
        }

        $availableDomains = $this->module->getDomainKeys();
        $domains = array_values(array_intersect($domains, $availableDomains));
        if (empty($domains)) {
            $errors[] = $this->module->l('Select at least one domain.', 'AdminMahanaTranslateController');
        }

        if (!empty($errors)) {
            $this->jsonError(implode(' ', $errors));
        }

        try {
            $provider = $this->module->getTranslationProvider();
            $manager = new TranslationManager($provider);
            $reports = $manager->translate($domains, $sourceLangId, $targetLangIds, $force);
        } catch (ProviderException $exception) {
            $this->jsonError($exception->getMessage());
        } catch (Exception $exception) {
            $this->jsonError($exception->getMessage());
        }

        $this->jsonSuccess([
            'reports' => $reports,
        ]);
    }

    public function ajaxProcessGetTranslationTotals()
    {
        $this->validateToken();
        if (!$this->module instanceof Mahana_Translate) {
            $this->jsonError($this->module ? $this->module->l('Module not loaded.', 'AdminMahanaTranslateController') : 'Module not loaded.');
        }

        $sourceLangId = (int) Tools::getValue('source_lang');
        $domains = (array) Tools::getValue('domains', []);
        $availableDomains = $this->module->getDomainKeys();
        $domains = array_values(array_unique(array_intersect($domains, $availableDomains)));
        if (empty($domains)) {
            $domains = $availableDomains;
        }

        if ($sourceLangId <= 0) {
            $this->jsonError($this->module->l('Select a source language.', 'AdminMahanaTranslateController'));
        }

        try {
            $provider = $this->module->getTranslationProvider();
            $manager = new TranslationManager($provider);
            $totals = $manager->getTotals($domains, $sourceLangId);
        } catch (ProviderException $exception) {
            $this->jsonError($exception->getMessage());
        } catch (Exception $exception) {
            $this->jsonError($exception->getMessage());
        }

        $this->jsonSuccess([
            'totals' => $totals,
        ]);
    }

    public function ajaxProcessRunTranslationBatch()
    {
        $this->validateToken();
        if (!$this->module instanceof Mahana_Translate) {
            $this->jsonError($this->module ? $this->module->l('Module not loaded.', 'AdminMahanaTranslateController') : 'Module not loaded.');
        }

        $sourceLangId = (int) Tools::getValue('source_lang');
        $targetLangId = (int) Tools::getValue('target_lang');
        $domain = (string) Tools::getValue('domain');
        $fields = (array) Tools::getValue('fields', []);
        $offset = max(0, (int) Tools::getValue('offset', 0));
        $limit = (int) Tools::getValue('limit', 20);
        $force = (bool) Tools::getValue('force', false);

        if ($limit <= 0) {
            $limit = 20;
        } elseif ($limit > 200) {
            $limit = 200;
        }

        $errors = [];
        if ($sourceLangId <= 0) {
            $errors[] = $this->module->l('Select a source language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId <= 0) {
            $errors[] = $this->module->l('Select a target language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId === $sourceLangId) {
            $errors[] = $this->module->l('Target languages must be different from the source.', 'AdminMahanaTranslateController');
        }

        $availableDomains = $this->module->getDomainKeys();
        if (!$domain || !in_array($domain, $availableDomains, true)) {
            $errors[] = $this->module->l('Select a valid domain.', 'AdminMahanaTranslateController');
        }

        if (!empty($errors)) {
            $this->jsonError(implode(' ', $errors));
        }

        try {
            $provider = $this->module->getTranslationProvider();
            $manager = new TranslationManager($provider);
            $result = $manager->translateBatch($domain, $sourceLangId, $targetLangId, $force, $offset, $limit, $fields);
        } catch (ProviderException $exception) {
            $this->jsonError($exception->getMessage());
        } catch (Exception $exception) {
            $this->jsonError($exception->getMessage());
        }

        $this->jsonSuccess($result);
    }

    public function ajaxProcessRunProductTranslationBatch()
    {
        $this->validateToken();
        if (!$this->module instanceof Mahana_Translate) {
            $this->jsonError($this->module ? $this->module->l('Module not loaded.', 'AdminMahanaTranslateController') : 'Module not loaded.');
        }

        $productId = (int) Tools::getValue('id_product');
        $sourceLangId = (int) Tools::getValue('source_lang');
        $targetLangId = (int) Tools::getValue('target_lang');
        $field = (string) Tools::getValue('field');
        $force = (bool) Tools::getValue('force', false);

        $errors = [];
        if ($productId <= 0) {
            $errors[] = $this->module->l('Missing product ID.', 'AdminMahanaTranslateController');
        }
        if ($sourceLangId <= 0) {
            $errors[] = $this->module->l('Select a source language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId <= 0) {
            $errors[] = $this->module->l('Select a target language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId === $sourceLangId) {
            $errors[] = $this->module->l('Target languages must be different from the source.', 'AdminMahanaTranslateController');
        }
        if ($field === '') {
            $errors[] = $this->module->l('Missing product field.', 'AdminMahanaTranslateController');
        }

        if (!empty($errors)) {
            $this->jsonError(implode(' ', $errors));
        }

        try {
            $provider = $this->module->getTranslationProvider();
            $manager = new TranslationManager($provider);
            $result = $manager->translateProductField($productId, $sourceLangId, $targetLangId, $field, $force);
        } catch (ProviderException $exception) {
            $this->jsonError($exception->getMessage());
        } catch (Exception $exception) {
            $this->jsonError($exception->getMessage());
        }

        $this->jsonSuccess($result);
    }

    public function ajaxProcessRunCategoryTranslationBatch()
    {
        $this->validateToken();
        if (!$this->module instanceof Mahana_Translate) {
            $this->jsonError($this->module ? $this->module->l('Module not loaded.', 'AdminMahanaTranslateController') : 'Module not loaded.');
        }

        $categoryId = (int) Tools::getValue('id_category');
        $sourceLangId = (int) Tools::getValue('source_lang');
        $targetLangId = (int) Tools::getValue('target_lang');
        $field = (string) Tools::getValue('field');
        $force = (bool) Tools::getValue('force', false);

        $errors = [];
        if ($categoryId <= 0) {
            $errors[] = $this->module->l('Missing category ID.', 'AdminMahanaTranslateController');
        }
        if ($sourceLangId <= 0) {
            $errors[] = $this->module->l('Select a source language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId <= 0) {
            $errors[] = $this->module->l('Select a target language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId === $sourceLangId) {
            $errors[] = $this->module->l('Target languages must be different from the source.', 'AdminMahanaTranslateController');
        }
        if ($field === '') {
            $errors[] = $this->module->l('Missing category field.', 'AdminMahanaTranslateController');
        }

        if (!empty($errors)) {
            $this->jsonError(implode(' ', $errors));
        }

        try {
            $provider = $this->module->getTranslationProvider();
            $manager = new TranslationManager($provider);
            $result = $manager->translateCategoryField($categoryId, $sourceLangId, $targetLangId, $field, $force);
        } catch (ProviderException $exception) {
            $this->jsonError($exception->getMessage());
        } catch (Exception $exception) {
            $this->jsonError($exception->getMessage());
        }

        $this->jsonSuccess($result);
    }

    public function ajaxProcessRunAnblogPostTranslationBatch()
    {
        $this->validateToken();
        if (!$this->module instanceof Mahana_Translate) {
            $this->jsonError($this->module ? $this->module->l('Module not loaded.', 'AdminMahanaTranslateController') : 'Module not loaded.');
        }

        $postId = (int) Tools::getValue('id_anblog_blog');
        $sourceLangId = (int) Tools::getValue('source_lang');
        $targetLangId = (int) Tools::getValue('target_lang');
        $field = (string) Tools::getValue('field');
        $force = (bool) Tools::getValue('force', false);

        $errors = [];
        if ($postId <= 0) {
            $errors[] = $this->module->l('Missing blog post ID.', 'AdminMahanaTranslateController');
        }
        if ($sourceLangId <= 0) {
            $errors[] = $this->module->l('Select a source language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId <= 0) {
            $errors[] = $this->module->l('Select a target language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId === $sourceLangId) {
            $errors[] = $this->module->l('Target languages must be different from the source.', 'AdminMahanaTranslateController');
        }
        if ($field === '') {
            $errors[] = $this->module->l('Missing blog post field.', 'AdminMahanaTranslateController');
        }

        if (!empty($errors)) {
            $this->jsonError(implode(' ', $errors));
        }

        try {
            $provider = $this->module->getTranslationProvider();
            $manager = new TranslationManager($provider);
            $result = $manager->translateAnblogPostField($postId, $sourceLangId, $targetLangId, $field, $force);
        } catch (ProviderException $exception) {
            $this->jsonError($exception->getMessage());
        } catch (Exception $exception) {
            $this->jsonError($exception->getMessage());
        }

        $this->jsonSuccess($result);
    }

    public function ajaxProcessRunAnblogCategoryTranslationBatch()
    {
        $this->validateToken();
        if (!$this->module instanceof Mahana_Translate) {
            $this->jsonError($this->module ? $this->module->l('Module not loaded.', 'AdminMahanaTranslateController') : 'Module not loaded.');
        }

        $categoryId = (int) Tools::getValue('id_anblogcat');
        $sourceLangId = (int) Tools::getValue('source_lang');
        $targetLangId = (int) Tools::getValue('target_lang');
        $field = (string) Tools::getValue('field');
        $force = (bool) Tools::getValue('force', false);

        $errors = [];
        if ($categoryId <= 0) {
            $errors[] = $this->module->l('Missing blog category ID.', 'AdminMahanaTranslateController');
        }
        if ($sourceLangId <= 0) {
            $errors[] = $this->module->l('Select a source language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId <= 0) {
            $errors[] = $this->module->l('Select a target language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId === $sourceLangId) {
            $errors[] = $this->module->l('Target languages must be different from the source.', 'AdminMahanaTranslateController');
        }
        if ($field === '') {
            $errors[] = $this->module->l('Missing blog category field.', 'AdminMahanaTranslateController');
        }

        if (!empty($errors)) {
            $this->jsonError(implode(' ', $errors));
        }

        try {
            $provider = $this->module->getTranslationProvider();
            $manager = new TranslationManager($provider);
            $result = $manager->translateAnblogCategoryField($categoryId, $sourceLangId, $targetLangId, $field, $force);
        } catch (ProviderException $exception) {
            $this->jsonError($exception->getMessage());
        } catch (Exception $exception) {
            $this->jsonError($exception->getMessage());
        }

        $this->jsonSuccess($result);
    }

    public function ajaxProcessRunAnAboutUsTranslationBatch()
    {
        $this->validateToken();
        if (!$this->module instanceof Mahana_Translate) {
            $this->jsonError($this->module ? $this->module->l('Module not loaded.', 'AdminMahanaTranslateController') : 'Module not loaded.');
        }

        $sourceLangId = (int) Tools::getValue('source_lang');
        $targetLangId = (int) Tools::getValue('target_lang');
        $field = (string) Tools::getValue('field');
        $force = (bool) Tools::getValue('force', false);

        $errors = [];
        if ($sourceLangId <= 0) {
            $errors[] = $this->module->l('Select a source language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId <= 0) {
            $errors[] = $this->module->l('Select a target language.', 'AdminMahanaTranslateController');
        }
        if ($targetLangId === $sourceLangId) {
            $errors[] = $this->module->l('Target languages must be different from the source.', 'AdminMahanaTranslateController');
        }
        if ($field === '') {
            $errors[] = $this->module->l('Missing About Us field.', 'AdminMahanaTranslateController');
        }

        if (!empty($errors)) {
            $this->jsonError(implode(' ', $errors));
        }

        try {
            $provider = $this->module->getTranslationProvider();
            $manager = new TranslationManager($provider);
            $result = $manager->translateAnAboutUsField($sourceLangId, $targetLangId, $field, $force);
        } catch (ProviderException $exception) {
            $this->jsonError($exception->getMessage());
        } catch (Exception $exception) {
            $this->jsonError($exception->getMessage());
        }

        $this->jsonSuccess($result);
    }

    private function validateToken()
    {
        if (TokenInUrls::isDisabled() || $this->checkToken()) {
            return;
        }

        $token = (string) Tools::getValue('token');
        $controllerToken = (string) Tools::getValue('controller_token');
        $validTokens = [
            (string) Tools::getAdminTokenLite('AdminMahanaTranslate'),
            (string) Tools::getAdminTokenLite('AdminModules'),
        ];

        if (in_array($token, $validTokens, true) || in_array($controllerToken, $validTokens, true)) {
            return;
        }

        $this->jsonError($this->module ? $this->module->l('Invalid security token.', 'AdminMahanaTranslateController') : 'Invalid security token.');
    }

    public function jsonError($message)
    {
        die(json_encode([
            'success' => false,
            'message' => $message,
        ]));
    }

    public function jsonSuccess(array $payload)
    {
        die(json_encode(array_merge([
            'success' => true,
        ], $payload)));
    }
}
