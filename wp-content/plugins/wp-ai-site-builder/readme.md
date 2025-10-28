# WP AI Site Builder (Example)
A WordPress plugin that lets an admin enter a natural-language prompt (optionally attach a mock image) and generates a draft **Page** using a free AI API.

## Providers
- **Gemini (Google AI Studio)**: requires an API key.
- **OpenAI-compatible**: use any service that implements the Chat Completions API (e.g., OpenRouter, local servers, etc.).

## Install
1. Upload the folder to `wp-content/plugins/wp-ai-site-builder/` or upload the zip via **Plugins → Add New → Upload Plugin**.
2. Activate **WP AI Site Builder**.
3. Go to **AI Site Builder** in the admin.
4. Open **Settings** (right card), set your provider and API key(s).
5. In **Generate Page** (left card), write your prompt, optionally attach a mock image, and click **Generate Draft Page**.

## Notes
- The plugin inserts generated HTML/CSS into a Gutenberg **HTML** block (by default) so you can tweak it without breaking.
- Always review the draft before publishing.
