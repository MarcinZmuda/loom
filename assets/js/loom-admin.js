/**
 * LOOM Admin JavaScript  -  by Marcin Żmuda (marcinzmuda.com)
 * Handles: scanning, podlinkuj, suggestions UI, applying links, settings.
 */
(function($) {
	'use strict';

	/* =======================================================================
	   MOVE WP NOTICES ABOVE LOOM
	   WordPress common.js moves notices into .wrap after first heading.
	   We run after it with setTimeout to ensure notices are already placed.
	   ======================================================================= */
	$(function() {
		setTimeout(function() {
			var $wrap = $('.loom-wrap');
			if ($wrap.length) {
				$wrap.children('.notice, .updated, .error, .update-nag, [class*="notice"]').each(function() {
					$(this).insertBefore($wrap);
				});
			}
		}, 100);
	});

	/* =======================================================================
	   BATCH SCAN
	   ======================================================================= */
	$(document).on('click', '#loom-start-scan', function() {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#loom-scan-progress').show();
		loomBatchScan(0);
	});

	function loomBatchScan(offset) {
		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'loom_batch_scan',
				nonce: loom_ajax.nonce,
				offset: offset
			},
			success: function(res) {
				if (res.success) {
					var d = res.data;
					var pct = Math.round((d.processed / d.total) * 100);
					$('#loom-progress-fill').css('width', pct + '%');
					$('#loom-progress-text').text(
						loom_ajax.i18n.scanning + ' ' + d.processed + '/' + d.total
					);

					if (d.status === 'next') {
						loomBatchScan(d.offset);
					} else {
						$('#loom-progress-text').text('✅ ' + loom_ajax.i18n.done);
						setTimeout(function() { location.reload(); }, 1500);
					}
				} else {
					$('#loom-progress-text').text('❌ ' + (res.data || loom_ajax.i18n.error));
				}
			},
			error: function() {
				$('#loom-progress-text').text('❌ ' + loom_ajax.i18n.error);
			}
		});
	}

	/* =======================================================================
	   PODLINKUJ  -  Trigger (Metabox + Dashboard/Bulk buttons)
	   ======================================================================= */
	$(document).on('click', '#loom-metabox-podlinkuj, .loom-podlinkuj-btn', function() {
		var $btn = $(this);
		var postId = $btn.data('post-id') || $('.loom-metabox-inner').data('post-id');

		if (!postId) return;

		$btn.prop('disabled', true);

		// Determine results container.
		var $results;
		if ($btn.attr('id') === 'loom-metabox-podlinkuj') {
			$results = $('#loom-metabox-results');
			$('#loom-metabox-spinner').addClass('is-active');
		} else {
			// Dashboard/Bulk  -  show inline or panel.
			var $panel = $('#loom-panel-' + postId);
			if ($panel.length) {
				$panel.show();
				$results = $panel.find('.loom-inline-results');
			} else {
				$results = $('#loom-suggestions-panel');
			}
		}

		$results.html('<p>' + loom_ajax.i18n.analyzing + '</p>').show();

		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'loom_podlinkuj',
				nonce: loom_ajax.nonce,
				post_id: postId
			},
			success: function(res) {
				$('#loom-metabox-spinner').removeClass('is-active');
				$btn.prop('disabled', false);

				if (res.success) {
					renderSuggestions($results, res.data);
				} else {
					$results.html('<p class="loom-status-bad">❌ ' + (res.data || loom_ajax.i18n.error) + '</p>');
				}
			},
			error: function() {
				$('#loom-metabox-spinner').removeClass('is-active');
				$btn.prop('disabled', false);
				$results.html('<p class="loom-status-bad">❌ ' + loom_ajax.i18n.error + '</p>');
			}
		});
	});

	/* =======================================================================
	   RENDER SUGGESTIONS UI
	   ======================================================================= */
	function renderSuggestions($container, data) {
		var suggestions = data.suggestions || [];
		var postId = data.post_id;
		var contentHash = data.content_hash;

		if (suggestions.length === 0) {
			$container.html('<p>Brak sugestii dla tego posta.</p>');
			return;
		}

		var html = '<h3 style="color:#008080; margin-top:0;">Sugestie linków (' + suggestions.length + ')</h3>';

		suggestions.forEach(function(s, idx) {
			var priorityClass = 'loom-priority-' + s.priority;
			var priorityLabel = s.priority === 'high' ? 'Wysoki' : (s.priority === 'medium' ? 'Średni' : 'Niski');
			var checked = (s.priority === 'high' || s.priority === 'medium') ? 'checked' : '';

			html += '<div class="loom-suggestion-card ' + (checked ? 'checked' : '') + '" data-idx="' + idx + '">';
			html += '  <div class="loom-suggestion-header">';
			html += '    <input type="checkbox" class="loom-sugg-check" data-idx="' + idx + '" ' + checked + '>';
			html += '    <span class="loom-suggestion-priority ' + priorityClass + '">' + priorityLabel + '</span>';
			html += '    <span>Akapit ' + s.paragraph_number + '</span>';
			html += '  </div>';
			html += '  <div>Anchor: <span class="loom-suggestion-anchor">"' + escHtml(s.anchor_text) + '"</span></div>';
			html += '  <div class="loom-suggestion-target">-> ' + escHtml(s.target_title) + ' <small>(' + escHtml(s.target_url) + ')</small></div>';
			html += '  <div class="loom-suggestion-meta">';
			html += '    <span>' + (s.surfer_label || '') + '</span>';
			if (s.cannibalization) {
				html += '    <span class="loom-cannibal-warn">⚠️ Anchor użyty do: ' + escHtml(s.cannibalization.post_title || '') + '</span>';
			}
			html += '  </div>';
			html += '  <div class="loom-suggestion-reason">' + escHtml(s.reason) + '</div>';
			html += '  <button type="button" class="loom-reject-btn" data-post-id="' + postId + '" data-target-url="' + escHtml(s.target_url) + '" data-anchor="' + escHtml(s.anchor_text) + '" title="Odrzuć i zapamiętaj">🚫</button>';
			html += '</div>';
		});

		html += '<div class="loom-apply-bar">';
		html += '  <button type="button" class="button button-primary" id="loom-apply-links" data-post-id="' + postId + '" data-hash="' + contentHash + '">✅ Zastosuj zaznaczone linki</button>';
		html += '  <button type="button" class="button" id="loom-cancel-links">Anuluj</button>';
		html += '  <span id="loom-apply-status" class="loom-status-text"></span>';
		html += '</div>';

		$container.html(html);

		// Store suggestions data.
		$container.data('suggestions', suggestions);

		// Checkbox toggle visual.
		$container.on('change', '.loom-sugg-check', function() {
			$(this).closest('.loom-suggestion-card').toggleClass('checked', this.checked);
		});
	}

	/* =======================================================================
	   APPLY LINKS
	   ======================================================================= */
	$(document).on('click', '#loom-apply-links', function() {
		var $btn = $(this);
		var postId = $btn.data('post-id');
		var hash = $btn.data('hash');
		var $container = $btn.closest('.loom-apply-bar').parent();
		var allSuggestions = $container.data('suggestions');

		if (!confirm(loom_ajax.i18n.confirm)) return;

		// Collect checked suggestions.
		var selected = [];
		$container.find('.loom-sugg-check:checked').each(function() {
			var idx = $(this).data('idx');
			selected.push(allSuggestions[idx]);
		});

		if (selected.length === 0) {
			$('#loom-apply-status').text('Zaznacz co najmniej 1 sugestię.');
			return;
		}

		$btn.prop('disabled', true);
		$('#loom-apply-status').text(loom_ajax.i18n.applying);

		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'loom_apply_links',
				nonce: loom_ajax.nonce,
				post_id: postId,
				content_hash: hash,
				links: JSON.stringify(selected)
			},
			success: function(res) {
				$btn.prop('disabled', false);
				if (res.success) {
					$('#loom-apply-status').text('✅ ' + res.data.message);
					setTimeout(function() { location.reload(); }, 2000);
				} else {
					$('#loom-apply-status').text('❌ ' + (res.data || loom_ajax.i18n.error));
				}
			},
			error: function() {
				$btn.prop('disabled', false);
				$('#loom-apply-status').text('❌ ' + loom_ajax.i18n.error);
			}
		});
	});

	$(document).on('click', '#loom-cancel-links', function() {
		$(this).closest('.loom-apply-bar').parent().hide();
	});

	/* =======================================================================
	   AUTO-PODLINKUJ  -  Single post (⚡ Auto button)
	   ======================================================================= */
	$(document).on('click', '.loom-auto-btn, #loom-metabox-auto', function() {
		var $btn = $(this);
		var postId = $btn.data('post-id') || $('.loom-metabox-inner').data('post-id');
		if (!postId) return;

		$btn.prop('disabled', true).text('⏳');

		// Find or create status area.
		var $row = $btn.closest('tr');
		var $statusEl;
		if ($btn.attr('id') === 'loom-metabox-auto') {
			$('#loom-metabox-spinner').addClass('is-active');
			$statusEl = $('#loom-metabox-results').show();
		} else {
			var $panel = $('#loom-panel-' + postId);
			if ($panel.length) {
				$panel.show();
				$statusEl = $panel.find('.loom-inline-results');
			} else {
				$statusEl = $('<span class="loom-auto-inline-status"></span>');
				$btn.after($statusEl);
			}
		}

		$statusEl.html('<p>⏳ ' + loom_ajax.i18n.analyzing + '</p>');

		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'loom_auto_podlinkuj',
				nonce: loom_ajax.nonce,
				post_id: postId
			},
			success: function(res) {
				$('#loom-metabox-spinner').removeClass('is-active');
				$btn.prop('disabled', false).text('⚡ Auto');

				if (res.success) {
					var d = res.data;
					var html = '<p class="loom-auto-result">';
					if (d.inserted > 0) {
						html += '✅ <strong>' + escHtml(d.message) + '</strong>';
						if (d.links && d.links.length) {
							html += '<ul class="loom-auto-link-list">';
							d.links.forEach(function(l) {
								html += '<li>-> <em>"' + escHtml(l.anchor) + '"</em> -> ' + escHtml(l.target) + ' <small>' + escHtml(l.zone) + '</small></li>';
							});
							html += '</ul>';
						}
					} else {
						html += '⚪ ' + escHtml(d.message);
					}
					html += '</p>';
					$statusEl.html(html);

					// Update row visually.
					if ($row.length) {
						$row.css('background', d.inserted > 0 ? '#e6f5e6' : '');
					}
				} else {
					$statusEl.html('<p class="loom-status-bad">❌ ' + (res.data || loom_ajax.i18n.error) + '</p>');
				}
			},
			error: function() {
				$('#loom-metabox-spinner').removeClass('is-active');
				$btn.prop('disabled', false).text('⚡ Auto');
				$statusEl.html('<p class="loom-status-bad">❌ ' + loom_ajax.i18n.error + '</p>');
			}
		});
	});

	/* =======================================================================
	   AUTO-PODLINKUJ  -  Batch (selected checkboxes / all orphans)
	   ======================================================================= */

	// Check-all checkbox.
	$(document).on('change', '#loom-check-all', function() {
		$('.loom-row-check:not(:disabled)').prop('checked', this.checked);
	});

	// Auto-podlinkuj zaznaczone.
	$(document).on('click', '#loom-auto-selected', function() {
		var ids = [];
		$('.loom-row-check:checked').each(function() {
			ids.push($(this).val());
		});
		if (ids.length === 0) {
			$('#loom-batch-auto-status').text('Zaznacz co najmniej 1 post.');
			return;
		}
		startBatchAuto(ids, 'zaznaczonych');
	});

	// Auto-podlinkuj wszystkie orphany.
	$(document).on('click', '#loom-auto-all-orphans', function() {
		var ids = [];
		$('.loom-table tbody tr[data-orphan="1"]').each(function() {
			var pid = $(this).data('post-id');
			if (pid) ids.push(String(pid));
		});
		if (ids.length === 0) {
			$('#loom-batch-auto-status').text('Brak orphanów do przetworzenia.');
			return;
		}
		if (!confirm('Auto-podlinkuj ' + ids.length + ' orphanów? Linki high+medium zostaną wstawione automatycznie.')) {
			return;
		}
		startBatchAuto(ids, 'orphanów');
	});

	function startBatchAuto(postIds, label) {
		$('#loom-auto-selected, #loom-auto-all-orphans').prop('disabled', true);
		$('#loom-batch-auto-progress').show();
		$('#loom-batch-auto-log').html('');

		var total = postIds.length;
		var current = 0;
		var totalInserted = 0;

		function processNext() {
			if (current >= total) {
				$('#loom-batch-auto-fill').css('width', '100%');
				$('#loom-batch-auto-text').html(
					'✅ Gotowe! Przetworzono ' + total + ' ' + label + ', wstawiono <strong>' + totalInserted + '</strong> linków.'
				);
				$('#loom-auto-selected, #loom-auto-all-orphans').prop('disabled', false);
				setTimeout(function() { location.reload(); }, 3000);
				return;
			}

			var pid = postIds[current];
			var pct = Math.round(((current) / total) * 100);
			$('#loom-batch-auto-fill').css('width', pct + '%');
			$('#loom-batch-auto-text').text(
				'Przetwarzanie ' + (current + 1) + '/' + total + '...'
			);

			$.ajax({
				url: loom_ajax.ajaxurl,
				type: 'POST',
				data: {
					action: 'loom_auto_podlinkuj',
					nonce: loom_ajax.nonce,
					post_id: pid
				},
				success: function(res) {
					current++;
					if (res.success) {
						var d = res.data;
						totalInserted += d.inserted;
						var logLine = '<div class="loom-auto-log-entry">';
						if (d.inserted > 0) {
							logLine += '✅ <strong>' + escHtml(d.post_title) + '</strong>  -  ' + d.inserted + ' linków';
						} else {
							logLine += '⚪ ' + escHtml(d.post_title) + '  -  ' + escHtml(d.message);
						}
						logLine += '</div>';
						$('#loom-batch-auto-log').prepend(logLine);

						// Mark row.
						$('tr[data-post-id="' + pid + '"]').css('background', d.inserted > 0 ? '#e6f5e6' : '#fef5e7');
					} else {
						$('#loom-batch-auto-log').prepend(
							'<div class="loom-auto-log-entry">❌ Post #' + pid + ': ' + (res.data || 'error') + '</div>'
						);
					}
					processNext();
				},
				error: function() {
					current++;
					$('#loom-batch-auto-log').prepend(
						'<div class="loom-auto-log-entry">❌ Post #' + pid + ': błąd połączenia</div>'
					);
					processNext();
				}
			});
		}

		processNext();
	}

	/* =======================================================================
	   GRAPH: Recalculate + Structural Suggestions
	   ======================================================================= */
	$(document).on('click', '#loom-recalc-graph', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).text('⏳');
		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: { action: 'loom_recalc_graph', nonce: loom_ajax.nonce },
			success: function(res) {
				$btn.prop('disabled', false).text('🔄 Przelicz');
				if (res.success) {
					location.reload();
				}
			},
			error: function() { $btn.prop('disabled', false).text('🔄 Przelicz'); }
		});
	});

	$(document).on('click', '#loom-load-structural', function() {
		var $btn = $(this);
		var $panel = $('#loom-structural-suggestions');
		$btn.prop('disabled', true).text('⏳');

		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: { action: 'loom_structural_suggestions', nonce: loom_ajax.nonce },
			success: function(res) {
				$btn.hide();
				if (!res.success || !res.data.suggestions.length) {
					$panel.html('<p class="loom-muted">Brak sugestii strukturalnych  -  graf wygląda zdrowo.</p>');
					return;
				}
				var html = '<div class="loom-structural-list">';
				res.data.suggestions.forEach(function(s) {
					html += '<div class="loom-struct-item">';
					html += '<span class="loom-struct-icon">' + escHtml(s.icon) + '</span>';
					html += '<div class="loom-struct-body">';
					html += '<div class="loom-struct-msg">' + escHtml(s.message) + '</div>';
					html += '<div class="loom-struct-action">' + escHtml(s.action) + '</div>';
					html += '</div>';
					html += '<span class="loom-struct-prio loom-prio-' + s.priority + '">' + s.priority + '</span>';
					html += '</div>';
				});
				html += '</div>';
				$panel.html(html);
			},
			error: function() { $btn.prop('disabled', false).text('📋 Pokaż sugestie'); }
		});
	});

	/* =======================================================================
	   SETTINGS: Save API Key
	   ======================================================================= */
	$(document).on('click', '#loom-save-key', function() {
		var key = $('#loom-api-key').val();
		if (!key || key.indexOf('••') === 0) {
			$('#loom-key-status').text('Wpisz nowy klucz.');
			return;
		}

		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'loom_save_settings',
				nonce: loom_ajax.nonce,
				action_type: 'save_key',
				api_key: key
			},
			success: function(res) {
				$('#loom-key-status').text(res.success ? '✅ ' + res.data : '❌ ' + res.data);
				if (res.success) {
					$('#loom-api-key').val('••••••••••••••••');
				}
			}
		});
	});

	/* =======================================================================
	   SETTINGS: Save General
	   ======================================================================= */
	$(document).on('click', '#loom-save-settings', function() {
		var types = [];
		$('input[name="loom_post_types[]"]:checked').each(function() {
			types.push($(this).val());
		});

		var data = {
			action: 'loom_save_settings',
			nonce: loom_ajax.nonce,
			action_type: 'save_settings',
			post_types: types,
			max_suggestions: $('input[name="loom_max_suggestions"]').val(),
			rescan_on_save: $('input[name="loom_rescan_on_save"]').is(':checked') ? 1 : 0,
			admin_notices: $('input[name="loom_admin_notices"]').is(':checked') ? 1 : 0
		};

		// Weights.
		$('.loom-weight-input').each(function() {
			data[$(this).attr('name')] = $(this).val();
		});

		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: data,
			success: function(res) {
				$('#loom-settings-status').text(res.success ? '✅ ' + res.data : '❌ ' + res.data);
			}
		});
	});

	// Calculate weight sum.
	$(document).on('input', '.loom-weight-input', function() {
		var sum = 0;
		$('.loom-weight-input').each(function() {
			sum += parseFloat($(this).val()) || 0;
		});
		$('#loom-weight-total').text(sum.toFixed(2));
		$('#loom-weight-total').css('color', Math.abs(sum - 1) < 0.01 ? '#10b981' : '#ef4444');
	});
	setTimeout(function() { $('.loom-weight-input').first().trigger('input'); }, 500);

	/* =======================================================================
	   REJECT SUGGESTION (feedback loop)
	   ======================================================================= */
	$(document).on('click', '.loom-reject-btn', function() {
		var $btn = $(this);
		var targetUrl = $btn.data('target-url');
		var targetId = 0;
		// Try to extract target_id from URL or pass 0
		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'loom_reject_suggestion',
				nonce: loom_ajax.nonce,
				post_id: $btn.data('post-id'),
				target_id: $btn.data('target-id') || 0,
				anchor_text: $btn.data('anchor')
			},
			success: function() {
				$btn.closest('.loom-suggestion-card').fadeOut(300, function() { $(this).remove(); });
			}
		});
	});

	/* =======================================================================
	   EXTRACT KEYWORDS (batch)
	   ======================================================================= */
	$(document).on('click', '#loom-extract-keywords', function() {
		var $btn = $(this);
		var useApi = $('#loom-kw-use-api').is(':checked') ? 1 : 0;
		$btn.prop('disabled', true);
		$('#loom-kw-progress').show();
		loomExtractKw(useApi);
	});

	function loomExtractKw(useApi) {
		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: { action: 'loom_extract_keywords', nonce: loom_ajax.nonce, use_api: useApi },
			success: function(res) {
				if (res.success) {
					$('#loom-kw-text').text('Pozostało: ' + res.data.remaining);
					if (res.data.status === 'next') {
						loomExtractKw(useApi);
					} else {
						$('#loom-kw-text').text('✅ Gotowe!');
						setTimeout(function() { location.reload(); }, 1500);
					}
				} else {
					$('#loom-kw-text').text('❌ ' + (res.data || ''));
				}
			}
		});
	}

	/* =======================================================================
	   RECALCULATE DEPTH
	   ======================================================================= */
	$(document).on('click', '#loom-recalc-depth', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).text('⏳...');
		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: { action: 'loom_recalc_depth', nonce: loom_ajax.nonce },
			success: function(res) {
				$btn.prop('disabled', false).text('✅ ' + (res.data || 'Gotowe'));
				setTimeout(function() { location.reload(); }, 1500);
			}
		});
	});

	/* =======================================================================
	   EMBEDDINGS: Batch Generate
	   ======================================================================= */
	var loomEmbTotal = 0;

	$(document).on('click', '#loom-generate-embeddings', function() {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#loom-emb-progress').show();
		// Extract total from button text, e.g. "Generuj embeddingi (66)"
		var match = $btn.text().match(/\((\d+)\)/);
		loomEmbTotal = match ? parseInt(match[1]) : 0;
		loomGenEmb();
	});

	function loomGenEmb() {
		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'loom_generate_embeddings',
				nonce: loom_ajax.nonce
			},
			timeout: 60000, // 60s timeout for slow API.
			success: function(res) {
				if (res.success) {
					var d = res.data;
					var done = loomEmbTotal > 0 ? loomEmbTotal - d.remaining : 0;
					var pct = loomEmbTotal > 0 ? Math.round((done / loomEmbTotal) * 100) : 0;

					$('#loom-emb-fill').css('width', pct + '%');
					$('#loom-emb-text').text(
						'Generowanie embeddingów... ' + done + '/' + loomEmbTotal +
						(d.error ? ' ⚠️ ' + d.error : '')
					);

					if (d.status === 'next') {
						loomGenEmb();
					} else {
						$('#loom-emb-text').text('✅ Gotowe! Wygenerowano embeddingi.');
						$('#loom-emb-fill').css('width', '100%');
						setTimeout(function() { location.reload(); }, 1500);
					}
				} else {
					$('#loom-emb-text').text('❌ ' + (res.data || 'Błąd API'));
					$('#loom-generate-embeddings').prop('disabled', false);
				}
			},
			error: function(xhr) {
				$('#loom-emb-text').text('❌ Błąd połączenia (timeout?). Kliknij ponownie.');
				$('#loom-generate-embeddings').prop('disabled', false);
			}
		});
	}

	/* =======================================================================
	   HELPERS
	   ======================================================================= */
	function escHtml(str) {
		if (!str) return '';
		return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	/* =======================================================================
	   LINK MAP  -  Force-directed graph on Canvas
	   ======================================================================= */
	var canvas = document.getElementById('loom-link-map');
	if (canvas) {
		var ctx = canvas.getContext('2d');
		var W, H, dpr;
		var nodes = [], edges = [];
		var dragging = null, dragOffX = 0, dragOffY = 0;
		var hoveredNode = null;

		function resizeCanvas() {
			dpr = window.devicePixelRatio || 1;
			var rect = canvas.parentElement.getBoundingClientRect();
			W = rect.width;
			H = 380;
			canvas.width = W * dpr;
			canvas.height = H * dpr;
			canvas.style.width = W + 'px';
			canvas.style.height = H + 'px';
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		}
		resizeCanvas();
		window.addEventListener('resize', function() { resizeCanvas(); draw(); });

		// Load data.
		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: { action: 'loom_get_link_map', nonce: loom_ajax.nonce },
			success: function(res) {
				if (!res.success) return;
				initGraph(res.data.nodes, res.data.edges);
			}
		});

		function initGraph(rawNodes, rawEdges) {
			var nodeMap = {};
			rawNodes.forEach(function(n, i) {
				var angle = (i / rawNodes.length) * Math.PI * 2;
				var r = Math.min(W, H) * 0.35;
				n.x = W / 2 + Math.cos(angle) * r * (0.6 + Math.random() * 0.4);
				n.y = H / 2 + Math.sin(angle) * r * (0.6 + Math.random() * 0.4);
				n.vx = 0; n.vy = 0;
				n.radius = Math.max(5, Math.min(22, 4 + n.in * 1.5));
				nodeMap[n.id] = n;
			});
			nodes = rawNodes;
			edges = rawEdges.filter(function(e) { return nodeMap[e.from] && nodeMap[e.to]; });

			// Run simulation.
			var iterations = 120;
			for (var iter = 0; iter < iterations; iter++) {
				simulate(0.15 * (1 - iter / iterations));
			}
			draw();
		}

		function simulate(alpha) {
			var k = Math.sqrt((W * H) / Math.max(nodes.length, 1));

			// Repulsion.
			for (var i = 0; i < nodes.length; i++) {
				for (var j = i + 1; j < nodes.length; j++) {
					var dx = nodes[j].x - nodes[i].x;
					var dy = nodes[j].y - nodes[i].y;
					var d = Math.sqrt(dx * dx + dy * dy) || 1;
					var force = (k * k) / d * alpha;
					var fx = (dx / d) * force;
					var fy = (dy / d) * force;
					nodes[i].x -= fx; nodes[i].y -= fy;
					nodes[j].x += fx; nodes[j].y += fy;
				}
			}

			// Attraction (edges).
			var nodeMap = {};
			nodes.forEach(function(n) { nodeMap[n.id] = n; });
			edges.forEach(function(e) {
				var a = nodeMap[e.from], b = nodeMap[e.to];
				if (!a || !b) return;
				var dx = b.x - a.x, dy = b.y - a.y;
				var d = Math.sqrt(dx * dx + dy * dy) || 1;
				var force = (d - k * 0.5) * alpha * 0.08;
				var fx = (dx / d) * force, fy = (dy / d) * force;
				a.x += fx; a.y += fy;
				b.x -= fx; b.y -= fy;
			});

			// Center gravity.
			nodes.forEach(function(n) {
				n.x += (W / 2 - n.x) * alpha * 0.05;
				n.y += (H / 2 - n.y) * alpha * 0.05;
				// Bounds.
				n.x = Math.max(n.radius + 5, Math.min(W - n.radius - 5, n.x));
				n.y = Math.max(n.radius + 5, Math.min(H - n.radius - 5, n.y));
			});
		}

		function draw() {
			ctx.clearRect(0, 0, W, H);
			var nodeMap = {};
			nodes.forEach(function(n) { nodeMap[n.id] = n; });

			// Draw edges.
			edges.forEach(function(e) {
				var a = nodeMap[e.from], b = nodeMap[e.to];
				if (!a || !b) return;
				ctx.beginPath();
				ctx.moveTo(a.x, a.y);
				ctx.lineTo(b.x, b.y);
				ctx.strokeStyle = e.loom ? '#008080' : '#cbd5e1';
				ctx.lineWidth = e.loom ? 1.5 : 0.7;
				ctx.globalAlpha = e.loom ? 0.6 : 0.3;
				ctx.stroke();
				ctx.globalAlpha = 1;

				// Arrow.
				if (e.loom) {
					var angle = Math.atan2(b.y - a.y, b.x - a.x);
					var mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
					ctx.beginPath();
					ctx.moveTo(mx + 5 * Math.cos(angle), my + 5 * Math.sin(angle));
					ctx.lineTo(mx - 4 * Math.cos(angle - 0.5), my - 4 * Math.sin(angle - 0.5));
					ctx.lineTo(mx - 4 * Math.cos(angle + 0.5), my - 4 * Math.sin(angle + 0.5));
					ctx.fillStyle = '#008080';
					ctx.globalAlpha = 0.5;
					ctx.fill();
					ctx.globalAlpha = 1;
				}
			});

			// Draw nodes.
			nodes.forEach(function(n) {
				var isHovered = hoveredNode && hoveredNode.id === n.id;
				ctx.beginPath();
				ctx.arc(n.x, n.y, n.radius + (isHovered ? 3 : 0), 0, Math.PI * 2);

				if (n.orphan) {
					ctx.fillStyle = '#fee2e2';
					ctx.strokeStyle = '#ef4444';
				} else if (n.money) {
					ctx.fillStyle = '#fef9c3';
					ctx.strokeStyle = '#eab308';
				} else if (n.striking) {
					ctx.fillStyle = '#f3e8ff';
					ctx.strokeStyle = '#a855f7';
				} else if (n.dead_end) {
					ctx.fillStyle = '#fef3c7';
					ctx.strokeStyle = '#f59e0b';
				} else if (n.bridge) {
					ctx.fillStyle = '#e0f2fe';
					ctx.strokeStyle = '#3b82f6';
				} else if (n.in >= 10) {
					ctx.fillStyle = '#ccfbf1';
					ctx.strokeStyle = '#0d9488';
				} else {
					ctx.fillStyle = '#f0fdfa';
					ctx.strokeStyle = '#14b8a6';
				}

				// Money page glow.
				if (n.money) {
					ctx.save();
					ctx.beginPath();
					ctx.arc(n.x, n.y, n.radius + 5, 0, Math.PI * 2);
					ctx.fillStyle = 'rgba(234,179,8,.12)';
					ctx.fill();
					ctx.restore();
					ctx.beginPath();
					ctx.arc(n.x, n.y, n.radius + (isHovered ? 3 : 0), 0, Math.PI * 2);
				}

				ctx.lineWidth = isHovered ? 3 : 1.5;
				ctx.fill();
				ctx.stroke();

				// Label.
				if (n.radius > 7 || isHovered) {
					ctx.font = (isHovered ? 'bold ' : '') + '10px -apple-system, sans-serif';
					ctx.fillStyle = '#1e293b';
					ctx.textAlign = 'center';
					ctx.fillText(n.label, n.x, n.y + n.radius + 12);
				}
			});

			// Tooltip.
			if (hoveredNode) {
				var n = hoveredNode;
				var tw = 180, th = 74;
				var tx = Math.min(n.x + 15, W - tw - 10);
				var ty = Math.max(n.y - 15 - th, 10);

				ctx.fillStyle = 'rgba(255,255,255,.95)';
				ctx.strokeStyle = '#008080';
				ctx.lineWidth = 1;
				ctx.beginPath();
				ctx.roundRect(tx, ty, tw, th, 6);
				ctx.fill(); ctx.stroke();

				ctx.font = 'bold 11px -apple-system, sans-serif';
				ctx.fillStyle = '#1e293b';
				ctx.textAlign = 'left';
				ctx.fillText(n.label, tx + 8, ty + 16);
				ctx.font = '11px -apple-system, sans-serif';
				ctx.fillStyle = '#64748b';
				ctx.fillText('IN: ' + n.in + '  OUT: ' + n.out, tx + 8, ty + 32);
				var prStr = n.pr ? 'PR: ' + (n.pr * 100).toFixed(1) : '';
				ctx.fillText(prStr, tx + 8, ty + 48);
				var status = n.orphan ? '🔴 Orphan' : n.money ? '⭐ Money Page' : n.striking ? '🎯 Striking' : n.dead_end ? '🟠 Dead End' : n.bridge ? '🌉 Bridge' : (n.in >= 10 ? '🟢 Hub' : '');
				ctx.fillText(status, tx + 8, ty + 64);
			}
		}

		// ─── Mouse interaction ───
		canvas.addEventListener('mousemove', function(e) {
			var rect = canvas.getBoundingClientRect();
			var mx = e.clientX - rect.left;
			var my = e.clientY - rect.top;

			if (dragging) {
				dragging.x = mx - dragOffX;
				dragging.y = my - dragOffY;
				draw();
				return;
			}

			hoveredNode = null;
			for (var i = nodes.length - 1; i >= 0; i--) {
				var n = nodes[i];
				var d = Math.sqrt((mx - n.x) * (mx - n.x) + (my - n.y) * (my - n.y));
				if (d < n.radius + 4) {
					hoveredNode = n;
					canvas.style.cursor = 'pointer';
					break;
				}
			}
			if (!hoveredNode) canvas.style.cursor = 'grab';
			draw();
		});

		canvas.addEventListener('mousedown', function(e) {
			if (hoveredNode) {
				var rect = canvas.getBoundingClientRect();
				dragging = hoveredNode;
				dragOffX = (e.clientX - rect.left) - hoveredNode.x;
				dragOffY = (e.clientY - rect.top) - hoveredNode.y;
				canvas.style.cursor = 'grabbing';
			}
		});

		canvas.addEventListener('mouseup', function() {
			dragging = null;
			canvas.style.cursor = hoveredNode ? 'pointer' : 'grab';
		});

		canvas.addEventListener('mouseleave', function() {
			dragging = null;
			hoveredNode = null;
			draw();
		});
	}

	/* ── Money Page Toggle ────────────────────── */
	$(document).on('click', '.loom-money-toggle', function() {
		var btn = $(this);
		var postId = btn.data('post-id');
		var currentlyMoney = parseInt(btn.data('is-money')) === 1;
		var newState = currentlyMoney ? 0 : 1;

		$.post(loom_ajax.ajaxurl, {
			action: 'loom_set_money_page',
			nonce: loom_ajax.nonce,
			post_id: postId,
			is_money: newState,
			priority: 3,
			goal: 10
		}, function(res) {
			if (res.success) {
				btn.data('is-money', newState);
				btn.text(newState ? '⭐' : '☆');
				btn.toggleClass('active', !!newState);
				btn.attr('title', newState ? 'Money page  -  kliknij aby usunąć' : 'Oznacz jako money page');
			}
		});
	});

	/* =======================================================================
	   WEIGHT SLIDERS
	   ======================================================================= */
	$(document).on('input', '.loom-weight-slider', function() {
		var $slider = $(this);
		$slider.closest('.loom-weight-row').find('.loom-weight-pct').text($slider.val() + '%');
		updateWeightSum();
	});

	function updateWeightSum() {
		var sum = 0;
		$('.loom-weight-slider').each(function() { sum += parseInt($(this).val()); });
		var $sumEl = $('#loom-weight-sum');
		$sumEl.text(sum + '%');
		$sumEl.css('color', Math.abs(sum - 100) <= 2 ? '#065f46' : '#991b1b');
	}
	// Init on page load.
	if ($('.loom-weight-slider').length) updateWeightSum();

	$(document).on('click', '#loom-normalize-weights', function() {
		var sum = 0;
		$('.loom-weight-slider').each(function() { sum += parseInt($(this).val()); });
		if (sum <= 0) return;
		$('.loom-weight-slider').each(function() {
			var normalized = Math.round(parseInt($(this).val()) / sum * 100);
			$(this).val(normalized);
			$(this).closest('.loom-weight-row').find('.loom-weight-pct').text(normalized + '%');
		});
		updateWeightSum();
	});

	$(document).on('click', '#loom-save-weights', function() {
		var data = { action: 'loom_save_settings', nonce: loom_ajax.nonce, action_type: 'save_settings' };
		$('.loom-weight-slider').each(function() {
			data[$(this).data('key')] = (parseInt($(this).val()) / 100).toFixed(2);
		});
		// Include other settings if present.
		$('.loom-settings-input, .loom-settings-select').each(function() {
			var name = $(this).attr('name');
			if (name) data[name.replace('loom_', '')] = $(this).val();
		});
		$.post(loom_ajax.ajaxurl, data, function(res) {
			if (res.success) {
				$('#loom-save-weights').text('✅ Zapisano').prop('disabled', true);
				setTimeout(function() { $('#loom-save-weights').text('Zapisz').prop('disabled', false); }, 2000);
			}
		});
	});

	/* =======================================================================
	   GSC SYNC
	   ======================================================================= */
	$(document).on('click', '#loom-gsc-sync', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).text('⏳ Syncing...');
		$.post(loom_ajax.ajaxurl, {
			action: 'loom_gsc_sync',
			nonce: loom_ajax.nonce
		}, function(res) {
			if (res.success) {
				$btn.text('✅ ' + res.data.message);
				setTimeout(function() { location.reload(); }, 2000);
			} else {
				$btn.text('❌ ' + (res.data || 'Error')).prop('disabled', false);
			}
		}).fail(function() {
			$btn.text('❌ Connection error').prop('disabled', false);
		});
	});

	$(document).on('click', '#loom-gsc-disconnect', function() {
		if (!confirm('Rozłączyć Google Search Console?')) return;
		$.post(loom_ajax.ajaxurl, {
			action: 'loom_gsc_disconnect',
			nonce: loom_ajax.nonce
		}, function(res) {
			if (res.success) location.reload();
		});
	});

	/* =======================================================================
	   GSC CONNECTION (Service Account  -  simple)
	   ======================================================================= */
	$(document).on('click', '#loom-gsc-connect', function() {
		var $btn = $(this);
		var json = $('#loom-gsc-json').val().trim();
		var siteUrl = $('#loom-gsc-site-url').val().trim();

		if (!json) { alert('Wklej zawartość pliku JSON Service Account.'); return; }

		$btn.prop('disabled', true).text('⏳ Łączenie...');
		$('#loom-gsc-connect-status').text('');

		$.post(loom_ajax.ajaxurl, {
			action: 'loom_gsc_save_credentials',
			nonce: loom_ajax.nonce,
			gsc_json: json,
			gsc_site_url: siteUrl
		}, function(res) {
			if (res.success) {
				$('#loom-gsc-connect-status').text('✅ ' + res.data.message).css('color', 'var(--ok)');
				setTimeout(function() { location.reload(); }, 1500);
			} else {
				$('#loom-gsc-connect-status').text('❌ ' + (res.data || 'Error')).css('color', 'var(--bad)');
				$btn.text('📊 Połącz GSC').prop('disabled', false);
			}
		}).fail(function() {
			$('#loom-gsc-connect-status').text('❌ Connection error');
			$btn.text('📊 Połącz GSC').prop('disabled', false);
		});
	});

	/* =======================================================================
	   REMOVE ALL LOOM LINKS
	   ======================================================================= */
	$(document).on('click', '#loom-remove-all-links', function() {
		if (!confirm('UWAGA: To usunie WSZYSTKIE linki wstawione przez LOOM ze wszystkich postów.\n\nKopia zapasowa treści zostanie zapisana.\n\nCzy na pewno chcesz kontynuować?')) return;
		if (!confirm('Drugie potwierdzenie: czy na pewno chcesz usunąć WSZYSTKIE linki LOOM?')) return;

		var $btn = $(this);
		$btn.prop('disabled', true).text('⏳ Usuwanie...');
		$('#loom-remove-status').text('');

		$.post(loom_ajax.ajaxurl, {
			action: 'loom_remove_all_links',
			nonce: loom_ajax.nonce
		}, function(res) {
			if (res.success) {
				$('#loom-remove-status').text('✅ ' + res.data.message).css('color', 'var(--ok)');
				$btn.text('✅ Usunięto').prop('disabled', true);
				setTimeout(function() { location.reload(); }, 2000);
			} else {
				$('#loom-remove-status').text('❌ ' + (res.data || 'Error')).css('color', 'var(--bad)');
				$btn.prop('disabled', false).text('🗑️ Usuń');
			}
		}).fail(function() {
			$('#loom-remove-status').text('❌ Connection error');
			$btn.prop('disabled', false).text('🗑️ Usuń');
		});
	});

})(jQuery);
