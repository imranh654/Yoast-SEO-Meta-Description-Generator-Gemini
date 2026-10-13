# Project: Yoast SEO Meta Description Generator Addon

This directory contains the Yoast SEO plugin reference codebase and the standalone **Yoast SEO Meta Description Generator (Gemini)** addon.

## Project Structure

```text
yoast seo meta description generation/
├── wordpress-seo/                 # Reference copy of Yoast SEO plugin (v28.7) - Unmodified
├── yoast-gemini-meta-desc/        # Standalone Addon Plugin for Yoast SEO
│   ├── yoast-gemini-meta-desc.php # Main entry point
│   ├── includes/
│   │   ├── class-plugin.php       # Plugin lifecycle & dependencies
│   │   ├── class-admin.php        # Admin interface & menu registration
│   │   ├── class-gemini-client.php# Gemini API client with 120–158 char enforcement
│   │   ├── class-yoast-bridge.php # Yoast SEO data bridge & indexable sync
│   │   └── class-batch-processor.php # AJAX batch runner (max 20 posts per click)
│   ├── assets/
│   │   ├── css/admin.css          # Admin styling, progress bar, counters
│   │   └── js/admin.js            # Batch queue controller & live progress
│   └── README.md                  # Addon documentation and usage guide
└── scratch/
    └── test-addon.php             # Unit test suite verifying logic and character targets
```

## Implementation Highlights

* **Yoast Storage**: Saves directly to `_yoast_wpseo_metadesc` and focus keyword `_yoast_wpseo_focuskw`.
* **Zero Yoast File Modifications**: All functionality resides purely inside `yoast-gemini-meta-desc/`.
* **Length Validation**: Strictly validates that all saved descriptions meet the **120–158 character** target using `mb_strlen` and automated refinement prompts.
* **Batch Capping**: Hard-capped at **20 posts per click session**. Repeatable until all eligible content is complete.
* **Security**: Nonce-protected, capability checks (`manage_options`), server-side only API key storage and masking.

## Running Tests

To run the offline unit test suite:
```bash
php scratch/test-addon.php
```
