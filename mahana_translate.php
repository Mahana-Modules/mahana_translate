<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

use MahanaTranslate\Provider\ProviderException;
use MahanaTranslate\Provider\ProviderFactory;
use MahanaTranslate\Provider\TranslationProviderInterface;
use MahanaTranslate\Translation\TranslationManager;

class Mahana_Translate extends Module
{
    private const MASK_CHAR = '*';
    private const CONFIG_PREFIX = 'MAHANA_TRANSLATE_';
    private const CONFIG_PROVIDER = self::CONFIG_PREFIX . 'PROVIDER';
    private const CONFIG_OPENAI_KEY = self::CONFIG_PREFIX . 'OPENAI_KEY';
    private const CONFIG_OPENAI_MODEL = self::CONFIG_PREFIX . 'OPENAI_MODEL';
    private const CONFIG_GOOGLE_KEY = self::CONFIG_PREFIX . 'GOOGLE_KEY';
    private const CONFIG_GOOGLE_PROJECT = self::CONFIG_PREFIX . 'GOOGLE_PROJECT';

    private const PROVIDER_OPENAI = 'openai';
    private const PROVIDER_GOOGLE = 'google';

    /** @var ProviderFactory|null */
    private $providerFactory;

    /** @var array<string, string> */
    private $providers = [
        self::PROVIDER_OPENAI => 'ChatGPT (OpenAI API)',
        self::PROVIDER_GOOGLE => 'Google Translate API',
    ];

    /** @var array<string, array{label: string, fields: string[]}> */
    private $domains = [
        'products' => [
            'label' => 'Products',
            'fields' => ['name', 'description_short', 'description', 'meta_title', 'meta_description'],
        ],
        'categories' => [
            'label' => 'Categories',
            'fields' => ['name', 'description', 'additional_description', 'meta_title', 'meta_description'],
        ],
        'cms_pages' => [
            'label' => 'CMS pages',
            'fields' => ['meta_title', 'meta_description', 'content'],
        ],
        'anblog_posts' => [
            'label' => 'Blog posts',
            'fields' => ['meta_title', 'meta_description', 'meta_keywords', 'tags', 'description', 'content'],
        ],
        'anblog_categories' => [
            'label' => 'Blog categories',
            'fields' => ['title', 'content_text', 'meta_title', 'meta_description', 'meta_keywords'],
        ],
        'anmegamenu_menus' => [
            'label' => 'Mega Menu menus',
            'fields' => ['title'],
        ],
        'anmegamenu_tabs' => [
            'label' => 'Mega Menu tabs',
            'fields' => ['title', 'label'],
        ],
        'anmegamenu_content' => [
            'label' => 'Mega Menu content',
            'fields' => ['title', 'text'],
        ],
    ];

    public function __construct()
    {
        $this->name = 'mahana_translate';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'Mahana';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Mahana Translate', [], 'Modules.Mahanatranslate.Admin');
        $this->description = $this->trans('Initial module scaffold for translation features.', [], 'Modules.Mahanatranslate.Admin');
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue(self::CONFIG_PROVIDER, self::PROVIDER_OPENAI)
            && Configuration::updateValue(self::CONFIG_OPENAI_MODEL, 'gpt-4o-mini')
            && Configuration::updateValue(self::CONFIG_OPENAI_KEY, '')
            && Configuration::updateValue(self::CONFIG_GOOGLE_KEY, '')
            && Configuration::updateValue(self::CONFIG_GOOGLE_PROJECT, '')
            && $this->registerHook('displayAdminProductsExtra')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->installTab();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName(self::CONFIG_PROVIDER)
            && Configuration::deleteByName(self::CONFIG_OPENAI_MODEL)
            && Configuration::deleteByName(self::CONFIG_OPENAI_KEY)
            && Configuration::deleteByName(self::CONFIG_GOOGLE_KEY)
            && Configuration::deleteByName(self::CONFIG_GOOGLE_PROJECT)
            && $this->uninstallTab();
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitMahanaTranslateModule')) {
            $errors = $this->processConfiguration();
            if (empty($errors)) {
                $output .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Mahanatranslate.Admin'));
            } else {
                foreach ($errors as $error) {
                    $output .= $this->displayError($error);
                }
            }
        }

        return $output . $this->renderTabbedContent($this->renderForm(), $this->renderJobForm());
    }

    /**
     * @return string[]
     */
    private function processConfiguration()
    {
        $errors = [];
        $provider = Tools::getValue(self::CONFIG_PROVIDER);
        if (!array_key_exists($provider, $this->providers)) {
            $errors[] = $this->trans('Unknown translation provider.', [], 'Modules.Mahanatranslate.Admin');
        }

        $openAiKey = trim((string) Tools::getValue(self::CONFIG_OPENAI_KEY));
        $openAiModel = trim((string) Tools::getValue(self::CONFIG_OPENAI_MODEL));
        $googleKey = trim((string) Tools::getValue(self::CONFIG_GOOGLE_KEY));
        $googleProject = trim((string) Tools::getValue(self::CONFIG_GOOGLE_PROJECT));
        $storedOpenAiKey = (string) Configuration::get(self::CONFIG_OPENAI_KEY);
        $storedGoogleKey = (string) Configuration::get(self::CONFIG_GOOGLE_KEY);

        $openAiKey = $this->resolveSecretSubmission($openAiKey, $storedOpenAiKey);
        $googleKey = $this->resolveSecretSubmission($googleKey, $storedGoogleKey);

        if ($provider === self::PROVIDER_OPENAI && $openAiKey === '') {
            $errors[] = $this->trans('OpenAI API key is required to use ChatGPT.', [], 'Modules.Mahanatranslate.Admin');
        }

        if ($provider === self::PROVIDER_GOOGLE && $googleKey === '') {
            $errors[] = $this->trans('Google Translate API key is required.', [], 'Modules.Mahanatranslate.Admin');
        }

        if (!empty($errors)) {
            return $errors;
        }

        Configuration::updateValue(self::CONFIG_PROVIDER, $provider);
        Configuration::updateValue(self::CONFIG_OPENAI_KEY, $openAiKey);
        Configuration::updateValue(self::CONFIG_OPENAI_MODEL, $openAiModel ?: 'gpt-4o-mini');
        Configuration::updateValue(self::CONFIG_GOOGLE_KEY, $googleKey);
        Configuration::updateValue(self::CONFIG_GOOGLE_PROJECT, $googleProject);

        return $errors;
    }

    private function renderForm()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Translation settings', [], 'Modules.Mahanatranslate.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->trans('Translation provider', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => self::CONFIG_PROVIDER,
                        'options' => [
                            'query' => $this->getProviderOptions(),
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $this->trans('Select the API used to translate storefront content.', [], 'Modules.Mahanatranslate.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('OpenAI API key', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => self::CONFIG_OPENAI_KEY,
                        'class' => 'fixed-width-xxl mahana-secret-field',
                        'desc' => $this->trans('Stored encrypted. Required when ChatGPT is selected. Leave the masked value unchanged to keep the current key.', [], 'Modules.Mahanatranslate.Admin'),
                        'form_group_class' => 'provider-field provider-openai',
                        'autocomplete' => 'off',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('OpenAI model', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => self::CONFIG_OPENAI_MODEL,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->trans('Default: gpt-4o-mini.', [], 'Modules.Mahanatranslate.Admin'),
                        'form_group_class' => 'provider-field provider-openai',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Google API key', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => self::CONFIG_GOOGLE_KEY,
                        'class' => 'fixed-width-xxl mahana-secret-field',
                        'desc' => $this->trans('Required when Google Translate is selected. Leave the masked value unchanged to keep the current key.', [], 'Modules.Mahanatranslate.Admin'),
                        'form_group_class' => 'provider-field provider-google',
                        'autocomplete' => 'off',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Google project/region', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => self::CONFIG_GOOGLE_PROJECT,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->trans('Optional helper to store the Cloud project or region identifier.', [], 'Modules.Mahanatranslate.Admin'),
                        'form_group_class' => 'provider-field provider-google',
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMahanaTranslateModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
        ];

        return $helper->generateForm([$fieldsForm]) . $this->renderProviderToggleScript() . $this->renderSecretFieldScript();
    }

    private function renderTabbedContent($settingsForm, $jobForm)
    {
        $activeTab = 'settings';
        if (Tools::getValue('mahana_tab') === 'jobs') {
            $activeTab = 'jobs';
        }

        $tabs = [
            'settings' => [
                'label' => $this->trans('Settings', [], 'Modules.Mahanatranslate.Admin'),
                'content' => $settingsForm,
            ],
            'jobs' => [
                'label' => $this->trans('Translation jobs', [], 'Modules.Mahanatranslate.Admin'),
                'content' => $jobForm,
            ],
        ];

        $tabLinks = '';
        $tabPanels = '';
        foreach ($tabs as $key => $tab) {
            $isActive = ($key === $activeTab);
            $tabId = 'mahana-translate-' . $key;
            $tabLinks .= '<li class="nav-item">'
                . '<a class="nav-link' . ($isActive ? ' active' : '') . '"'
                . ' href="#' . $tabId . '"'
                . ' data-mahana-tab="' . $key . '"'
                . ' role="tab"'
                . ' aria-selected="' . ($isActive ? 'true' : 'false') . '"'
                . '>'
                . $tab['label']
                . '</a></li>';

            $tabPanels .= '<div class="tab-pane mahana-translate-tab-pane' . ($isActive ? ' active' : '') . '"'
                . ' id="' . $tabId . '"'
                . ' data-mahana-tab="' . $key . '"'
                . ' role="tabpanel">'
                . $tab['content']
                . '</div>';
        }

        $activeTabJson = json_encode($activeTab);

        return '<div class="mahana-translate-tabs">'
            . '<ul class="nav nav-tabs" id="mahana-translate-tabs" role="tablist">'
            . $tabLinks
            . '</ul>'
            . '<div class="tab-content" id="mahana-translate-tabs-content">'
            . $tabPanels
            . '</div>'
            . '</div>'
            . '<style>'
            . '.mahana-translate-tabs .nav-tabs{border-bottom:1px solid #d0d7de;}'
            . '.mahana-translate-tabs .nav-tabs .nav-link{border:none;border-bottom:2px solid transparent;}'
            . '.mahana-translate-tabs .nav-tabs .nav-link.active{border-bottom-color:#0077c8;font-weight:600;}'
            . '#mahana-translate-tabs-content .mahana-translate-tab-pane{display:none;}'
            . '#mahana-translate-tabs-content .mahana-translate-tab-pane.active{display:block;}'
            . '</style>'
            . '<script>'
            . 'document.addEventListener("DOMContentLoaded",function(){'
            . 'var tabs=document.querySelectorAll("#mahana-translate-tabs a[data-mahana-tab]");'
            . 'var panes=document.querySelectorAll("#mahana-translate-tabs-content .mahana-translate-tab-pane");'
            . 'function activate(tabKey){'
            . 'tabs.forEach(function(tab){'
            . 'var isActive=tab.getAttribute("data-mahana-tab")==tabKey;'
            . 'tab.classList.toggle("active",isActive);'
            . 'tab.setAttribute("aria-selected",isActive?"true":"false");'
            . '});'
            . 'panes.forEach(function(pane){'
            . 'var isActive=pane.getAttribute("data-mahana-tab")==tabKey;'
            . 'pane.classList.toggle("active",isActive);'
            . '});'
            . 'try{localStorage.setItem("mahana_translate_active_tab",tabKey);}catch(e){}'
            . '}'
            . 'var initial=' . $activeTabJson . ';'
            . 'try{var stored=localStorage.getItem("mahana_translate_active_tab");'
            . 'if(stored&&document.querySelector("#mahana-translate-tabs a[data-mahana-tab=\'"+stored+"\']")){'
            . 'initial=stored;}}catch(e){}'
            . 'var hash=window.location.hash;'
            . 'if(hash==="#mahana-translate-settings"){initial="settings";}'
            . 'if(hash==="#mahana-translate-jobs"){initial="jobs";}'
            . 'activate(initial);'
            . 'tabs.forEach(function(tab){'
            . 'tab.addEventListener("click",function(event){'
            . 'event.preventDefault();'
            . 'activate(tab.getAttribute("data-mahana-tab"));'
            . '});'
            . '});'
            . '});'
            . '</script>';
    }

    private function renderSecretFieldScript()
    {
        return '<style>
            .mahana-secret-field{
                -webkit-text-security: disc;
            }
        </style><script>
            document.addEventListener("DOMContentLoaded", function () {
                var selectors = [
                    \'input[name="' . pSQL(self::CONFIG_OPENAI_KEY) . '"]\',
                    \'input[name="' . pSQL(self::CONFIG_GOOGLE_KEY) . '"]\'
                ];

                selectors.forEach(function (selector) {
                    var input = document.querySelector(selector);
                    if (!input) {
                        return;
                    }
                    input.setAttribute("spellcheck", "false");
                    input.setAttribute("autocapitalize", "off");
                    input.setAttribute("autocomplete", "off");
                });
            });
        </script>';
    }

    private function renderJobForm()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $languages = $this->getLanguageOptions();
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Run translations', [], 'Modules.Mahanatranslate.Admin'),
                    'icon' => 'icon-language',
                ],
                'description' => $this->trans('Pick a source language, one or more targets, and the domains you want to translate now.', [], 'Modules.Mahanatranslate.Admin'),
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->trans('Source language', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => 'source_lang',
                        'options' => [
                            'query' => $languages,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'required' => true,
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Target languages', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => 'target_lang[]',
                        'multiple' => true,
                        'options' => [
                            'query' => $languages,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'required' => true,
                        'desc' => $this->trans('Hold CTRL or CMD to select multiple languages.', [], 'Modules.Mahanatranslate.Admin'),
                    ],
                    [
                        'type' => 'checkbox',
                        'label' => $this->trans('Domains to process', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => 'job_domains',
                        'values' => [
                            'query' => $this->getJobDomainOptions(),
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $this->trans('Select the content types to translate for this run (all are enabled by default).', [], 'Modules.Mahanatranslate.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Force overwrite', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => 'force_translation',
                        'is_bool' => true,
                        'desc' => $this->trans('Enable to retranslate and overwrite fields even if a translation already exists.', [], 'Modules.Mahanatranslate.Admin'),
                        'values' => [
                            [
                                'id' => 'force_translation_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'force_translation_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Batch size', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => 'batch_size',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->trans('Number of items per request (smaller values reduce timeouts).', [], 'Modules.Mahanatranslate.Admin'),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Translate now', [], 'Modules.Mahanatranslate.Admin'),
                    'name' => 'submitMahanaTranslateJob',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMahanaTranslateJob';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getJobFormValues(),
        ];

        $resultsContainer = '<div id="mahana-translate-job-results" class="alert" style="display:none;"></div>';

        return $helper->generateForm([$fieldsForm]) . $resultsContainer . $this->renderJobScript();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function getProviderOptions()
    {
        $options = [];
        foreach ($this->providers as $id => $name) {
            $options[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $options;
    }

    private function getLanguageOptions()
    {
        $languages = Language::getLanguages(false);
        $options = [];
        foreach ($languages as $language) {
            $options[] = [
                'id' => (int) $language['id_lang'],
                'name' => sprintf('%s (%s)', $language['name'], $language['iso_code']),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfigFormValues()
    {
        $storedOpenAiKey = (string) Configuration::get(self::CONFIG_OPENAI_KEY);
        $storedGoogleKey = (string) Configuration::get(self::CONFIG_GOOGLE_KEY);

        return [
            self::CONFIG_PROVIDER => Configuration::get(self::CONFIG_PROVIDER, self::PROVIDER_OPENAI),
            self::CONFIG_OPENAI_KEY => Tools::getValue(self::CONFIG_OPENAI_KEY, $this->maskSecret($storedOpenAiKey)),
            self::CONFIG_OPENAI_MODEL => Tools::getValue(self::CONFIG_OPENAI_MODEL, Configuration::get(self::CONFIG_OPENAI_MODEL)),
            self::CONFIG_GOOGLE_KEY => Tools::getValue(self::CONFIG_GOOGLE_KEY, $this->maskSecret($storedGoogleKey)),
            self::CONFIG_GOOGLE_PROJECT => Tools::getValue(self::CONFIG_GOOGLE_PROJECT, Configuration::get(self::CONFIG_GOOGLE_PROJECT)),
        ];
    }

    private function maskSecret($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat(self::MASK_CHAR, $length);
        }

        return substr($value, 0, 2)
            . str_repeat(self::MASK_CHAR, max(4, $length - 4))
            . substr($value, -2);
    }

    private function resolveSecretSubmission($submittedValue, $storedValue)
    {
        $submittedValue = trim((string) $submittedValue);
        $storedValue = (string) $storedValue;

        if ($storedValue === '') {
            return $submittedValue;
        }

        if ($submittedValue === '' || $submittedValue === $this->maskSecret($storedValue)) {
            return $storedValue;
        }

        return $submittedValue;
    }

    private function getJobFormValues()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $values = [
            'source_lang' => Tools::getValue('source_lang', $defaultLang),
            'target_lang[]' => Tools::getValue('target_lang', []),
            'force_translation' => (bool) Tools::getValue('force_translation', false),
            'batch_size' => (int) Tools::getValue('batch_size', 10),
        ];

        $defaultSelection = !Tools::isSubmit('submitMahanaTranslateJob');
        $domainSelection = $this->getJobDomainSelection($defaultSelection);
        foreach ($domainSelection as $domainKey => $enabled) {
            $fieldName = $this->getJobDomainFieldName($domainKey);
            $values[$fieldName] = $enabled;
        }

        return $values;
    }

    private function renderProviderToggleScript()
    {
        return '<style>
            .mahana-tag-select{border:1px solid #d0d7de;border-radius:4px;padding:8px;background:#fff;}
            .mahana-tag-list{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;}
            .mahana-tag{background:#eef2f6;border:1px solid #cfd6dd;border-radius:12px;padding:4px 10px;font-size:12px;cursor:pointer;}
            .mahana-tag-input{width:100%;max-width:320px;border:1px solid #cfd6dd;border-radius:4px;padding:6px 8px;}
            .mahana-tag-options{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
            .mahana-tag-option{border:1px solid #d0d7de;background:#fff;padding:4px 8px;border-radius:12px;font-size:12px;cursor:pointer;}
            .mahana-tag-option:hover{background:#f3f6f9;}
            .mahana-tag-empty{color:#6c757d;font-size:12px;padding:4px;}
        </style><script>
            document.addEventListener("DOMContentLoaded", function () {
                var providerSelect = document.querySelector(\'select[name="' . pSQL(self::CONFIG_PROVIDER) . '"]\');
                function toggleProviderFields() {
                    var provider = providerSelect ? providerSelect.value : "";
                    document.querySelectorAll(".provider-field").forEach(function (el) {
                        el.style.display = "none";
                    });
                    if (provider) {
                        document.querySelectorAll(".provider-" + provider).forEach(function (el) {
                            el.style.display = "";
                        });
                    }
                }
                if (providerSelect) {
                    providerSelect.addEventListener("change", toggleProviderFields);
                    toggleProviderFields();
                }
            });
        </script>';
    }

    private function renderJobScript()
    {
        $domainFields = [];
        foreach ($this->domains as $key => $domain) {
            $fieldLabels = [];
            foreach ($domain['fields'] as $fieldName) {
                $fieldLabels[$fieldName] = ucwords(str_replace('_', ' ', $fieldName));
            }
            $domainFields[] = [
                'field' => $this->getJobDomainFieldName($key),
                'key' => $key,
                'label' => $this->trans($domain['label'], [], 'Modules.Mahanatranslate.Admin'),
                'fields' => array_values($domain['fields']),
                'fieldLabels' => $fieldLabels,
            ];
        }

        $languageLabels = [];
        foreach (Language::getLanguages(false) as $language) {
            $languageLabels[(int) $language['id_lang']] = sprintf('%s (%s)', $language['name'], $language['iso_code']);
        }

        $ajaxUrl = $this->getLegacyAdminUrl('AdminMahanaTranslate', []);
        $ajaxToken = Tools::getAdminTokenLite('AdminModules');
        $controllerToken = Tools::getAdminTokenLite('AdminMahanaTranslate');
        $i18n = [
            'running' => $this->trans('Translation in progress...', [], 'Modules.Mahanatranslate.Admin'),
            'preparing' => $this->trans('Preparing translation batches...', [], 'Modules.Mahanatranslate.Admin'),
            'success' => $this->trans('Translation finished.', [], 'Modules.Mahanatranslate.Admin'),
            'error' => $this->trans('Translation failed: %s', [], 'Modules.Mahanatranslate.Admin'),
            'status' => $this->trans('%s -> %s: %d/%d items', [], 'Modules.Mahanatranslate.Admin'),
            'overall' => $this->trans('Overall: %d/%d items', [], 'Modules.Mahanatranslate.Admin'),
            'batch' => $this->trans('Last batch: %d items, %d fields updated', [], 'Modules.Mahanatranslate.Admin'),
            'empty' => $this->trans('No items found to translate.', [], 'Modules.Mahanatranslate.Admin'),
            'missing_source' => $this->trans('Select a source language.', [], 'Modules.Mahanatranslate.Admin'),
            'missing_target' => $this->trans('Select at least one target language.', [], 'Modules.Mahanatranslate.Admin'),
            'confirm' => $this->trans('Translate into: %s ?', [], 'Modules.Mahanatranslate.Admin'),
            'confirm_force' => $this->trans('Warning: force mode is enabled. This will overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
            'filter' => $this->trans('Filter target languages...', [], 'Modules.Mahanatranslate.Admin'),
            'no_results' => $this->trans('No languages found.', [], 'Modules.Mahanatranslate.Admin'),
        ];

        return '<style>
            #mahana-translate-job-results .mahana-translate-progress{display:flex;flex-direction:column;gap:6px;}
            #mahana-translate-job-results .mahana-translate-status{font-weight:600;}
            #mahana-translate-job-results .mahana-translate-detail{color:#6c757d;font-size:12px;}
        </style><script>
            document.addEventListener("DOMContentLoaded", function () {
                var submitButton = document.querySelector(\'button[name="submitMahanaTranslateJob"]\');
                if (!submitButton) {
                    return;
                }
                var form = submitButton.closest("form");
                if (!form) {
                    return;
                }
                var sourceSelect = form.querySelector(\'select[name="source_lang"]\');
                var targetSelect = form.querySelector(\'select[name="target_lang[]"]\');
                var resultBox = document.getElementById("mahana-translate-job-results");
                var domains = ' . json_encode($domainFields) . ';
                var languageLabels = ' . json_encode($languageLabels) . ';
                var ajaxUrl = ' . json_encode($ajaxUrl) . ';
                var ajaxToken = ' . json_encode($ajaxToken) . ';
                var controllerToken = ' . json_encode($controllerToken) . ';
                var messages = ' . json_encode($i18n) . ';
                var domainLabels = {};
                var domainFieldMap = {};
                var domainFieldLabels = {};
                domains.forEach(function (domain) {
                    domainLabels[domain.key] = domain.label;
                    domainFieldMap[domain.key] = Array.isArray(domain.fields) ? domain.fields : [];
                    domainFieldLabels[domain.key] = domain.fieldLabels || {};
                });
                var targetTagUi = null;
                var targetTagInput = null;
                var targetTagList = null;
                var targetOptionList = null;

                function formatMessage(template, values) {
                    if (!template) {
                        return "";
                    }
                    var index = 0;
                    return template.replace(/%s|%d/g, function () {
                        var value = values[index++];
                        return typeof value === "undefined" ? "" : value;
                    });
                }

                function postForm(payload) {
                    return fetch(ajaxUrl, {
                        method: "POST",
                        body: payload,
                        credentials: "same-origin"
                    }).then(function (response) {
                        return response.json();
                    });
                }

                function getLanguageLabel(langId) {
                    if (languageLabels && languageLabels[langId]) {
                        return languageLabels[langId];
                    }
                    return "ID " + langId;
                }

                function refreshTargetTags() {
                    if (!targetSelect || !targetTagUi || !targetTagList || !targetOptionList) {
                        return;
                    }
                    var filter = targetTagInput ? (targetTagInput.value || "").toLowerCase() : "";
                    while (targetTagList.firstChild) {
                        targetTagList.removeChild(targetTagList.firstChild);
                    }
                    while (targetOptionList.firstChild) {
                        targetOptionList.removeChild(targetOptionList.firstChild);
                    }
                    var hasOptions = false;
                    Array.prototype.forEach.call(targetSelect.options, function (option) {
                        var label = option.text || option.label || "";
                        var value = option.value || "";
                        var matchesFilter = !filter || label.toLowerCase().indexOf(filter) !== -1;

                        if (option.selected) {
                            var tag = document.createElement("button");
                            tag.type = "button";
                            tag.className = "mahana-tag";
                            tag.setAttribute("data-value", value);
                            tag.innerHTML = label + " <span aria-hidden=\"true\">&times;</span>";
                            tag.addEventListener("click", function () {
                                option.selected = false;
                                targetSelect.dispatchEvent(new Event("change", { bubbles: true }));
                                refreshTargetTags();
                            });
                            targetTagList.appendChild(tag);
                            return;
                        }

                        if (option.disabled || !matchesFilter) {
                            return;
                        }

                        var optButton = document.createElement("button");
                        optButton.type = "button";
                        optButton.className = "mahana-tag-option";
                        optButton.textContent = label;
                        optButton.setAttribute("data-value", value);
                        optButton.addEventListener("click", function () {
                            option.selected = true;
                            targetSelect.dispatchEvent(new Event("change", { bubbles: true }));
                            refreshTargetTags();
                        });
                        targetOptionList.appendChild(optButton);
                        hasOptions = true;
                    });

                    if (!hasOptions) {
                        var empty = document.createElement("div");
                        empty.className = "mahana-tag-empty";
                        empty.textContent = messages.no_results || "No languages found.";
                        targetOptionList.appendChild(empty);
                    }
                }

                function buildTargetTags() {
                    if (!targetSelect || targetTagUi) {
                        return;
                    }
                    targetTagUi = document.createElement("div");
                    targetTagUi.className = "mahana-tag-select";

                    targetTagList = document.createElement("div");
                    targetTagList.className = "mahana-tag-list";

                    targetTagInput = document.createElement("input");
                    targetTagInput.type = "text";
                    targetTagInput.className = "mahana-tag-input";
                    targetTagInput.placeholder = messages.filter || "Filter target languages...";

                    targetOptionList = document.createElement("div");
                    targetOptionList.className = "mahana-tag-options";

                    targetTagUi.appendChild(targetTagList);
                    targetTagUi.appendChild(targetTagInput);
                    targetTagUi.appendChild(targetOptionList);

                    targetSelect.style.display = "none";
                    targetSelect.setAttribute("aria-hidden", "true");
                    targetSelect.parentNode.insertBefore(targetTagUi, targetSelect);

                    targetTagInput.addEventListener("input", refreshTargetTags);
                    targetSelect.addEventListener("change", refreshTargetTags);

                    refreshTargetTags();
                }

                function syncTargetLanguages() {
                    if (!sourceSelect || !targetSelect) {
                        return;
                    }
                    var sourceValue = sourceSelect.value || "";
                    Array.prototype.forEach.call(targetSelect.options, function (option) {
                        var shouldDisable = sourceValue && option.value === sourceValue;
                        if (shouldDisable && option.selected) {
                            option.selected = false;
                        }
                        option.disabled = shouldDisable;
                    });
                    refreshTargetTags();
                }

                if (sourceSelect) {
                    sourceSelect.addEventListener("change", syncTargetLanguages);
                }
                buildTargetTags();
                syncTargetLanguages();

                form.addEventListener("submit", function (event) {
                    event.preventDefault();
                    if (!resultBox) {
                        return;
                    }

                    var formData = new FormData(form);
                    var sourceLangId = formData.get("source_lang") || "";
                    var targetLangIds = (formData.getAll("target_lang[]") || []).filter(function (value) {
                        return value && value !== sourceLangId;
                    });
                    var selectedDomains = [];
                    domains.forEach(function (domain) {
                        if (formData.get(domain.field)) {
                            selectedDomains.push(domain.key);
                        }
                    });
                    if (!selectedDomains.length) {
                        domains.forEach(function (domain) {
                            selectedDomains.push(domain.key);
                        });
                    }

                    if (!sourceLangId) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.innerHTML = messages.error.replace("%s", messages.missing_source || "Missing source language.");
                        return;
                    }
                    if (!targetLangIds.length) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.innerHTML = messages.error.replace("%s", messages.missing_target || "Select at least one target language.");
                        return;
                    }

                    var forceValue = formData.get("force_translation") === "1";
                    var batchSize = parseInt(formData.get("batch_size") || "10", 10);
                    if (isNaN(batchSize) || batchSize < 1) {
                        batchSize = 5;
                    } else if (batchSize > 100) {
                        batchSize = 100;
                    }
                    var targetLabels = targetLangIds.map(function (langId) {
                        return getLanguageLabel(langId);
                    });
                    var confirmText = formatMessage(messages.confirm, [targetLabels.join(", ")]);
                    if (forceValue && messages.confirm_force) {
                        confirmText += "\\n" + messages.confirm_force;
                    }
                    if (confirmText && !window.confirm(confirmText)) {
                        return;
                    }

                    submitButton.disabled = true;
                    resultBox.style.display = "";
                    resultBox.className = "alert alert-info";
                    resultBox.innerHTML = "";

                    var progressWrap = document.createElement("div");
                    progressWrap.className = "mahana-translate-progress";
                    var progress = document.createElement("div");
                    progress.className = "progress";
                    var progressBar = document.createElement("div");
                    progressBar.className = "progress-bar";
                    progressBar.setAttribute("role", "progressbar");
                    progressBar.style.width = "0%";
                    progressBar.textContent = "0%";
                    progress.appendChild(progressBar);
                    var statusLine = document.createElement("div");
                    statusLine.className = "mahana-translate-status";
                    var detailLine = document.createElement("div");
                    detailLine.className = "mahana-translate-detail";
                    progressWrap.appendChild(progress);
                    progressWrap.appendChild(statusLine);
                    progressWrap.appendChild(detailLine);
                    resultBox.appendChild(progressWrap);

                    statusLine.textContent = messages.preparing || messages.running;
                    detailLine.textContent = "";

                    var totalsPayload = new FormData();
                    totalsPayload.append("ajax", "1");
                    totalsPayload.append("action", "getTranslationTotals");
                    totalsPayload.append("token", ajaxToken || controllerToken || "");
                    totalsPayload.append("controller_token", controllerToken || "");
                    totalsPayload.append("source_lang", sourceLangId);
                    selectedDomains.forEach(function (domain) {
                        totalsPayload.append("domains[]", domain);
                    });

                    postForm(totalsPayload).then(function (data) {
                        if (!data.success) {
                            throw new Error(data.message || "Unknown error");
                        }

                        var totals = data.totals || {};
                        var tasks = [];
                        var overallTotal = 0;
                        selectedDomains.forEach(function (domain) {
                            var total = parseInt(totals[domain] || 0, 10);
                            if (isNaN(total) || total < 0) {
                                total = 0;
                            }
                            var fields = domainFieldMap[domain] || [];
                            if (!fields.length || total <= 0) {
                                return;
                            }
                            targetLangIds.forEach(function (targetLangId) {
                                fields.forEach(function (fieldName) {
                                    tasks.push({
                                        domain: domain,
                                        targetLangId: targetLangId,
                                        field: fieldName,
                                        fieldLabel: (domainFieldLabels[domain] || {})[fieldName] || fieldName,
                                        total: total,
                                        offset: 0,
                                        translated: 0
                                    });
                                    overallTotal += total;
                                });
                            });
                        });

                        if (overallTotal <= 0) {
                            resultBox.className = "alert alert-warning";
                            resultBox.innerHTML = messages.empty || "No items found to translate.";
                            submitButton.disabled = false;
                            return;
                        }

                        var overallProcessed = 0;
                        var reports = [];
                        function updateProgress(task, batchProcessed, batchTranslated) {
                            if (overallProcessed > overallTotal) {
                                overallProcessed = overallTotal;
                            }
                            var percent = overallTotal > 0 ? Math.round((overallProcessed / overallTotal) * 100) : 0;
                            progressBar.style.width = percent + "%";
                            progressBar.textContent = percent + "%";
                            var domainLabel = domainLabels[task.domain] || task.domain;
                            if (task.fieldLabel) {
                                domainLabel = domainLabel + " / " + task.fieldLabel;
                            }
                            var targetLabel = getLanguageLabel(task.targetLangId);
                            statusLine.textContent = formatMessage(messages.status, [domainLabel, targetLabel, task.offset, task.total]);
                            var overallText = formatMessage(messages.overall, [overallProcessed, overallTotal]);
                            var batchText = formatMessage(messages.batch, [batchProcessed, batchTranslated]);
                            detailLine.textContent = overallText + " - " + batchText;
                        }

                        function finishJobs() {
                            var html = "<p>" + messages.success + "</p>";
                            if (reports.length) {
                                var summary = {};
                                reports.forEach(function (report) {
                                    var key = report.domain + "|" + report.targetLangId;
                                    if (!summary[key]) {
                                        summary[key] = {
                                            domain: report.domain,
                                            targetLangId: report.targetLangId,
                                            total: report.total,
                                            translated: 0
                                        };
                                    }
                                    if (report.total > summary[key].total) {
                                        summary[key].total = report.total;
                                    }
                                    summary[key].translated += report.translated;
                                });

                                html += "<ul>";
                                Object.keys(summary).forEach(function (key) {
                                    var item = summary[key];
                                    var domainLabel = domainLabels[item.domain] || item.domain;
                                    var targetLabel = getLanguageLabel(item.targetLangId);
                                    html += "<li><strong>" + domainLabel + " (" + targetLabel + "):</strong> "
                                        + item.total + " items, " + item.translated + " fields updated.</li>";
                                });
                                html += "</ul>";
                            }
                            resultBox.className = "alert alert-success";
                            resultBox.innerHTML = html;
                            submitButton.disabled = false;
                        }

                        function runNextTask() {
                            if (!tasks.length) {
                                finishJobs();
                                return;
                            }
                            var task = tasks[0];
                            if (task.total <= 0) {
                                reports.push({
                                    domain: task.domain,
                                    targetLangId: task.targetLangId,
                                    total: 0,
                                    translated: 0
                                });
                                tasks.shift();
                                runNextTask();
                                return;
                            }

                            runBatch(task);
                        }

                        function runBatch(task) {
                            var payload = new FormData();
                            payload.append("ajax", "1");
                            payload.append("action", "runTranslationBatch");
                            payload.append("token", ajaxToken || controllerToken || "");
                            payload.append("controller_token", controllerToken || "");
                            payload.append("source_lang", sourceLangId);
                            payload.append("target_lang", task.targetLangId);
                            payload.append("domain", task.domain);
                            if (task.field) {
                                payload.append("fields[]", task.field);
                            }
                            payload.append("offset", task.offset);
                            payload.append("limit", batchSize);
                            payload.append("force", forceValue ? 1 : 0);

                            postForm(payload).then(function (data) {
                                if (!data.success) {
                                    throw new Error(data.message || "Unknown error");
                                }

                                var processed = parseInt(data.processed || 0, 10);
                                var translated = parseInt(data.translated || 0, 10);
                                if (isNaN(processed) || processed < 0) {
                                    processed = 0;
                                }
                                if (isNaN(translated) || translated < 0) {
                                    translated = 0;
                                }

                                task.offset += processed;
                                task.translated += translated;
                                overallProcessed += processed;

                                updateProgress(task, processed, translated);

                                if (processed === 0 || task.offset >= task.total) {
                                    reports.push({
                                        domain: task.domain,
                                        targetLangId: task.targetLangId,
                                        total: task.total,
                                        translated: task.translated
                                    });
                                    tasks.shift();
                                    runNextTask();
                                    return;
                                }

                                setTimeout(function () {
                                    runBatch(task);
                                }, 0);
                            }).catch(function (error) {
                                resultBox.className = "alert alert-danger";
                                resultBox.innerHTML = messages.error.replace("%s", error.message || error);
                                submitButton.disabled = false;
                            });
                        }

                        updateProgress(tasks[0], 0, 0);
                        runNextTask();
                    }).catch(function (error) {
                        resultBox.className = "alert alert-danger";
                        resultBox.innerHTML = messages.error.replace("%s", error.message || error);
                        submitButton.disabled = false;
                    });
                });
            });
        </script>';
    }

    private function getJobDomainOptions()
    {
        $options = [];
        foreach ($this->domains as $key => $domain) {
            $options[] = [
                'id' => $key,
                'name' => $this->trans($domain['label'], [], 'Modules.Mahanatranslate.Admin'),
            ];
        }

        return $options;
    }

    private function getJobDomainSelection($defaultWhenMissing = false)
    {
        $selection = [];
        foreach ($this->domains as $domainKey => $domain) {
            $fieldName = $this->getJobDomainFieldName($domainKey);
            $value = Tools::getValue($fieldName);
            if ($value === null) {
                $selection[$domainKey] = (bool) $defaultWhenMissing;
            } else {
                $selection[$domainKey] = (bool) $value;
            }
        }

        return $selection;
    }

    private function getJobDomainFieldName($domainKey)
    {
        return 'job_domains_' . $domainKey;
    }

    /**
     * Build a legacy admin URL to avoid Symfony route conversion for AJAX calls.
     *
     * @param array<string, string> $params
     */
    private function getLegacyAdminUrl(string $controller, array $params)
    {
        $base = Tools::getHttpHost(true) . __PS_BASE_URI__ . basename(_PS_ADMIN_DIR_) . '/index.php';
        $query = array_merge(['controller' => $controller], $params);

        return $base . '?' . http_build_query($query, '', '&');
    }

    /**
     * @return string[]
     */
    public function getDomainKeys()
    {
        return array_keys($this->domains);
    }

    public function hookDisplayAdminProductsExtra($params)
    {
        $productId = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if ($productId <= 0) {
            return '';
        }

        $languages = Language::getLanguages(false);
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $ajaxUrl = $this->getLegacyAdminUrl('AdminMahanaTranslate', []);
        $ajaxToken = Tools::getAdminTokenLite('AdminModules');
        $controllerToken = Tools::getAdminTokenLite('AdminMahanaTranslate');

        $sourceOptions = '';
        $targetOptions = '';
        $languageLabels = [];
        foreach ($languages as $language) {
            $langId = (int) $language['id_lang'];
            $label = sprintf('%s (%s)', $language['name'], $language['iso_code']);
            $languageLabels[$langId] = $label;
            $sourceOptions .= sprintf(
                '<option value="%d"%s>%s</option>',
                $langId,
                $langId === $defaultLang ? ' selected="selected"' : '',
                $label
            );
            $targetOptions .= sprintf(
                '<label class="mahana-target-option"><input type="checkbox" name="mahana_translate_product_target[]" value="%d"%s> %s</label>',
                $langId,
                $langId === $defaultLang ? '' : ' checked="checked"',
                $label
            );
        }

        $fields = isset($this->domains['products']['fields']) ? $this->domains['products']['fields'] : [];
        $fieldLabels = [];
        foreach ($fields as $fieldName) {
            $fieldLabels[$fieldName] = ucwords(str_replace('_', ' ', $fieldName));
        }

        $i18n = [
            'title' => $this->trans('Translate this product', [], 'Modules.Mahanatranslate.Admin'),
            'source' => $this->trans('Source language', [], 'Modules.Mahanatranslate.Admin'),
            'targets' => $this->trans('Target languages', [], 'Modules.Mahanatranslate.Admin'),
            'force' => $this->trans('Force overwrite', [], 'Modules.Mahanatranslate.Admin'),
            'force_help' => $this->trans('Overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
            'cta' => $this->trans('Translate', [], 'Modules.Mahanatranslate.Admin'),
            'confirm' => $this->trans('Translate this product into: %s ?', [], 'Modules.Mahanatranslate.Admin'),
            'confirm_force' => $this->trans('Warning: force mode is enabled. This will overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
            'missing_source' => $this->trans('Select a source language.', [], 'Modules.Mahanatranslate.Admin'),
            'missing_target' => $this->trans('Select at least one target language.', [], 'Modules.Mahanatranslate.Admin'),
            'missing_fields' => $this->trans('No product fields are available for translation.', [], 'Modules.Mahanatranslate.Admin'),
            'running' => $this->trans('Translation in progress...', [], 'Modules.Mahanatranslate.Admin'),
            'success' => $this->trans('Product translation finished.', [], 'Modules.Mahanatranslate.Admin'),
            'error' => $this->trans('Translation failed: %s', [], 'Modules.Mahanatranslate.Admin'),
            'status' => $this->trans('%s -> %s (%d/%d)', [], 'Modules.Mahanatranslate.Admin'),
        ];

        $config = [
            'productId' => $productId,
            'ajaxUrl' => $ajaxUrl,
            'token' => $ajaxToken,
            'controllerToken' => $controllerToken,
            'defaultLang' => $defaultLang,
            'fields' => array_values($fields),
            'fieldLabels' => $fieldLabels,
            'languages' => $languageLabels,
            'i18n' => $i18n,
        ];

        $panelId = 'mahana-translate-product-panel-' . $productId;
        $resultId = 'mahana-translate-product-result-' . $productId;
        $buttonId = 'mahana-translate-product-run-' . $productId;
        $sourceId = 'mahana-translate-product-source-' . $productId;

        return '<div class="panel" id="' . $panelId . '">
            <h3><i class="icon-language"></i> ' . $i18n['title'] . '</h3>
            <div class="form-group">
                <label class="control-label" for="' . $sourceId . '">' . $i18n['source'] . '</label>
                <select class="form-control fixed-width-xxl" id="' . $sourceId . '" name="mahana_translate_product_source">
                    ' . $sourceOptions . '
                </select>
            </div>
            <div class="form-group">
                <label class="control-label">' . $i18n['targets'] . '</label>
                <div class="mahana-target-list">' . $targetOptions . '</div>
            </div>
            <div class="form-group">
                <label class="mahana-force-option">
                    <input type="checkbox" name="mahana_translate_product_force" value="1"> ' . $i18n['force'] . '
                </label>
                <p class="help-block">' . $i18n['force_help'] . '</p>
            </div>
            <button type="button" class="btn btn-primary" id="' . $buttonId . '">' . $i18n['cta'] . '</button>
            <div id="' . $resultId . '" class="alert" style="display:none;margin-top:12px;"></div>
        </div>
        <style>
            #' . $panelId . ' .mahana-target-list{display:flex;flex-wrap:wrap;gap:8px;}
            #' . $panelId . ' .mahana-target-option{font-weight:400;}
            #' . $panelId . ' .mahana-force-option{font-weight:400;}
            #' . $panelId . ' .mahana-translate-progress{display:flex;flex-direction:column;gap:6px;}
            #' . $panelId . ' .mahana-translate-status{font-weight:600;}
            #' . $panelId . ' .mahana-translate-detail{color:#6c757d;font-size:12px;}
        </style>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                var config = ' . json_encode($config) . ';
                var panel = document.getElementById(' . json_encode($panelId) . ');
                if (!panel) {
                    return;
                }
                var sourceSelect = panel.querySelector(\'select[name="mahana_translate_product_source"]\');
                var targetCheckboxes = panel.querySelectorAll(\'input[name="mahana_translate_product_target[]"]\');
                var forceCheckbox = panel.querySelector(\'input[name="mahana_translate_product_force"]\');
                var button = document.getElementById(' . json_encode($buttonId) . ');
                var resultBox = document.getElementById(' . json_encode($resultId) . ');

                function formatMessage(template, values) {
                    if (!template) {
                        return "";
                    }
                    var index = 0;
                    return template.replace(/%s|%d/g, function () {
                        var value = values[index++];
                        return typeof value === "undefined" ? "" : value;
                    });
                }

                function syncTargets() {
                    if (!sourceSelect || !targetCheckboxes) {
                        return;
                    }
                    var sourceValue = sourceSelect.value || "";
                    Array.prototype.forEach.call(targetCheckboxes, function (checkbox) {
                        if (checkbox.value === sourceValue) {
                            checkbox.checked = false;
                            checkbox.disabled = true;
                        } else {
                            checkbox.disabled = false;
                        }
                    });
                }

                function postForm(payload) {
                    return fetch(config.ajaxUrl, {
                        method: "POST",
                        body: payload,
                        credentials: "same-origin"
                    }).then(function (response) {
                        return response.json();
                    });
                }

                if (sourceSelect) {
                    sourceSelect.addEventListener("change", syncTargets);
                }
                syncTargets();

                if (!button || !resultBox) {
                    return;
                }

                button.addEventListener("click", function () {
                    var sourceLangId = sourceSelect ? sourceSelect.value : "";
                    if (!sourceLangId) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.innerHTML = config.i18n.missing_source || "Missing source language.";
                        return;
                    }

                    var targetLangIds = [];
                    Array.prototype.forEach.call(targetCheckboxes, function (checkbox) {
                        if (checkbox.checked && !checkbox.disabled) {
                            targetLangIds.push(checkbox.value);
                        }
                    });
                    if (!targetLangIds.length) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.innerHTML = config.i18n.missing_target || "Missing target languages.";
                        return;
                    }

                    if (!config.fields || !config.fields.length) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.innerHTML = config.i18n.missing_fields || "Missing product fields.";
                        return;
                    }

                    var forceValue = !!(forceCheckbox && forceCheckbox.checked);
                    var targetLabels = targetLangIds.map(function (langId) {
                        return config.languages[langId] || ("ID " + langId);
                    });
                    var confirmText = formatMessage(config.i18n.confirm, [targetLabels.join(", ")]);
                    if (forceValue && config.i18n.confirm_force) {
                        confirmText += "\\n" + config.i18n.confirm_force;
                    }
                    if (confirmText && !window.confirm(confirmText)) {
                        return;
                    }

                    button.disabled = true;
                    resultBox.style.display = "";
                    resultBox.className = "alert alert-info";
                    resultBox.innerHTML = "";

                    var progressWrap = document.createElement("div");
                    progressWrap.className = "mahana-translate-progress";
                    var progress = document.createElement("div");
                    progress.className = "progress";
                    var progressBar = document.createElement("div");
                    progressBar.className = "progress-bar";
                    progressBar.setAttribute("role", "progressbar");
                    progressBar.style.width = "0%";
                    progressBar.textContent = "0%";
                    progress.appendChild(progressBar);
                    var statusLine = document.createElement("div");
                    statusLine.className = "mahana-translate-status";
                    var detailLine = document.createElement("div");
                    detailLine.className = "mahana-translate-detail";
                    progressWrap.appendChild(progress);
                    progressWrap.appendChild(statusLine);
                    progressWrap.appendChild(detailLine);
                    resultBox.appendChild(progressWrap);

                    statusLine.textContent = config.i18n.running || "Translation in progress...";
                    detailLine.textContent = "";

                    var tasks = [];
                    config.fields.forEach(function (fieldName) {
                        targetLangIds.forEach(function (targetLangId) {
                            tasks.push({
                                field: fieldName,
                                fieldLabel: (config.fieldLabels && config.fieldLabels[fieldName]) ? config.fieldLabels[fieldName] : fieldName,
                                targetLangId: targetLangId
                            });
                        });
                    });

                    var totalTasks = tasks.length;
                    var completed = 0;

                    function updateProgress(task) {
                        var percent = totalTasks > 0 ? Math.round((completed / totalTasks) * 100) : 0;
                        progressBar.style.width = percent + "%";
                        progressBar.textContent = percent + "%";
                        var targetLabel = config.languages[task.targetLangId] || ("ID " + task.targetLangId);
                        statusLine.textContent = formatMessage(config.i18n.status, [task.fieldLabel, targetLabel, completed, totalTasks]);
                        detailLine.textContent = config.i18n.running || "Translation in progress...";
                    }

                    function finishSuccess() {
                        resultBox.className = "alert alert-success";
                        resultBox.innerHTML = config.i18n.success || "Product translation finished.";
                        button.disabled = false;
                    }

                    function runNext() {
                        if (!tasks.length) {
                            finishSuccess();
                            return;
                        }

                        var task = tasks.shift();
                        updateProgress(task);

                        var payload = new FormData();
                        payload.append("ajax", "1");
                        payload.append("action", "runProductTranslationBatch");
                        payload.append("token", config.token || config.controllerToken || "");
                        payload.append("controller_token", config.controllerToken || "");
                        payload.append("id_product", config.productId);
                        payload.append("source_lang", sourceLangId);
                        payload.append("target_lang", task.targetLangId);
                        payload.append("field", task.field);
                        payload.append("force", forceValue ? 1 : 0);

                        postForm(payload).then(function (data) {
                            if (!data.success) {
                                throw new Error(data.message || "Unknown error");
                            }
                            completed += 1;
                            runNext();
                        }).catch(function (error) {
                            resultBox.className = "alert alert-danger";
                            resultBox.innerHTML = formatMessage(config.i18n.error, [error.message || error]);
                            button.disabled = false;
                        });
                    }

                    runNext();
                });
            });
        </script>';
    }

    private function renderAnblogEntityTranslationPanel($domainKey, $entityKey, $controllerName, $idParam, $addFlag, $updateFlag, $action, array $i18n)
    {
        $languages = Language::getLanguages(false);
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $ajaxUrl = $this->getLegacyAdminUrl('AdminMahanaTranslate', []);
        $ajaxToken = Tools::getAdminTokenLite('AdminModules');
        $controllerToken = Tools::getAdminTokenLite('AdminMahanaTranslate');

        $languageLabels = [];
        foreach ($languages as $language) {
            $languageLabels[(int) $language['id_lang']] = sprintf('%s (%s)', $language['name'], $language['iso_code']);
        }

        $fields = isset($this->domains[$domainKey]['fields']) ? $this->domains[$domainKey]['fields'] : [];
        $fieldLabels = [];
        foreach ($fields as $fieldName) {
            $fieldLabels[$fieldName] = ucwords(str_replace('_', ' ', $fieldName));
        }

        $config = [
            'controller' => $controllerName,
            'idParam' => $idParam,
            'addFlag' => $addFlag,
            'updateFlag' => $updateFlag,
            'action' => $action,
            'ajaxUrl' => $ajaxUrl,
            'token' => $ajaxToken,
            'controllerToken' => $controllerToken,
            'defaultLang' => $defaultLang,
            'fields' => array_values($fields),
            'fieldLabels' => $fieldLabels,
            'languages' => $languageLabels,
            'i18n' => $i18n,
            'entityKey' => $entityKey,
        ];

        return '<style>
            .mahana-translate-' . pSQL($entityKey) . '-card .mahana-target-list{display:flex;flex-wrap:wrap;gap:8px;}
            .mahana-translate-' . pSQL($entityKey) . '-card .mahana-target-option{font-weight:400;}
            .mahana-translate-' . pSQL($entityKey) . '-card .mahana-force-option{font-weight:400;}
            .mahana-translate-' . pSQL($entityKey) . '-card .mahana-translate-progress{display:flex;flex-direction:column;gap:6px;}
            .mahana-translate-' . pSQL($entityKey) . '-card .mahana-translate-status{font-weight:600;}
            .mahana-translate-' . pSQL($entityKey) . '-card .mahana-translate-detail{color:#6c757d;font-size:12px;}
        </style><script>
            document.addEventListener("DOMContentLoaded", function () {
                var config = ' . json_encode($config) . ';
                var cardSelector = ".mahana-translate-" + config.entityKey + "-card";
                if (document.querySelector(cardSelector)) {
                    return;
                }

                var search = new URLSearchParams(window.location.search || "");
                if (search.get("controller") !== config.controller) {
                    return;
                }

                var isFormPage = search.has(config.updateFlag) || search.has(config.addFlag) || search.has(config.idParam);
                if (!isFormPage) {
                    return;
                }

                function getEntityId() {
                    var fromQuery = parseInt(search.get(config.idParam) || "0", 10);
                    if (!isNaN(fromQuery) && fromQuery > 0) {
                        return fromQuery;
                    }

                    var selectors = [
                        "input[name=\\"" + config.idParam + "\\"]",
                        "input[name=\\"" + config.idParam + "[]\\"]",
                        "[data-" + config.idParam.replace(/_/g, "-") + "]"
                    ];

                    for (var i = 0; i < selectors.length; i += 1) {
                        var element = document.querySelector(selectors[i]);
                        if (!element) {
                            continue;
                        }
                        var value = element.value || element.getAttribute("data-" + config.idParam.replace(/_/g, "-")) || "";
                        var parsed = parseInt(value, 10);
                        if (!isNaN(parsed) && parsed > 0) {
                            return parsed;
                        }
                    }

                    return 0;
                }

                function getInsertTarget() {
                    return document.querySelector("#content form")
                        || document.querySelector(".bootstrap form")
                        || document.querySelector("form");
                }

                var entityId = getEntityId();
                var target = getInsertTarget();
                if (!target || !target.parentNode) {
                    return;
                }

                var card = document.createElement("div");
                card.className = "panel mahana-translate-" + config.entityKey + "-card";
                var title = document.createElement("h3");
                title.innerHTML = "<i class=\\"icon-language\\"></i> " + (config.i18n.title || "Translate");
                card.appendChild(title);

                var sourceGroup = document.createElement("div");
                sourceGroup.className = "form-group";
                var sourceLabel = document.createElement("label");
                sourceLabel.className = "control-label";
                sourceLabel.textContent = config.i18n.source || "Source language";
                var sourceSelect = document.createElement("select");
                sourceSelect.className = "form-control fixed-width-xxl";
                Object.keys(config.languages || {}).forEach(function (langId) {
                    var option = document.createElement("option");
                    option.value = langId;
                    option.textContent = config.languages[langId];
                    if (parseInt(langId, 10) === parseInt(config.defaultLang, 10)) {
                        option.selected = true;
                    }
                    sourceSelect.appendChild(option);
                });
                sourceGroup.appendChild(sourceLabel);
                sourceGroup.appendChild(sourceSelect);
                card.appendChild(sourceGroup);

                var targetsGroup = document.createElement("div");
                targetsGroup.className = "form-group";
                var targetsLabel = document.createElement("label");
                targetsLabel.className = "control-label";
                targetsLabel.textContent = config.i18n.targets || "Target languages";
                var targetsList = document.createElement("div");
                targetsList.className = "mahana-target-list";
                Object.keys(config.languages || {}).forEach(function (langId) {
                    var label = document.createElement("label");
                    label.className = "mahana-target-option";
                    var checkbox = document.createElement("input");
                    checkbox.type = "checkbox";
                    checkbox.value = langId;
                    checkbox.name = "mahana_translate_" + config.entityKey + "_target[]";
                    if (parseInt(langId, 10) !== parseInt(config.defaultLang, 10)) {
                        checkbox.checked = true;
                    }
                    label.appendChild(checkbox);
                    label.appendChild(document.createTextNode(" " + config.languages[langId]));
                    targetsList.appendChild(label);
                });
                targetsGroup.appendChild(targetsLabel);
                targetsGroup.appendChild(targetsList);
                card.appendChild(targetsGroup);

                var forceGroup = document.createElement("div");
                forceGroup.className = "form-group";
                var forceLabel = document.createElement("label");
                forceLabel.className = "mahana-force-option";
                var forceCheckbox = document.createElement("input");
                forceCheckbox.type = "checkbox";
                forceCheckbox.value = "1";
                forceCheckbox.name = "mahana_translate_" + config.entityKey + "_force";
                forceLabel.appendChild(forceCheckbox);
                forceLabel.appendChild(document.createTextNode(" " + (config.i18n.force || "Force overwrite")));
                var forceHelp = document.createElement("p");
                forceHelp.className = "help-block";
                forceHelp.textContent = config.i18n.force_help || "Overwrite existing translations.";
                forceGroup.appendChild(forceLabel);
                forceGroup.appendChild(forceHelp);
                card.appendChild(forceGroup);

                var button = document.createElement("button");
                button.type = "button";
                button.className = "btn btn-primary";
                button.textContent = config.i18n.cta || "Translate";
                card.appendChild(button);

                var resultBox = document.createElement("div");
                resultBox.className = "alert";
                resultBox.style.display = "none";
                resultBox.style.marginTop = "12px";
                card.appendChild(resultBox);

                target.parentNode.insertBefore(card, target.nextSibling);

                function formatMessage(template, values) {
                    if (!template) {
                        return "";
                    }
                    var index = 0;
                    return template.replace(/%s|%d/g, function () {
                        var value = values[index++];
                        return typeof value === "undefined" ? "" : value;
                    });
                }

                function syncTargets() {
                    var sourceValue = sourceSelect.value || "";
                    Array.prototype.forEach.call(targetsList.querySelectorAll("input[type=\\"checkbox\\"]"), function (checkbox) {
                        if (checkbox.value === sourceValue) {
                            checkbox.checked = false;
                            checkbox.disabled = true;
                        } else {
                            checkbox.disabled = false;
                        }
                    });
                }

                function postForm(payload) {
                    return fetch(config.ajaxUrl, {
                        method: "POST",
                        body: payload,
                        credentials: "same-origin"
                    }).then(function (response) {
                        return response.json();
                    });
                }

                sourceSelect.addEventListener("change", syncTargets);
                syncTargets();

                button.addEventListener("click", function () {
                    if (!entityId || entityId <= 0) {
                        entityId = getEntityId();
                    }
                    if (!entityId || entityId <= 0) {
                        resultBox.className = "alert alert-warning";
                        resultBox.style.display = "";
                        resultBox.textContent = config.i18n.missing_id || "Save before translating.";
                        return;
                    }

                    var sourceLangId = sourceSelect.value || "";
                    if (!sourceLangId) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.textContent = config.i18n.missing_source || "Missing source language.";
                        return;
                    }

                    var targetLangIds = [];
                    Array.prototype.forEach.call(targetsList.querySelectorAll("input[type=\\"checkbox\\"]"), function (checkbox) {
                        if (checkbox.checked && !checkbox.disabled) {
                            targetLangIds.push(checkbox.value);
                        }
                    });
                    if (!targetLangIds.length) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.textContent = config.i18n.missing_target || "Missing target languages.";
                        return;
                    }

                    if (!config.fields || !config.fields.length) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.textContent = config.i18n.missing_fields || "Missing fields.";
                        return;
                    }

                    var forceValue = !!forceCheckbox.checked;
                    var targetLabels = targetLangIds.map(function (langId) {
                        return config.languages[langId] || ("ID " + langId);
                    });
                    var confirmText = formatMessage(config.i18n.confirm, [targetLabels.join(", ")]);
                    if (forceValue && config.i18n.confirm_force) {
                        confirmText += "\\n" + config.i18n.confirm_force;
                    }
                    if (confirmText && !window.confirm(confirmText)) {
                        return;
                    }

                    button.disabled = true;
                    resultBox.style.display = "";
                    resultBox.className = "alert alert-info";
                    resultBox.innerHTML = "";

                    var progressWrap = document.createElement("div");
                    progressWrap.className = "mahana-translate-progress";
                    var progress = document.createElement("div");
                    progress.className = "progress";
                    var progressBar = document.createElement("div");
                    progressBar.className = "progress-bar";
                    progressBar.setAttribute("role", "progressbar");
                    progressBar.style.width = "0%";
                    progressBar.textContent = "0%";
                    progress.appendChild(progressBar);
                    var statusLine = document.createElement("div");
                    statusLine.className = "mahana-translate-status";
                    var detailLine = document.createElement("div");
                    detailLine.className = "mahana-translate-detail";
                    progressWrap.appendChild(progress);
                    progressWrap.appendChild(statusLine);
                    progressWrap.appendChild(detailLine);
                    resultBox.appendChild(progressWrap);

                    statusLine.textContent = config.i18n.running || "Translation in progress...";
                    detailLine.textContent = "";

                    var tasks = [];
                    config.fields.forEach(function (fieldName) {
                        targetLangIds.forEach(function (targetLangId) {
                            tasks.push({
                                field: fieldName,
                                fieldLabel: (config.fieldLabels && config.fieldLabels[fieldName]) ? config.fieldLabels[fieldName] : fieldName,
                                targetLangId: targetLangId
                            });
                        });
                    });

                    var totalTasks = tasks.length;
                    var completed = 0;

                    function updateProgress(task) {
                        var percent = totalTasks > 0 ? Math.round((completed / totalTasks) * 100) : 0;
                        progressBar.style.width = percent + "%";
                        progressBar.textContent = percent + "%";
                        var targetLabel = config.languages[task.targetLangId] || ("ID " + task.targetLangId);
                        statusLine.textContent = formatMessage(config.i18n.status, [task.fieldLabel, targetLabel, completed, totalTasks]);
                        detailLine.textContent = config.i18n.running || "Translation in progress...";
                    }

                    function finishSuccess() {
                        resultBox.className = "alert alert-success";
                        resultBox.textContent = config.i18n.success || "Translation finished.";
                        button.disabled = false;
                    }

                    function runNext() {
                        if (!tasks.length) {
                            finishSuccess();
                            return;
                        }

                        var task = tasks.shift();
                        updateProgress(task);

                        var payload = new FormData();
                        payload.append("ajax", "1");
                        payload.append("action", config.action);
                        payload.append("token", config.token || config.controllerToken || "");
                        payload.append("controller_token", config.controllerToken || "");
                        payload.append(config.idParam, entityId);
                        payload.append("source_lang", sourceLangId);
                        payload.append("target_lang", task.targetLangId);
                        payload.append("field", task.field);
                        payload.append("force", forceValue ? 1 : 0);

                        postForm(payload).then(function (data) {
                            if (!data.success) {
                                throw new Error(data.message || "Unknown error");
                            }
                            completed += 1;
                            runNext();
                        }).catch(function (error) {
                            resultBox.className = "alert alert-danger";
                            resultBox.textContent = formatMessage(config.i18n.error, [error.message || error]);
                            button.disabled = false;
                        });
                    }

                    runNext();
                });
            });
        </script>';
    }

    private function renderAnblogPostTranslationPanel()
    {
        return $this->renderAnblogEntityTranslationPanel(
            'anblog_posts',
            'anblog-post',
            'AdminAnblogBlogs',
            'id_anblog_blog',
            'addanblog_blog',
            'updateanblog_blog',
            'runAnblogPostTranslationBatch',
            [
                'title' => $this->trans('Translate this blog post', [], 'Modules.Mahanatranslate.Admin'),
                'source' => $this->trans('Source language', [], 'Modules.Mahanatranslate.Admin'),
                'targets' => $this->trans('Target languages', [], 'Modules.Mahanatranslate.Admin'),
                'force' => $this->trans('Force overwrite', [], 'Modules.Mahanatranslate.Admin'),
                'force_help' => $this->trans('Overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
                'cta' => $this->trans('Translate', [], 'Modules.Mahanatranslate.Admin'),
                'missing_id' => $this->trans('Save the blog post before translating.', [], 'Modules.Mahanatranslate.Admin'),
                'confirm' => $this->trans('Translate this blog post into: %s ?', [], 'Modules.Mahanatranslate.Admin'),
                'confirm_force' => $this->trans('Warning: force mode is enabled. This will overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
                'missing_source' => $this->trans('Select a source language.', [], 'Modules.Mahanatranslate.Admin'),
                'missing_target' => $this->trans('Select at least one target language.', [], 'Modules.Mahanatranslate.Admin'),
                'missing_fields' => $this->trans('No blog post fields are available for translation.', [], 'Modules.Mahanatranslate.Admin'),
                'running' => $this->trans('Translation in progress...', [], 'Modules.Mahanatranslate.Admin'),
                'success' => $this->trans('Blog post translation finished.', [], 'Modules.Mahanatranslate.Admin'),
                'error' => $this->trans('Translation failed: %s', [], 'Modules.Mahanatranslate.Admin'),
                'status' => $this->trans('%s -> %s (%d/%d)', [], 'Modules.Mahanatranslate.Admin'),
            ]
        );
    }

    private function renderAnblogCategoryTranslationPanel()
    {
        return $this->renderAnblogEntityTranslationPanel(
            'anblog_categories',
            'anblog-category',
            'AdminAnblogCategories',
            'id_anblogcat',
            'addanblogcat',
            'updateanblogcat',
            'runAnblogCategoryTranslationBatch',
            [
                'title' => $this->trans('Translate this blog category', [], 'Modules.Mahanatranslate.Admin'),
                'source' => $this->trans('Source language', [], 'Modules.Mahanatranslate.Admin'),
                'targets' => $this->trans('Target languages', [], 'Modules.Mahanatranslate.Admin'),
                'force' => $this->trans('Force overwrite', [], 'Modules.Mahanatranslate.Admin'),
                'force_help' => $this->trans('Overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
                'cta' => $this->trans('Translate', [], 'Modules.Mahanatranslate.Admin'),
                'missing_id' => $this->trans('Save the blog category before translating.', [], 'Modules.Mahanatranslate.Admin'),
                'confirm' => $this->trans('Translate this blog category into: %s ?', [], 'Modules.Mahanatranslate.Admin'),
                'confirm_force' => $this->trans('Warning: force mode is enabled. This will overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
                'missing_source' => $this->trans('Select a source language.', [], 'Modules.Mahanatranslate.Admin'),
                'missing_target' => $this->trans('Select at least one target language.', [], 'Modules.Mahanatranslate.Admin'),
                'missing_fields' => $this->trans('No blog category fields are available for translation.', [], 'Modules.Mahanatranslate.Admin'),
                'running' => $this->trans('Translation in progress...', [], 'Modules.Mahanatranslate.Admin'),
                'success' => $this->trans('Blog category translation finished.', [], 'Modules.Mahanatranslate.Admin'),
                'error' => $this->trans('Translation failed: %s', [], 'Modules.Mahanatranslate.Admin'),
                'status' => $this->trans('%s -> %s (%d/%d)', [], 'Modules.Mahanatranslate.Admin'),
            ]
        );
    }

    public function hookDisplayBackOfficeHeader()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '') {
            return '';
        }

        $output = '';
        $controllerName = (string) Tools::getValue('controller');

        if ($controllerName === 'AdminAnblogBlogs') {
            $output .= $this->renderAnblogPostTranslationPanel();
        }

        if ($controllerName === 'AdminAnblogCategories') {
            $output .= $this->renderAnblogCategoryTranslationPanel();
        }

        if (strpos($uri, '/sell/catalog/products') !== false) {
            $languages = Language::getLanguages(false);
            $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
            $ajaxUrl = $this->getLegacyAdminUrl('AdminMahanaTranslate', []);
            $ajaxToken = Tools::getAdminTokenLite('AdminModules');
            $controllerToken = Tools::getAdminTokenLite('AdminMahanaTranslate');

            $languageLabels = [];
            foreach ($languages as $language) {
                $languageLabels[(int) $language['id_lang']] = sprintf('%s (%s)', $language['name'], $language['iso_code']);
            }

            $fields = isset($this->domains['products']['fields']) ? $this->domains['products']['fields'] : [];
            $fieldLabels = [];
            foreach ($fields as $fieldName) {
                $fieldLabels[$fieldName] = ucwords(str_replace('_', ' ', $fieldName));
            }

            $i18n = [
                'title' => $this->trans('Translate this product', [], 'Modules.Mahanatranslate.Admin'),
                'source' => $this->trans('Source language', [], 'Modules.Mahanatranslate.Admin'),
                'targets' => $this->trans('Target languages', [], 'Modules.Mahanatranslate.Admin'),
                'force' => $this->trans('Force overwrite', [], 'Modules.Mahanatranslate.Admin'),
                'force_help' => $this->trans('Overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
                'cta' => $this->trans('Translate', [], 'Modules.Mahanatranslate.Admin'),
                'missing_id' => $this->trans('Save the product before translating.', [], 'Modules.Mahanatranslate.Admin'),
                'confirm' => $this->trans('Translate this product into: %s ?', [], 'Modules.Mahanatranslate.Admin'),
                'confirm_force' => $this->trans('Warning: force mode is enabled. This will overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
                'missing_source' => $this->trans('Select a source language.', [], 'Modules.Mahanatranslate.Admin'),
                'missing_target' => $this->trans('Select at least one target language.', [], 'Modules.Mahanatranslate.Admin'),
                'missing_fields' => $this->trans('No product fields are available for translation.', [], 'Modules.Mahanatranslate.Admin'),
                'running' => $this->trans('Translation in progress...', [], 'Modules.Mahanatranslate.Admin'),
                'success' => $this->trans('Product translation finished.', [], 'Modules.Mahanatranslate.Admin'),
                'error' => $this->trans('Translation failed: %s', [], 'Modules.Mahanatranslate.Admin'),
                'status' => $this->trans('%s -> %s (%d/%d)', [], 'Modules.Mahanatranslate.Admin'),
            ];

            $config = [
                'ajaxUrl' => $ajaxUrl,
                'token' => $ajaxToken,
                'controllerToken' => $controllerToken,
                'defaultLang' => $defaultLang,
                'fields' => array_values($fields),
                'fieldLabels' => $fieldLabels,
                'languages' => $languageLabels,
                'i18n' => $i18n,
            ];

            $output .= '<style>
                .mahana-translate-product-card .mahana-target-list{display:flex;flex-wrap:wrap;gap:8px;}
                .mahana-translate-product-card .mahana-target-option{font-weight:400;}
                .mahana-translate-product-card .mahana-force-option{font-weight:400;}
                .mahana-translate-product-card .mahana-translate-progress{display:flex;flex-direction:column;gap:6px;}
                .mahana-translate-product-card .mahana-translate-status{font-weight:600;}
                .mahana-translate-product-card .mahana-translate-detail{color:#6c757d;font-size:12px;}
            </style><script>
                document.addEventListener("DOMContentLoaded", function () {
                    if (window.location.pathname.indexOf("/sell/catalog/products") === -1) {
                        return;
                    }
                    if (document.querySelector(".mahana-translate-product-card")) {
                        return;
                    }

                    var config = ' . json_encode($config) . ';

                    function getProductId() {
                        var fromUrl = window.location.pathname.match(/\\/sell\\/catalog\\/products\\/(\\d+)/);
                        if (fromUrl && fromUrl[1]) {
                            return parseInt(fromUrl[1], 10) || 0;
                        }

                        var selectors = [
                            "[data-product-id]",
                            "form[data-product-id]",
                            "input[name=\\"product[id]\\"]",
                            "input[name=\\"id_product\\"]",
                            "input[data-product-id]"
                        ];

                        for (var i = 0; i < selectors.length; i += 1) {
                            var element = document.querySelector(selectors[i]);
                            if (!element) {
                                continue;
                            }
                            var candidate = element.getAttribute("data-product-id") || element.value || "";
                            var parsed = parseInt(candidate, 10);
                            if (!isNaN(parsed) && parsed > 0) {
                                return parsed;
                            }
                        }

                        return 0;
                    }

                    function getInsertTarget() {
                        return document.querySelector("form[data-product-id]")
                            || document.querySelector("[data-product-id]")
                            || document.querySelector("main form")
                            || document.querySelector(".product-page form")
                            || document.querySelector("form");
                    }

                    var productId = getProductId();
                    var target = getInsertTarget();
                    if (!target || !target.parentNode) {
                        return;
                    }

                    var card = document.createElement("div");
                    card.className = "card mahana-translate-product-card";
                    var header = document.createElement("h3");
                    header.className = "card-header";
                    header.innerHTML = "<i class=\\"icon-language\\"></i> " + (config.i18n.title || "Translate this product");
                    card.appendChild(header);

                    var body = document.createElement("div");
                    body.className = "card-body";

                    var sourceGroup = document.createElement("div");
                    sourceGroup.className = "form-group";
                    var sourceLabel = document.createElement("label");
                    sourceLabel.className = "control-label";
                    sourceLabel.textContent = config.i18n.source || "Source language";
                    var sourceSelect = document.createElement("select");
                    sourceSelect.className = "form-control fixed-width-xxl";
                    Object.keys(config.languages || {}).forEach(function (langId) {
                        var option = document.createElement("option");
                        option.value = langId;
                        option.textContent = config.languages[langId];
                        if (parseInt(langId, 10) === parseInt(config.defaultLang, 10)) {
                            option.selected = true;
                        }
                        sourceSelect.appendChild(option);
                    });
                    sourceGroup.appendChild(sourceLabel);
                    sourceGroup.appendChild(sourceSelect);
                    body.appendChild(sourceGroup);

                    var targetsGroup = document.createElement("div");
                    targetsGroup.className = "form-group";
                    var targetsLabel = document.createElement("label");
                    targetsLabel.className = "control-label";
                    targetsLabel.textContent = config.i18n.targets || "Target languages";
                    var targetsList = document.createElement("div");
                    targetsList.className = "mahana-target-list";
                    Object.keys(config.languages || {}).forEach(function (langId) {
                        var label = document.createElement("label");
                        label.className = "mahana-target-option";
                        var checkbox = document.createElement("input");
                        checkbox.type = "checkbox";
                        checkbox.value = langId;
                        checkbox.name = "mahana_translate_product_target[]";
                        if (parseInt(langId, 10) !== parseInt(config.defaultLang, 10)) {
                            checkbox.checked = true;
                        }
                        label.appendChild(checkbox);
                        label.appendChild(document.createTextNode(" " + config.languages[langId]));
                        targetsList.appendChild(label);
                    });
                    targetsGroup.appendChild(targetsLabel);
                    targetsGroup.appendChild(targetsList);
                    body.appendChild(targetsGroup);

                    var forceGroup = document.createElement("div");
                    forceGroup.className = "form-group";
                    var forceLabel = document.createElement("label");
                    forceLabel.className = "mahana-force-option";
                    var forceCheckbox = document.createElement("input");
                    forceCheckbox.type = "checkbox";
                    forceCheckbox.value = "1";
                    forceCheckbox.name = "mahana_translate_product_force";
                    forceLabel.appendChild(forceCheckbox);
                    forceLabel.appendChild(document.createTextNode(" " + (config.i18n.force || "Force overwrite")));
                    var forceHelp = document.createElement("p");
                    forceHelp.className = "help-block";
                    forceHelp.textContent = config.i18n.force_help || "Overwrite existing translations.";
                    forceGroup.appendChild(forceLabel);
                    forceGroup.appendChild(forceHelp);
                    body.appendChild(forceGroup);

                    card.appendChild(body);

                    var footer = document.createElement("div");
                    footer.className = "card-footer";
                    var button = document.createElement("button");
                    button.type = "button";
                    button.className = "btn btn-primary";
                    button.textContent = config.i18n.cta || "Translate";
                    footer.appendChild(button);
                    card.appendChild(footer);

                    var resultBox = document.createElement("div");
                    resultBox.className = "alert";
                    resultBox.style.display = "none";
                    resultBox.style.margin = "12px";
                    card.appendChild(resultBox);

                    target.parentNode.insertBefore(card, target.nextSibling);

                    function formatMessage(template, values) {
                        if (!template) {
                            return "";
                        }
                        var index = 0;
                        return template.replace(/%s|%d/g, function () {
                            var value = values[index++];
                            return typeof value === "undefined" ? "" : value;
                        });
                    }

                    function syncTargets() {
                        var sourceValue = sourceSelect.value || "";
                        var checkboxes = targetsList.querySelectorAll("input[type=\\"checkbox\\"]");
                        Array.prototype.forEach.call(checkboxes, function (checkbox) {
                            if (checkbox.value === sourceValue) {
                                checkbox.checked = false;
                                checkbox.disabled = true;
                            } else {
                                checkbox.disabled = false;
                            }
                        });
                    }

                    function postForm(payload) {
                        return fetch(config.ajaxUrl, {
                            method: "POST",
                            body: payload,
                            credentials: "same-origin"
                        }).then(function (response) {
                            return response.json();
                        });
                    }

                    sourceSelect.addEventListener("change", syncTargets);
                    syncTargets();

                    button.addEventListener("click", function () {
                        if (!productId || productId <= 0) {
                            productId = getProductId();
                        }

                        if (!productId || productId <= 0) {
                            resultBox.className = "alert alert-warning";
                            resultBox.style.display = "";
                            resultBox.textContent = config.i18n.missing_id || "Save the product before translating.";
                            return;
                        }

                        var sourceLangId = sourceSelect.value || "";
                        if (!sourceLangId) {
                            resultBox.className = "alert alert-danger";
                            resultBox.style.display = "";
                            resultBox.textContent = config.i18n.missing_source || "Missing source language.";
                            return;
                        }

                        var targetLangIds = [];
                        Array.prototype.forEach.call(targetsList.querySelectorAll("input[type=\\"checkbox\\"]"), function (checkbox) {
                            if (checkbox.checked && !checkbox.disabled) {
                                targetLangIds.push(checkbox.value);
                            }
                        });
                        if (!targetLangIds.length) {
                            resultBox.className = "alert alert-danger";
                            resultBox.style.display = "";
                            resultBox.textContent = config.i18n.missing_target || "Missing target languages.";
                            return;
                        }

                        if (!config.fields || !config.fields.length) {
                            resultBox.className = "alert alert-danger";
                            resultBox.style.display = "";
                            resultBox.textContent = config.i18n.missing_fields || "Missing product fields.";
                            return;
                        }

                        var forceValue = !!forceCheckbox.checked;
                        var targetLabels = targetLangIds.map(function (langId) {
                            return config.languages[langId] || ("ID " + langId);
                        });
                        var confirmText = formatMessage(config.i18n.confirm, [targetLabels.join(", ")]);
                        if (forceValue && config.i18n.confirm_force) {
                            confirmText += "\\n" + config.i18n.confirm_force;
                        }
                        if (confirmText && !window.confirm(confirmText)) {
                            return;
                        }

                        button.disabled = true;
                        resultBox.style.display = "";
                        resultBox.className = "alert alert-info";
                        resultBox.innerHTML = "";

                        var progressWrap = document.createElement("div");
                        progressWrap.className = "mahana-translate-progress";
                        var progress = document.createElement("div");
                        progress.className = "progress";
                        var progressBar = document.createElement("div");
                        progressBar.className = "progress-bar";
                        progressBar.setAttribute("role", "progressbar");
                        progressBar.style.width = "0%";
                        progressBar.textContent = "0%";
                        progress.appendChild(progressBar);
                        var statusLine = document.createElement("div");
                        statusLine.className = "mahana-translate-status";
                        var detailLine = document.createElement("div");
                        detailLine.className = "mahana-translate-detail";
                        progressWrap.appendChild(progress);
                        progressWrap.appendChild(statusLine);
                        progressWrap.appendChild(detailLine);
                        resultBox.appendChild(progressWrap);

                        statusLine.textContent = config.i18n.running || "Translation in progress...";
                        detailLine.textContent = "";

                        var tasks = [];
                        config.fields.forEach(function (fieldName) {
                            targetLangIds.forEach(function (targetLangId) {
                                tasks.push({
                                    field: fieldName,
                                    fieldLabel: (config.fieldLabels && config.fieldLabels[fieldName]) ? config.fieldLabels[fieldName] : fieldName,
                                    targetLangId: targetLangId
                                });
                            });
                        });

                        var totalTasks = tasks.length;
                        var completed = 0;

                        function updateProgress(task) {
                            var percent = totalTasks > 0 ? Math.round((completed / totalTasks) * 100) : 0;
                            progressBar.style.width = percent + "%";
                            progressBar.textContent = percent + "%";
                            var targetLabel = config.languages[task.targetLangId] || ("ID " + task.targetLangId);
                            statusLine.textContent = formatMessage(config.i18n.status, [task.fieldLabel, targetLabel, completed, totalTasks]);
                            detailLine.textContent = config.i18n.running || "Translation in progress...";
                        }

                        function finishSuccess() {
                            resultBox.className = "alert alert-success";
                            resultBox.textContent = config.i18n.success || "Product translation finished.";
                            button.disabled = false;
                        }

                        function runNext() {
                            if (!tasks.length) {
                                finishSuccess();
                                return;
                            }

                            var task = tasks.shift();
                            updateProgress(task);

                            var payload = new FormData();
                            payload.append("ajax", "1");
                            payload.append("action", "runProductTranslationBatch");
                            payload.append("token", config.token || config.controllerToken || "");
                            payload.append("controller_token", config.controllerToken || "");
                            payload.append("id_product", productId);
                            payload.append("source_lang", sourceLangId);
                            payload.append("target_lang", task.targetLangId);
                            payload.append("field", task.field);
                            payload.append("force", forceValue ? 1 : 0);

                            postForm(payload).then(function (data) {
                                if (!data.success) {
                                    throw new Error(data.message || "Unknown error");
                                }
                                completed += 1;
                                runNext();
                            }).catch(function (error) {
                                resultBox.className = "alert alert-danger";
                                resultBox.textContent = formatMessage(config.i18n.error, [error.message || error]);
                                button.disabled = false;
                            });
                        }

                        runNext();
                    });
                });
            </script>';
        }

        if (strpos($uri, '/sell/catalog/categories') === false) {
            return $output;
        }

        $languages = Language::getLanguages(false);
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $ajaxUrl = $this->getLegacyAdminUrl('AdminMahanaTranslate', []);
        $ajaxToken = Tools::getAdminTokenLite('AdminModules');
        $controllerToken = Tools::getAdminTokenLite('AdminMahanaTranslate');

        $languageLabels = [];
        foreach ($languages as $language) {
            $languageLabels[(int) $language['id_lang']] = sprintf('%s (%s)', $language['name'], $language['iso_code']);
        }

        $fields = isset($this->domains['categories']['fields']) ? $this->domains['categories']['fields'] : [];
        $fieldLabels = [];
        foreach ($fields as $fieldName) {
            $fieldLabels[$fieldName] = ucwords(str_replace('_', ' ', $fieldName));
        }

        $i18n = [
            'title' => $this->trans('Translate this category', [], 'Modules.Mahanatranslate.Admin'),
            'source' => $this->trans('Source language', [], 'Modules.Mahanatranslate.Admin'),
            'targets' => $this->trans('Target languages', [], 'Modules.Mahanatranslate.Admin'),
            'force' => $this->trans('Force overwrite', [], 'Modules.Mahanatranslate.Admin'),
            'force_help' => $this->trans('Overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
            'cta' => $this->trans('Translate', [], 'Modules.Mahanatranslate.Admin'),
            'missing_id' => $this->trans('Save the category before translating.', [], 'Modules.Mahanatranslate.Admin'),
            'confirm' => $this->trans('Translate this category into: %s ?', [], 'Modules.Mahanatranslate.Admin'),
            'confirm_force' => $this->trans('Warning: force mode is enabled. This will overwrite existing translations.', [], 'Modules.Mahanatranslate.Admin'),
            'missing_source' => $this->trans('Select a source language.', [], 'Modules.Mahanatranslate.Admin'),
            'missing_target' => $this->trans('Select at least one target language.', [], 'Modules.Mahanatranslate.Admin'),
            'missing_fields' => $this->trans('No category fields are available for translation.', [], 'Modules.Mahanatranslate.Admin'),
            'running' => $this->trans('Translation in progress...', [], 'Modules.Mahanatranslate.Admin'),
            'success' => $this->trans('Category translation finished.', [], 'Modules.Mahanatranslate.Admin'),
            'error' => $this->trans('Translation failed: %s', [], 'Modules.Mahanatranslate.Admin'),
            'status' => $this->trans('%s -> %s (%d/%d)', [], 'Modules.Mahanatranslate.Admin'),
        ];

        $config = [
            'ajaxUrl' => $ajaxUrl,
            'token' => $ajaxToken,
            'controllerToken' => $controllerToken,
            'defaultLang' => $defaultLang,
            'fields' => array_values($fields),
            'fieldLabels' => $fieldLabels,
            'languages' => $languageLabels,
            'i18n' => $i18n,
        ];

        $output .= '<style>
            .mahana-translate-category-card .mahana-target-list{display:flex;flex-wrap:wrap;gap:8px;}
            .mahana-translate-category-card .mahana-target-option{font-weight:400;}
            .mahana-translate-category-card .mahana-force-option{font-weight:400;}
            .mahana-translate-category-card .mahana-translate-progress{display:flex;flex-direction:column;gap:6px;}
            .mahana-translate-category-card .mahana-translate-status{font-weight:600;}
            .mahana-translate-category-card .mahana-translate-detail{color:#6c757d;font-size:12px;}
        </style><script>
            document.addEventListener("DOMContentLoaded", function () {
                if (window.location.pathname.indexOf("/sell/catalog/categories") === -1) {
                    return;
                }
                var config = ' . json_encode($config) . ';
                var form = document.querySelector("form[data-id]");
                if (!form) {
                    return;
                }
                if (document.querySelector(".mahana-translate-category-card")) {
                    return;
                }
                var categoryId = parseInt(form.getAttribute("data-id") || "0", 10);

                var card = document.createElement("div");
                card.className = "card mahana-translate-category-card";
                var header = document.createElement("h3");
                header.className = "card-header";
                header.innerHTML = "<i class=\\"icon-language\\"></i> " + (config.i18n.title || "Translate this category");
                card.appendChild(header);

                var body = document.createElement("div");
                body.className = "card-body";

                var sourceGroup = document.createElement("div");
                sourceGroup.className = "form-group";
                var sourceLabel = document.createElement("label");
                sourceLabel.className = "control-label";
                sourceLabel.textContent = config.i18n.source || "Source language";
                var sourceSelect = document.createElement("select");
                sourceSelect.className = "form-control fixed-width-xxl";
                Object.keys(config.languages || {}).forEach(function (langId) {
                    var option = document.createElement("option");
                    option.value = langId;
                    option.textContent = config.languages[langId];
                    if (parseInt(langId, 10) === parseInt(config.defaultLang, 10)) {
                        option.selected = true;
                    }
                    sourceSelect.appendChild(option);
                });
                sourceGroup.appendChild(sourceLabel);
                sourceGroup.appendChild(sourceSelect);
                body.appendChild(sourceGroup);

                var targetsGroup = document.createElement("div");
                targetsGroup.className = "form-group";
                var targetsLabel = document.createElement("label");
                targetsLabel.className = "control-label";
                targetsLabel.textContent = config.i18n.targets || "Target languages";
                var targetsList = document.createElement("div");
                targetsList.className = "mahana-target-list";
                Object.keys(config.languages || {}).forEach(function (langId) {
                    var label = document.createElement("label");
                    label.className = "mahana-target-option";
                    var checkbox = document.createElement("input");
                    checkbox.type = "checkbox";
                    checkbox.value = langId;
                    checkbox.name = "mahana_translate_category_target[]";
                    if (parseInt(langId, 10) !== parseInt(config.defaultLang, 10)) {
                        checkbox.checked = true;
                    }
                    label.appendChild(checkbox);
                    label.appendChild(document.createTextNode(" " + config.languages[langId]));
                    targetsList.appendChild(label);
                });
                targetsGroup.appendChild(targetsLabel);
                targetsGroup.appendChild(targetsList);
                body.appendChild(targetsGroup);

                var forceGroup = document.createElement("div");
                forceGroup.className = "form-group";
                var forceLabel = document.createElement("label");
                forceLabel.className = "mahana-force-option";
                var forceCheckbox = document.createElement("input");
                forceCheckbox.type = "checkbox";
                forceCheckbox.value = "1";
                forceCheckbox.name = "mahana_translate_category_force";
                forceLabel.appendChild(forceCheckbox);
                forceLabel.appendChild(document.createTextNode(" " + (config.i18n.force || "Force overwrite")));
                var forceHelp = document.createElement("p");
                forceHelp.className = "help-block";
                forceHelp.textContent = config.i18n.force_help || "Overwrite existing translations.";
                forceGroup.appendChild(forceLabel);
                forceGroup.appendChild(forceHelp);
                body.appendChild(forceGroup);

                card.appendChild(body);

                var footer = document.createElement("div");
                footer.className = "card-footer";
                var button = document.createElement("button");
                button.type = "button";
                button.className = "btn btn-primary";
                button.textContent = config.i18n.cta || "Translate";
                footer.appendChild(button);
                card.appendChild(footer);

                var resultBox = document.createElement("div");
                resultBox.className = "alert";
                resultBox.style.display = "none";
                resultBox.style.margin = "12px";
                card.appendChild(resultBox);

                form.parentNode.insertBefore(card, form.nextSibling);

                function formatMessage(template, values) {
                    if (!template) {
                        return "";
                    }
                    var index = 0;
                    return template.replace(/%s|%d/g, function () {
                        var value = values[index++];
                        return typeof value === "undefined" ? "" : value;
                    });
                }

                function syncTargets() {
                    var sourceValue = sourceSelect.value || "";
                    var checkboxes = targetsList.querySelectorAll("input[type=\\"checkbox\\"]");
                    Array.prototype.forEach.call(checkboxes, function (checkbox) {
                        if (checkbox.value === sourceValue) {
                            checkbox.checked = false;
                            checkbox.disabled = true;
                        } else {
                            checkbox.disabled = false;
                        }
                    });
                }

                function postForm(payload) {
                    return fetch(config.ajaxUrl, {
                        method: "POST",
                        body: payload,
                        credentials: "same-origin"
                    }).then(function (response) {
                        return response.json();
                    });
                }

                sourceSelect.addEventListener("change", syncTargets);
                syncTargets();

                button.addEventListener("click", function () {
                    if (!categoryId || categoryId <= 0) {
                        resultBox.className = "alert alert-warning";
                        resultBox.style.display = "";
                        resultBox.textContent = config.i18n.missing_id || "Save the category before translating.";
                        return;
                    }

                    var sourceLangId = sourceSelect.value || "";
                    if (!sourceLangId) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.textContent = config.i18n.missing_source || "Missing source language.";
                        return;
                    }

                    var targetLangIds = [];
                    Array.prototype.forEach.call(targetsList.querySelectorAll("input[type=\\"checkbox\\"]"), function (checkbox) {
                        if (checkbox.checked && !checkbox.disabled) {
                            targetLangIds.push(checkbox.value);
                        }
                    });
                    if (!targetLangIds.length) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.textContent = config.i18n.missing_target || "Missing target languages.";
                        return;
                    }

                    if (!config.fields || !config.fields.length) {
                        resultBox.className = "alert alert-danger";
                        resultBox.style.display = "";
                        resultBox.textContent = config.i18n.missing_fields || "Missing fields.";
                        return;
                    }

                    var forceValue = !!forceCheckbox.checked;
                    var targetLabels = targetLangIds.map(function (langId) {
                        return config.languages[langId] || ("ID " + langId);
                    });
                    var confirmText = formatMessage(config.i18n.confirm, [targetLabels.join(", ")]);
                    if (forceValue && config.i18n.confirm_force) {
                        confirmText += "\\n" + config.i18n.confirm_force;
                    }
                    if (confirmText && !window.confirm(confirmText)) {
                        return;
                    }

                    button.disabled = true;
                    resultBox.style.display = "";
                    resultBox.className = "alert alert-info";
                    resultBox.innerHTML = "";

                    var progressWrap = document.createElement("div");
                    progressWrap.className = "mahana-translate-progress";
                    var progress = document.createElement("div");
                    progress.className = "progress";
                    var progressBar = document.createElement("div");
                    progressBar.className = "progress-bar";
                    progressBar.setAttribute("role", "progressbar");
                    progressBar.style.width = "0%";
                    progressBar.textContent = "0%";
                    progress.appendChild(progressBar);
                    var statusLine = document.createElement("div");
                    statusLine.className = "mahana-translate-status";
                    var detailLine = document.createElement("div");
                    detailLine.className = "mahana-translate-detail";
                    progressWrap.appendChild(progress);
                    progressWrap.appendChild(statusLine);
                    progressWrap.appendChild(detailLine);
                    resultBox.appendChild(progressWrap);

                    statusLine.textContent = config.i18n.running || "Translation in progress...";
                    detailLine.textContent = "";

                    var tasks = [];
                    config.fields.forEach(function (fieldName) {
                        targetLangIds.forEach(function (targetLangId) {
                            tasks.push({
                                field: fieldName,
                                fieldLabel: (config.fieldLabels && config.fieldLabels[fieldName]) ? config.fieldLabels[fieldName] : fieldName,
                                targetLangId: targetLangId
                            });
                        });
                    });

                    var totalTasks = tasks.length;
                    var completed = 0;

                    function updateProgress(task) {
                        var percent = totalTasks > 0 ? Math.round((completed / totalTasks) * 100) : 0;
                        progressBar.style.width = percent + "%";
                        progressBar.textContent = percent + "%";
                        var targetLabel = config.languages[task.targetLangId] || ("ID " + task.targetLangId);
                        statusLine.textContent = formatMessage(config.i18n.status, [task.fieldLabel, targetLabel, completed, totalTasks]);
                        detailLine.textContent = config.i18n.running || "Translation in progress...";
                    }

                    function finishSuccess() {
                        resultBox.className = "alert alert-success";
                        resultBox.textContent = config.i18n.success || "Category translation finished.";
                        button.disabled = false;
                    }

                    function runNext() {
                        if (!tasks.length) {
                            finishSuccess();
                            return;
                        }

                        var task = tasks.shift();
                        updateProgress(task);

                        var payload = new FormData();
                        payload.append("ajax", "1");
                        payload.append("action", "runCategoryTranslationBatch");
                        payload.append("token", config.token || config.controllerToken || "");
                        payload.append("controller_token", config.controllerToken || "");
                        payload.append("id_category", categoryId);
                        payload.append("source_lang", sourceLangId);
                        payload.append("target_lang", task.targetLangId);
                        payload.append("field", task.field);
                        payload.append("force", forceValue ? 1 : 0);

                        postForm(payload).then(function (data) {
                            if (!data.success) {
                                throw new Error(data.message || "Unknown error");
                            }
                            completed += 1;
                            runNext();
                        }).catch(function (error) {
                            resultBox.className = "alert alert-danger";
                            resultBox.textContent = formatMessage(config.i18n.error, [error.message || error]);
                            button.disabled = false;
                        });
                    }

                    runNext();
                });
            });
        </script>';

        return $output;
    }

    public function ajaxProcessRunTranslationJob()
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->handleTranslationJobRequest());
        exit;
    }

    public function installTab()
    {
        $tabClass = 'AdminMahanaTranslate';
        $tabId = (int) Tab::getIdFromClassName($tabClass);
        if ($tabId) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = $tabClass;
        $tab->active = 0;
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentModulesSf');
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'Mahana Translate';
        }

        return (bool) $tab->add();
    }

    public function uninstallTab()
    {
        $tabClass = 'AdminMahanaTranslate';
        $tabId = (int) Tab::getIdFromClassName($tabClass);
        if (!$tabId) {
            return true;
        }

        $tab = new Tab($tabId);

        return $tab->delete();
    }

    /**
     * Lazily instantiate the translation provider selected in configuration.
     */
    public function getTranslationProvider()
    {
        if (!$this->providerFactory) {
            $this->providerFactory = new ProviderFactory();
        }

        $providerKey = Configuration::get(self::CONFIG_PROVIDER, self::PROVIDER_OPENAI);

        return $this->providerFactory->create($providerKey);
    }

    public function testProviderConnection()
    {
        try {
            $provider = $this->getTranslationProvider();
            $provider->translate(['ping'], 'en', 'fr');
        } catch (ProviderException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    private function handleTranslationJobRequest()
    {
        $sourceLangId = (int) Tools::getValue('source_lang');
        $targetLangIds = array_map('intval', (array) Tools::getValue('target_lang', []));
        $targetLangIds = array_values(array_unique(array_filter($targetLangIds)));
        $force = (bool) Tools::getValue('force', Tools::getValue('force_translation', false));

        $domains = (array) Tools::getValue('domains', []);
        $domains = array_values(array_unique(array_intersect($domains, $this->getDomainKeys())));
        if (empty($domains)) {
            $domainSelection = $this->getJobDomainSelection(false);
            foreach ($domainSelection as $key => $enabled) {
                if ($enabled) {
                    $domains[] = $key;
                }
            }
        }

        $errors = [];
        if ($sourceLangId <= 0) {
            $errors[] = $this->trans('Select a source language.', [], 'Modules.Mahanatranslate.Admin');
        }
        if (empty($targetLangIds)) {
            $errors[] = $this->trans('Select at least one target language.', [], 'Modules.Mahanatranslate.Admin');
        }
        if (!empty($targetLangIds) && in_array($sourceLangId, $targetLangIds, true)) {
            $targetLangIds = array_values(array_filter($targetLangIds, function ($langId) use ($sourceLangId) {
                return $langId !== $sourceLangId;
            }));
        }
        if (empty($targetLangIds)) {
            $errors[] = $this->trans('Target languages must be different from the source.', [], 'Modules.Mahanatranslate.Admin');
        }
        if (empty($domains)) {
            $errors[] = $this->trans('Select at least one domain to translate.', [], 'Modules.Mahanatranslate.Admin');
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => implode(' ', $errors),
            ];
        }

        try {
            $provider = $this->getTranslationProvider();
        } catch (ProviderException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $manager = new TranslationManager($provider);
        $reports = $manager->translate($domains, $sourceLangId, $targetLangIds, $force);

        return [
            'success' => true,
            'reports' => $reports,
        ];
    }
}
