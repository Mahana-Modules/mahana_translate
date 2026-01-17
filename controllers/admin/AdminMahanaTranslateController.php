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
