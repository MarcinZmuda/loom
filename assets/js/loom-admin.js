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
	   LINK MAP  -  Multi-view graph (Rings / Table / Matrix)
	   ======================================================================= */
	var canvas = document.getElementById('loom-link-map');
	if (canvas) {
		var ctx = canvas.getContext('2d');
		var W, H, dpr;
		var graphNodes = [], graphEdges = [], nodeMap = {};
		var selectedNode = null, hoveredNode = null;
		var currentView = 'rings';
		var TIER_LABELS = ['Homepage', 'Pillar', 'Kategoria', 'Artykuł'];
		var TIER_COLORS = ['#0d9488', '#0ea5e9', '#8b5cf6', '#94a3b8'];

		// ─── View switcher ───
		$(document).on('click', '.loom-graph-view-btn', function() {
			$('.loom-graph-view-btn').removeClass('active');
			$(this).addClass('active');
			currentView = $(this).data('view');
			$('.loom-graph-panel').hide();
			$('#loom-view-' + currentView).show();
			if (currentView === 'rings') drawRings();
			if (currentView === 'table') renderTable();
			if (currentView === 'matrix') renderMatrix();
		});

		function resizeCanvas() {
			dpr = window.devicePixelRatio || 1;
			var rect = canvas.parentElement.getBoundingClientRect();
			W = rect.width;
			H = 460;
			canvas.width = W * dpr;
			canvas.height = H * dpr;
			canvas.style.width = W + 'px';
			canvas.style.height = H + 'px';
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		}
		resizeCanvas();
		window.addEventListener('resize', function() { resizeCanvas(); if (currentView === 'rings') positionNodes(); drawRings(); });

		// Load data.
		$.ajax({
			url: loom_ajax.ajaxurl, type: 'POST',
			data: { action: 'loom_get_link_map', nonce: loom_ajax.nonce },
			success: function(res) {
				if (!res.success) return;
				graphNodes = res.data.nodes;
				graphEdges = res.data.edges;
				nodeMap = {};
				graphNodes.forEach(function(n) { nodeMap[n.id] = n; });
				positionNodes();
				drawRings();
			}
		});

		// ═══════════════════════════════════════════════
		//  VIEW 1: CONCENTRIC RINGS
		// ═══════════════════════════════════════════════
		function positionNodes() {
			var CX = W / 2, CY = H / 2;
			var maxR = Math.min(W, H) * 0.42;
			var rings = [0, maxR * 0.28, maxR * 0.58, maxR];

			var byTier = [[], [], [], []];
			graphNodes.forEach(function(n) {
				var t = Math.min(3, Math.max(0, n.tier || 3));
				byTier[t].push(n);
			});

			graphNodes.forEach(function(n) {
				var t = Math.min(3, Math.max(0, n.tier || 3));
				var siblings = byTier[t];
				var idx = siblings.indexOf(n);
				var count = siblings.length;
				n.radius = Math.max(5, Math.min(20, 4 + n['in'] * 1.2));

				if (t === 0) { n.gx = CX; n.gy = CY; return; }

				var angleStep = (Math.PI * 2) / count;
				var angle = angleStep * idx - Math.PI / 2;
				n.gx = CX + Math.cos(angle) * rings[t];
				n.gy = CY + Math.sin(angle) * rings[t];
			});
		}

		function getNodeStyle(n) {
			var fill, stroke;
			if (n.orphan) { fill = '#fee2e2'; stroke = '#ef4444'; }
			else if (n.money) { fill = '#fef9c3'; stroke = '#eab308'; }
			else if (n.striking) { fill = '#f3e8ff'; stroke = '#a855f7'; }
			else if (n.dead_end) { fill = '#fef3c7'; stroke = '#f59e0b'; }
			else if (n.bridge) { fill = '#e0f2fe'; stroke = '#3b82f6'; }
			else if (n['in'] >= 8) { fill = '#ccfbf1'; stroke = '#0d9488'; }
			else { fill = '#f0fdfa'; stroke = TIER_COLORS[Math.min(3, n.tier || 3)]; }
			return { fill: fill, stroke: stroke };
		}

		function drawRings() {
			ctx.clearRect(0, 0, W, H);
			var CX = W / 2, CY = H / 2;
			var maxR = Math.min(W, H) * 0.42;
			var rings = [0, maxR * 0.28, maxR * 0.58, maxR];

			// Tier ring guides.
			for (var r = 1; r <= 3; r++) {
				ctx.beginPath();
				ctx.arc(CX, CY, rings[r], 0, Math.PI * 2);
				ctx.strokeStyle = '#f1f5f9'; ctx.lineWidth = 1; ctx.setLineDash([4, 4]);
				ctx.stroke(); ctx.setLineDash([]);
				ctx.font = '9px -apple-system, sans-serif';
				ctx.fillStyle = '#cbd5e1'; ctx.textAlign = 'start';
				ctx.fillText(TIER_LABELS[r], CX + rings[r] - 50, CY - rings[r] + 14);
			}

			// Edges — only for selected node.
			var selEdges = [];
			if (selectedNode) {
				selEdges = graphEdges.filter(function(e) { return e.from === selectedNode || e.to === selectedNode; });
			}
			selEdges.forEach(function(e) {
				var a = nodeMap[e.from], b = nodeMap[e.to];
				if (!a || !b) return;
				ctx.beginPath();
				ctx.moveTo(a.gx, a.gy); ctx.lineTo(b.gx, b.gy);
				ctx.strokeStyle = e.loom ? '#0d9488' : '#94a3b8';
				ctx.lineWidth = e.loom ? 2.5 : 1;
				ctx.globalAlpha = e.loom ? 0.7 : 0.4;
				ctx.stroke();

				// Arrow for LOOM links.
				if (e.loom) {
					var angle = Math.atan2(b.gy - a.gy, b.gx - a.gx);
					var mx = (a.gx + b.gx) / 2, my = (a.gy + b.gy) / 2;
					ctx.beginPath();
					ctx.moveTo(mx + 5 * Math.cos(angle), my + 5 * Math.sin(angle));
					ctx.lineTo(mx - 4 * Math.cos(angle - 0.5), my - 4 * Math.sin(angle - 0.5));
					ctx.lineTo(mx - 4 * Math.cos(angle + 0.5), my - 4 * Math.sin(angle + 0.5));
					ctx.fillStyle = '#0d9488'; ctx.fill();
				}
				ctx.globalAlpha = 1;
			});

			// Nodes.
			graphNodes.forEach(function(n) {
				var isSelected = n.id === selectedNode;
				var isConnected = selEdges.some(function(e) { return e.from === n.id || e.to === n.id; });
				var dimmed = selectedNode && !isSelected && !isConnected;
				var isHover = hoveredNode && hoveredNode.id === n.id;
				var style = getNodeStyle(n);

				ctx.globalAlpha = dimmed ? 0.12 : 1;

				// Money glow.
				if (n.money && !dimmed) {
					ctx.beginPath();
					ctx.arc(n.gx, n.gy, n.radius + 5, 0, Math.PI * 2);
					ctx.fillStyle = 'rgba(234,179,8,.12)';
					ctx.fill();
				}

				ctx.beginPath();
				ctx.arc(n.gx, n.gy, n.radius + (isSelected ? 3 : isHover ? 2 : 0), 0, Math.PI * 2);
				ctx.fillStyle = style.fill;
				ctx.strokeStyle = style.stroke;
				ctx.lineWidth = isSelected ? 3 : (isHover ? 2.5 : 1.5);
				ctx.fill(); ctx.stroke();

				// Label.
				if ((n.radius > 7 || isSelected || isHover) && !dimmed) {
					ctx.font = (isSelected || isHover ? 'bold ' : '') + '9px -apple-system, sans-serif';
					ctx.fillStyle = '#1e293b'; ctx.textAlign = 'center';
					var lbl = n.label.length > 20 ? n.label.substring(0, 18) + '…' : n.label;
					ctx.fillText(lbl, n.gx, n.gy + n.radius + 12);
				}
				ctx.globalAlpha = 1;
			});

			// Info panel for selected node.
			if (selectedNode && nodeMap[selectedNode]) {
				var sn = nodeMap[selectedNode];
				var inEdges = graphEdges.filter(function(e) { return e.to === selectedNode; });
				var outEdges = graphEdges.filter(function(e) { return e.from === selectedNode; });
				var tw = 200, th = 90;
				var tx = 12, ty = 12;

				ctx.fillStyle = 'rgba(255,255,255,.96)';
				ctx.strokeStyle = '#e5e7eb'; ctx.lineWidth = 1;
				ctx.beginPath(); ctx.roundRect(tx, ty, tw, th, 8); ctx.fill(); ctx.stroke();

				ctx.textAlign = 'left';
				ctx.font = 'bold 11px -apple-system, sans-serif';
				ctx.fillStyle = '#0f172a';
				ctx.fillText(sn.label, tx + 10, ty + 18);
				ctx.font = '10px -apple-system, sans-serif';
				ctx.fillStyle = '#64748b';
				ctx.fillText('IN: ' + sn['in'] + ' · OUT: ' + sn.out + ' · Tier: ' + TIER_LABELS[Math.min(3, sn.tier || 3)], tx + 10, ty + 34);
				ctx.fillText('PR: ' + (sn.pr ? (sn.pr * 100).toFixed(1) : '—'), tx + 10, ty + 50);
				var flags = [];
				if (sn.orphan) flags.push('🔴 Orphan');
				if (sn.money) flags.push('⭐ Money');
				if (sn.striking) flags.push('🎯 Striking');
				if (sn.dead_end) flags.push('🟠 Dead End');
				if (sn.bridge) flags.push('🌉 Bridge');
				ctx.fillText(flags.join(' ') || '✅ OK', tx + 10, ty + 66);
				ctx.fillText('→ ' + inEdges.length + ' IN · ' + outEdges.length + ' OUT', tx + 10, ty + 82);
			}

			// Legend bar.
			var lx = 10, ly = H - 18;
			ctx.font = '9px -apple-system, sans-serif'; ctx.textAlign = 'left';
			var legend = [
				{ c: '#0d9488', l: 'Hub' }, { c: '#94a3b8', l: 'Normal' }, { c: '#ef4444', l: 'Orphan' },
				{ c: '#f59e0b', l: 'Dead End' }, { c: '#3b82f6', l: 'Bridge' },
				{ c: '#a855f7', l: 'Striking' }, { c: '#eab308', l: 'Money' }
			];
			legend.forEach(function(item) {
				ctx.beginPath(); ctx.arc(lx + 4, ly, 3.5, 0, Math.PI * 2);
				ctx.fillStyle = item.c; ctx.fill();
				ctx.fillStyle = '#94a3b8';
				ctx.fillText(item.l, lx + 11, ly + 3);
				lx += ctx.measureText(item.l).width + 22;
			});
		}

		// ─── Canvas mouse interaction ───
		canvas.addEventListener('mousemove', function(e) {
			var rect = canvas.getBoundingClientRect();
			var mx = e.clientX - rect.left, my = e.clientY - rect.top;
			hoveredNode = null;
			for (var i = graphNodes.length - 1; i >= 0; i--) {
				var n = graphNodes[i];
				var d = Math.sqrt((mx - n.gx) * (mx - n.gx) + (my - n.gy) * (my - n.gy));
				if (d < n.radius + 4) { hoveredNode = n; break; }
			}
			canvas.style.cursor = hoveredNode ? 'pointer' : 'default';
			drawRings();
		});
		canvas.addEventListener('click', function(e) {
			var rect = canvas.getBoundingClientRect();
			var mx = e.clientX - rect.left, my = e.clientY - rect.top;
			var clicked = null;
			for (var i = graphNodes.length - 1; i >= 0; i--) {
				var n = graphNodes[i];
				var d = Math.sqrt((mx - n.gx) * (mx - n.gx) + (my - n.gy) * (my - n.gy));
				if (d < n.radius + 4) { clicked = n; break; }
			}
			selectedNode = (clicked && clicked.id === selectedNode) ? null : (clicked ? clicked.id : null);
			drawRings();
		});
		canvas.addEventListener('mouseleave', function() { hoveredNode = null; drawRings(); });

		// ═══════════════════════════════════════════════
		//  VIEW 2: TABLE + DETAIL PANEL
		// ═══════════════════════════════════════════════
		var tableSelected = null;

		function renderTable() {
			var sorted = graphNodes.slice().sort(function(a, b) { return b['in'] - a['in']; });
			var html = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
			html += '<thead><tr style="background:#f9fafb;font-size:10px;text-transform:uppercase;color:#94a3b8">';
			html += '<th style="padding:6px 8px;text-align:left">Strona</th>';
			html += '<th style="padding:6px 4px;text-align:center">IN</th>';
			html += '<th style="padding:6px 4px;text-align:center">OUT</th>';
			html += '<th style="padding:6px 4px;text-align:center">Status</th></tr></thead><tbody>';

			sorted.forEach(function(n) {
				var sel = n.id === tableSelected;
				var dotC = TIER_COLORS[Math.min(3, n.tier || 3)];
				var statusBadge = '';
				if (n.orphan) statusBadge = '<span style="background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:700">Orphan</span>';
				else if (n.money) statusBadge = '<span style="background:#fef9c3;color:#854d0e;padding:1px 6px;border-radius:10px;font-size:9px">⭐ Money</span>';
				else if (n.dead_end) statusBadge = '<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:10px;font-size:9px">Dead End</span>';
				else if (n.striking) statusBadge = '<span style="background:#f3e8ff;color:#7c3aed;padding:1px 6px;border-radius:10px;font-size:9px">🎯 Striking</span>';
				else if (n['in'] >= 3) statusBadge = '<span style="background:#d1fae5;color:#065f46;padding:1px 6px;border-radius:10px;font-size:9px">OK</span>';
				else statusBadge = '<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:10px;font-size:9px">Słaby</span>';

				html += '<tr class="loom-table-row" data-nid="' + n.id + '" style="cursor:pointer;background:' + (sel ? '#f0fdfa' : '#fff') + ';border-bottom:1px solid #f1f5f9">';
				html += '<td style="padding:6px 8px;font-weight:' + (sel ? 700 : 400) + '"><span style="display:inline-block;width:8px;height:8px;border-radius:4px;background:' + dotC + ';margin-right:6px"></span>' + escHtml(n.label) + '</td>';
				html += '<td style="text-align:center;font-weight:700;font-family:monospace">' + n['in'] + '</td>';
				html += '<td style="text-align:center;color:#94a3b8;font-family:monospace">' + n.out + '</td>';
				html += '<td style="text-align:center">' + statusBadge + '</td></tr>';
			});
			html += '</tbody></table>';
			$('#loom-table-list').html(html);
			renderTableDetail();
		}

		function renderTableDetail() {
			if (!tableSelected || !nodeMap[tableSelected]) {
				$('#loom-table-detail').html('<div style="color:#94a3b8;text-align:center;padding-top:60px">← Kliknij stronę żeby zobaczyć połączenia</div>');
				return;
			}
			var n = nodeMap[tableSelected];
			var inE = graphEdges.filter(function(e) { return e.to === tableSelected; });
			var outE = graphEdges.filter(function(e) { return e.from === tableSelected; });

			var html = '<div style="background:#f0fdfa;border-radius:10px;padding:14px;margin-bottom:12px;border:2px solid #99f6e4">';
			html += '<div style="font-weight:700;font-size:14px;margin-bottom:4px">' + escHtml(n.label) + '</div>';
			html += '<div style="color:#6b7280;font-size:12px">Tier: ' + TIER_LABELS[Math.min(3, n.tier || 3)] + ' · PR: ' + (n.pr ? (n.pr * 100).toFixed(1) : '—') + ' · IN: <strong>' + n['in'] + '</strong> · OUT: <strong>' + n.out + '</strong></div></div>';

			// Incoming
			html += '<div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:6px">↙️ Linki przychodzące (' + inE.length + ')</div>';
			if (!inE.length) html += '<div style="color:#ef4444;font-size:11px;margin-bottom:12px">🔴 Brak — orphan!</div>';
			inE.forEach(function(e) {
				var src = nodeMap[e.from];
				var lbl = src ? escHtml(src.label) : '?';
				var loomBadge = e.loom ? '<span style="background:#ccfbf1;color:#115e59;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:600">LOOM</span>' : '';
				html += '<div class="loom-table-row" data-nid="' + e.from + '" style="padding:4px 8px;background:' + (e.loom ? '#f0fdfa' : '#f9fafb') + ';border-radius:6px;margin-bottom:3px;display:flex;justify-content:space-between;align-items:center;cursor:pointer">';
				html += '<span style="color:#0d9488;font-weight:500;font-size:12px">' + lbl + '</span>' + loomBadge + '</div>';
			});

			// Outgoing
			html += '<div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin:12px 0 6px">↗️ Linki wychodzące (' + outE.length + ')</div>';
			if (!outE.length) html += '<div style="color:#f59e0b;font-size:11px">⚠️ Dead end — brak linków wychodzących</div>';
			outE.forEach(function(e) {
				var tgt = nodeMap[e.to];
				var lbl = tgt ? escHtml(tgt.label) : '?';
				var loomBadge = e.loom ? '<span style="background:#ccfbf1;color:#115e59;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:600">LOOM</span>' : '';
				html += '<div class="loom-table-row" data-nid="' + e.to + '" style="padding:4px 8px;background:' + (e.loom ? '#f0fdfa' : '#f9fafb') + ';border-radius:6px;margin-bottom:3px;display:flex;justify-content:space-between;align-items:center;cursor:pointer">';
				html += '<span style="color:#0d9488;font-weight:500;font-size:12px">' + lbl + '</span>' + loomBadge + '</div>';
			});

			$('#loom-table-detail').html(html);
		}

		$(document).on('click', '.loom-table-row', function() {
			var nid = parseInt($(this).data('nid'));
			tableSelected = (nid === tableSelected) ? null : nid;
			renderTable();
		});

		// ═══════════════════════════════════════════════
		//  VIEW 3: ADJACENCY MATRIX
		// ═══════════════════════════════════════════════
		function renderMatrix() {
			var sorted = graphNodes.slice().sort(function(a, b) { return a.tier - b.tier || b['in'] - a['in']; });
			var N = sorted.length;
			if (N > 40) sorted = sorted.slice(0, 40); // Limit for readability.
			N = sorted.length;

			var edgeSet = {};
			graphEdges.forEach(function(e) { edgeSet[e.from + '-' + e.to] = e.loom ? 'loom' : 'manual'; });

			var size = Math.max(14, Math.min(22, Math.floor(600 / N)));
			var margin = 130;
			var total = margin + N * size + 30;

			var svg = '<svg width="' + total + '" height="' + total + '" xmlns="http://www.w3.org/2000/svg">';

			// Column headers (rotated).
			sorted.forEach(function(n, i) {
				var x = margin + i * size + size / 2;
				var tc = TIER_COLORS[Math.min(3, n.tier || 3)];
				var lbl = n.label.length > 16 ? n.label.substring(0, 14) + '…' : n.label;
				svg += '<text x="' + x + '" y="' + (margin - 4) + '" font-size="7" fill="' + tc + '" text-anchor="end" transform="rotate(-55,' + x + ',' + (margin - 4) + ')">' + escHtml(lbl) + '</text>';
			});

			// Rows.
			sorted.forEach(function(row, ri) {
				var tc = TIER_COLORS[Math.min(3, row.tier || 3)];
				var lbl = row.label.length > 18 ? row.label.substring(0, 16) + '…' : row.label;
				svg += '<text x="' + (margin - 6) + '" y="' + (margin + ri * size + size / 2 + 3) + '" font-size="7" fill="' + tc + '" text-anchor="end">' + escHtml(lbl) + '</text>';

				sorted.forEach(function(col, ci) {
					var key = row.id + '-' + col.id;
					var type = edgeSet[key];
					var fill = type === 'loom' ? '#0d9488' : (type === 'manual' ? '#bae6fd' : '#fafafa');
					var opacity = type ? 1 : 0.3;
					svg += '<rect x="' + (margin + ci * size) + '" y="' + (margin + ri * size) + '" width="' + (size - 1) + '" height="' + (size - 1) + '" rx="2" fill="' + fill + '" stroke="#f1f5f9" stroke-width="0.5" opacity="' + opacity + '"/>';
				});
			});

			// Legend.
			var ly = margin + N * size + 14;
			svg += '<rect x="' + margin + '" y="' + ly + '" width="12" height="12" fill="#0d9488" rx="2"/>';
			svg += '<text x="' + (margin + 16) + '" y="' + (ly + 10) + '" font-size="9" fill="#374151">LOOM link</text>';
			svg += '<rect x="' + (margin + 80) + '" y="' + ly + '" width="12" height="12" fill="#bae6fd" rx="2"/>';
			svg += '<text x="' + (margin + 96) + '" y="' + (ly + 10) + '" font-size="9" fill="#374151">Ręczny link</text>';

			if (graphNodes.length > 40) {
				svg += '<text x="' + (margin + 200) + '" y="' + (ly + 10) + '" font-size="9" fill="#94a3b8">Wyświetlam top 40 z ' + graphNodes.length + '</text>';
			}
			svg += '</svg>';

			$('#loom-matrix-container').html(svg);
		}

	} // end if(canvas)

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
