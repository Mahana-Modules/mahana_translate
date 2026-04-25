# Agent Instructions

- Product: PrestaShop 9 module.
- Module name: mahana_translate.
- Goal: translate all user-created front-office content (products, categories, CMS pages, static blocks, etc.) into other shop languages; do not touch back-office strings.
- Entry point: mahana_translate.php.
- Metadata/config: config.xml.
- Reference: always check the official PrestaShop 9 developer documentation before relying on assumptions.

## Code preparation guide

- Keep to PrestaShop 9 module conventions (module class, install/uninstall hooks, and configuration storage).
- Prefer native translation mechanisms and language data (no hard-coded language IDs); respect the language enabled status.
- Ensure content updates are idempotent and safe to re-run.
- Support multi-shop and multi-language contexts explicitly.
- Add only minimal admin UI needed to configure and trigger translations.
- Provide UI to pick a source language and one or more target languages from the active shop languages.
- Abstract translation providers so translators can swap between OpenAI (ChatGPT) and Google Translate now; keep the design open for more providers later (factory + interface).
- Store API credentials securely in module configuration; validate before use.
- Allow the user to choose provider per translation job and show clear errors/logs if an API fails; fall back or retry gracefully.
