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
		var btn = $(this);
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
			min_similarity: parseFloat(($('input[name="loom_min_similarity"]').val() || '0.35').replace(',', '.')) || 0.35,
			rescan_on_save: $('input[name="loom_rescan_on_save"]').is(':checked') ? 1 : 0,
			admin_notices: $('input[name="loom_admin_notices"]').is(':checked') ? 1 : 0
		};

		// Weights.
		$('.loom-weight-input').each(function() {
			data[$(this).attr('name')] = $(this).val();
		});

		btn.prop('disabled', true).text('⏳ Zapisywanie...');
		$('#loom-settings-status').text('');

		$.ajax({
			url: loom_ajax.ajaxurl,
			type: 'POST',
			data: data,
			timeout: 15000,
			success: function(res) {
				btn.prop('disabled', false).text(btn.data('orig-text') || 'Zapisz ustawienia');
				if (res.success) {
					var msg = typeof res.data === 'string' ? res.data : (res.data.message || 'Zapisano');
					$('#loom-settings-status').html('<span style="color:#16a34a">✅ ' + msg + '</span>');

					// If post types changed, prompt for rescan.
					if (res.data && res.data.types_changed) {
						var rescanHtml = '<div style="margin-top:12px;padding:12px 16px;background:#fef3c7;border:2px solid #f59e0b;border-radius:10px">';
						rescanHtml += '<strong style="color:#92400e">⚠️ Zmieniono typy postów!</strong>';
						rescanHtml += '<p style="font-size:12px;color:#92400e;margin:4px 0 8px">Nowe typy postów zostaną dodane do indeksu dopiero po ponownym skanie.</p>';
						rescanHtml += '<button type="button" class="button button-primary" id="loom-rescan-after-types" style="background:#f59e0b;border-color:#d97706">🔄 Skanuj teraz</button>';
						rescanHtml += '</div>';
						$('#loom-settings-status').after(rescanHtml);
					}
				} else {
					$('#loom-settings-status').html('<span style="color:#dc2626">❌ ' + (res.data || 'Nieznany błąd') + '</span>');
				}
			},
			error: function(xhr, status, err) {
				btn.prop('disabled', false).text(btn.data('orig-text') || 'Zapisz ustawienia');
				$('#loom-settings-status').html('<span style="color:#dc2626">❌ AJAX error: ' + status + ' — ' + err + ' (HTTP ' + xhr.status + ')</span>');
			}
		});
	});

	$(document).on('click', '#loom-rescan-after-types', function() {
		var btn = $(this);
		btn.prop('disabled', true).text('⏳ Przekierowuję...');
		window.location.href = loom_ajax.adminurl + 'admin.php?page=loom&auto_scan=1';
	});

	// Auto-scan on dashboard if redirected from settings.
	if (window.location.search.indexOf('auto_scan=1') >= 0) {
		setTimeout(function() {
			var scanBtn = $('#loom-start-scan');
			if (scanBtn.length) scanBtn.trigger('click');
		}, 500);
	}

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
	   LINK MAP  -  Multi-view graph (Rings / Table / Bubble / Keywords / Anchors)
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
			if (currentView === 'bubble') renderBubble();
			if (currentView === 'keywords') renderKeywords();
			if (currentView === 'anchors') renderAnchors();
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

				// Pulse ring on selected node.
				if (isSelected && pulsePhase) {
					var pr = n.radius + 8 + Math.sin(pulsePhase) * 5;
					ctx.beginPath();
					ctx.arc(n.gx, n.gy, pr, 0, Math.PI * 2);
					ctx.strokeStyle = style.stroke;
					ctx.lineWidth = 1.5;
					ctx.globalAlpha = 0.3 + Math.sin(pulsePhase) * 0.2;
					ctx.stroke();
					ctx.globalAlpha = dimmed ? 0.12 : 1;
				}

				// Label.
				if ((n.radius > 7 || isSelected || isHover) && !dimmed) {
					ctx.font = (isSelected || isHover ? 'bold ' : '') + '9px -apple-system, sans-serif';
					ctx.fillStyle = '#1e293b'; ctx.textAlign = 'center';
					var lbl = n.label.length > 20 ? n.label.substring(0, 18) + '…' : n.label;
					ctx.fillText(lbl, n.gx, n.gy + n.radius + 12);
				}
				ctx.globalAlpha = 1;
			});

			// Hover tooltip (richer than label).
			if (hoveredNode && (!selectedNode || hoveredNode.id !== selectedNode)) {
				var hn = hoveredNode;
				var tw = 190, th = 68;
				var tx = Math.min(hn.gx + 18, W - tw - 10);
				var ty = Math.max(hn.gy - th - 10, 10);

				ctx.fillStyle = 'rgba(255,255,255,.97)';
				ctx.strokeStyle = '#e5e7eb'; ctx.lineWidth = 1;
				ctx.beginPath(); ctx.roundRect(tx, ty, tw, th, 8); ctx.fill(); ctx.stroke();

				ctx.textAlign = 'left';
				ctx.font = 'bold 11px -apple-system, sans-serif'; ctx.fillStyle = '#0f172a';
				ctx.fillText(hn.label, tx + 10, ty + 17);
				ctx.font = '10px -apple-system, sans-serif'; ctx.fillStyle = '#64748b';
				ctx.fillText('IN: ' + hn['in'] + ' · OUT: ' + hn.out + ' · PR: ' + (hn.pr ? (hn.pr * 100).toFixed(1) : '—'), tx + 10, ty + 33);
				var flags = [];
				if (hn.orphan) flags.push('🔴 Orphan');
				if (hn.money) flags.push('⭐ Money');
				if (hn.striking) flags.push('🎯 Striking');
				if (hn.dead_end) flags.push('🟠 Dead End');
				ctx.fillText(flags.join(' ') || TIER_LABELS[Math.min(3, hn.tier || 3)], tx + 10, ty + 49);
				ctx.fillStyle = '#94a3b8'; ctx.font = '9px -apple-system, sans-serif';
				ctx.fillText('Kliknij = połączenia · Przeciągnij = przesuń', tx + 10, ty + 62);
			}

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

		// ─── Canvas mouse interaction — drag + click + hover ───
		var dragging = null, dragOffX = 0, dragOffY = 0, dragMoved = false;
		var pulsePhase = 0;
		var animFrame = null;

		// Pulse animation for selected node.
		function startPulse() {
			if (animFrame) return;
			(function tick() {
				pulsePhase = (pulsePhase + 0.06) % (Math.PI * 2);
				drawRings();
				if (selectedNode) animFrame = requestAnimationFrame(tick);
				else { animFrame = null; pulsePhase = 0; }
			})();
		}

		function findNodeAt(mx, my) {
			for (var i = graphNodes.length - 1; i >= 0; i--) {
				var n = graphNodes[i];
				var d = Math.sqrt((mx - n.gx) * (mx - n.gx) + (my - n.gy) * (my - n.gy));
				if (d < n.radius + 6) return n;
			}
			return null;
		}

		canvas.addEventListener('mousemove', function(e) {
			var rect = canvas.getBoundingClientRect();
			var mx = e.clientX - rect.left, my = e.clientY - rect.top;

			if (dragging) {
				dragMoved = true;
				dragging.gx = Math.max(dragging.radius + 5, Math.min(W - dragging.radius - 5, mx - dragOffX));
				dragging.gy = Math.max(dragging.radius + 5, Math.min(H - dragging.radius - 5, my - dragOffY));
				drawRings();
				return;
			}

			hoveredNode = findNodeAt(mx, my);
			canvas.style.cursor = hoveredNode ? 'pointer' : 'default';
			drawRings();
		});

		canvas.addEventListener('mousedown', function(e) {
			var rect = canvas.getBoundingClientRect();
			var mx = e.clientX - rect.left, my = e.clientY - rect.top;
			var node = findNodeAt(mx, my);
			if (node) {
				dragging = node;
				dragMoved = false;
				dragOffX = mx - node.gx;
				dragOffY = my - node.gy;
				canvas.style.cursor = 'grabbing';
			}
		});

		canvas.addEventListener('mouseup', function(e) {
			var rect = canvas.getBoundingClientRect();
			var mx = e.clientX - rect.left, my = e.clientY - rect.top;

			if (dragging) {
				var clicked = dragging;
				var wasDrag = dragMoved;
				dragging = null;
				canvas.style.cursor = 'pointer';

				if (!wasDrag) {
					// Click (no drag) — toggle selection.
					selectedNode = (clicked.id === selectedNode) ? null : clicked.id;
					if (selectedNode) startPulse();
				}
				drawRings();
				return;
			}

			// Click on empty area — deselect.
			var node = findNodeAt(mx, my);
			if (!node && selectedNode) {
				selectedNode = null;
				drawRings();
			}
		});

		// Click handled by mouseup — prevent default click from doing anything else.
		canvas.addEventListener('click', function(e) { e.preventDefault(); });

		canvas.addEventListener('mouseleave', function() {
			dragging = null; dragMoved = false;
			hoveredNode = null;
			drawRings();
		});

		// Click on empty area — deselect.
		canvas.addEventListener('dblclick', function(e) {
			var rect = canvas.getBoundingClientRect();
			var mx = e.clientX - rect.left, my = e.clientY - rect.top;
			if (!findNodeAt(mx, my)) {
				selectedNode = null;
				positionNodes();
				drawRings();
			}
		});

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
		//  VIEW 3: BUBBLE SCATTER (IN vs OUT vs PR)
		// ═══════════════════════════════════════════════
		function renderBubble() {
			var W = $('#loom-bubble-container').width() || 780, H = 440;
			var m = {t:30,r:30,b:50,l:55};
			var iW = W-m.l-m.r, iH = H-m.t-m.b;
			var maxIn = 1, maxOut = 1, maxPr = 0.001;
			graphNodes.forEach(function(n) {
				if (n['in'] > maxIn) maxIn = n['in'];
				if (n.out > maxOut) maxOut = n.out;
				if (n.pr > maxPr) maxPr = n.pr;
			});
			maxIn++; maxOut++;

			var xs = function(v) { return m.l + (v / maxIn) * iW; };
			var ys = function(v) { return H - m.b - (v / maxOut) * iH; };
			var rs = function(v) { return Math.max(5, Math.min(24, (v / maxPr) * 22)); };

			var svg = '<svg width="'+W+'" height="'+H+'" xmlns="http://www.w3.org/2000/svg">';

			// Danger zones
			svg += '<rect x="'+m.l+'" y="'+ys(1)+'" width="'+(xs(1)-m.l)+'" height="'+(ys(0)-ys(1))+'" fill="#fef2f2" opacity="0.5" rx="4"/>';
			svg += '<text x="'+(m.l+6)+'" y="'+ys(0.3)+'" font-size="9" fill="#ef4444" font-weight="600">Orphan + Dead End</text>';
			var hubX = xs(Math.max(6, maxIn*0.5));
			svg += '<rect x="'+hubX+'" y="'+m.t+'" width="'+(W-m.r-hubX)+'" height="'+(ys(Math.max(4,maxOut*0.3))-m.t)+'" fill="#f0fdf4" opacity="0.4" rx="4"/>';
			svg += '<text x="'+(W-m.r-6)+'" y="'+(m.t+14)+'" font-size="9" fill="#16a34a" font-weight="600" text-anchor="end">Zdrowe huby</text>';

			// Grid
			for (var gi = 0; gi <= maxIn; gi += Math.max(1, Math.round(maxIn/5))) {
				svg += '<line x1="'+xs(gi)+'" y1="'+m.t+'" x2="'+xs(gi)+'" y2="'+(H-m.b)+'" stroke="#f1f5f9"/>';
				svg += '<text x="'+xs(gi)+'" y="'+(H-m.b+16)+'" font-size="10" fill="#94a3b8" text-anchor="middle">'+gi+'</text>';
			}
			for (var gj = 0; gj <= maxOut; gj += Math.max(1, Math.round(maxOut/4))) {
				svg += '<line x1="'+m.l+'" y1="'+ys(gj)+'" x2="'+(W-m.r)+'" y2="'+ys(gj)+'" stroke="#f1f5f9"/>';
				svg += '<text x="'+(m.l-8)+'" y="'+(ys(gj)+4)+'" font-size="10" fill="#94a3b8" text-anchor="end">'+gj+'</text>';
			}
			svg += '<text x="'+(W/2)+'" y="'+(H-6)+'" font-size="11" fill="#64748b" text-anchor="middle" font-weight="600">← Linki IN (przychodzące) →</text>';
			svg += '<text x="14" y="'+(H/2)+'" font-size="11" fill="#64748b" text-anchor="middle" font-weight="600" transform="rotate(-90,14,'+(H/2)+')">← Linki OUT →</text>';

			// Bubbles
			graphNodes.forEach(function(n) {
				var cx = xs(n['in']), cy = ys(n.out), cr = rs(n.pr);
				var col = n.orphan ? '#ef4444' : n.money ? '#eab308' : n.striking ? '#a855f7' : TIER_COLORS[Math.min(3, n.tier||3)];
				svg += '<circle cx="'+cx+'" cy="'+cy+'" r="'+cr+'" fill="'+col+'25" stroke="'+col+'" stroke-width="1.5" class="loom-bub" data-id="'+n.id+'" style="cursor:pointer"/>';
				if (cr > 9) svg += '<text x="'+cx+'" y="'+(cy+cr+12)+'" font-size="9" fill="#374151" text-anchor="middle">'+(n.label.length>16?n.label.substring(0,14)+'…':n.label)+'</text>';
			});
			svg += '</svg>';

			// Legend
			svg += '<div style="display:flex;gap:12px;justify-content:center;margin-top:6px;font-size:9px;color:#94a3b8">';
			TIER_COLORS.forEach(function(c,i) { svg += '<span style="display:flex;align-items:center;gap:3px"><span style="width:7px;height:7px;border-radius:4px;background:'+c+'"></span>'+TIER_LABELS[i]+'</span>'; });
			svg += '<span>| Rozmiar = PageRank</span></div>';

			$('#loom-bubble-container').html(svg);

			// Hover
			$('#loom-bubble-container').on('mouseenter', '.loom-bub', function() {
				var id = parseInt($(this).data('id'));
				var n = nodeMap[id]; if (!n) return;
				$(this).attr('stroke-width', '3').attr('r', parseInt($(this).attr('r')) + 3);
				var tip = '<div style="position:fixed;background:white;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;font-size:11px;z-index:999;box-shadow:0 4px 12px rgba(0,0,0,.1);pointer-events:none" id="loom-bub-tip">';
				tip += '<div style="font-weight:700;font-size:12px">'+escHtml(n.label)+'</div>';
				tip += '<div style="color:#64748b">IN: '+n['in']+' · OUT: '+n.out+' · PR: '+(n.pr?(n.pr*100).toFixed(1):'—')+'</div>';
				var flags = [];
				if (n.orphan) flags.push('🔴 Orphan'); if (n.money) flags.push('⭐ Money');
				if (n.striking) flags.push('🎯 Striking'); if (n.dead_end) flags.push('⚫ Dead End');
				if (flags.length) tip += '<div style="margin-top:2px">'+flags.join(' ')+'</div>';
				tip += '</div>';
				$('body').append(tip);
			}).on('mousemove', '.loom-bub', function(e) {
				$('#loom-bub-tip').css({left: e.clientX+15, top: e.clientY-10});
			}).on('mouseleave', '.loom-bub', function() {
				$(this).attr('stroke-width', '1.5').attr('r', parseInt($(this).attr('r')) - 3);
				$('#loom-bub-tip').remove();
			});
		}

		// ═══════════════════════════════════════════════
		//  VIEW 4: KEYWORD GALAXY (GSC queries)
		// ═══════════════════════════════════════════════
		var kwSelectedPage = null;

		function renderKeywords() {
			var pagesWithQ = graphNodes.filter(function(n) { return n.queries && n.queries.length; });

			// Page list
			var html = '<div style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:6px">Filtruj stronę</div>';
			html += '<div class="loom-kw-page-item" data-pid="0" style="padding:5px 10px;cursor:pointer;border-radius:6px;font-size:10px;'+(kwSelectedPage===null?'font-weight:700;background:#f0fdfa;color:#0d9488':'color:#64748b')+'">Wszystkie</div>';
			pagesWithQ.forEach(function(p) {
				var sel = kwSelectedPage === p.id;
				html += '<div class="loom-kw-page-item" data-pid="'+p.id+'" style="padding:4px 10px;cursor:pointer;border-radius:6px;font-size:10px;border-left:3px solid '+(sel?'#0d9488':'transparent')+';'+(sel?'font-weight:700;background:#f0fdfa;color:#0d9488':'color:#64748b')+'">';
				html += (p.money?'⭐ ':'')+(p.label.length>22?p.label.substring(0,20)+'…':p.label);
				html += '<span style="float:right;color:#94a3b8">'+p.queries.length+'</span></div>';
			});
			$('#loom-kw-pages').html(html);

			// Gather queries
			var allQ = [];
			pagesWithQ.forEach(function(p) {
				(p.queries||[]).forEach(function(q) { allQ.push({q:q.query||q.q, clicks:q.clicks, impr:q.impressions||q.impr, pos:q.position||q.pos, pid:p.id, pName:p.label}); });
			});
			var filtered = kwSelectedPage ? allQ.filter(function(q){return q.pid===kwSelectedPage;}) : allQ;
			filtered.sort(function(a,b){return b.impr-a.impr;});
			var maxI = Math.max(1, filtered.length ? filtered[0].impr : 1);

			// Cloud
			var cloud = '';
			if (!filtered.length) {
				cloud = '<div style="color:#94a3b8;padding:40px;text-align:center">Brak danych GSC. Połącz Google Search Console i kliknij Sync.</div>';
			} else {
				filtered.forEach(function(q, i) {
					var sz = Math.max(10, Math.min(18, 8 + (q.impr/maxI)*10));
					var pc = q.pos<=3?'#16a34a':q.pos<=10?'#0d9488':q.pos<=20?'#7c3aed':'#94a3b8';
					var bg = q.pos<=3?'#dcfce7':q.pos<=10?'#f0fdfa':q.pos<=20?'#faf5ff':'#f9fafb';
					cloud += '<span class="loom-kw-tag" data-idx="'+i+'" style="display:inline-block;padding:'+(sz>13?'6px 12px':'4px 8px')+';background:'+bg+';border:1.5px solid '+bg+';border-radius:8px;cursor:pointer;font-size:'+sz+'px;font-weight:'+(sz>12?700:500)+';color:'+pc+';transition:all .15s">';
					cloud += escHtml(q.q) + '</span>';
				});
				cloud += '<div style="display:flex;gap:12px;justify-content:center;margin-top:12px;font-size:9px;color:#94a3b8">';
				cloud += '<span><span style="display:inline-block;width:7px;height:7px;border-radius:4px;background:#16a34a;margin-right:3px"></span>Top 3</span>';
				cloud += '<span><span style="display:inline-block;width:7px;height:7px;border-radius:4px;background:#0d9488;margin-right:3px"></span>Strona 1</span>';
				cloud += '<span><span style="display:inline-block;width:7px;height:7px;border-radius:4px;background:#7c3aed;margin-right:3px"></span>Striking</span>';
				cloud += '<span>Rozmiar = impressions</span></div>';
			}
			$('#loom-kw-cloud').html(cloud);

			// Store for tooltip
			$('#loom-kw-cloud').data('queries', filtered);
		}

		$(document).on('click', '.loom-kw-page-item', function() {
			var pid = parseInt($(this).data('pid'));
			kwSelectedPage = pid === 0 ? null : (kwSelectedPage === pid ? null : pid);
			renderKeywords();
		});

		$(document).on('mouseenter', '.loom-kw-tag', function(e) {
			var idx = parseInt($(this).data('idx'));
			var qs = $('#loom-kw-cloud').data('queries');
			var q = qs ? qs[idx] : null;
			if (!q) return;
			$(this).css({'border-color': $(this).css('color'), 'background': $(this).css('color') + '15'});
			var pc = q.pos<=3?'#16a34a':q.pos<=10?'#0d9488':q.pos<=20?'#7c3aed':'#94a3b8';
			var tip = '<div id="loom-kw-tip" style="position:fixed;background:white;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 12px;font-size:10px;z-index:999;box-shadow:0 4px 12px rgba(0,0,0,.1);pointer-events:none;white-space:nowrap">';
			tip += '<div style="font-weight:700;font-size:12px">'+escHtml(q.q)+'</div>';
			tip += '<div style="color:#64748b">Poz: <b style="color:'+pc+'">'+q.pos.toFixed(1)+'</b> · Impr: <b>'+q.impr.toLocaleString()+'</b> · Clicks: <b style="color:#0d9488">'+q.clicks+'</b></div>';
			if (!kwSelectedPage) tip += '<div style="color:#94a3b8;margin-top:2px">→ '+escHtml(q.pName.substring(0,30))+'</div>';
			tip += '<div style="color:#0d9488;margin-top:3px;font-weight:600">💡 Użyj jako anchor text w linkach</div></div>';
			$('body').append(tip);
			$('#loom-kw-tip').css({left: e.clientX+15, top: e.clientY-20});
		}).on('mousemove', '.loom-kw-tag', function(e) {
			$('#loom-kw-tip').css({left: e.clientX+15, top: e.clientY-20});
		}).on('mouseleave', '.loom-kw-tag', function() {
			var bg = $(this).css('color').replace(')', ',0.06)').replace('rgb', 'rgba');
			$(this).css({'border-color': 'transparent'});
			$('#loom-kw-tip').remove();
		});

		// ═══════════════════════════════════════════════
		//  VIEW 5: ANCHOR EXPLORER
		// ═══════════════════════════════════════════════
		var anchorSelectedPage = null;
		var anchorExpanded = null;
		var GENERIC = ['tutaj','kliknij','więcej','sprawdź','czytaj więcej','więcej informacji','kliknij tutaj','pomoc prawna','here','click','read more'];

		function classifyAnchor(a, pk) {
			var al = (a||'').toLowerCase().trim(), pkl = (pk||'').toLowerCase().trim();
			if (GENERIC.indexOf(al) >= 0) return 'generic';
			if (pkl && al === pkl) return 'exact';
			if (pkl && (al.indexOf(pkl) >= 0 || pkl.indexOf(al) >= 0)) return 'partial';
			return 'contextual';
		}
		var ATYPE = {
			exact: {label:'Exact',color:'#dc2626',bg:'#fee2e2',icon:'🎯'},
			partial: {label:'Partial',color:'#f59e0b',bg:'#fef3c7',icon:'🔶'},
			contextual: {label:'Context',color:'#0d9488',bg:'#f0fdfa',icon:'💬'},
			generic: {label:'Generic',color:'#94a3b8',bg:'#f8fafc',icon:'⚪'}
		};

		function renderAnchors() {
			// Pages that have incoming links
			var targets = [];
			var targetSet = {};
			graphEdges.forEach(function(e) {
				if (e.to && !targetSet[e.to]) { targetSet[e.to] = true; targets.push(e.to); }
			});
			targets.sort(function(a,b) {
				var na = nodeMap[a], nb = nodeMap[b];
				return (nb ? nb['in'] : 0) - (na ? na['in'] : 0);
			});

			if (!anchorSelectedPage && targets.length) anchorSelectedPage = targets[0];

			// Page list
			var html = '<div style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:6px">Strona docelowa</div>';
			targets.forEach(function(tid) {
				var n = nodeMap[tid]; if (!n) return;
				var cnt = graphEdges.filter(function(e){return e.to===tid;}).length;
				var sel = anchorSelectedPage === tid;
				html += '<div class="loom-anch-page" data-pid="'+tid+'" style="padding:5px 10px;cursor:pointer;border-radius:6px;font-size:10px;display:flex;justify-content:space-between;align-items:center;border-left:3px solid '+(sel?'#0d9488':'transparent')+';'+(sel?'font-weight:700;background:#f0fdfa;color:#0d9488':'color:#374151')+'">';
				html += '<span>'+(n.money?'⭐ ':'')+(n.label.length>20?n.label.substring(0,18)+'…':n.label)+'</span>';
				html += '<span style="font-family:monospace;font-size:9px;color:#0d9488;font-weight:700">'+cnt+'</span></div>';
			});
			$('#loom-anchor-pages').html(html);

			// Anchor detail
			if (!anchorSelectedPage || !nodeMap[anchorSelectedPage]) {
				$('#loom-anchor-detail').html('<div style="color:#94a3b8;text-align:center;padding-top:60px">← Wybierz stronę</div>');
				return;
			}

			var page = nodeMap[anchorSelectedPage];
			var linksTo = graphEdges.filter(function(e){return e.to===anchorSelectedPage;});
			var total = linksTo.length;
			var pk = page.primary_kw || '';

			// Group anchors
			var groups = {};
			linksTo.forEach(function(l) {
				var key = (l.anchor||'').toLowerCase();
				if (!key) key = '(brak tekstu)';
				if (!groups[key]) groups[key] = {anchor:l.anchor||'(brak)', count:0, sources:[], loomCt:0, type:classifyAnchor(l.anchor,pk)};
				groups[key].count++;
				groups[key].sources.push(l);
				if (l.loom) groups[key].loomCt++;
			});
			var anchorList = Object.values(groups).sort(function(a,b){return b.count-a.count;});

			// Distribution
			var dist = {exact:0,partial:0,contextual:0,generic:0};
			linksTo.forEach(function(l){dist[classifyAnchor(l.anchor,pk)]++;});

			// Health
			var health = 100;
			if (total > 0) {
				var ep = dist.exact/total, gp = dist.generic/total;
				if (ep>.3) health -= (ep-.3)*200;
				if (ep>.5) health -= 30;
				if (gp>.15) health -= (gp-.15)*150;
			}
			health = Math.max(0, Math.min(100, Math.round(health)));
			var hc = health>=70?'#16a34a':health>=40?'#f59e0b':'#dc2626';

			var det = '';
			// Header
			det += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
			det += '<div><div style="font-size:16px;font-weight:800">'+escHtml(page.label)+'</div>';
			det += '<div style="font-size:10px;color:#64748b">'+total+' linków · KW: <b style="color:#0d9488">'+(pk?escHtml(pk):'—')+'</b></div></div>';
			det += '<div style="text-align:center"><div style="font-size:28px;font-weight:900;color:'+hc+';font-family:monospace;line-height:1">'+health+'</div>';
			det += '<div style="font-size:8px;color:#94a3b8;font-weight:600">ANCHOR HEALTH</div></div></div>';

			// Distribution bar
			det += '<div style="display:flex;height:22px;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;margin-bottom:6px">';
			['exact','partial','contextual','generic'].forEach(function(type) {
				var count = dist[type]; if (!count) return;
				var pct = total ? (count/total)*100 : 0;
				var mt = ATYPE[type];
				det += '<div style="width:'+pct+'%;background:'+mt.bg+';display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:'+mt.color+';border-right:1px solid #e5e7eb" title="'+mt.label+': '+count+' ('+(Math.round(pct))+'%)">';
				if (pct >= 12) det += mt.icon+' '+Math.round(pct)+'%';
				det += '</div>';
			});
			det += '</div>';

			// Type legend
			det += '<div style="display:flex;gap:10px;margin-bottom:8px;font-size:9px">';
			['exact','partial','contextual','generic'].forEach(function(type) {
				var mt = ATYPE[type]; det += '<span style="color:'+mt.color+';font-weight:600">'+mt.icon+' '+mt.label+': '+dist[type]+'</span>';
			});
			det += '</div>';

			// Warnings
			if (total>2 && dist.exact/total>.3) det += '<div style="padding:5px 10px;background:#fef2f2;border-radius:8px;font-size:10px;color:#dc2626;font-weight:600;margin-bottom:6px">⚠️ Exact match '+Math.round(dist.exact/total*100)+'% — ryzyko over-optymalizacji!</div>';
			if (total>2 && dist.generic/total>.2) det += '<div style="padding:5px 10px;background:#fef3c7;border-radius:8px;font-size:10px;color:#92400e;font-weight:600;margin-bottom:6px">⚠️ Generic '+Math.round(dist.generic/total*100)+'% — zero wartości SEO</div>';
			if (health>=70 && total>=3) det += '<div style="padding:5px 10px;background:#dcfce7;border-radius:8px;font-size:10px;color:#16a34a;font-weight:600;margin-bottom:6px">✅ Profil anchorów wygląda naturalnie</div>';

			// Anchor list
			anchorList.forEach(function(ag, i) {
				var mt = ATYPE[ag.type];
				var pct = total ? Math.round((ag.count/total)*100) : 0;
				var isExp = anchorExpanded === i;

				det += '<div style="margin-bottom:3px">';
				det += '<div class="loom-anch-row" data-idx="'+i+'" style="display:flex;align-items:center;gap:6px;padding:6px 10px;background:'+(isExp?'#f0fdfa':'#fff')+';border:1.5px solid '+(isExp?'#0d9488':'#f1f5f9')+';border-radius:8px;cursor:pointer;font-size:11px">';
				det += '<span style="background:'+mt.bg+';color:'+mt.color+';padding:1px 6px;border-radius:5px;font-size:8px;font-weight:700;min-width:50px;text-align:center">'+mt.icon+' '+mt.label+'</span>';
				det += '<span style="flex:1;font-weight:600;color:#0f172a">"'+escHtml(ag.anchor)+'"</span>';
				det += '<div style="width:50px;height:5px;background:#f1f5f9;border-radius:3px;overflow:hidden"><div style="width:'+pct+'%;height:100%;background:'+(pct>=40?'#ef4444':mt.color)+';border-radius:3px"></div></div>';
				det += '<span style="font-size:11px;font-weight:800;color:'+(pct>=40?'#ef4444':'#374151')+';font-family:monospace;min-width:40px;text-align:right">'+ag.count+'× ('+pct+'%)</span>';
				if (ag.loomCt>0) det += '<span style="background:#ccfbf1;color:#115e59;padding:0 5px;border-radius:5px;font-size:8px;font-weight:700">'+ag.loomCt+'L</span>';
				det += '<span style="font-size:9px;color:#94a3b8">'+(isExp?'▼':'▶')+'</span>';
				det += '</div>';

				// Expanded sources
				if (isExp) {
					det += '<div style="margin-left:18px;margin-top:3px;margin-bottom:6px">';
					ag.sources.forEach(function(s) {
						var srcName = nodeMap[s.from] ? nodeMap[s.from].label : 'ID:'+s.from;
						var posLabel = s.pos==='top'?'⬆ Góra':s.pos==='middle'?'↔ Środek':'⬇ Dół';
						var posBg = s.pos==='top'?'#dcfce7':s.pos==='middle'?'#f0fdfa':'#fef3c7';
						det += '<div style="display:flex;align-items:center;gap:6px;padding:3px 8px;background:'+(s.loom?'#f0fdfa':'#f9fafb')+';border-radius:5px;margin-bottom:2px;font-size:10px">';
						det += '<span style="color:#94a3b8">←</span>';
						det += '<span style="flex:1;color:#374151">'+escHtml(srcName.substring(0,28))+'</span>';
						det += '<span style="font-size:8px;padding:1px 5px;border-radius:5px;background:'+posBg+'">'+posLabel+'</span>';
						if (s.loom) det += '<span style="background:#ccfbf1;color:#115e59;padding:0 4px;border-radius:5px;font-size:8px;font-weight:700">LOOM</span>';
						det += '</div>';
					});
					det += '</div>';
				}
				det += '</div>';
			});

			if (!total) det += '<div style="text-align:center;padding:30px;color:#ef4444;font-weight:700">🔴 Orphan — brak linków!</div>';

			$('#loom-anchor-detail').html(det);
		}

		$(document).on('click', '.loom-anch-page', function() {
			anchorSelectedPage = parseInt($(this).data('pid'));
			anchorExpanded = null;
			renderAnchors();
		});
		$(document).on('click', '.loom-anch-row', function() {
			var idx = parseInt($(this).data('idx'));
			anchorExpanded = (anchorExpanded === idx) ? null : idx;
			renderAnchors();
		});

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
	   STRUCTURAL PAGE TOGGLE (v2.4)
	   ======================================================================= */
	$(document).on('click', '.loom-structural-toggle', function() {
		var btn = $(this);
		var postId = btn.data('post-id');
		var current = parseInt(btn.data('is-structural')) === 1;
		var newState = current ? 0 : 1;

		$.post(loom_ajax.ajaxurl, {
			action: 'loom_set_structural',
			nonce: loom_ajax.nonce,
			post_id: postId,
			is_structural: newState
		}, function(res) {
			if (res.success) {
				btn.data('is-structural', newState);
				btn.text(newState ? '🏗️' : '·');
				btn.toggleClass('active', !!newState);
				btn.css('border-color', newState ? 'var(--teal)' : '#e5e7eb');
				var row = btn.closest('tr');
				row.css('opacity', newState ? '.6' : '1');
				// Reload page to update counts.
				if (newState) { setTimeout(function() { location.reload(); }, 500); }
			}
		});
	});

	/* =======================================================================
	   REVERSE ORPHAN RESCUE (v2.4)
	   ======================================================================= */
	$(document).on('click', '.loom-rescue-btn', function() {
		var btn = $(this);
		var postId = btn.data('post-id');
		btn.prop('disabled', true).text('⏳...');

		$.post(loom_ajax.ajaxurl, {
			action: 'loom_reverse_rescue',
			nonce: loom_ajax.nonce,
			post_id: postId
		}, function(res) {
			btn.prop('disabled', false).text('🔍 Rescue');
			if (!res.success) {
				alert(res.data || 'Error');
				return;
			}
			var d = res.data;
			var candidates = d.candidates || [];
			if (!candidates.length) {
				alert('Brak kandydatów — żaden artykuł nie jest wystarczająco powiązany semantycznie.');
				return;
			}

			// Build results popup.
			var html = '<div style="max-width:600px;max-height:400px;overflow-y:auto;padding:16px">';
			html += '<h3 style="margin:0 0 8px;font-size:14px">🔍 Reverse Rescue: ' + d.target_title + '</h3>';
			html += '<p style="font-size:11px;color:#64748b;margin:0 0 12px">Te artykuły mogą dodać link DO tej strony' + (d.adaptive ? ' (adaptacyjny próg: ' + d.threshold.toFixed(2) + ')' : '') + '</p>';

			candidates.forEach(function(c) {
				var linked = c.already_linked;
				html += '<div style="padding:8px 10px;background:' + (linked ? '#f9fafb' : '#f0fdfa') + ';border:1px solid ' + (linked ? '#e5e7eb' : '#99f6e4') + ';border-radius:8px;margin-bottom:4px;font-size:12px">';
				html += '<div style="display:flex;justify-content:space-between;align-items:center">';
				html += '<strong>' + c.source_title + '</strong>';
				html += '<span style="font-size:10px;color:' + (linked ? '#94a3b8' : '#0d9488') + ';font-weight:700">' + (linked ? '✅ Już linkuje' : 'sim: ' + c.similarity) + '</span>';
				html += '</div>';
				html += '<div style="font-size:10px;color:#94a3b8;margin-top:2px">OUT: ' + c.source_out + ' · PR: ' + (c.source_pr * 100).toFixed(1) + (c.source_is_money ? ' · ⭐ Money' : '') + '</div>';
				if (!linked) {
					html += '<div style="margin-top:4px"><a href="' + loom_ajax.adminurl + 'post.php?post=' + c.source_id + '&action=edit" target="_blank" class="loom-btn loom-btn-sm" style="font-size:10px">✏️ Otwórz & Podlinkuj</a></div>';
				}
				html += '</div>';
			});
			html += '</div>';

			// Show in a modal-like overlay.
			var $overlay = $('<div>').css({
				position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
				background: 'rgba(0,0,0,.4)', zIndex: 99999, display: 'flex',
				alignItems: 'center', justifyContent: 'center'
			}).appendTo('body');

			var $modal = $('<div>').css({
				background: 'white', borderRadius: 12, boxShadow: '0 20px 60px rgba(0,0,0,.2)',
				position: 'relative', maxWidth: '90vw'
			}).html(html + '<button class="loom-rescue-close" style="position:absolute;top:8px;right:12px;background:none;border:none;font-size:18px;cursor:pointer">✕</button>').appendTo($overlay);

			$overlay.on('click', function(e) { if (e.target === $overlay[0]) $overlay.remove(); });
			$modal.on('click', '.loom-rescue-close', function() { $overlay.remove(); });
		});
	});

	/* =======================================================================
	   DIAGNOSTICS (v2.4)
	   ======================================================================= */
	$(document).on('click', '#loom-run-diagnostics', function() {
		var btn = $(this);
		btn.prop('disabled', true).text('⏳ Analizowanie...');
		var $result = $('#loom-diagnostics-result');

		$.post(loom_ajax.ajaxurl, {
			action: 'loom_diagnostics',
			nonce: loom_ajax.nonce
		}, function(res) {
			btn.prop('disabled', false).text('🩺 Diagnostyka');
			if (!res.success) { $result.html('<p style="color:var(--bad)">Błąd: ' + (res.data || '') + '</p>').show(); return; }

			var d = res.data, c = d.counts, html = '';

			// Keyword cannibalization.
			if (c.kw_cannibal > 0) {
				html += '<div style="padding:8px 10px;background:#fef2f2;border-radius:8px;margin-bottom:6px"><strong style="color:#dc2626">🔴 Kanibalizacja keywords (' + c.kw_cannibal + ')</strong>';
				d.keyword_cannibalization.forEach(function(k) {
					html += '<div style="margin-top:4px;padding:4px 8px;background:white;border-radius:5px;font-size:10px">';
					html += '<strong>"' + k.query + '"</strong> — ';
					k.pages.forEach(function(p) { html += p.title + ' (poz: ' + p.position.toFixed(1) + '), '; });
					html += '</div>';
				});
				html += '</div>';
			}

			// Anchor cannibalization.
			if (c.anchor_cannibal > 0) {
				html += '<div style="padding:8px 10px;background:#fef3c7;border-radius:8px;margin-bottom:6px"><strong style="color:#92400e">🟡 Kanibalizacja anchorów (' + c.anchor_cannibal + ')</strong>';
				d.anchor_cannibalization.slice(0, 5).forEach(function(a) {
					html += '<div style="margin-top:4px;font-size:10px">"' + a.anchor_text + '" → ' + a.target_count + ' stron: ' + a.target_titles + '</div>';
				});
				html += '</div>';
			}

			// Duplicates.
			if (c.duplicates > 0) {
				html += '<div style="padding:8px 10px;background:#f0fdfa;border-radius:8px;margin-bottom:6px"><strong style="color:#0d9488">🔗 Duplikaty linków (' + c.duplicates + ')</strong>';
				d.duplicate_links.slice(0, 5).forEach(function(dup) {
					html += '<div style="margin-top:4px;font-size:10px">' + dup.source_title + ' → ' + dup.target_title + ' (' + dup.link_count + '× anchory: ' + dup.anchors + ')</div>';
				});
				html += '</div>';
			}

			// Overlinked.
			if (c.overlinked > 0) {
				html += '<div style="padding:8px 10px;background:#fef2f2;border-radius:8px;margin-bottom:6px"><strong style="color:#dc2626">⚠️ Overlinked (' + c.overlinked + ')</strong>';
				d.overlinked_pages.slice(0, 5).forEach(function(p) {
					html += '<div style="margin-top:4px;font-size:10px">' + p.post_title + ' — ' + p.outgoing_links_count + ' OUT</div>';
				});
				html += '</div>';
			}

			if (!html) html = '<div style="padding:8px 10px;background:#dcfce7;border-radius:8px;color:#16a34a;font-weight:700">✅ Brak problemów! Struktura linkowania wygląda zdrowo.</div>';

			$result.html(html).show();
		});
	});

	/* =======================================================================
	   SILO INTEGRITY CHECK (v2.4)
	   ======================================================================= */
	$(document).on('click', '#loom-silo-check', function() {
		var btn = $(this);
		btn.prop('disabled', true).text('⏳...');
		var $result = $('#loom-silo-result');

		$.post(loom_ajax.ajaxurl, {
			action: 'loom_silo_check',
			nonce: loom_ajax.nonce
		}, function(res) {
			btn.prop('disabled', false).text('🏗️ Sprawdź silo');
			if (!res.success) { $result.html('<p style="color:var(--bad)">Błąd</p>').show(); return; }

			var d = res.data, html = '';
			if (!d.clusters.length) {
				html = '<div style="padding:8px;color:var(--muted)">Brak zdefiniowanych klastrów. Uruchom skan + graf.</div>';
			} else {
				html += '<div style="font-weight:700;margin-bottom:6px">Integralność silo: ' + (d.issues === 0 ? '<span style="color:#16a34a">✅ OK</span>' : '<span style="color:#dc2626">⚠️ ' + d.issues + ' brakujących linków</span>') + '</div>';
				d.clusters.forEach(function(cl) {
					var ok = cl.issues.length === 0;
					html += '<div style="padding:6px 10px;background:' + (ok ? '#dcfce7' : '#fef2f2') + ';border-radius:8px;margin-bottom:4px">';
					html += '<strong>' + cl.cluster_name + '</strong> (pillar: ' + cl.pillar + ', ' + cl.members + ' artykułów)';
					if (ok) { html += ' ✅'; }
					else {
						cl.issues.forEach(function(iss) {
							html += '<div style="font-size:10px;margin-top:2px;color:#dc2626">';
							html += iss.type === 'pillar_missing' ? '↗ Pillar nie linkuje do: ' + iss.to : '↙ Brak linka do pillara z: ' + iss.from;
							html += '</div>';
						});
					}
					html += '</div>';
				});
			}
			$result.html(html).show();
		});
	});

	/* =======================================================================
	   ORPHAN TREND CHART (v2.4)
	   ======================================================================= */
	var $trendChart = $('#loom-trend-chart');
	if ($trendChart.length) {
		var trendData = $trendChart.data('trend') || [];
		if (trendData.length >= 2) {
			var W = $trendChart.width() || 600, H = 140;
			var m = {t:10,r:20,b:24,l:30};
			var iW = W-m.l-m.r, iH = H-m.t-m.b;
			var maxV = 1;
			trendData.forEach(function(d) { if (d.orphans > maxV) maxV = d.orphans; if (d.near > maxV) maxV = d.near; });
			maxV = Math.max(maxV, 3);
			var xs = function(i) { return m.l + (i / (trendData.length - 1)) * iW; };
			var ys = function(v) { return H - m.b - (v / maxV) * iH; };

			var svg = '<svg width="'+W+'" height="'+H+'">';
			// Grid
			for (var g = 0; g <= maxV; g += Math.max(1, Math.ceil(maxV/4))) {
				svg += '<line x1="'+m.l+'" y1="'+ys(g)+'" x2="'+(W-m.r)+'" y2="'+ys(g)+'" stroke="#f1f5f9"/>';
				svg += '<text x="'+(m.l-6)+'" y="'+(ys(g)+3)+'" font-size="9" fill="#94a3b8" text-anchor="end">'+g+'</text>';
			}

			// Near-orphan line (yellow)
			var nearPath = 'M';
			trendData.forEach(function(d,i) { nearPath += (i?'L':'') + xs(i) + ',' + ys(d.near); });
			svg += '<path d="'+nearPath+'" fill="none" stroke="#f59e0b" stroke-width="2" opacity="0.6"/>';

			// Orphan line (red)
			var orphPath = 'M';
			trendData.forEach(function(d,i) { orphPath += (i?'L':'') + xs(i) + ',' + ys(d.orphans); });
			svg += '<path d="'+orphPath+'" fill="none" stroke="#dc2626" stroke-width="2.5"/>';

			// Dots + labels
			trendData.forEach(function(d,i) {
				svg += '<circle cx="'+xs(i)+'" cy="'+ys(d.orphans)+'" r="3" fill="#dc2626"/>';
				svg += '<circle cx="'+xs(i)+'" cy="'+ys(d.near)+'" r="2.5" fill="#f59e0b"/>';
				if (i === 0 || i === trendData.length-1 || i % Math.max(1,Math.floor(trendData.length/5)) === 0) {
					svg += '<text x="'+xs(i)+'" y="'+(H-6)+'" font-size="8" fill="#94a3b8" text-anchor="middle">'+d.date.substring(5)+'</text>';
				}
			});

			// End values
			var last = trendData[trendData.length-1];
			svg += '<text x="'+(W-m.r+4)+'" y="'+(ys(last.orphans)+3)+'" font-size="10" fill="#dc2626" font-weight="700">'+last.orphans+'</text>';
			svg += '<text x="'+(W-m.r+4)+'" y="'+(ys(last.near)+3)+'" font-size="10" fill="#f59e0b" font-weight="700">'+last.near+'</text>';

			svg += '</svg>';
			svg += '<div style="display:flex;gap:14px;font-size:9px;color:#94a3b8;margin-top:2px"><span style="color:#dc2626">● Orphany</span><span style="color:#f59e0b">● Near-orphany</span></div>';
			$trendChart.html(svg);
		}
	}

	/* =======================================================================
	   BROKEN LINKS
	   ======================================================================= */
	var $brokenList = $('#loom-broken-list');
	if ($brokenList.length) {
		$.post(loom_ajax.ajaxurl, {
			action: 'loom_get_broken_links',
			nonce: loom_ajax.nonce
		}, function(res) {
			if (!res.success || !res.data.length) {
				$brokenList.html('<p class="loom-muted">✅ Brak broken linków.</p>');
				return;
			}
			var html = '<table class="loom-tbl"><thead><tr>';
			html += '<th>Źródło</th><th>Anchor text</th><th>Broken URL</th><th style="text-align:center">Typ</th><th style="text-align:center">Akcja</th>';
			html += '</tr></thead><tbody>';

			res.data.forEach(function(b) {
				var loomBadge = b.loom ? '<span style="background:#ccfbf1;color:#115e59;padding:1px 6px;border-radius:10px;font-size:9px">LOOM</span>' : '';
				var shortUrl = b.target_url ? (b.target_url.length > 40 ? b.target_url.substring(0, 38) + '…' : b.target_url) : '<em style="color:#94a3b8">brak URL</em>';

				html += '<tr data-link-id="' + b.id + '">';
				html += '<td><a href="' + escHtml(b.edit_url || '#') + '" class="loom-link" target="_blank">' + escHtml(b.source_title) + '</a></td>';
				html += '<td><code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px">' + escHtml(b.anchor) + '</code></td>';
				html += '<td style="font-size:11px;color:#ef4444;word-break:break-all" title="' + escHtml(b.target_url) + '">' + shortUrl + '</td>';
				html += '<td style="text-align:center">' + loomBadge + '</td>';
				html += '<td style="text-align:center;white-space:nowrap">';
				html += '<button class="loom-btn loom-btn-sm loom-fix-broken" data-lid="' + b.id + '" data-fix="remove" title="Usuń tag &lt;a&gt;, zachowaj tekst" style="margin-right:4px">🗑️ Usuń</button>';
				html += '<button class="loom-btn loom-btn-sm loom-btn-outline loom-fix-broken-replace" data-lid="' + b.id + '" title="Zamień URL na inny">🔄 Zamień</button>';
				html += '</td></tr>';
			});
			html += '</tbody></table>';
			$brokenList.html(html);
		});
	}

	// Remove broken link.
	$(document).on('click', '.loom-fix-broken', function() {
		var $btn = $(this);
		var lid = $btn.data('lid');
		$btn.prop('disabled', true).text('⏳');

		$.post(loom_ajax.ajaxurl, {
			action: 'loom_fix_broken_link',
			nonce: loom_ajax.nonce,
			link_id: lid,
			fix_type: 'remove'
		}, function(res) {
			if (res.success) {
				$btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
			} else {
				$btn.prop('disabled', false).text('🗑️ Usuń');
				alert(res.data || 'Error');
			}
		}).fail(function() {
			$btn.prop('disabled', false).text('🗑️ Usuń');
		});
	});

	// Replace broken link URL — prompt for new URL.
	$(document).on('click', '.loom-fix-broken-replace', function() {
		var $btn = $(this);
		var lid = $btn.data('lid');
		var newUrl = prompt('Podaj nowy URL:');
		if (!newUrl) return;

		$btn.prop('disabled', true).text('⏳');

		$.post(loom_ajax.ajaxurl, {
			action: 'loom_fix_broken_link',
			nonce: loom_ajax.nonce,
			link_id: lid,
			fix_type: 'replace',
			new_url: newUrl
		}, function(res) {
			if (res.success) {
				$btn.closest('tr').css('background', '#f0fdfa').find('td:eq(2)').html('<span style="color:var(--ok)">' + escHtml(newUrl) + '</span>');
				$btn.text('✅').prop('disabled', true);
			} else {
				$btn.prop('disabled', false).text('🔄 Zamień');
				alert(res.data || 'Error');
			}
		}).fail(function() {
			$btn.prop('disabled', false).text('🔄 Zamień');
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
			if (name) {
				var val = $(this).val();
				if ($(this).attr('type') === 'number') val = String(val).replace(',', '.');
				data[name.replace('loom_', '')] = val;
			}
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
