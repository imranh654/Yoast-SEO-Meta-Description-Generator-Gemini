/**
 * Yoast SEO Meta Description Generator (Gemini)
 * Admin Dashboard JavaScript Controller
 */

(function ($) {
	'use strict';

	const app = {
		isProcessing: false,

		init: function () {
			this.bindEvents();
			this.loadInitialStats();
		},

		bindEvents: function () {
			// Toggle API key visibility.
			$('#toggle-key-visibility').on('click', this.toggleKeyVisibility);

			// Settings form submission.
			$('#yoast-gemini-settings-form').on('submit', this.handleSaveSettings);

			// Test API connection.
			$('#btn-test-api').on('click', this.handleTestApi);

			// Post type selection change.
			$('#select-post-type').on('change', this.handlePostTypeChange);

			// Start batch generation.
			$('#btn-start-batch').on('click', this.handleStartBatch);
		},

		toggleKeyVisibility: function (e) {
			e.preventDefault();
			const $input = $('#gemini-api-key');
			const $btn = $(this);
			const $icon = $btn.find('.dashicons');

			if ($input.attr('type') === 'password') {
				$input.attr('type', 'text');
				$icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
			} else {
				$input.attr('type', 'password');
				$icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
			}
		},

		handleSaveSettings: function (e) {
			e.preventDefault();
			const $form = $(this);
			const $spinner = $('#settings-spinner');
			const $feedback = $('#settings-feedback');
			const $btn = $('#btn-save-settings');

			const apiKey = $('#gemini-api-key').val().trim();
			const model = $('#gemini-model').val();

			$spinner.addClass('is-active');
			$btn.prop('disabled', true);
			$feedback.hide().removeClass('feedback-success feedback-error');

			$.ajax({
				url: yoastGeminiData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'yoast_gemini_save_settings',
					nonce: yoastGeminiData.nonce,
					api_key: apiKey,
					model: model
				},
				success: function (res) {
					$spinner.removeClass('is-active');
					$btn.prop('disabled', false);

					if (res.success) {
						$feedback.addClass('feedback-success')
							.text(res.data.message)
							.fadeIn();

						if (res.data.masked_key) {
							yoastGeminiData.hasApiKey = true;
							$('#gemini-api-key').val('').attr('placeholder', res.data.masked_key);
							$('.key-saved-notice').remove();
							$('.description').append(
								'<span class="key-saved-notice"><span class="dashicons dashicons-yes-alt"></span> Key configured: ' +
								res.data.masked_key + '</span>'
							);
						}
					} else {
						$feedback.addClass('feedback-error')
							.text(res.data.message || 'Failed to save settings.')
							.fadeIn();
					}
				},
				error: function (xhr) {
					$spinner.removeClass('is-active');
					$btn.prop('disabled', false);
					$feedback.addClass('feedback-error')
						.text('Server communication error. Please try again.')
						.fadeIn();
				}
			});
		},

		handleTestApi: function (e) {
			e.preventDefault();
			const $btn = $(this);
			const $spinner = $('#settings-spinner');
			const $feedback = $('#settings-feedback');
			const apiKey = $('#gemini-api-key').val().trim();
			const model = $('#gemini-model').val();

			$spinner.addClass('is-active');
			$btn.prop('disabled', true);
			$feedback.hide().removeClass('feedback-success feedback-error');

			$.ajax({
				url: yoastGeminiData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'yoast_gemini_test_api',
					nonce: yoastGeminiData.nonce,
					api_key: apiKey,
					model: model
				},
				success: function (res) {
					$spinner.removeClass('is-active');
					$btn.prop('disabled', false);

					if (res.success) {
						$feedback.addClass('feedback-success')
							.text(res.data.message)
							.fadeIn();
					} else {
						$feedback.addClass('feedback-error')
							.text(res.data.message || 'Connection test failed.')
							.fadeIn();
					}
				},
				error: function () {
					$spinner.removeClass('is-active');
					$btn.prop('disabled', false);
					$feedback.addClass('feedback-error')
						.text('Connection test request failed.')
						.fadeIn();
				}
			});
		},

		handlePostTypeChange: function () {
			if (app.isProcessing) {
				return;
			}
			app.loadStats($('#select-post-type').val());
			$('#all-completed-banner').hide();
			$('#batch-progress-section').hide();
			$('#batch-results-tbody').html(
				'<tr class="empty-row"><td colspan="5" class="text-center text-muted">Click "Generate Meta Descriptions" above to begin processing eligible posts.</td></tr>'
			);
		},

		loadInitialStats: function () {
			const postType = $('#select-post-type').val() || 'post';
			this.loadStats(postType);
		},

		loadStats: function (postType) {
			const $spinner = $('#stats-spinner');
			$spinner.addClass('is-active');

			$.ajax({
				url: yoastGeminiData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'yoast_gemini_get_stats',
					nonce: yoastGeminiData.nonce,
					post_type: postType
				},
				success: function (res) {
					$spinner.removeClass('is-active');
					if (res.success && res.data) {
						$('#stat-total-count').text(res.data.total);
						$('#stat-completed-count').text(res.data.completed);
						$('#stat-eligible-count').text(res.data.eligible);
						$('#stat-remaining-count').text(res.data.remaining);

						if (res.data.remaining === 0 && res.data.total > 0) {
							$('#all-completed-banner').fadeIn();
							$('#btn-start-batch').prop('disabled', true);
						} else {
							$('#all-completed-banner').hide();
							if (!app.isProcessing) {
								$('#btn-start-batch').prop('disabled', false);
							}
						}
					}
				},
				error: function () {
					$spinner.removeClass('is-active');
				}
			});
		},

		handleStartBatch: function (e) {
			e.preventDefault();

			if (app.isProcessing) {
				return;
			}

			if (!yoastGeminiData.hasApiKey && !$('#gemini-api-key').val().trim()) {
				alert(yoastGeminiData.strings.keyMissingError);
				$('#gemini-api-key').focus();
				return;
			}

			const postType = $('#select-post-type').val();
			const $btn = $('#btn-start-batch');
			const $progressBox = $('#batch-progress-section');
			const $statusMsg = $('#batch-status-message');
			const $progressFill = $('#batch-progress-fill');
			const $tbody = $('#batch-results-tbody');

			// Clear empty placeholder row.
			$tbody.find('.empty-row').remove();

			app.isProcessing = true;
			$btn.prop('disabled', true);
			$('#select-post-type').prop('disabled', true);
			$progressBox.fadeIn();
			$statusMsg.text(yoastGeminiData.strings.batchStarting);
			$progressFill.css('width', '0%');
			$('#batch-active-spinner').addClass('is-active');

			// Reset counters.
			$('#batch-current-count').text('0');
			$('#batch-total-count').text('0');
			$('#batch-success-count').text('0');
			$('#batch-failed-count').text('0');
			$('#batch-skipped-count').text('0');

			// Fetch eligible queue (hard capped at 20 posts).
			$.ajax({
				url: yoastGeminiData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'yoast_gemini_get_batch_queue',
					nonce: yoastGeminiData.nonce,
					post_type: postType
				},
				success: function (res) {
					if (!res.success) {
						alert(res.data.message || 'Error fetching eligible posts.');
						app.finishBatch(postType);
						return;
					}

					const queue = res.data.queue || [];
					const totalInBatch = queue.length;

					if (totalInBatch === 0) {
						$statusMsg.text(yoastGeminiData.strings.noEligible);
						$('#all-completed-banner').fadeIn();
						app.finishBatch(postType);
						return;
					}

					$('#batch-total-count').text(totalInBatch);
					app.processQueueSequentially(queue, postType);
				},
				error: function () {
					alert('Server error requesting batch queue.');
					app.finishBatch(postType);
				}
			});
		},

		processQueueSequentially: async function (queue, postType) {
			const total = queue.length;
			let successCount = 0;
			let failedCount = 0;
			let skippedCount = 0;

			for (let i = 0; i < total; i++) {
				const item = queue[i];
				const currentNumber = i + 1;

				$('#batch-current-count').text(currentNumber);
				$('#batch-status-message').text(
					yoastGeminiData.strings.processingItem
						.replace('%1$d', currentNumber)
						.replace('%2$d', total)
						.replace('%3$s', item.title)
				);

				try {
					const result = await app.processSinglePost(item.id, postType);

					if (result.success && !result.skipped) {
						successCount++;
						$('#batch-success-count').text(successCount);
						app.appendResultRow({
							id: item.id,
							title: item.title,
							description: result.description,
							length: result.length,
							status: 'success'
						});
					} else if (result.success && result.skipped) {
						skippedCount++;
						$('#batch-skipped-count').text(skippedCount);
						app.appendResultRow({
							id: item.id,
							title: item.title,
							description: result.description || 'Skipped (Already exists)',
							length: result.length || 0,
							status: 'skipped'
						});
					} else {
						failedCount++;
						$('#batch-failed-count').text(failedCount);
						app.appendResultRow({
							id: item.id,
							title: item.title,
							description: '<span class="error-text">' + (result.message || 'Generation error') + '</span>',
							length: 0,
							status: 'failed'
						});
					}
				} catch (err) {
					failedCount++;
					$('#batch-failed-count').text(failedCount);
					app.appendResultRow({
						id: item.id,
						title: item.title,
						description: '<span class="error-text">Request failed: ' + (err.message || 'Unknown error') + '</span>',
						length: 0,
						status: 'failed'
					});
				}

				// Update progress fill bar.
				const percent = Math.round((currentNumber / total) * 100);
				$('#batch-progress-fill').css('width', percent + '%');
			}

			// Batch completed.
			$('#batch-active-spinner').removeClass('is-active');
			$('#batch-status-message').text(
				yoastGeminiData.strings.batchComplete
					.replace('%1$d', successCount)
					.replace('%2$d', total)
			);

			app.finishBatch(postType);
		},

		processSinglePost: function (postId, postType) {
			return new Promise(function (resolve) {
				$.ajax({
					url: yoastGeminiData.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'yoast_gemini_process_single_post',
						nonce: yoastGeminiData.nonce,
						post_id: postId,
						post_type: postType
					},
					success: function (res) {
						if (res && res.success) {
							resolve(res.data);
						} else {
							resolve(res && res.data ? res.data : { success: false, message: 'Unknown API error' });
						}
					},
					error: function (xhr, status, error) {
						resolve({ success: false, message: 'AJAX HTTP ' + status + ': ' + error });
					}
				});
			});
		},

		appendResultRow: function (item) {
			const $tbody = $('#batch-results-tbody');
			let lengthBadge = '-';
			let statusBadge = '';

			if (item.length > 0) {
				const isStrictRange = item.length >= 120 && item.length <= 158;
				const badgeClass = isStrictRange ? 'char-badge-valid' : 'char-badge-invalid';
				lengthBadge = '<span class="char-badge ' + badgeClass + '">' + item.length + ' ' + yoastGeminiData.strings.chars + '</span>';
			}

			if (item.status === 'success') {
				statusBadge = '<span class="status-badge status-badge-success"><span class="dashicons dashicons-yes-alt"></span> ' + yoastGeminiData.strings.success + '</span>';
			} else if (item.status === 'skipped') {
				statusBadge = '<span class="status-badge status-badge-skipped"><span class="dashicons dashicons-controls-skipforward"></span> ' + yoastGeminiData.strings.skipped + '</span>';
			} else {
				statusBadge = '<span class="status-badge status-badge-failed"><span class="dashicons dashicons-warning"></span> ' + yoastGeminiData.strings.failed + '</span>';
			}

			const editUrl = 'post.php?post=' + item.id + '&action=edit';

			const rowHtml = '<tr>' +
				'<td><strong>#' + item.id + '</strong></td>' +
				'<td><a href="' + editUrl + '" target="_blank" rel="noopener noreferrer">' + item.title + ' <span class="dashicons dashicons-external" style="font-size:14px;width:14px;height:14px;"></span></a></td>' +
				'<td class="desc-text">' + item.description + '</td>' +
				'<td class="text-center">' + lengthBadge + '</td>' +
				'<td class="text-center">' + statusBadge + '</td>' +
				'</tr>';

			$tbody.prepend(rowHtml);
		},

		finishBatch: function (postType) {
			app.isProcessing = false;
			$('#select-post-type').prop('disabled', false);
			$('#batch-active-spinner').removeClass('is-active');
			app.loadStats(postType);
		}
	};

	$(document).ready(function () {
		app.init();
	});

})(jQuery);
