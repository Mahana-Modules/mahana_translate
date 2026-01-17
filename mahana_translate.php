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

    /** @var array<string, array{label: string}> */
    private $domains = [
        'products' => [
            'label' => 'Products',
        ],
        'categories' => [
            'label' => 'Categories',
        ],
        'cms_pages' => [
            'label' => 'CMS pages',
        ],
        'static_pages' => [
            'label' => 'Static blocks/pages',
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
                        'type' => 'password',
                        'label' => $this->trans('OpenAI API key', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => self::CONFIG_OPENAI_KEY,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->trans('Stored encrypted. Required when ChatGPT is selected.', [], 'Modules.Mahanatranslate.Admin'),
                        'form_group_class' => 'provider-field provider-openai',
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
                        'type' => 'password',
                        'label' => $this->trans('Google API key', [], 'Modules.Mahanatranslate.Admin'),
                        'name' => self::CONFIG_GOOGLE_KEY,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->trans('Required when Google Translate is selected.', [], 'Modules.Mahanatranslate.Admin'),
                        'form_group_class' => 'provider-field provider-google',
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

        return $helper->generateForm([$fieldsForm]) . $this->renderProviderToggleScript();
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
        return [
            self::CONFIG_PROVIDER => Configuration::get(self::CONFIG_PROVIDER, self::PROVIDER_OPENAI),
            self::CONFIG_OPENAI_KEY => Tools::getValue(self::CONFIG_OPENAI_KEY, Configuration::get(self::CONFIG_OPENAI_KEY)),
            self::CONFIG_OPENAI_MODEL => Tools::getValue(self::CONFIG_OPENAI_MODEL, Configuration::get(self::CONFIG_OPENAI_MODEL)),
            self::CONFIG_GOOGLE_KEY => Tools::getValue(self::CONFIG_GOOGLE_KEY, Configuration::get(self::CONFIG_GOOGLE_KEY)),
            self::CONFIG_GOOGLE_PROJECT => Tools::getValue(self::CONFIG_GOOGLE_PROJECT, Configuration::get(self::CONFIG_GOOGLE_PROJECT)),
        ];
    }

    private function getJobFormValues()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $values = [
            'source_lang' => Tools::getValue('source_lang', $defaultLang),
            'target_lang[]' => Tools::getValue('target_lang', []),
            'force_translation' => (bool) Tools::getValue('force_translation', false),
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
            $domainFields[] = [
                'field' => $this->getJobDomainFieldName($key),
                'key' => $key,
                'label' => $this->trans($domain['label'], [], 'Modules.Mahanatranslate.Admin'),
            ];
        }

        $ajaxUrl = $this->getLegacyAdminUrl('AdminMahanaTranslate', []);
        $ajaxToken = Tools::getAdminTokenLite('AdminModules');
        $controllerToken = Tools::getAdminTokenLite('AdminMahanaTranslate');
        $i18n = [
            'running' => $this->trans('Translation in progress...', [], 'Modules.Mahanatranslate.Admin'),
            'success' => $this->trans('Translation finished.', [], 'Modules.Mahanatranslate.Admin'),
            'error' => $this->trans('Translation failed: %s', [], 'Modules.Mahanatranslate.Admin'),
            'filter' => $this->trans('Filter target languages...', [], 'Modules.Mahanatranslate.Admin'),
            'no_results' => $this->trans('No languages found.', [], 'Modules.Mahanatranslate.Admin'),
        ];

        return '<script>
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
                var ajaxUrl = ' . json_encode($ajaxUrl) . ';
                var ajaxToken = ' . json_encode($ajaxToken) . ';
                var controllerToken = ' . json_encode($controllerToken) . ';
                var messages = ' . json_encode($i18n) . ';
                var targetTagUi = null;
                var targetTagInput = null;
                var targetTagList = null;
                var targetOptionList = null;

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
                    var payload = new FormData();
                    payload.append("ajax", "1");
                    payload.append("action", "runTranslationJob");
                    payload.append("token", ajaxToken || controllerToken || "");
                    payload.append("controller_token", controllerToken || "");
                    payload.append("source_lang", formData.get("source_lang") || "");
                    (formData.getAll("target_lang[]") || []).forEach(function (value) {
                        payload.append("target_lang[]", value);
                    });
                    domains.forEach(function (domain) {
                        if (formData.get(domain.field)) {
                            payload.append("domains[]", domain.key);
                        }
                    });
                    payload.append("force", formData.get("force_translation") === "1" ? 1 : 0);

                    submitButton.disabled = true;
                    resultBox.style.display = "";
                    resultBox.className = "alert alert-info";
                    resultBox.innerHTML = messages.running;

                    fetch(ajaxUrl, {
                        method: "POST",
                        body: payload,
                        credentials: "same-origin"
                    }).then(function (response) {
                        return response.json();
                    }).then(function (data) {
                        if (!data.success) {
                            throw new Error(data.message || "Unknown error");
                        }
                        var html = "<p>" + messages.success + "</p>";
                        if (Array.isArray(data.reports) && data.reports.length) {
                            html += "<ul>";
                            data.reports.forEach(function (report) {
                                var label = "";
                                for (var i = 0; i < domains.length; i++) {
                                    if (domains[i].key === report.domain) {
                                        label = domains[i].label;
                                        break;
                                    }
                                }
                                html += "<li><strong>" + label + ":</strong> " + report.message + "</li>";
                            });
                            html += "</ul>";
                        }
                        resultBox.className = "alert alert-success";
                        resultBox.innerHTML = html;
                    }).catch(function (error) {
                        resultBox.className = "alert alert-danger";
                        resultBox.innerHTML = messages.error.replace("%s", error.message || error);
                    }).finally(function () {
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
