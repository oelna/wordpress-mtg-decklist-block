<?php
/**
 * Plugin Name: MTG Decklist Block
 * Description: Gutenberg block that turns Magic: The Gathering decklists into a formatted, linked table with grouping, Scryfall enrichment, and clipboard copy.
 * Version: 1.3.3
 * Update URI: https://github.com/oelna/wordpress-mtg-decklist-block
 * Author: Arno Richter
 * License: MIT
 */

if (!defined('ABSPATH')) {
	exit;
}

function mtgdl_register_block() {
	wp_register_script(
		'mtgdl-block-editor',
		plugins_url('block.js', __FILE__),
		array('wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components'),
		'1.2.0',
		true
	);

	wp_register_style(
		'mtgdl-frontend',
		plugins_url('style.css', __FILE__),
		array(),
		'1.2.0'
	);

	wp_register_style(
		'mtgdl-editor',
		plugins_url('editor.css', __FILE__),
		array('wp-edit-blocks'),
		'1.2.0'
	);

	wp_register_script(
		'mtgdl-frontend-js',
		plugins_url('frontend.js', __FILE__),
		array(),
		'1.2.0',
		true
	);

	register_block_type('mtg/decklist', array(
		'api_version' => 2,
		'editor_script' => 'mtgdl-block-editor',
		'editor_style' => 'mtgdl-editor',
		'style' => 'mtgdl-frontend',
		'script' => 'mtgdl-frontend-js',
		'attributes' => array(
			'content' => array(
				'type' => 'string',
				'default' => '',
			),
			'instanceId' => array(
				'type' => 'string',
				'default' => '',
			),
			'styleVariant' => array(
				'type' => 'string',
				'default' => 'A',
			),
			'grouping' => array(
				'type' => 'string',
				'default' => 'alpha',
			),
		),
		'supports' => array(
			'html' => false,
		),
		'render_callback' => 'mtgdl_render_block',
	));
}
add_action('init', 'mtgdl_register_block');

function mtgdl_starts_with($haystack, $needle) {
	return substr($haystack, 0, strlen($needle)) === $needle;
}

function mtgdl_clean_card_name($name) {
	$name = trim((string)$name);

	// MTG Arena often uses: "Card Name (SET) 123"
	$name = preg_replace('/\s+\([A-Z0-9]{3,6}\)\s+\d+$/', '', $name);

	// MTGO sometimes uses: "[SET] Card Name"
	$name = preg_replace('/^\[[A-Z0-9]{3,6}\]\s*/', '', $name);

	// Strip trailing collector-number-like fragments: "#123"
	$name = preg_replace('/\s+#\d+$/', '', $name);

	return trim($name);
}

function mtgdl_parse_decklist($raw) {
	$lines = preg_split("/\r\n|\n|\r/", (string)$raw);

	$sections = array(
		'main' => array(),
		'sideboard' => array(),
		'other' => array(),
	);

	$section = 'main';
	$seen_cards = false;
	$meta_mode = true;

	foreach ($lines as $line) {
		$line = trim((string)$line);

		if ($line === '') {
			if ($meta_mode && !$seen_cards) {
				$meta_mode = false;
			}
			continue;
		}

		$lower = strtolower($line);

		// Optional "About" header block:
		// About
		// Name XYZ
		// <empty line>
		if (!$seen_cards && $meta_mode) {
			if ($lower === 'about') {
				continue;
			}
			if (
				mtgdl_starts_with($lower, 'name ') ||
				mtgdl_starts_with($lower, 'format ') ||
				mtgdl_starts_with($lower, 'description ') ||
				mtgdl_starts_with($lower, 'author ')
			) {
				continue;
			}
		}

		// Section headers
		if (preg_match('/^(sideboard|sb)\s*:?\$/i', $line)) {
			$section = 'sideboard';
			$seen_cards = true;
			continue;
		}
		if (preg_match('/^(deck|maindeck|mainboard|main)\s*:?\$/i', $line)) {
			$section = 'main';
			$seen_cards = true;
			continue;
		}
		if (preg_match('/^(commander|companion|maybeboard|considering)\s*:?\$/i', $line)) {
			$section = 'other';
			$seen_cards = true;
			continue;
		}
		if (preg_match('/^([A-Z][A-Z\s]+):\s*$/', $line, $hm)) {
			$h = strtolower(trim($hm[1]));
			if ($h === 'sideboard') {
				$section = 'sideboard';
				$seen_cards = true;
				continue;
			}
			if ($h === 'main' || $h === 'mainboard' || $h === 'maindeck' || $h === 'deck') {
				$section = 'main';
				$seen_cards = true;
				continue;
			}
		}

		// Card lines:
		// "1 Card Name"
		// "2x Card Name"
		// "3\tCard Name"
		if (preg_match('/^(\d+)\s*(?:x\s*)?(.+)$/i', $line, $m)) {
			$qty = intval($m[1]);
			$name = mtgdl_clean_card_name($m[2]);

			if ($qty > 0 && $name !== '') {
				$sections[$section][] = array(
					'qty' => $qty,
					'name' => $name,
				);
				$seen_cards = true;
			}
		}
	}

	return $sections;
}

function mtgdl_scryfall_search_url($card_name) {
	$q = '!\"' . $card_name . '\"';
	return 'https://scryfall.com/search?q=' . rawurlencode($q);
}

function mtgdl_collect_blocks($blocks, &$out) {
	foreach ($blocks as $b) {
		if (!is_array($b)) {
			continue;
		}
		if (!empty($b['blockName']) && $b['blockName'] === 'mtg/decklist') {
			$out[] = $b;
		}
		if (!empty($b['innerBlocks']) && is_array($b['innerBlocks'])) {
			mtgdl_collect_blocks($b['innerBlocks'], $out);
		}
	}
}

function mtgdl_scryfall_collection_fetch($card_names) {
	$card_names = array_values(array_unique(array_filter(array_map('trim', (array)$card_names))));
	if (empty($card_names)) {
		return array();
	}

	$results = array(); // keyed by lowercase name
	$chunks = array_chunk($card_names, 75);

	foreach ($chunks as $chunk) {
		$identifiers = array();
		foreach ($chunk as $name) {
			$identifiers[] = array('name' => $name);
		}

		$args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
				'User-Agent' => 'MTGDecklistBlock/1.2; ' . home_url('/'),
			),
			'timeout' => 15,
			'body' => wp_json_encode(array('identifiers' => $identifiers)),
		);

		$resp = wp_remote_post('https://api.scryfall.com/cards/collection', $args);
		if (is_wp_error($resp)) {
			continue;
		}

		$code = wp_remote_retrieve_response_code($resp);
		$body = wp_remote_retrieve_body($resp);
		if ($code < 200 || $code >= 300 || empty($body)) {
			continue;
		}

		$json = json_decode($body, true);
		if (empty($json['data']) || !is_array($json['data'])) {
			continue;
		}

		foreach ($json['data'] as $card) {
			if (empty($card['name'])) {
				continue;
			}

			$key = mb_strtolower(trim($card['name']));

			$results[$key] = array(
				'name' => $card['name'],
				'scryfall_uri' => $card['scryfall_uri'] ?? '',
				'type_line' => $card['type_line'] ?? '',
				'rarity' => $card['rarity'] ?? '',
				'colors' => $card['colors'] ?? array(),
				'color_identity' => $card['color_identity'] ?? array(),
				'mana_cost' => $card['mana_cost'] ?? '',
				'cmc' => $card['cmc'] ?? null,
				'image_uris' => $card['image_uris'] ?? null,
				'card_faces' => $card['card_faces'] ?? null,
				'oracle_id' => $card['oracle_id'] ?? '',
				'id' => $card['id'] ?? '',
			);
		}

		usleep(110000);
	}

	return $results;
}

function mtgdl_update_resolved_meta_on_save($post_id, $post, $update) {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (wp_is_post_revision($post_id)) {
		return;
	}
	if (!is_object($post) || empty($post->post_content)) {
		delete_post_meta($post_id, '_mtgdl_resolved');
		return;
	}

	$blocks = parse_blocks($post->post_content);
	$deck_blocks = array();
	mtgdl_collect_blocks($blocks, $deck_blocks);

	if (empty($deck_blocks)) {
		delete_post_meta($post_id, '_mtgdl_resolved');
		return;
	}

	$old_meta = get_post_meta($post_id, '_mtgdl_resolved', true);
	if (!is_array($old_meta)) {
		$old_meta = array();
	}

	$new_meta = array();

	foreach ($deck_blocks as $b) {
		$attrs = $b['attrs'] ?? array();
		$instance_id = isset($attrs['instanceId']) ? sanitize_text_field($attrs['instanceId']) : '';
		$raw = isset($attrs['content']) ? (string)$attrs['content'] : '';

		if ($instance_id === '' || $raw === '') {
			continue;
		}

		$hash = md5($raw);

		if (isset($old_meta[$instance_id]) && is_array($old_meta[$instance_id]) && !empty($old_meta[$instance_id]['decklist_hash']) && $old_meta[$instance_id]['decklist_hash'] === $hash) {
			$new_meta[$instance_id] = $old_meta[$instance_id];
			continue;
		}

		$parsed = mtgdl_parse_decklist($raw);

		$names = array();
		foreach (array('main', 'sideboard', 'other') as $sec) {
			if (empty($parsed[$sec])) {
				continue;
			}
			foreach ($parsed[$sec] as $row) {
				if (!empty($row['name'])) {
					$names[] = (string)$row['name'];
				}
			}
		}

		$cards = mtgdl_scryfall_collection_fetch($names);

		$new_meta[$instance_id] = array(
			'fetched_at' => time(),
			'decklist_hash' => $hash,
			'cards' => $cards,
		);
	}

	update_post_meta($post_id, '_mtgdl_resolved', $new_meta);
}
add_action('save_post', 'mtgdl_update_resolved_meta_on_save', 10, 3);

function mtgdl_is_land_card($card) {
	if (!is_array($card)) {
		return false;
	}
	$type_line = isset($card['type_line']) ? (string)$card['type_line'] : '';
	if ($type_line === '') {
		return false;
	}
	return stripos($type_line, 'land') !== false;
}

function mtgdl_ci_to_string($ci) {
	if (!is_array($ci)) {
		$ci = array();
	}
	$ci = array_values(array_filter($ci));
	if (empty($ci)) {
		return 'C';
	}

	$order = array('W' => 1, 'U' => 2, 'B' => 3, 'R' => 4, 'G' => 5);
	usort($ci, function($a, $b) use ($order) {
		$aa = strtoupper((string)$a);
		$bb = strtoupper((string)$b);
		$ia = isset($order[$aa]) ? $order[$aa] : 99;
		$ib = isset($order[$bb]) ? $order[$bb] : 99;
		if ($ia === $ib) {
			return strcmp($aa, $bb);
		}
		return $ia < $ib ? -1 : 1;
	});

	return implode('', array_map(function($x){ return strtoupper((string)$x); }, $ci));
}

function mtgdl_mana_cost_html($mana_cost) {
	$mana_cost = (string)$mana_cost;
	if ($mana_cost === '') {
		return '';
	}

	preg_match_all('/\{([^}]+)\}/', $mana_cost, $m);
	if (empty($m[1])) {
		return '';
	}

	$tokens = $m[1];
	$numeric = 0;
	$parts = array();

	foreach ($tokens as $t) {
		$t = strtoupper(trim((string)$t));
		if ($t === '') {
			continue;
		}
		if (ctype_digit($t)) {
			$numeric += intval($t);
			continue;
		}

		$class_tok = strtolower(preg_replace('/[^A-Z0-9]+/', '-', $t));
		$parts[] = '<span class="mtgdl-mana-sym mtgdl-mana-' . esc_attr($class_tok) . '" data-sym="' . esc_attr($t) . '">' . esc_html($t) . '</span>';
	}

	$out = '<span class="mtgdl-mana">';
	if ($numeric > 0) {
		$out .= '<span class="mtgdl-mana-num" data-sym="' . esc_attr((string)$numeric) . '">' . esc_html((string)$numeric) . '</span>';
	}
	if (!empty($parts)) {
		$out .= implode('', $parts);
	}
	$out .= '</span>';

	return $out;
}

function mtgdl_color_group_label($color_identity) {
	$ci = is_array($color_identity) ? array_values(array_filter($color_identity)) : array();
	if (count($ci) === 0) {
		return 'Colorless';
	}
	if (count($ci) > 1) {
		return 'Multicolor';
	}
	$c = strtoupper((string)$ci[0]);
	if ($c === 'W') return 'White';
	if ($c === 'U') return 'Blue';
	if ($c === 'B') return 'Black';
	if ($c === 'R') return 'Red';
	if ($c === 'G') return 'Green';
	return 'Other';
}

function mtgdl_color_group_sort_index($label) {
	$order = array(
		'White' => 1,
		'Blue' => 2,
		'Black' => 3,
		'Red' => 4,
		'Green' => 5,
		'Multicolor' => 6,
		'Colorless' => 7,
		'Other' => 8,
	);
	return isset($order[$label]) ? intval($order[$label]) : 99;
}

function mtgdl_split_lands($rows, $resolved_cards) {
	$spells = array();
	$lands = array();

	foreach ($rows as $row) {
		$name = isset($row['name']) ? (string)$row['name'] : '';
		if ($name === '') {
			continue;
		}
		$key = mb_strtolower($name);
		$card = (is_array($resolved_cards) && isset($resolved_cards[$key]) && is_array($resolved_cards[$key])) ? $resolved_cards[$key] : null;

		if ($card && mtgdl_is_land_card($card)) {
			$lands[] = $row;
		} else {
			$spells[] = $row;
		}
	}

	return array($spells, $lands);
}

function mtgdl_sort_rows_alpha(&$rows) {
	usort($rows, function($a, $b) {
		$an = isset($a['name']) ? (string)$a['name'] : '';
		$bn = isset($b['name']) ? (string)$b['name'] : '';
		return strcasecmp($an, $bn);
	});
}

function mtgdl_sort_rows_mana(&$rows, $resolved_cards) {
	usort($rows, function($a, $b) use ($resolved_cards) {
		$an = isset($a['name']) ? (string)$a['name'] : '';
		$bn = isset($b['name']) ? (string)$b['name'] : '';

		$ak = mb_strtolower($an);
		$bk = mb_strtolower($bn);

		$ac = (is_array($resolved_cards) && isset($resolved_cards[$ak]) && is_array($resolved_cards[$ak]) && isset($resolved_cards[$ak]['cmc']) && is_numeric($resolved_cards[$ak]['cmc'])) ? floatval($resolved_cards[$ak]['cmc']) : 9999.0;
		$bc = (is_array($resolved_cards) && isset($resolved_cards[$bk]) && is_array($resolved_cards[$bk]) && isset($resolved_cards[$bk]['cmc']) && is_numeric($resolved_cards[$bk]['cmc'])) ? floatval($resolved_cards[$bk]['cmc']) : 9999.0;

		if ($ac < $bc) return -1;
		if ($ac > $bc) return 1;

		return strcasecmp($an, $bn);
	});
}

function mtgdl_group_rows_color($rows, $resolved_cards) {
	$groups = array();

	foreach ($rows as $row) {
		$name = isset($row['name']) ? (string)$row['name'] : '';
		if ($name === '') {
			continue;
		}

		$key = mb_strtolower($name);
		$card = (is_array($resolved_cards) && isset($resolved_cards[$key]) && is_array($resolved_cards[$key])) ? $resolved_cards[$key] : null;

		$ci = $card && isset($card['color_identity']) ? $card['color_identity'] : array();
		$label = mtgdl_color_group_label($ci);

		if (!isset($groups[$label])) {
			$groups[$label] = array();
		}
		$groups[$label][] = $row;
	}

	foreach ($groups as $label => $grows) {
		mtgdl_sort_rows_alpha($grows);
		$groups[$label] = $grows;
	}

	$labels = array_keys($groups);
	usort($labels, function($a, $b) {
		$ia = mtgdl_color_group_sort_index($a);
		$ib = mtgdl_color_group_sort_index($b);
		if ($ia === $ib) {
			return strcmp($a, $b);
		}
		return $ia < $ib ? -1 : 1;
	});

	$sorted = array();
	foreach ($labels as $label) {
		$sorted[$label] = $groups[$label];
	}

	return $sorted;
}

function mtgdl_render_table_header() {
	$out = '<thead><tr>';
	$out .= '<th class="mtgdl-th-qty">' . esc_html__('Amount', 'mtgdl') . '</th>';
	$out .= '<th class="mtgdl-th-ci">' . esc_html__('CI', 'mtgdl') . '</th>';
	$out .= '<th class="mtgdl-th-mana">' . esc_html__('Mana', 'mtgdl') . '</th>';
	$out .= '<th class="mtgdl-th-name">' . esc_html__('Card', 'mtgdl') . '</th>';
	$out .= '</tr></thead>';
	return $out;
}

function mtgdl_render_row($qty, $name, $card) {
	$key_ci = $card && isset($card['color_identity']) ? $card['color_identity'] : array();
	$ci = mtgdl_ci_to_string($key_ci);
	$mana_cost = $card && isset($card['mana_cost']) ? (string)$card['mana_cost'] : '';

	$url = $card && !empty($card['scryfall_uri']) ? (string)$card['scryfall_uri'] : mtgdl_scryfall_search_url($name);

	$out = '<tr>';
	$out .= '<td class="mtgdl-qty">' . esc_html((string)$qty) . '</td>';
	$out .= '<td class="mtgdl-ci"><span class="mtgdl-ci-badge" data-ci="' . esc_attr($ci) . '">' . esc_html($ci) . '</span></td>';
	$out .= '<td class="mtgdl-mana-cell">' . mtgdl_mana_cost_html($mana_cost) . '</td>';
	$out .= '<td class="mtgdl-name">';
	$out .= '<a class="mtgdl-card-link" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" data-card-name="' . esc_attr($name) . '">';
	$out .= esc_html($name);
	$out .= '</a>';
	$out .= '</td>';
	$out .= '</tr>';

	return $out;
}

function mtgdl_render_table_simple($rows, $resolved_cards) {
	if (empty($rows)) {
		return '';
	}

	$out = '<table class="mtgdl-table">';
	$out .= mtgdl_render_table_header();
	$out .= '<tbody>';

	foreach ($rows as $row) {
		$qty = isset($row['qty']) ? intval($row['qty']) : 0;
		$name = isset($row['name']) ? (string)$row['name'] : '';
		if ($qty <= 0 || $name === '') {
			continue;
		}

		$key = mb_strtolower($name);
		$card = (is_array($resolved_cards) && isset($resolved_cards[$key]) && is_array($resolved_cards[$key])) ? $resolved_cards[$key] : null;

		$out .= mtgdl_render_row($qty, $name, $card);
	}

	$out .= '</tbody></table>';
	return $out;
}

function mtgdl_render_table_color_grouped($rows, $resolved_cards) {
	if (empty($rows)) {
		return '';
	}

	$groups = mtgdl_group_rows_color($rows, $resolved_cards);

	$out = '<table class="mtgdl-table">';
	$out .= mtgdl_render_table_header();
	$out .= '<tbody>';

	foreach ($groups as $group_label => $grows) {
		$out .= '<tr class="mtgdl-group-row"><td colspan="4">' . esc_html($group_label) . '</td></tr>';
		foreach ($grows as $row) {
			$qty = isset($row['qty']) ? intval($row['qty']) : 0;
			$name = isset($row['name']) ? (string)$row['name'] : '';
			if ($qty <= 0 || $name === '') {
				continue;
			}
			$key = mb_strtolower($name);
			$card = (is_array($resolved_cards) && isset($resolved_cards[$key]) && is_array($resolved_cards[$key])) ? $resolved_cards[$key] : null;
			$out .= mtgdl_render_row($qty, $name, $card);
		}
	}

	$out .= '</tbody></table>';
	return $out;
}

function mtgdl_render_section($rows, $title, $grouping, $resolved_cards) {
	if (empty($rows)) {
		return '';
	}

	list($spells, $lands) = mtgdl_split_lands($rows, $resolved_cards);

	$out = '<section>';
	if ($title) {
		$out .= '<h3 class="mtgdl-section-title">' . esc_html($title) . '</h3>';
	}

	$grouping = in_array($grouping, array('alpha', 'mana', 'color'), true) ? $grouping : 'alpha';

	if (!empty($spells)) {
		if ($grouping === 'color') {
			$out .= mtgdl_render_table_color_grouped($spells, $resolved_cards);
		} elseif ($grouping === 'mana') {
			mtgdl_sort_rows_mana($spells, $resolved_cards);
			$out .= mtgdl_render_table_simple($spells, $resolved_cards);
		} else {
			mtgdl_sort_rows_alpha($spells);
			$out .= mtgdl_render_table_simple($spells, $resolved_cards);
		}
	}

	if (!empty($lands)) {
		mtgdl_sort_rows_alpha($lands);
		$out .= '<h4 class="mtgdl-subsection-title">' . esc_html__('Lands', 'mtgdl') . '</h4>';
		$out .= mtgdl_render_table_simple($lands, $resolved_cards);
	}

	return $out.'</section>';
}

function mtgdl_render_block($attributes, $content = '', $block = null) {
	$raw = '';
	if (is_array($attributes) && isset($attributes['content'])) {
		$raw = (string)$attributes['content'];
	}

	$instance_id = is_array($attributes) && isset($attributes['instanceId']) ? (string)$attributes['instanceId'] : '';
	$style_variant = is_array($attributes) && isset($attributes['styleVariant']) ? (string)$attributes['styleVariant'] : 'A';
	$grouping = is_array($attributes) && isset($attributes['grouping']) ? (string)$attributes['grouping'] : 'alpha';

	$post_id = 0;
	if ($block && isset($block->context['postId'])) {
		$post_id = intval($block->context['postId']);
	}
	if (!$post_id) {
		$post_id = get_the_ID();
	}

	$resolved_cards = array();
	if ($post_id && $instance_id) {
		$all = get_post_meta($post_id, '_mtgdl_resolved', true);
		if (is_array($all) && isset($all[$instance_id]) && is_array($all[$instance_id]) && isset($all[$instance_id]['cards']) && is_array($all[$instance_id]['cards'])) {
			$resolved_cards = $all[$instance_id]['cards'];
		}
	}

	$parsed = mtgdl_parse_decklist($raw);

	$sv = strtoupper($style_variant);
	if ($sv !== 'A' && $sv !== 'B' && $sv !== 'C') {
		$sv = 'A';
	}

	$grouping = in_array($grouping, array('alpha', 'mana', 'color'), true) ? $grouping : 'alpha';

	$classes = array('mtgdl', 'mtgdl--style-' . strtolower($sv), 'mtgdl--group-' . $grouping);

	$preload = wp_json_encode($resolved_cards);
	$source = $raw;

	$out = '<div class="' . esc_attr(implode(' ', $classes)) . '" data-mtgdl="1">';

	$out .= '<div class="mtgdl-grid">';
	$out .= mtgdl_render_section($parsed['main'], __('Mainboard', 'mtgdl'), $grouping, $resolved_cards);
	$out .= mtgdl_render_section($parsed['sideboard'], __('Sideboard', 'mtgdl'), $grouping, $resolved_cards);

	if (!empty($parsed['other'])) {
		$out .= mtgdl_render_section($parsed['other'], __('Other', 'mtgdl'), $grouping, $resolved_cards);
	}
	$out .= '</div>'; /* grid */

	$out .= '<div class="mtgdl-controls">';
	$out .= '<button type="button" class="mtgdl-copy" data-mtgdl-copy="1">' . esc_html__('Copy decklist', 'mtgdl') . '</button>';
	$out .= '<span class="mtgdl-copy-status" aria-live="polite" aria-atomic="true"></span>';
	$out .= '</div>';

	$out .= '<script type="application/json" class="mtgdl-preload">' . esc_html($preload ? $preload : '{}') . '</script>';
	$out .= '<textarea class="mtgdl-source" aria-hidden="true" tabindex="-1">' . esc_textarea($source) . '</textarea>';
	
	$out .= '</div>';

	return $out;
}

// Github auto-updates
add_filter('update_plugins_github.com', 'mtgdl_update_from_github', 10, 4);

function mtgdl_update_from_github($update, $plugin_data, $plugin_file, $locales) {
	$expected_update_uri = 'https://github.com/oelna/wordpress-mtg-decklist-block';
	$expected_plugin_file = plugin_basename(__FILE__);

	if (empty($plugin_data['UpdateURI']) || $plugin_data['UpdateURI'] !== $expected_update_uri) {
		return $update;
	}
	if ($plugin_file !== $expected_plugin_file) {
		return $update;
	}

	$release = mtgdl_github_latest_release_cached();
	if (!$release || empty($release['version']) || empty($release['package'])) {
		return $update;
	}

	$current = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';
	if (version_compare($release['version'], $current, '<=')) {
		return $update;
	}

	return array(
		'slug' => 'mtg-decklist-block',
		'version' => $release['version'],
		'url' => $expected_update_uri,
		'package' => $release['package'],
		// Optional fields
		// 'tested' => '6.5',
		// 'requires_php' => '7.4',
	);
}

function mtgdl_github_latest_release_cached() {
	$cache_key = 'mtgdl_gh_latest_release';
	$cached = get_site_transient($cache_key);
	if (is_array($cached)) {
		return $cached;
	}

	$repo_api = 'https://api.github.com/repos/oelna/wordpress-mtg-decklist-block/releases/latest';

	$args = array(
		'timeout' => 15,
		'headers' => array(
			'Accept' => 'application/vnd.github+json',
			'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
		),
	);

	$response = wp_remote_get($repo_api, $args);
	if (is_wp_error($response)) {
		set_site_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
		return null;
	}

	$code = wp_remote_retrieve_response_code($response);
	$body = wp_remote_retrieve_body($response);

	if ($code < 200 || $code >= 300 || empty($body)) {
		set_site_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
		return null;
	}

	$data = json_decode($body, true);
	if (!is_array($data)) {
		set_site_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
		return null;
	}

	// Tag should look like "1.2.3" or "v1.2.3"
	$tag = isset($data['tag_name']) ? (string)$data['tag_name'] : '';
	$version = ltrim($tag, "vV");

	// Pick a release asset that is a ready-to-install plugin ZIP
	$package = '';
	if (!empty($data['assets']) && is_array($data['assets'])) {
		foreach ($data['assets'] as $asset) {
			$name = isset($asset['name']) ? (string)$asset['name'] : '';
			$url = isset($asset['browser_download_url']) ? (string)$asset['browser_download_url'] : '';
			if ($url && preg_match('/\\.zip$/i', $name)) {
				// Optional: enforce a specific filename
				if ($name !== 'mtg-decklist-block.zip') { continue; }
				$package = $url;
				break;
			}
		}
	}

	$result = array(
		'version' => $version,
		'package' => $package,
	);

	// Cache for 6 hours to avoid GitHub rate limits
	set_site_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);

	return $result;
}
