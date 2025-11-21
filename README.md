# 🤖 AI Divi Generator

<p align="center">
  <a href="/mnt/data/ai-divi-generator.zip">
    <img src="https://upload.wikimedia.org/wikipedia/commons/2/20/Divi_logo.png"
         alt="AI Divi Generator Logo"
         width="280"
         style="background-color: white; padding: 10px; border-radius: 8px;" />
  </a>
</p>

> A developer-friendly WordPress plugin that uses AI to generate **Divi Builder** sections, rows, columns and modules from natural language prompts.  
> Generate full pages or single sections, export ready-to-import Divi JSON, and speed up your page-building workflow.

---

## 📖 About

**AI Divi Generator** converts human prompts (and optional design images) into Divi shortcodes / JSON layout exports.  
It’s designed developers who want to speed up layout creation inside WordPress + Divi.

Key ideas:
- Feed an instruction like *“Create a hero with headline, subhead, CTA, and right-side image”*  
- Plugin builds a strict AI prompt, calls your AI backend, validates JSON, and injects content into a post/page.  
- Backup system prevents accidental overwrites.

---

## ✨ Features

- 🤖 **AI-powered Divi section & page generation**  
- 🧩 **Generates Divi sections, rows, columns & modules** (Text, Image, Button, Blurbs, etc.)  
- 🔒 **Secure AJAX (nonce + sanitization)**  
- 🔁 **Append or replace page content** (configurable; respects `existing_code` rules)  
- 🗄 **Automatic backup before edits** (`_aidg_backup_page_content` meta)  
- ⚙️ **Admin UI Sidebar → AI → Divi**  
- 🛠 **Developer friendly: modular, extensible, clear function points**

---

## 🛠 Tech Stack

| Component | Tech |
|-----------|------|
| WordPress | PHP (OOP) |
| Builder | Divi Theme / Divi Builder plugin |
| AI backend | OpenAI / custom GPT endpoint |
| Frontend | JS (AJAX), CSS |
| Data | Divi shortcodes & Divi JSON export format |

---

## 📁 Repository Layout

ai-divi-generator/
├── index.php # Plugin header + bootstrap
├── ai-divi-generator-page.php # Main class (AIDiviGenerator)
├── ai-divi-generator-section.php # Section generator class/UI
└── readme.md # (this file)


---

## 🔧 Installation

**Manual install**

1. Upload `ai-divi-generator.zip` (or the extracted folder) to `wp-content/plugins/`.
2. Activate plugin: **WP Admin → Plugins → Activate**.
3. Visit **AI → Divi** or **Tools under AI → Divi**.

**Requirements**
- WordPress 5.8+
- Divi Theme or Divi Builder installed & active
- PHP 7.4+
- Your AI API endpoint / key configured in plugin settings (if applicable)

---

## 🚀 Quick Usage

1. Go to **Tools → AI → Divi and select the platform Gemini/Open AI and the model with the API Key**.  
2. Enter a brief or upload an image.  
   Example prompt:  
   > `Create a hero section with a bold headline, short subheadline, CTA button (“Get Started”), and an image on the right.`  
3. (Optional) Provide `existing_code` if you want to append/modify a specific page.  
4. Select **Generate**.  

---

## 🔍 Prompt Engine (Important)

The plugin uses a strict `prompt_template()` that enforces:
- Output must be **valid JSON only**: `{ "title": "", "slug": "", "layout": "..." }`
- **No memory**: AI must not reuse previous outputs unless `existing_code` is provided again in the current request.
- **Existing code rules**:
  - If `existing_code` is empty → produce ONLY new code.
  - If `existing_code` provided → append or modify **only if** `can_modify_existing_code` is `Yes`.
- **Image handling**: replace detected images with placeholders (`https://placehold.co/{width}x{height}`)
- **Module preset enforcement**: if `ai_divi_specific_module` & `ai_divi_module_preset_id` are provided, apply into the specific module with `_module_preset` attribute and DO NOT change styles.

---

## 🔎 Main Functions (what they do)

> Presented as a quick reference for developers reading the plugin files.

### `prompt_template($type, $brief, $existingCode, $title, $slug, $canModifyCapability, $ai_divi_specific_module, $ai_divi_module_preset_id)`
- Builds the final text prompt for the AI including rules for existing code handling, JSON schema, image placeholders, module preset rules, and other strict instructions.

### `ajax_generate_page()`
- Uses `wp_insert_post()` to create a new page or `wp_update_post()` to update.
- Saves Divi layout into post content or post meta (as shortcode/HTML).
- Before saving, triggers backup with `_ai_divi_backup_page_content key`.

---

## 🛡 Security & Best Practices

- All admin forms use `wp_nonce_field()` and server-side `wp_verify_nonce()`.
- Input sanitized via `sanitize_text_field()` and `wp_kses_post()` where appropriate.
- Capability checks (only users with `manage_options` or admin capabilities can run generation).
- Keep your AI API key secret and do not hardcode in public repos.

---

## 🧰 Example Prompt & Expected JSON

**Prompt**

- Create a hero section:
- Headline: "Launch Faster"
- Subheadline: "AI-powered Divi layouts"
- CTA text: "Try Now"
- Image on the right (placeholder)

**Expected AI response (conceptual)**
{
  "title": "AI Hero - Launch Faster",
  "slug": "ai-hero-launch-faster",
  "layout": "[et_pb_section][et_pb_row]...[/et_pb_section]"
}

**🔄 Backup & Restore**

- Backups are stored in post meta _ai_divi_backup_page_content.
- To restore: fetch the meta and replace post content with the stored backup (the plugin UI includes a restore button).