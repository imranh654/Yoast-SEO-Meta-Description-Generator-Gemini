<?php
/**
 * Google Gemini API Client.
 *
 * Handles server-side communication with Google Gemini API, prompt generation,
 * strict 120–158 character length enforcement, and retry loops.
 *
 * @package Yoast_Gemini_Meta_Desc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Yoast_Gemini_Client
 */
class Yoast_Gemini_Client {

	/**
	 * Default Gemini model.
	 */
	const DEFAULT_MODEL = 'gemini-1.5-flash';

	/**
	 * Target minimum characters for meta description.
	 */
	const MIN_CHARS = 120;

	/**
	 * Target maximum characters for meta description.
	 */
	const MAX_CHARS = 158;

	/**
	 * Google Gemini API endpoint template.
	 */
	const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * HTTP timeout in seconds for Gemini API requests (accommodates LLM generation latency).
	 */
	const API_TIMEOUT = 60;

	/**
	 * Options key for plugin settings.
	 */
	const OPTION_KEY = 'yoast_gemini_meta_settings';

	/**
	 * Retrieve saved plugin options.
	 *
	 * @return array The options array.
	 */
	public static function get_options() {
		$defaults = [
			'api_key' => '',
			'model'   => self::DEFAULT_MODEL,
		];

		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Save plugin options.
	 *
	 * @param array $options The options to save.
	 * @return bool True on success.
	 */
	public static function update_options( array $options ) {
		$current = self::get_options();

		// Handle explicit clearing of the API key if requested.
		if ( ! empty( $options['clear_api_key'] ) ) {
			$current['api_key'] = '';
		} elseif ( isset( $options['api_key'] ) ) {
			$api_key = sanitize_text_field( trim( $options['api_key'] ) );
			// Only update if a non-empty key without bullet mask characters is provided.
			// If empty or masked, preserve the existing saved key.
			if ( '' !== $api_key && false === strpos( $api_key, '•' ) && false === strpos( $api_key, '&#8226;' ) ) {
				$current['api_key'] = $api_key;
			}
		}

		if ( ! empty( $options['model'] ) ) {
			$allowed_models   = array_keys( self::get_available_models() );
			$selected_model   = sanitize_text_field( $options['model'] );
			$current['model'] = in_array( $selected_model, $allowed_models, true ) ? $selected_model : self::DEFAULT_MODEL;
		}

		return update_option( self::OPTION_KEY, $current );
	}

	/**
	 * Available models map.
	 *
	 * @return array Array of model slug => label.
	 */
	public static function get_available_models() {
		return [
			'gemini-1.5-flash'      => 'Gemini 1.5 Flash (Recommended - Fast & Cost-effective)',
			'gemini-2.0-flash'      => 'Gemini 2.0 Flash (Next-gen Fast Model)',
			'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
			'gemini-3.8-flash'      => 'Gemini 3.8 Flash',
			'gemini-3.6-flash'      => 'Gemini 3.6 Flash',
			'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite',
			'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite',
			'gemini-1.5-pro'        => 'Gemini 1.5 Pro (High Reasoning)',
		];
	}

	/**
	 * Mask an API key for safe display in the admin UI.
	 *
	 * @param string $key The raw API key.
	 * @return string Masked key (e.g. AIzaSy••••••••••••3XyZ).
	 */
	public static function mask_api_key( $key ) {
		$key = trim( (string) $key );
		if ( empty( $key ) ) {
			return '';
		}

		$len = strlen( $key );
		if ( $len <= 8 ) {
			return '••••••••';
		}

		$start = substr( $key, 0, 6 );
		$end   = substr( $key, -4 );

		return $start . str_repeat( '•', max( 8, $len - 10 ) ) . $end;
	}

	/**
	 * Test the Google Gemini API connection with an API key.
	 *
	 * @param string|null $api_key Optional API key to test; defaults to saved key.
	 * @param string|null $model   Optional model to test; defaults to saved model.
	 * @return array Array with ['success' => bool, 'message' => string].
	 */
	public static function test_connection( $api_key = null, $model = null ) {
		$options = self::get_options();

		// If key argument is empty or contains bullet mask characters, use saved key.
		if ( null === $api_key || '' === trim( (string) $api_key ) || false !== strpos( (string) $api_key, '•' ) ) {
			$key = $options['api_key'];
		} else {
			$key = trim( (string) $api_key );
		}

		$mod = ( null !== $model && '' !== trim( (string) $model ) ) ? trim( (string) $model ) : $options['model'];

		if ( empty( $key ) ) {
			return [
				'success' => false,
				'message' => __( 'Please enter and save your Google Gemini API key first.', 'yoast-gemini-meta-desc' ),
			];
		}

		$endpoint = self::API_BASE_URL . rawurlencode( $mod ) . ':generateContent?key=' . rawurlencode( $key );

		$body = [
			'contents' => [
				[
					'role'  => 'user',
					'parts' => [
						[ 'text' => 'Respond with the single word "CONNECTED" if you can read this.' ],
					],
				],
			],
			'generationConfig' => [
				'temperature'     => 0.1,
				'maxOutputTokens' => 10,
			],
		];

		$response = wp_remote_post(
			$endpoint,
			[
				'headers'     => [ 'Content-Type' => 'application/json' ],
				'body'        => wp_json_encode( $body ),
				'timeout'     => self::API_TIMEOUT,
				'httpversion' => '1.1',
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					__( 'Network error connecting to Gemini API: %s', 'yoast-gemini-meta-desc' ),
					$response->get_error_message()
				),
			];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $raw_body, true );

		if ( 200 !== $status_code ) {
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown API error.', 'yoast-gemini-meta-desc' );
			return [
				'success' => false,
				'message' => sprintf(
					/* translators: 1: Status code, 2: Error message */
					__( 'Gemini API Error (HTTP %1$d): %2$s', 'yoast-gemini-meta-desc' ),
					$status_code,
					esc_html( $error_msg )
				),
			];
		}

		return [
			'success' => true,
			'message' => __( 'Success! Google Gemini API connection is active and valid.', 'yoast-gemini-meta-desc' ),
		];
	}

	/**
	 * Generate an SEO-optimized meta description for a specific post.
	 *
	 * Implements content extraction, prompt engineering, and a strict
	 * retry loop to enforce the 120–158 character target range.
	 *
	 * @param WP_Post $post The post object.
	 * @return array Array with ['success' => bool, 'description' => string, 'length' => int, 'error' => string].
	 */
	public static function generate_meta_description( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return [
				'success'     => false,
				'description' => '',
				'length'      => 0,
				'error'       => __( 'Invalid post object provided.', 'yoast-gemini-meta-desc' ),
			];
		}

		$options = self::get_options();
		$api_key = $options['api_key'];
		$model   = $options['model'];

		if ( empty( $api_key ) ) {
			return [
				'success'     => false,
				'description' => '',
				'length'      => 0,
				'error'       => __( 'Google Gemini API key is missing. Please configure it in settings.', 'yoast-gemini-meta-desc' ),
			];
		}

		// 1. Prepare post contextual data.
		$title     = Yoast_Gemini_Yoast_Bridge::get_seo_title( $post->ID );
		$focus_kw  = Yoast_Gemini_Yoast_Bridge::get_focus_keyword( $post->ID );
		$excerpt   = ! empty( $post->post_excerpt ) ? wp_strip_all_tags( $post->post_excerpt ) : '';
		$clean_raw = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );

		// Normalize whitespace.
		$clean_content = preg_replace( '/\s+/', ' ', $clean_raw );
		$clean_content = trim( (string) $clean_content );

		// If post has virtually no content, use title and excerpt or fail with clear message.
		if ( empty( $clean_content ) && empty( $excerpt ) ) {
			return [
				'success'     => false,
				'description' => '',
				'length'      => 0,
				'error'       => __( 'Post has no body content or excerpt to generate a description from.', 'yoast-gemini-meta-desc' ),
			];
		}

		// Truncate content context to ~800 words to conserve tokens, reduce API latency, and prevent timeouts while preserving rich context.
		$words = explode( ' ', $clean_content );
		if ( count( $words ) > 800 ) {
			$clean_content = implode( ' ', array_slice( $words, 0, 800 ) );
		}

		// 2. Build the primary prompt.
		$initial_prompt = self::build_initial_prompt( $title, $focus_kw, $excerpt, $clean_content );

		// 3. Make initial request to Gemini.
		$attempt_result = self::call_gemini_api( $initial_prompt, $api_key, $model );
		if ( ! $attempt_result['success'] ) {
			return $attempt_result;
		}

		$candidate = self::clean_response_text( $attempt_result['text'] );
		$char_len  = mb_strlen( $candidate, 'UTF-8' );

		// 4. Validate character length (120–158 characters).
		// If outside bounds, perform a targeted refinement retry loop.
		$max_retries = 2;
		$retry_count = 0;

		while ( ( $char_len < self::MIN_CHARS || $char_len > self::MAX_CHARS ) && $retry_count < $max_retries ) {
			$retry_count++;

			$refinement_prompt = self::build_refinement_prompt(
				$title,
				$candidate,
				$char_len,
				$focus_kw
			);

			$retry_api_result = self::call_gemini_api( $refinement_prompt, $api_key, $model );

			if ( $retry_api_result['success'] && ! empty( $retry_api_result['text'] ) ) {
				$candidate = self::clean_response_text( $retry_api_result['text'] );
				$char_len  = mb_strlen( $candidate, 'UTF-8' );
			}
		}

		// 5. Intelligent final boundary adjustment if slightly over maximum (159–168 chars).
		if ( $char_len > self::MAX_CHARS && $char_len <= 168 ) {
			$candidate = self::trim_to_word_boundary( $candidate, self::MAX_CHARS );
			$char_len  = mb_strlen( $candidate, 'UTF-8' );
		}

		// 6. Final validation check.
		if ( $char_len < self::MIN_CHARS || $char_len > self::MAX_CHARS ) {
			return [
				'success'     => false,
				'description' => $candidate,
				'length'      => $char_len,
				'error'       => sprintf(
					/* translators: 1: Actual length, 2: Min length, 3: Max length */
					__( 'Generated text length (%1$d characters) did not meet the required target (%2$d–%3$d characters).', 'yoast-gemini-meta-desc' ),
					$char_len,
					self::MIN_CHARS,
					self::MAX_CHARS
				),
			];
		}

		return [
			'success'     => true,
			'description' => $candidate,
			'length'      => $char_len,
			'error'       => '',
		];
	}

	/**
	 * Build initial Gemini prompt.
	 *
	 * @param string $title     Page title.
	 * @param string $focus_kw  Focus keyword.
	 * @param string $excerpt   Excerpt.
	 * @param string $content   Body content.
	 * @return string The formatted prompt.
	 */
	protected static function build_initial_prompt( $title, $focus_kw, $excerpt, $content ) {
		$prompt  = "You are an expert SEO copywriter. Write a compelling, high-converting meta description for the webpage below.\n\n";
		$prompt .= "STRICT REQUIREMENT: The meta description MUST be strictly between 120 and 158 characters long, including all spaces and punctuation.\n\n";
		$prompt .= "Guidelines:\n";
		$prompt .= "1. Length: Exactly between 120 and 158 characters total.\n";
		$prompt .= "2. Accurately reflect the page's actual topic and main value proposition. Do not invent facts.\n";
		$prompt .= "3. Write naturally in the exact same language as the post content.\n";
		if ( ! empty( $focus_kw ) ) {
			$prompt .= "4. Include the primary focus keyphrase '{$focus_kw}' naturally if possible.\n";
		}
		$prompt .= "5. Include a clear, persuasive call-to-action or benefit to encourage clicks in Google search results.\n";
		$prompt .= "6. Do NOT use quotation marks around the output.\n";
		$prompt .= "7. Do NOT include prefixes like 'Meta description:', 'Summary:', or 'In this article'.\n";
		$prompt .= "8. Return ONLY the final raw meta description text and nothing else.\n\n";

		$prompt .= "--- PAGE DETAILS ---\n";
		$prompt .= "Page Title: " . $title . "\n";
		if ( ! empty( $focus_kw ) ) {
			$prompt .= "Focus Keyword: " . $focus_kw . "\n";
		}
		if ( ! empty( $excerpt ) ) {
			$prompt .= "Excerpt: " . $excerpt . "\n";
		}
		$prompt .= "Main Content:\n" . $content . "\n";
		$prompt .= "--- END DETAILS ---\n\n";
		$prompt .= "Now, write the meta description (strictly 120–158 characters):";

		return $prompt;
	}

	/**
	 * Build refinement prompt when length fails the target.
	 *
	 * @param string $title     Page title.
	 * @param string $candidate Current candidate text.
	 * @param int    $char_len  Current candidate character length.
	 * @param string $focus_kw  Focus keyword.
	 * @return string The refinement prompt.
	 */
	protected static function build_refinement_prompt( $title, $candidate, $char_len, $focus_kw ) {
		$issue = $char_len < self::MIN_CHARS
			? "TOO SHORT ({$char_len} characters, minimum is 120)"
			: "TOO LONG ({$char_len} characters, maximum is 158)";

		$prompt  = "Rewrite the meta description below for the page '{$title}'.\n\n";
		$prompt .= "PROBLEM WITH PREVIOUS ATTEMPT: It was {$issue}.\n";
		$prompt .= "Previous text: \"{$candidate}\"\n\n";
		$prompt .= "STRICT INSTRUCTION: Rewrite it so the character count is strictly between 120 and 158 characters long, including all letters, spaces, and punctuation.\n";
		$prompt .= "- Count every character accurately.\n";
		$prompt .= "- Keep it compelling, natural, and accurate to the page.\n";
		if ( ! empty( $focus_kw ) ) {
			$prompt .= "- Include '{$focus_kw}' naturally.\n";
		}
		$prompt .= "- Do NOT use quotation marks or introductory labels.\n";
		$prompt .= "- Output ONLY the rewritten description:";

		return $prompt;
	}

	/**
	 * Send HTTP POST request to Google Gemini API.
	 *
	 * @param string $prompt  The user prompt.
	 * @param string $api_key The Gemini API key.
	 * @param string $model   The model name.
	 * @return array Array with ['success' => bool, 'text' => string, 'error' => string].
	 */
	protected static function call_gemini_api( $prompt, $api_key, $model ) {
		$endpoint = self::API_BASE_URL . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );

		$body = [
			'contents' => [
				[
					'role'  => 'user',
					'parts' => [
						[ 'text' => $prompt ],
					],
				],
			],
			'generationConfig' => [
				'temperature'     => 0.4,
				'maxOutputTokens' => 200,
			],
		];

		$encoded_body = wp_json_encode( $body );
		$max_attempts = 2;
		$last_error   = '';

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			// Extend PHP execution time limit for this generation cycle.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 );
			}

			$response = wp_remote_post(
				$endpoint,
				[
					'headers'     => [ 'Content-Type' => 'application/json' ],
					'body'        => $encoded_body,
					'timeout'     => self::API_TIMEOUT,
					'httpversion' => '1.1',
				]
			);

			if ( is_wp_error( $response ) ) {
				$err_msg    = $response->get_error_message();
				$last_error = $err_msg;

				// If timeout or connection error, wait briefly and retry if attempts remain.
				$is_timeout = ( false !== strpos( $err_msg, 'timed out' ) || false !== strpos( $err_msg, 'cURL error 28' ) );
				if ( $is_timeout && $attempt < $max_attempts ) {
					sleep( 2 );
					continue;
				}

				return [
					'success' => false,
					'text'    => '',
					'error'   => sprintf(
						/* translators: %s: Error message */
						__( 'HTTP request failed: %s', 'yoast-gemini-meta-desc' ),
						$err_msg
					),
				];
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );
			$data        = json_decode( $raw_body, true );

			// If rate limited (429) or temporary server unavailable (503), retry once after pause.
			if ( in_array( $status_code, [ 429, 503 ], true ) && $attempt < $max_attempts ) {
				sleep( 2 );
				continue;
			}

			if ( 200 !== $status_code ) {
				$message = isset( $data['error']['message'] )
					? $data['error']['message']
					: sprintf( __( 'Gemini API returned status code %d', 'yoast-gemini-meta-desc' ), $status_code );

				return [
					'success' => false,
					'text'    => '',
					'error'   => $message,
				];
			}

			if ( empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				return [
					'success' => false,
					'text'    => '',
					'error'   => __( 'Gemini returned an empty response candidate.', 'yoast-gemini-meta-desc' ),
				];
			}

			return [
				'success' => true,
				'text'    => (string) $data['candidates'][0]['content']['parts'][0]['text'],
				'error'   => '',
			];
		}

		return [
			'success' => false,
			'text'    => '',
			'error'   => ! empty( $last_error ) ? $last_error : __( 'Request timed out after multiple attempts.', 'yoast-gemini-meta-desc' ),
		];
	}

	/**
	 * Clean raw AI text by removing quotes, introductory labels, and markdown artifacts.
	 *
	 * @param string $text Raw AI output.
	 * @return string Cleaned text.
	 */
	public static function clean_response_text( $text ) {
		$text = trim( (string) $text );

		// Strip surrounding markdown code fences.
		$text = preg_replace( '/^```[a-z]*\s*/iu', '', $text );
		$text = preg_replace( '/\s*```$/u', '', $text );

		// Iteratively strip surrounding quotation marks and AI introductory prefixes.
		for ( $i = 0; $i < 3; $i++ ) {
			// Strip surrounding quotation marks (standard, typographic, guillemets, backticks).
			$text = preg_replace( '/^["\'“‘«`\s]+|["\'”’»`\s]+$/u', '', $text );

			// Strip common AI prefixes (case-insensitive).
			$text = preg_replace( '/^(meta\s*description|seo\s*description|description|here\s*(is|are)\s*(the\s*)?(suggested\s*)?(meta\s*description)?|suggested\s*meta\s*description)\s*:\s*/iu', '', $text );
		}

		// Final quote cleanup.
		$text = preg_replace( '/^["\'“‘«`\s]+|["\'”’»`\s]+$/u', '', $text );

		// Normalize multiple spaces and line breaks to single space.
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Intelligently trim text to the nearest word boundary before max_chars and append punctuation.
	 *
	 * @param string $text      The text to trim.
	 * @param int    $max_chars Maximum allowed characters.
	 * @return string Trimmed text.
	 */
	protected static function trim_to_word_boundary( $text, $max_chars = 158 ) {
		if ( mb_strlen( $text, 'UTF-8' ) <= $max_chars ) {
			return $text;
		}

		// Cut safely at max_chars - 1 to leave room for terminal punctuation if needed.
		$cut = mb_substr( $text, 0, $max_chars, 'UTF-8' );

		// Find last space in the cut substring.
		$last_space = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( false !== $last_space && $last_space > ( $max_chars - 30 ) ) {
			$cut = mb_substr( $cut, 0, $last_space, 'UTF-8' );
		}

		$cut = rtrim( $cut, " \t\n\r\0\x0B,;:-" );

		// Ensure it ends with punctuation (. ! ?)
		if ( ! in_array( mb_substr( $cut, -1, 1, 'UTF-8' ), [ '.', '!', '?' ], true ) ) {
			if ( mb_strlen( $cut, 'UTF-8' ) < $max_chars ) {
				$cut .= '.';
			}
		}

		return $cut;
	}
}
