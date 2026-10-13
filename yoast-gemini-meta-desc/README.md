# Yoast SEO Meta Description Generator (Gemini)

An enterprise-ready, standalone WordPress addon for **Yoast SEO** that automatically generates engaging, SEO-optimized meta descriptions (strictly 120–158 characters) using the **Google Gemini API**.

---

## 🌟 Key Features

* **Yoast SEO Native Integration**: Directly populates Yoast's postmeta field `_yoast_wpseo_metadesc` and triggers Yoast's native `Indexable_Post_Meta_Watcher` to synchronize `{$wpdb->prefix}yoast_indexable` without touching Yoast core files.
* **Strict Character Length Enforcement (120–158 chars)**:
  * Automatically calculates multi-byte string length (`mb_strlen`).
  * Features a server-side refinement retry loop that re-prompts Gemini if the initial output falls outside 120–158 characters.
  * Intelligent word-boundary trimming if text slightly exceeds 158 characters.
* **Batch Processing (Max 20 Posts per Click)**:
  * Enforces a hard cap of **20 posts per click** to prevent server timeouts and rate-limiting.
  * Real-time AJAX processing with dynamic progress bar (`0%` to `100%`) and live item indicator.
  * Fault-isolated: If an individual post fails, the error is logged while remaining posts in the batch continue uninterrupted.
  * Repeatable: Subsequent clicks automatically process the next batch of eligible posts until all content is optimized.
* **Zero Overwrite Safety**: Posts that already have non-empty Yoast meta descriptions are automatically skipped.
* **Configurable Gemini Models**: Choose between `Gemini 1.5 Flash` (default, high speed), `Gemini 2.0 Flash`, `Gemini 2.5 Flash`, or `Gemini 1.5 Pro`.
* **Security & Credential Protection**:
  * API keys are masked in the UI (`AIzaSy••••••••••••3XyZ`).
  * Requests are processed exclusively server-side via `wp_remote_post`.
  * Protected by WordPress capabilities (`manage_options`) and nonce verification (`check_ajax_referer`).

---

## 🚀 Installation & Setup

1. **Prerequisites**:
   * WordPress 5.8 or higher.
   * PHP 7.4 or higher.
   * [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) plugin installed and active.
   * A Google Gemini API Key from [Google AI Studio](https://aistudio.google.com/app/apikey).

2. **Installation**:
   * Upload the `yoast-gemini-meta-desc` folder to your WordPress plugins directory (`wp-content/plugins/`).
   * In the WordPress admin dashboard, navigate to **Plugins > Installed Plugins**.
   * Locate **Yoast SEO Meta Description Generator (Gemini)** and click **Activate**.

3. **Configuring the Gemini API Key**:
   * In the WordPress admin, navigate to **Yoast SEO > Gemini Meta Generator** (or top-level **Gemini Meta Gen**).
   * Enter your Google Gemini API Key in the **Google Gemini API Key** field.
   * Click **Test API Connection** to verify your key and model connectivity.
   * Click **Save Settings**.

---

## 📖 Usage Instructions

1. **Select Post Type**:
   * Choose the desired post type (e.g., Posts, Pages, or custom post types) from the dropdown.
   * The stats grid will automatically display:
     * **Total Published**: Total published content count.
     * **Have Description**: Content that already has a Yoast meta description.
     * **Missing Description (Eligible)**: Content needing descriptions.
     * **Remaining to Process**: Eligible posts left.

2. **Generate Meta Descriptions**:
   * Click the **"Generate Meta Descriptions"** button.
   * The addon will queue up to 20 eligible posts for this click session.
   * Watch the live progress bar and live activity table as each post is processed.
   * Each entry displays:
     * Post ID & Title (with clickable edit link).
     * Generated meta description text.
     * Character count badge (green for 120–158 characters).
     * Status indicator (Success, Skipped, or Failed with error explanation).

3. **Repeatable Batches**:
   * Once the batch of up to 20 posts finishes, the remaining count updates automatically.
   * Click **"Generate Meta Descriptions"** again to process the next batch of 20 posts until all posts are optimized.
   * When 0 eligible posts remain, a celebratory completion banner will be displayed.

---

## 🔒 Security Architecture

* **Role-Based Access Control**: All administrative operations and AJAX endpoints require `current_user_can('manage_options')`.
* **CSRF Protection**: All requests validate a WordPress nonce generated via `wp_create_nonce('yoast_gemini_meta_nonce')`.
* **Sanitization and Escaping**:
  * Inputs sanitized via `sanitize_text_field()` and `sanitize_key()`.
  * Outputs escaped using `esc_html()`, `esc_attr()`, and `esc_url()`.
  * Descriptions slashed and saved securely via `wp_slash()`.
* **No Credential Exposure**: API keys are never exposed in JavaScript variables, frontend markup, or API responses.

---

## ⚠️ Known Limitations & Considerations

* **Empty Posts**: Posts with no body text, no headings, and no excerpt cannot generate high-quality descriptions and will be reported as skipped/failed with a clear note.
* **Gemini API Quotas**: Ensure your Google AI Studio account has sufficient request quota for the model selected.
* **Drafts / Private Content**: The addon intentionally targets only `publish` status posts to ensure public search engine optimization integrity.

---

## 📄 License

GPL v2 or later. See `LICENSE` for details.
