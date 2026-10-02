<?php

/**
 * Plugin Name: LLM Posts
 * Description: A minimal custom post type that creates posts mainly intended for LLMs to read. Serves content as Markdown when requested (via the Accept: text/markdown header).
 * Version:     1.1.0
 * Requires at least: 5.7
 * Requires PHP: 7.1
 * Author:      Kristoffer Klintberg
 * License:     UNLICENSED
 */

if (!defined('ABSPATH')) {
	exit;
}

// Make sure we always use the plugin template instead of the theme template(s).
define('LLM_POST_FORCE_FALLBACK_TEMPLATE', true);

/* ------------------------------------------------------------------------
 * 1. Custom post type, activation, deactivation, uninstall
 * --------------------------------------------------------------------- */

/**
 * Register the custom post type "LLM Post".
 *
 * A named function so the activation hook can call it before `init` has run.
 */
function llm_post_register_cpt(): void
{
	register_post_type(
		'llm_post',
		array(
			'labels'       => array(
				'name'          => __('LLM Posts', 'llm-post'),
				'singular_name' => __('LLM Post', 'llm-post'),
			),
			'public'       => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'supports'     => array('title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'),
			'rewrite'      => array('slug' => 'llm-post'),
			'menu_icon'    => 'dashicons-format-chat',
		)
	);
}

add_action('init', 'llm_post_register_cpt');

/**
 * FIX: register the CPT before flushing, otherwise the flushed rules
 * do not contain /llm-post/.
 */
register_activation_hook(
	__FILE__,
	function () {
		llm_post_register_cpt();
		flush_rewrite_rules();
	}
);

/**
 * FIX: on deactivation the CPT is still registered, so a plain flush would
 * keep its rules. Unregister it first.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		unregister_post_type('llm_post');
		flush_rewrite_rules();
	}
);

/**
 * Remove the plugin's options on uninstall.
 */
function llm_post_uninstall(): void
{
	delete_option('llm_post_archive_title');
	delete_option('llm_post_header_content');
	delete_option('llm_post_footer_content');
}

register_uninstall_hook(__FILE__, 'llm_post_uninstall');

/* ------------------------------------------------------------------------
 * 2. Content negotiation
 * --------------------------------------------------------------------- */

/**
 * Post types that can be served as Markdown.
 *
 * @return string[]
 */
function llm_post_markdown_post_types(): array
{
	return (array) apply_filters(
		'llm_post_markdown_post_types',
		array('llm_post', 'post', 'page')
	);
}

/**
 * Page size used for the Markdown archive. Shared by the main query
 * (pre_get_posts) and the Markdown renderer so pagination, 404 handling
 * and the output all agree.
 */
function llm_post_markdown_per_page(): int
{
	$per_page = (int) apply_filters('llm_post_archive_markdown_per_page', 200);

	return $per_page > 0 ? $per_page : 200;
}

/**
 * Does the current request ask for Markdown?
 */
function llm_post_request_wants_markdown(): bool
{
	$accept = isset($_SERVER['HTTP_ACCEPT'])
		? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])), 0, 1024)
		: '';

	return llm_post_accepts_markdown($accept);
}

/**
 * Determine whether the Accept header asks for text/markdown in preference
 * to text/html.
 *
 * Wildcards such as `* / *` or `text/*` are intentionally NOT matched.
 * Quality values are honoured: `text/markdown;q=0` does not count, and
 * `text/html, text/markdown;q=0.1` prefers HTML. On a tie (equal q-values)
 * Markdown wins, since the client explicitly opted in.
 *
 * @param string $accept Accept header.
 * @return bool
 */
function llm_post_accepts_markdown(string $accept): bool
{
	if ('' === $accept) {
		return false;
	}

	$markdown_q = 0.0;
	$html_q     = 0.0;

	foreach (explode(',', $accept) as $media_type) {
		$parts = array_map('trim', explode(';', $media_type));
		$type  = strtolower($parts[0]);

		if ('text/markdown' !== $type && 'text/html' !== $type) {
			continue;
		}

		$q = 1.0;

		foreach (array_slice($parts, 1) as $parameter) {
			if (preg_match('/^q\s*=\s*([0-9]*\.?[0-9]+)$/i', $parameter, $matches)) {
				$q = (float) $matches[1];
			}
		}

		if ('text/markdown' === $type) {
			$markdown_q = max($markdown_q, $q);
		} else {
			$html_q = max($html_q, $q);
		}
	}

	return $markdown_q > 0 && $markdown_q >= $html_q;
}

/**
 * Make the main archive query use the same (large) page size for HTML and
 * Markdown, so the overview lists (nearly) all posts and `paged` / 404
 * handling stay consistent between the two representations.
 */
add_action(
	'pre_get_posts',
	function ($query) {
		if (is_admin() || !$query instanceof WP_Query || !$query->is_main_query()) {
			return;
		}

		if (!$query->is_post_type_archive('llm_post')) {
			return;
		}

		$query->set('posts_per_page', llm_post_markdown_per_page());
	}
);

/**
 * Tell page caches not to store this response.
 */
function llm_post_no_page_cache(): void
{
	if (!defined('DONOTCACHEPAGE')) {
		define('DONOTCACHEPAGE', true);
	}
}

/**
 * Send the common headers for a Markdown response.
 */
function llm_post_send_markdown_headers(): void
{
	llm_post_no_page_cache();

	header('Content-Type: text/markdown; charset=UTF-8');
	header('Content-Disposition: inline');
	header('X-Content-Type-Options: nosniff');
}

/**
 * Advertise the Markdown representation on HTML responses.
 */
function llm_post_send_alternate_link(string $url): void
{
	if ('' === $url) {
		return;
	}

	header(
		'Link: <' . esc_url_raw($url) . '>; rel="alternate"; type="text/markdown"',
		false
	);
}

/**
 * FIX: `Vary: Accept` used to be sent from `send_headers`, which runs
 * before the main query is parsed, so the conditional tags were always
 * false there. It is now sent from `template_redirect`, for both the HTML
 * and Markdown branches.
 */
add_action(
	'template_redirect',
	function () {
		$is_singular = is_singular(llm_post_markdown_post_types());
		$is_archive  = is_post_type_archive('llm_post');

		if (!$is_singular && !$is_archive) {
			return;
		}

		header('Vary: Accept', false);

		$wants_markdown = llm_post_request_wants_markdown();

		/*
		 * --- Singular: LLM post (or regular post / page) ---
		 */
		if ($is_singular) {
			$post = get_queried_object();

			if (!$post instanceof WP_Post) {
				return;
			}

			if (!is_post_publicly_viewable($post)) {
				return;
			}

			if (post_password_required($post)) {
				return;
			}

			if (!$wants_markdown) {
				llm_post_send_alternate_link((string) get_permalink($post));
				return;
			}

			if (!empty($post->post_password)) {
				nocache_headers();
			}

			llm_post_send_markdown_headers();

			echo llm_post_to_markdown($post); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		/*
		 * --- Archive: /llm-post/ ---
		 */
		if (!$wants_markdown) {
			llm_post_send_alternate_link((string) get_post_type_archive_link('llm_post'));
			return;
		}

		llm_post_send_markdown_headers();

		echo llm_post_archive_to_markdown(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
);

/* ------------------------------------------------------------------------
 * 3. Settings page
 * --------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'edit.php?post_type=llm_post',
			__('LLM Post Settings', 'llm-post'),
			__('Settings', 'llm-post'),
			'manage_options',
			'llm-post-settings',
			'llm_post_render_settings_page'
		);
	}
);

/**
 * Render (and save) the plugin's settings page.
 */
function llm_post_render_settings_page(): void
{
	if (!current_user_can('manage_options')) {
		return;
	}

	if (
		isset($_POST['llm_post_settings_nonce'])
		&& wp_verify_nonce(
			sanitize_text_field(wp_unslash($_POST['llm_post_settings_nonce'])),
			'llm_post_save_settings'
		)
	) {
		update_option(
			'llm_post_archive_title',
			isset($_POST['llm_post_archive_title'])
				? sanitize_text_field(wp_unslash($_POST['llm_post_archive_title']))
				: ''
		);
		update_option(
			'llm_post_header_content',
			isset($_POST['llm_post_header_content'])
				? wp_kses_post(wp_unslash($_POST['llm_post_header_content']))
				: ''
		);

		update_option(
			'llm_post_footer_content',
			isset($_POST['llm_post_footer_content'])
				? wp_kses_post(wp_unslash($_POST['llm_post_footer_content']))
				: ''
		);

		echo '<div class="notice notice-success"><p>'
			. esc_html__('Settings saved.', 'llm-post')
			. '</p></div>';
	}

	$archive_title = (string) get_option('llm_post_archive_title', '');
	$header        = (string) get_option('llm_post_header_content', '');
	$footer        = (string) get_option('llm_post_footer_content', '');

?>
	<div class="wrap">
		<h1><?php esc_html_e('LLM Post Settings', 'llm-post'); ?></h1>
		<p>
			<?php esc_html_e('This content is added to every LLM post page and to the archive page, in both the Markdown and HTML output.', 'llm-post'); ?>
		</p>
		<form method="post">
			<?php wp_nonce_field('llm_post_save_settings', 'llm_post_settings_nonce'); ?>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="llm_post_archive_title"><?php esc_html_e('Archive title', 'llm-post'); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="llm_post_archive_title"
							name="llm_post_archive_title"
							value="<?php echo esc_attr($archive_title); ?>"
							class="regular-text" />
						<p class="description">
							<?php esc_html_e('Title shown on the archive page (/llm-post/) in both the HTML and Markdown output. Leave blank to use the default post type label.', 'llm-post'); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="llm_post_header_content"><?php esc_html_e('Header content', 'llm-post'); ?></label>
					</th>
					<td>
						<textarea
							id="llm_post_header_content"
							name="llm_post_header_content"
							rows="6"
							class="large-text code"><?php echo esc_textarea($header); ?></textarea>
						<p class="description">
							<?php esc_html_e('Basic HTML allowed. Shown before the post content (or before the listing, on the archive page).', 'llm-post'); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="llm_post_footer_content"><?php esc_html_e('Footer content', 'llm-post'); ?></label>
					</th>
					<td>
						<textarea
							id="llm_post_footer_content"
							name="llm_post_footer_content"
							rows="6"
							class="large-text code"><?php echo esc_textarea($footer); ?></textarea>
						<p class="description">
							<?php esc_html_e('Basic HTML allowed. Shown after the post content (or after the listing, on the archive page).', 'llm-post'); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
<?php
}

/**
 * Return the configured archive title.
 *
 * Falls back to the post type label registered with the CPT when no
 * override is set on the options page.
 *
 * Uses the registered label directly (rather than calling
 * post_type_archive_title()) so the helper stays independent of the
 * filter below and cannot recurse into it.
 */
function llm_post_get_archive_title(): string
{
	$custom = trim((string) get_option('llm_post_archive_title', ''));

	if ('' !== $custom) {
		return $custom;
	}

	$post_type_obj = get_post_type_object('llm_post');

	return $post_type_obj instanceof WP_Post_Type
		? (string) $post_type_obj->labels->name
		: __('LLM Posts', 'llm-post');
}

/**
 * Apply the configured archive title everywhere WordPress renders it,
 * including theme templates that call the_archive_title().
 */
add_filter(
	'post_type_archive_title',
	function ($title, $post_type) {
		if ('llm_post' !== $post_type) {
			return $title;
		}

		$custom = trim((string) get_option('llm_post_archive_title', ''));

		return '' !== $custom ? $custom : $title;
	},
	10,
	2
);

/**
 * Get the configured common header/footer content as sanitized HTML.
 *
 * FIX: sanitized again on output (wp_kses_post), not only on save.
 *
 * @param string $which 'header' or 'footer'.
 * @return string
 */
function llm_post_get_common_html(string $which): string
{
	$option = 'header' === $which ? 'llm_post_header_content' : 'llm_post_footer_content';

	return trim(wp_kses_post((string) get_option($option, '')));
}

/**
 * Get the configured common header/footer content converted to Markdown,
 * reusing the same HTML -> Markdown pipeline as post content.
 *
 * @param string $which 'header' or 'footer'.
 * @return string
 */
function llm_post_get_common_markdown(string $which): string
{
	$html = llm_post_get_common_html($which);

	return '' === $html ? '' : trim(llm_post_html_to_markdown($html));
}

/**
 * Sanitize an LLM post identifier.
 *
 * Identifiers are intended for machine use, so keep them predictable and
 * limited to letters, numbers, dots, underscores and hyphens.
 *
 * @param string $identifier Identifier to sanitize.
 * @return string
 */
function llm_post_sanitize_identifier(string $identifier): string
{
	$identifier = strtolower(trim($identifier));
	$identifier = preg_replace('/[^a-z0-9._-]+/i', '_', $identifier);

	return trim(null === $identifier ? '' : $identifier, '._-');
}

/**
 * Return the stable identifier for an LLM post.
 *
 * A custom field named `llm_post_identifier` can be used when a specific
 * semantic identifier is required, e.g.:
 *
 *     segment.example.com.office_parking
 *
 * Otherwise the identifier is generated from:
 *
 *     llm.<site-host>.<post-slug>
 *
 * The prefix is filterable so a site can use a namespace such as `segment`
 * without the plugin having to assume that every LLM post is a segment.
 *
 * @param WP_Post $post Post object.
 * @return string
 */
function llm_post_get_identifier(WP_Post $post): string
{
	$custom = trim(
		(string) get_post_meta(
			$post->ID,
			'llm_post_identifier',
			true
		)
	);

	if ('' !== $custom) {
		$identifier = llm_post_sanitize_identifier($custom);

		if ('' !== $identifier) {
			return (string) apply_filters(
				'llm_post_identifier',
				$identifier,
				$post
			);
		}
	}

	$host = (string) wp_parse_url(
		home_url('/'),
		PHP_URL_HOST
	);
	$host = preg_replace('/^www\./i', '', $host);
	$host = llm_post_sanitize_identifier(
		null === $host ? '' : $host
	);

	$slug = trim((string) $post->post_name);

	if ('' === $slug) {
		$slug = sanitize_title(get_the_title($post));
	}

	$slug = llm_post_sanitize_identifier($slug);

	$prefix = (string) apply_filters(
		'llm_post_identifier_prefix',
		'llm',
		$post
	);
	$prefix = llm_post_sanitize_identifier($prefix);

	$parts = array();

	if ('' !== $prefix) {
		$parts[] = $prefix;
	}

	if ('' !== $host) {
		$parts[] = $host;
	}

	if ('' !== $slug) {
		$parts[] = $slug;
	}

	$identifier = implode('.', $parts);

	if ('' === $identifier) {
		$identifier = 'llm-post-' . (int) $post->ID;
	}

	return (string) apply_filters(
		'llm_post_identifier',
		$identifier,
		$post
	);
}

/**
 * Register the "Verified" custom field so it is available in the REST API
 * and the block editor's custom fields panel.
 */
add_action(
	'init',
	function () {
		register_post_meta(
			'llm_post',
			'llm_post_verified',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'llm_post_sanitize_verified_date',
				'auth_callback'     => function () {
					return current_user_can('edit_posts');
				},
			)
		);
	}
);

/**
 * Sanitize the "Verified" custom field. Accepts YYYY-MM or YYYY-MM-DD;
 * anything else is discarded (an empty value means "not set").
 *
 * @param mixed $value Raw value.
 * @return string
 */
function llm_post_sanitize_verified_date($value): string
{
	$value = trim((string) $value);

	return preg_match('/^\d{4}-(0[1-9]|1[0-2])(-(0[1-9]|[12]\d|3[01]))?$/', $value)
		? $value
		: '';
}

/**
 * "Verified" box in the post editor (works in the block editor and the
 * classic editor), so the date can be set without enabling the Custom
 * Fields panel.
 */
add_action(
	'add_meta_boxes_llm_post',
	function () {
		add_meta_box(
			'llm_post_verified_box',
			__('Verified', 'llm-post'),
			'llm_post_render_verified_box',
			'llm_post',
			'side',
			'default'
		);
	}
);

/**
 * Render the "Verified" box.
 *
 * @param WP_Post $post Post being edited.
 */
function llm_post_render_verified_box(WP_Post $post): void
{
	$value = llm_post_sanitize_verified_date(
		(string) get_post_meta($post->ID, 'llm_post_verified', true)
	);

	/* A month-only value (YYYY-MM) can't be shown in a date input. */
	if (7 === strlen($value)) {
		$value .= '-01';
	}

	wp_nonce_field('llm_post_save_verified', 'llm_post_verified_nonce');

?>
	<p>
		<label for="llm_post_verified_field">
			<?php esc_html_e('Date someone last checked that this content is still correct.', 'llm-post'); ?>
		</label>
	</p>
	<p>
		<input
			type="date"
			id="llm_post_verified_field"
			name="llm_post_verified_field"
			value="<?php echo esc_attr($value); ?>" />
		<button type="button" class="button" id="llm_post_verified_today">
			<?php esc_html_e('Today', 'llm-post'); ?>
		</button>
	</p>
	<p class="description">
		<?php esc_html_e('Leave empty to show the Updated date as Verified. Remember to click Update afterwards.', 'llm-post'); ?>
	</p>
	<script>
		(function() {
			var button = document.getElementById('llm_post_verified_today');
			var input = document.getElementById('llm_post_verified_field');

			if (!button || !input) {
				return;
			}

			button.addEventListener('click', function() {
				var d = new Date();
				var pad = function(n) {
					return (n < 10 ? '0' : '') + n;
				};

				input.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
				input.dispatchEvent(new Event('change', {
					bubbles: true
				}));
			});
		}());
	</script>
<?php
}

/**
 * Save the "Verified" box.
 *
 * @param int $post_id Post ID.
 */
add_action(
	'save_post_llm_post',
	function ($post_id) {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (
			!isset($_POST['llm_post_verified_nonce'])
			|| !wp_verify_nonce(
				sanitize_text_field(wp_unslash($_POST['llm_post_verified_nonce'])),
				'llm_post_save_verified'
			)
		) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		$value = isset($_POST['llm_post_verified_field'])
			? llm_post_sanitize_verified_date(
				sanitize_text_field(wp_unslash($_POST['llm_post_verified_field']))
			)
			: '';

		if ('' === $value) {
			delete_post_meta($post_id, 'llm_post_verified');
		} else {
			update_post_meta($post_id, 'llm_post_verified', $value);
		}
	}
);

/**
 * Return the "Updated" and "Verified" dates of an LLM post.
 *
 * - Updated:  the post's last modified date (automatic).
 * - Verified: the custom field `llm_post_verified` (YYYY-MM or
 *   YYYY-MM-DD), set when someone has actually checked that the content is
 *   still correct. If it is not set, it falls back to the Updated date, so
 *   every post carries both dates; use the `llm_post_verified_date` filter
 *   to change that (e.g. return '' to omit it when unset).
 *
 * Displayed dates use the format `Y-m` (e.g. 2026-09), filterable via
 * `llm_post_date_format`. The `iso` values are full dates (Y-m-d) for
 * machine-readable output.
 *
 * @param WP_Post $post Post object.
 * @return array<string, array{display: string, iso: string}>
 */
function llm_post_get_dates(WP_Post $post): array
{
	/* Only LLM posts carry Updated/Verified dates. */
	if ('llm_post' !== $post->post_type) {
		return array();
	}

	$format = (string) apply_filters('llm_post_date_format', 'Y-m', $post);

	$modified_iso = (string) mysql2date('Y-m-d', $post->post_modified, false);

	$verified_iso = llm_post_sanitize_verified_date(
		(string) get_post_meta($post->ID, 'llm_post_verified', true)
	);

	if ('' !== $verified_iso && 7 === strlen($verified_iso)) {
		/* YYYY-MM: the machine-readable value stays month-precise. */
		$verified_ts = strtotime($verified_iso . '-01 00:00:00 UTC');
	} elseif ('' !== $verified_iso) {
		$verified_ts = strtotime($verified_iso . ' 00:00:00 UTC');
	} else {
		$verified_ts = false;
	}

	$verified_display = false !== $verified_ts
		? gmdate($format, $verified_ts)
		: (string) mysql2date($format, $post->post_modified, false);

	if (false === $verified_ts) {
		$verified_iso = $modified_iso;
	}

	$verified_display = (string) apply_filters(
		'llm_post_verified_date',
		$verified_display,
		$post
	);

	$dates = array(
		'updated' => array(
			'display' => (string) mysql2date($format, $post->post_modified, false),
			'iso'     => $modified_iso,
		),
	);

	if ('' !== $verified_display) {
		$dates['verified'] = array(
			'display' => $verified_display,
			'iso'     => $verified_iso,
		);
	}

	return $dates;
}

/**
 * The dates as one plain-text line: "Updated: 2026-09 Verified: 2026-09".
 *
 * @param WP_Post $post Post object.
 * @return string
 */
function llm_post_get_dates_line(WP_Post $post): string
{
	$parts = array();

	foreach (llm_post_get_dates($post) as $key => $date) {
		$parts[] = ucfirst($key) . ': ' . $date['display'];
	}

	return implode(' ', $parts);
}

/**
 * Advertise the LLM post identifier and dates in the HTML <head>.
 *
 * This works even when the active theme supplies the single-post template.
 */
add_action(
	'wp_head',
	function () {
		if (!is_singular('llm_post')) {
			return;
		}

		$post = get_queried_object();

		if (!$post instanceof WP_Post) {
			return;
		}

		echo '<meta name="llm-post-id" content="'
			. esc_attr(llm_post_get_identifier($post))
			. '">' . "\n";

		foreach (llm_post_get_dates($post) as $key => $date) {
			echo '<meta name="llm-post-' . esc_attr($key) . '" content="'
				. esc_attr($date['iso'])
				. '">' . "\n";
		}
	},
	20
);

/**
 * Add the identifier and the Updated/Verified dates visibly to the rendered
 * LLM post content.
 *
 * This also makes them available to normal HTML/theme templates, while the
 * Markdown representation gets them separately (front matter + a line at
 * the top of the body).
 */
add_filter(
	'the_content',
	function ($content) {
		if (
			is_admin()
			|| !is_singular('llm_post')
			|| !in_the_loop()
			|| !is_main_query()
		) {
			return $content;
		}

		$post = get_queried_object();

		if (!$post instanceof WP_Post) {
			return $content;
		}

		$identifier = llm_post_get_identifier($post);

		$dates_html = array();

		foreach (llm_post_get_dates($post) as $key => $date) {
			$dates_html[] = esc_html(ucfirst($key)) . ': <time datetime="'
				. esc_attr($date['iso']) . '">'
				. esc_html($date['display'])
				. '</time>';
		}

		return '<p class="llm-post-identifier"><strong>ID:</strong> '
			. esc_html($identifier)
			. '</p>'
			. ('' !== implode('', $dates_html)
				? '<p class="llm-post-dates">' . implode(' ', $dates_html) . '</p>'
				: '')
			. $content;
	},
	5
);

/* ------------------------------------------------------------------------
 * 4. Markdown rendering
 * --------------------------------------------------------------------- */

/**
 * Convert an HTML string (title, excerpt, ...) to single-line plain text.
 *
 * Strips tags, decodes entities (e.g. &#8217; and &amp;) and collapses
 * whitespace, including newlines.
 */
function llm_post_plain_text(string $html): string
{
	$text = html_entity_decode(
		wp_strip_all_tags($html),
		ENT_QUOTES | ENT_HTML5,
		'UTF-8'
	);

	$collapsed = preg_replace('/\s+/u', ' ', $text);

	return trim(null === $collapsed ? $text : $collapsed);
}

/**
 * Build a cheap plain-text excerpt.
 *
 * get_the_excerpt() runs the whole `the_content` pipeline for every post
 * without a manual excerpt, which is expensive on a 200-item archive. This
 * works on the raw content instead. Password-protected posts yield nothing.
 */
function llm_post_excerpt(WP_Post $post): string
{
	if (!empty($post->post_password)) {
		return '';
	}

	if ('' !== trim($post->post_excerpt)) {
		return llm_post_plain_text($post->post_excerpt);
	}

	$text = llm_post_plain_text(strip_shortcodes($post->post_content));

	return wp_trim_words(
		$text,
		(int) apply_filters('excerpt_length', 55),
		'…'
	);
}

/**
 * Get/set the base URL used to resolve relative links and images.
 *
 * @param string|null $set New base URL, or null to just read.
 * @return string
 */
function llm_post_base_url(?string $set = null): string
{
	static $base = '';

	if (null !== $set) {
		$base = $set;
	}

	return '' !== $base ? $base : home_url('/');
}

/**
 * Convert a post into Markdown.
 *
 * @param WP_Post $post Post object.
 * @return string
 */
function llm_post_to_markdown(WP_Post $post): string
{
	llm_post_base_url((string) get_permalink($post));

	$front_matter  = "---\n";
	$front_matter .= 'id: ' . llm_post_yaml_scalar(llm_post_get_identifier($post)) . "\n";
	$front_matter .= 'title: ' . llm_post_yaml_scalar(llm_post_plain_text(get_the_title($post))) . "\n";
	$front_matter .= 'date: ' . llm_post_yaml_scalar(get_the_date('c', $post)) . "\n";

	foreach (llm_post_get_dates($post) as $key => $date) {
		$front_matter .= $key . ': ' . llm_post_yaml_scalar($date['display']) . "\n";
	}

	$front_matter .= 'author: ' . llm_post_yaml_scalar(
		get_the_author_meta('display_name', $post->post_author)
	) . "\n";
	$front_matter .= 'permalink: ' . llm_post_yaml_scalar(get_permalink($post)) . "\n";
	$front_matter .= "---\n\n";

	$html = apply_filters('the_content', $post->post_content);
	$html = wp_kses_post($html);

	$markdown = llm_post_html_to_markdown($html);

	/*
	 * Collapse the extra blank lines that adjacent block emitters produce,
	 * without touching the inside of fenced code blocks.
	 */
	$markdown = trim(llm_post_collapse_blank_lines($markdown));

	/* "Updated: 2026-09 Verified: 2026-09" as a visible line before the content. */
	$dates_line = llm_post_get_dates_line($post);

	if ('' !== $dates_line) {
		$markdown = $dates_line . "\n\n" . $markdown;
	}

	/*
	 * FIX: the site-wide header/footer are meant for LLM posts only.
	 */
	if ('llm_post' === $post->post_type) {
		$header = llm_post_get_common_markdown('header');
		$footer = llm_post_get_common_markdown('footer');

		if ('' !== $header) {
			$markdown = $header . "\n\n" . $markdown;
		}

		if ('' !== $footer) {
			$markdown = $markdown . "\n\n" . $footer;
		}
	}

	return $front_matter . $markdown . "\n";
}

/**
 * Collapse runs of blank lines to a single blank line, except inside
 * fenced code blocks (where blank lines are significant).
 *
 * @param string $markdown Markdown source.
 * @return string
 */
function llm_post_collapse_blank_lines(string $markdown): string
{
	$out   = array();
	$fence = '';
	$blank = 0;

	foreach (explode("\n", $markdown) as $line) {
		if (preg_match('/^[ >]*(`{3,}|~{3,})/', $line, $matches)) {
			if ('' === $fence) {
				$fence = $matches[1];
			} elseif (
				$matches[1][0] === $fence[0]
				&& strlen($matches[1]) >= strlen($fence)
			) {
				$fence = '';
			}
		}

		if ('' === $fence && '' === trim($line)) {
			$blank++;

			if ($blank > 1) {
				continue;
			}
		} else {
			$blank = 0;
		}

		$out[] = $line;
	}

	return implode("\n", $out);
}

/**
 * Convert an HTML fragment to Markdown using DOMDocument.
 *
 * @param string $html Sanitized HTML fragment.
 * @return string
 */
function llm_post_html_to_markdown(string $html): string
{
	if ('' === trim($html)) {
		return '';
	}

	/* Fail closed when the DOM extension is unavailable. */
	if (!class_exists('DOMDocument')) {
		return '';
	}

	$previous_use_errors = libxml_use_internal_errors(true);

	$dom = new DOMDocument('1.0', 'UTF-8');

	$document = '<?xml encoding="UTF-8"><div id="llm-post-root">'
		. $html
		. '</div>';

	$loaded = $dom->loadHTML(
		$document,
		LIBXML_HTML_NOIMPLIED |
			LIBXML_HTML_NODEFDTD |
			LIBXML_NONET
	);

	libxml_clear_errors();
	libxml_use_internal_errors($previous_use_errors);

	if (!$loaded) {
		return '';
	}

	/* XPath rather than getElementById(), which depends on ID registration. */
	$xpath = new DOMXPath($dom);
	$nodes = $xpath->query('//*[@id="llm-post-root"]');

	if (!$nodes || 0 === $nodes->length) {
		return '';
	}

	$root = $nodes->item(0);

	if (!$root instanceof DOMElement) {
		return '';
	}

	return llm_post_walk_children($root);
}

/**
 * Walk all child nodes of a DOM node.
 *
 * @param DOMNode $node Parent node.
 * @return string
 */
function llm_post_walk_children(DOMNode $node): string
{
	$output = '';

	foreach ($node->childNodes as $child) {
		$output .= llm_post_walk_node($child);
	}

	return $output;
}

/**
 * Wrap inline content in an emphasis marker, keeping surrounding
 * whitespace outside the markers (`**bar** baz`, not `**bar**baz`).
 *
 * @param string $content Inner Markdown.
 * @param string $marker  `**` or `*`.
 * @return string
 */
function llm_post_wrap_inline(string $content, string $marker): string
{
	if (!preg_match('/^(\s*)(.*?)(\s*)$/s', $content, $matches)) {
		return $content;
	}

	if ('' === $matches[2]) {
		return $matches[1] . $matches[3];
	}

	return $matches[1] . $marker . $matches[2] . $marker . $matches[3];
}

/**
 * Convert one DOM node to Markdown.
 *
 * @param DOMNode $node Node to convert.
 * @return string
 */
function llm_post_walk_node(DOMNode $node): string
{
	switch ($node->nodeType) {
		case XML_TEXT_NODE:
			return llm_post_markdown_text($node->nodeValue);

		case XML_ELEMENT_NODE:
			break;

		default:
			return '';
	}

	$tag = strtolower($node->nodeName);

	switch ($tag) {

		/*
		 * FIX: wp_kses_post strips these tags but keeps their text, so
		 * drop the content here.
		 */
		case 'script':
		case 'style':
		case 'noscript':
		case 'template':
			return '';

			/* Transparent block containers. */
		case 'div':
		case 'main':
		case 'section':
		case 'article':
		case 'header':
		case 'footer':
		case 'figure':
		case 'figcaption':
		case 'aside':
		case 'nav':
		case 'address':
		case 'hgroup':
		case 'details':
		case 'dl':
		case 'dd':
			return llm_post_block(llm_post_walk_children($node));

			/* Summary / definition term: a bold line of its own. */
		case 'summary':
		case 'dt':
			$content = trim(llm_post_walk_children($node));

			return '' === $content ? '' : "\n\n**" . $content . "**\n\n";

			/* Headings. */
		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
		case 'h5':
		case 'h6':
			$level = (int) substr($tag, 1);
			$text  = trim(llm_post_walk_children($node));
			$text  = trim((string) preg_replace('/\s*\n\s*/', ' ', $text));

			if ('' === $text) {
				return '';
			}

			return "\n\n"
				. str_repeat('#', $level)
				. ' '
				. $text
				. "\n\n";

			/* Paragraph. */
		case 'p':
			$content = trim(llm_post_walk_children($node));

			return '' === $content
				? ''
				: "\n\n" . $content . "\n\n";

			/* Line break (hard break). */
		case 'br':
			return "  \n";

			/* Horizontal rule. */
		case 'hr':
			return "\n\n---\n\n";

			/* Bold. */
		case 'strong':
		case 'b':
			return llm_post_wrap_inline(llm_post_walk_children($node), '**');

			/* Italic. */
		case 'em':
		case 'i':
			return llm_post_wrap_inline(llm_post_walk_children($node), '*');

			/*
		 * Inline code. A <code> directly inside <pre> never reaches this
		 * branch because <pre> short-circuits.
		 */
		case 'code':
			return llm_post_inline_code($node->textContent);

			/* Fenced code block. */
		case 'pre':
			return llm_post_fenced_code_block($node);

			/* Links. */
		case 'a':
			return llm_post_link($node);

			/* Images. */
		case 'img':
			return llm_post_image($node);

			/* Blockquote. */
		case 'blockquote':
			$content = trim(llm_post_walk_children($node));

			if ('' === $content) {
				return '';
			}

			$quoted = array();

			foreach (preg_split("/\n/", $content) as $line) {
				$quoted[] = ('' === trim($line)) ? '>' : '> ' . $line;
			}

			return "\n\n" . implode("\n", $quoted) . "\n\n";

			/* Lists. */
		case 'ul':
			return llm_post_unordered_list($node);

		case 'ol':
			return llm_post_ordered_list($node);

		case 'li':
			/* Fallback for malformed markup. */
			return llm_post_walk_children($node);

			/* Tables. */
		case 'table':
			return llm_post_table($node);

			/*
		 * Table section elements and elements without a Markdown
		 * equivalent: preserve their text rather than emitting raw HTML.
		 */
		case 'thead':
		case 'tbody':
		case 'tfoot':
		case 'tr':
		case 'td':
		case 'th':
		case 'del':
		case 's':
		case 'strike':
		case 'u':
		case 'sup':
		case 'sub':
		default:
			return llm_post_walk_children($node);
	}
}

/**
 * Turn a block's content into a normalized Markdown block.
 *
 * @param string $content Markdown content.
 * @return string
 */
function llm_post_block(string $content): string
{
	$content = trim($content);

	return '' === $content ? '' : "\n\n" . $content . "\n\n";
}

/**
 * Render an unordered list.
 *
 * @param DOMNode $list  List node.
 * @param int     $depth Nesting depth.
 * @return string
 */
function llm_post_unordered_list(DOMNode $list, int $depth = 0): string
{
	$output = "\n\n";

	foreach ($list->childNodes as $child) {
		if (XML_ELEMENT_NODE !== $child->nodeType) {
			continue;
		}

		if ('li' !== strtolower($child->nodeName)) {
			continue;
		}

		$output .= llm_post_list_item($child, '- ', $depth);
	}

	return $output . "\n";
}

/**
 * Render an ordered list.
 *
 * @param DOMNode $list  List node.
 * @param int     $depth Nesting depth.
 * @return string
 */
function llm_post_ordered_list(DOMNode $list, int $depth = 0): string
{
	$output = "\n\n";
	$number = 1;

	foreach ($list->childNodes as $child) {
		if (XML_ELEMENT_NODE !== $child->nodeType) {
			continue;
		}

		if ('li' !== strtolower($child->nodeName)) {
			continue;
		}

		$output .= llm_post_list_item($child, $number . '. ', $depth);

		$number++;
	}

	return $output . "\n";
}

/**
 * Render one list item, including nested lists.
 *
 * @param DOMElement $item   List item.
 * @param string     $marker Markdown marker.
 * @param int        $depth  Nesting depth.
 * @return string
 */
function llm_post_list_item(
	DOMElement $item,
	string $marker,
	int $depth
): string {
	$inline = '';
	$nested = '';

	foreach ($item->childNodes as $child) {
		if (XML_ELEMENT_NODE === $child->nodeType) {
			$tag = strtolower($child->nodeName);

			if ('ul' === $tag) {
				$nested .= llm_post_unordered_list($child, $depth + 1);
				continue;
			}

			if ('ol' === $tag) {
				$nested .= llm_post_ordered_list($child, $depth + 1);
				continue;
			}
		}

		$inline .= llm_post_walk_node($child);
	}

	$inline = trim($inline);

	$indent = str_repeat('    ', $depth);

	/*
	 * Indent every line after the first so it stays attached to the
	 * bullet. This is done line by line (not by splitting on blank lines)
	 * so fenced code blocks inside list items survive intact.
	 */
	if ('' !== $inline) {
		$inline       = llm_post_collapse_blank_lines($inline);
		$lines        = explode("\n", $inline);
		$continuation = $indent . str_repeat(' ', strlen($marker));
		$result       = array_shift($lines);

		foreach ($lines as $line) {
			$result .= "\n" . ('' === trim($line) ? '' : $continuation . $line);
		}

		$inline = $result;
	}

	$output = $indent . $marker . $inline . "\n";

	if ('' !== trim($nested)) {
		$output .= "\n" . trim($nested, "\n") . "\n";
	}

	return $output;
}

/**
 * Convert an anchor element to Markdown.
 *
 * @param DOMElement $node Anchor element.
 * @return string
 */
function llm_post_link(DOMElement $node): string
{
	$text = '';

	foreach ($node->childNodes as $child) {
		$text .= llm_post_walk_node($child);
	}

	$text = trim($text);

	if (!$node->hasAttribute('href')) {
		return $text;
	}

	$url = llm_post_sanitize_markdown_url($node->getAttribute('href'));

	if ('' === $url) {
		return $text;
	}

	return '[' . $text . '](' . llm_post_markdown_destination($url) . ')';
}

/**
 * Convert an image element to Markdown.
 *
 * @param DOMElement $node Image element.
 * @return string
 */
function llm_post_image(DOMElement $node): string
{
	if (!$node->hasAttribute('src')) {
		return '';
	}

	$src = llm_post_sanitize_markdown_url($node->getAttribute('src'));

	if ('' === $src) {
		return '';
	}

	$alt = $node->hasAttribute('alt') ? $node->getAttribute('alt') : '';

	return '!['
		. llm_post_markdown_text($alt)
		. ']('
		. llm_post_markdown_destination($src)
		. ')';
}

/**
 * Render a fenced code block.
 *
 * @param DOMElement $node <pre> element.
 * @return string
 */
function llm_post_fenced_code_block(DOMElement $node): string
{
	$code_node = null;

	foreach ($node->childNodes as $child) {
		if (
			XML_ELEMENT_NODE === $child->nodeType &&
			'code' === strtolower($child->nodeName)
		) {
			$code_node = $child;
			break;
		}
	}

	$source = $code_node instanceof DOMNode
		? $code_node->textContent
		: $node->textContent;

	$source = str_replace(array("\r\n", "\r"), "\n", $source);
	$source = rtrim($source, "\n");

	$lang = '';

	if (
		$code_node instanceof DOMElement &&
		$code_node->hasAttribute('class')
	) {
		$class = $code_node->getAttribute('class');

		if (
			preg_match(
				'/(?:^|\s)language-([a-zA-Z0-9_+\-]+)(?:\s|$)/',
				$class,
				$matches
			)
		) {
			$lang = strtolower($matches[1]);
		}
	}

	$fence = '```';

	while (false !== strpos($source, $fence)) {
		$fence .= '`';
	}

	return "\n\n" . $fence . $lang . "\n" . $source . "\n" . $fence . "\n\n";
}

/**
 * Convert inline code to Markdown.
 *
 * @param string $text Code text.
 * @return string
 */
function llm_post_inline_code(string $text): string
{
	$text = str_replace(array("\r\n", "\r", "\n"), ' ', $text);

	$fence = '`';

	while (false !== strpos($text, $fence)) {
		$fence .= '`';
	}

	$padding = '';

	if (
		'' !== $text &&
		(
			' ' === $text[0] ||
			'`' === $text[0] ||
			' ' === substr($text, -1) ||
			'`' === substr($text, -1)
		)
	) {
		$padding = ' ';
	}

	return $fence . $padding . $text . $padding . $fence;
}

/**
 * Collect the rows of a table that belong to that table only (not rows
 * of nested tables).
 *
 * @param DOMElement $table Table element.
 * @return DOMElement[]
 */
function llm_post_table_rows(DOMElement $table): array
{
	$rows = array();

	foreach ($table->childNodes as $child) {
		if (XML_ELEMENT_NODE !== $child->nodeType) {
			continue;
		}

		$tag = strtolower($child->nodeName);

		if ('tr' === $tag) {
			$rows[] = $child;
			continue;
		}

		if (in_array($tag, array('thead', 'tbody', 'tfoot'), true)) {
			foreach ($child->childNodes as $tr) {
				if (
					XML_ELEMENT_NODE === $tr->nodeType &&
					'tr' === strtolower($tr->nodeName)
				) {
					$rows[] = $tr;
				}
			}
		}
	}

	return $rows;
}

/**
 * Convert a table to GitHub-flavored Markdown.
 *
 * @param DOMElement $table Table element.
 * @return string
 */
function llm_post_table(DOMElement $table): string
{
	$rows = array();

	foreach (llm_post_table_rows($table) as $tr) {
		$cells = array();

		foreach ($tr->childNodes as $cell) {
			if (XML_ELEMENT_NODE !== $cell->nodeType) {
				continue;
			}

			$tag = strtolower($cell->nodeName);

			if (!in_array($tag, array('td', 'th'), true)) {
				continue;
			}

			$content = trim(llm_post_walk_children($cell));

			/* A literal pipe would create another column. */
			$content = str_replace('|', '\|', $content);

			/* Newlines (including hard breaks) terminate a table row. */
			$content = preg_replace('/\s*(?:\r\n|\r|\n)\s*/', ' ', $content);

			$cells[] = $content;
		}

		if (!empty($cells)) {
			$rows[] = $cells;
		}
	}

	if (empty($rows)) {
		return '';
	}

	$columns = 0;

	foreach ($rows as $row) {
		$columns = max($columns, count($row));
	}

	$output = array();

	foreach ($rows as $index => $row) {
		while (count($row) < $columns) {
			$row[] = '';
		}

		$output[] = '| ' . implode(' | ', $row) . ' |';

		if (0 === $index) {
			$output[] = '| '
				. implode(' | ', array_fill(0, $columns, '---'))
				. ' |';
		}
	}

	return "\n\n" . implode("\n", $output) . "\n\n";
}

/**
 * Escape ordinary Markdown text.
 *
 * - `<` and `>` become character references (`&lt;` / `&gt;`) so author
 *   text can never round-trip into a real HTML tag.
 * - A literal `&` is escaped (`&amp;`) only when it looks like the start of
 *   an entity, so "AT&T" stays readable but "&lt;" typed by the author
 *   isn't misread.
 * - `_` is escaped only where it could actually start/end emphasis
 *   (not inside words such as my_function_name).
 * - Characters at the start of a line that would turn prose into a
 *   heading or list (`#`, `-`, `+`, `1.`) are escaped.
 *
 * @param string $text Text to escape.
 * @return string
 */
function llm_post_markdown_text(string $text): string
{
	$text = (string) preg_replace('/&(?=#?[A-Za-z0-9]+;)/', '&amp;', $text);

	$text = strtr(
		$text,
		array(
			'\\' => '\\\\',
			'`'  => '\\`',
			'*'  => '\\*',
			'['  => '\\[',
			']'  => '\\]',
			'<'  => '&lt;',
			'>'  => '&gt;',
		)
	);

	if (false !== strpos($text, '_')) {
		$escaped = preg_replace(
			'/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u',
			'\\\\_',
			$text
		);

		if (null !== $escaped) {
			$text = $escaped;
		}
	}

	$text = preg_replace_callback(
		'/(^|\n)([ \t]*)(?:(#{1,6})(?=[ \t]|$)|([-+])(?=[ \t]|$)|(\d{1,9})([.)])(?=[ \t]|$))/',
		function ($m) {
			$prefix = $m[1] . $m[2];

			if (isset($m[3]) && '' !== $m[3]) {
				return $prefix . '\\' . $m[3];
			}

			if (isset($m[4]) && '' !== $m[4]) {
				return $prefix . '\\' . $m[4];
			}

			return $prefix . $m[5] . '\\' . $m[6];
		},
		$text
	);

	return (string) $text;
}

/**
 * Safely serialize a scalar for YAML front matter.
 *
 * @param mixed $value Value to serialize.
 * @return string
 */
function llm_post_yaml_scalar($value): string
{
	$json = wp_json_encode(
		(string) $value,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);

	return false === $json ? '""' : $json;
}

/**
 * Scheme + host (+ port) of the site, e.g. "https://example.com".
 */
function llm_post_site_origin(): string
{
	$parts = wp_parse_url(home_url());

	if (!is_array($parts) || empty($parts['host'])) {
		return '';
	}

	return (isset($parts['scheme']) ? $parts['scheme'] : 'https')
		. '://'
		. $parts['host']
		. (isset($parts['port']) ? ':' . $parts['port'] : '');
}

/**
 * Sanitize a URL before placing it into Markdown, resolving relative URLs
 * to absolute ones against the current document's URL.
 *
 * @param string $url URL to sanitize.
 * @return string
 */
function llm_post_sanitize_markdown_url(string $url): string
{
	$url = trim($url);

	if ('' === $url) {
		return '';
	}

	if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
		return '';
	}

	/* In-page anchor. */
	if ('#' === $url[0]) {
		return $url;
	}

	/* Explicit scheme: http(s) only. */
	if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $matches)) {
		$scheme = strtolower($matches[1]);

		if (!in_array($scheme, array('http', 'https'), true)) {
			return '';
		}

		return esc_url_raw($url, array('http', 'https'));
	}

	$origin = llm_post_site_origin();
	$base   = (string) preg_replace('/[?#].*$/', '', llm_post_base_url());

	/* Protocol-relative. (Must be checked before the "/" case.) */
	if (0 === strpos($url, '//')) {
		$scheme = (string) strstr($origin, ':', true);

		return esc_url_raw(
			('' !== $scheme ? $scheme : 'https') . ':' . $url,
			array('http', 'https')
		);
	}

	/* Root-relative. */
	if ('/' === $url[0]) {
		return esc_url_raw($origin . $url, array('http', 'https'));
	}

	/* Query-only. */
	if ('?' === $url[0]) {
		return esc_url_raw($base . $url, array('http', 'https'));
	}

	/* Path-relative: resolve against the directory of the base URL. */
	$directory = (string) preg_replace('#[^/]*$#', '', $base);

	return esc_url_raw($directory . $url, array('http', 'https'));
}

/**
 * Return a Markdown URL destination.
 *
 * @param string $url Sanitized URL.
 * @return string
 */
function llm_post_markdown_destination(string $url): string
{
	$url = str_replace(
		array('\\', '<', '>'),
		array('%5C', '%3C', '%3E'),
		$url
	);

	return '<' . $url . '>';
}

/**
 * Make a string safe for use inside a Markdown table cell.
 *
 * A literal pipe would start a new column and a newline would end the row.
 *
 * @param string $text Cell content (already Markdown-escaped).
 * @return string
 */
function llm_post_archive_table_cell(string $text): string
{
	$text = str_replace('|', '\\|', $text);

	return trim((string) preg_replace('/\s*(?:\r\n|\r|\n)\s*/', ' ', $text));
}

/**
 * Render the LLM post archive as Markdown.
 *
 * Yields a YAML front matter block, an H1 title, and a table of posts with
 * their title, URL and LLM post identifier. Page size is capped (filterable via
 * `llm_post_archive_markdown_per_page`) and the front matter exposes
 * page / total_pages / next / previous so an agent can walk the catalogue.
 *
 * @return string
 */
function llm_post_archive_to_markdown(): string
{
	$archive_url = (string) get_post_type_archive_link('llm_post');
	$title       = llm_post_plain_text(llm_post_get_archive_title());

	llm_post_base_url($archive_url);

	$paged = max(1, (int) get_query_var('paged'));

	$query = new WP_Query(
		array(
			'post_type'           => 'llm_post',
			'post_status'         => 'publish',
			'posts_per_page'      => llm_post_markdown_per_page(),
			'paged'               => $paged,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
			'no_found_rows'       => false,
		)
	);

	$front_matter  = "---\n";
	$front_matter .= 'title: ' . llm_post_yaml_scalar($title) . "\n";
	$front_matter .= 'permalink: ' . llm_post_yaml_scalar($archive_url) . "\n";
	$front_matter .= 'page: ' . $paged . "\n";
	$front_matter .= 'total_pages: ' . max(1, (int) $query->max_num_pages) . "\n";

	if ($paged < (int) $query->max_num_pages) {
		$front_matter .= 'next: ' . llm_post_yaml_scalar(get_pagenum_link($paged + 1)) . "\n";
	}

	if ($paged > 1) {
		$front_matter .= 'previous: ' . llm_post_yaml_scalar(get_pagenum_link($paged - 1)) . "\n";
	}

	$front_matter .= "---\n\n";

	$out = '# ' . llm_post_markdown_text($title) . "\n\n";

	$header = llm_post_get_common_markdown('header');
	$footer = llm_post_get_common_markdown('footer');

	if ('' !== $header) {
		$out .= $header . "\n\n";
	}

	if (empty($query->posts)) {
		$out .= "_No LLM posts yet._\n";
	} else {
		$out .= "| Title | URL | ID |\n";
		$out .= "| --- | --- | --- |\n";

		foreach ($query->posts as $post) {
			$out .= '| '
				. llm_post_archive_table_cell(
					llm_post_markdown_text(llm_post_plain_text(get_the_title($post)))
				)
				. ' | '
				. llm_post_archive_table_cell(
					llm_post_sanitize_markdown_url((string) get_permalink($post))
				)
				. ' | '
				. llm_post_archive_table_cell(
					llm_post_inline_code(llm_post_get_identifier($post))
				)
				. " |\n";
		}
	}

	if ('' !== $footer) {
		$out .= "\n" . $footer . "\n";
	}

	return $front_matter . $out;
}

/* ------------------------------------------------------------------------
 * 5. HTML fallback templates
 * --------------------------------------------------------------------- */

/**
 * Does the active theme provide a template for this request that we should
 * leave alone?
 *
 * Block themes are always treated as handling it: locate_template() only
 * finds PHP templates, so it would wrongly report "no template" for them.
 *
 * @param string[] $files Template files to look for (classic themes).
 */
function llm_post_theme_handles(array $files): bool
{
	if (defined('LLM_POST_FORCE_FALLBACK_TEMPLATE')) {
		return false;
	}

	if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
		return true;
	}

	return (bool) locate_template($files);
}

/**
 * Provide a fallback template for LLM posts.
 *
 * Themes that DO provide single-llm_post.php / single.php (or archive
 * equivalents), and block themes, are left completely alone.
 *
 * `index.php` is deliberately NOT in the archive list: every theme ships
 * one, and it is exactly the template that renders as a blog listing or a
 * landing page when a CPT archive is unhandled.
 */
add_filter(
	'template_include',
	function ($template) {
		if (is_singular('llm_post')) {
			if (llm_post_theme_handles(array('single-llm_post.php', 'single.php', 'singular.php'))) {
				return $template;
			}

			llm_post_render_single_fallback_template();
			exit;
		}

		if (is_post_type_archive('llm_post')) {
			if (llm_post_theme_handles(array('archive-llm_post.php', 'archive.php'))) {
				return $template;
			}

			llm_post_render_archive_fallback_template();
			exit;
		}

		return $template;
	},
	99
);

/**
 * Open a fallback page: doctype, <head> (with wp_head()), and <body>.
 *
 * @param string $title Plain-text page title.
 */
function llm_post_fallback_open(string $title): void
{
	/*
	 * Flag this request as a fallback render so the asset/output strippers
	 * below activate. Must be set before wp_head() runs.
	 */
	$GLOBALS['llm_post_rendering_fallback'] = true;

	/*
	 * Emoji detection and styles are hooked directly on wp_head /
	 * wp_print_styles rather than going through the enqueue system, so
	 * they have to be removed explicitly.
	 */
	remove_action('wp_head', 'print_emoji_detection_script', 7);
	remove_action('wp_print_styles', 'print_emoji_styles');

	header('Content-Type: text/html; charset=UTF-8');

?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>

	<head>
		<meta charset="<?php bloginfo('charset'); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php
		/*
		 * FIX: only print our own <title> when the theme does not add one
		 * via wp_head() (title-tag support), to avoid duplicates.
		 */
		if (!current_theme_supports('title-tag')) :
		?>
			<title><?php echo esc_html($title); ?></title>
		<?php endif; ?>
		<?php llm_post_render_fallback_styles(); ?>
		<?php wp_head(); ?>
	</head>

	<body <?php body_class(); ?>>
		<?php wp_body_open(); ?>
	<?php
}

/**
 * Close a fallback page. wp_footer() is required so footer-enqueued
 * scripts (admin toolbar etc.) run.
 */
function llm_post_fallback_close(): void
{
	wp_footer();
	?>
	</body>

	</html>
	<?php
}

/**
 * Echo the configured (already sanitized) header or footer content.
 *
 * @param string $which 'header' or 'footer'.
 */
function llm_post_echo_common(string $which): void
{
	$html = llm_post_get_common_html($which);

	if ('' === $html) {
		return;
	}

	echo '<div class="llm-post-common-' . esc_attr($which) . '">'
		. $html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		. '</div>';
}

/**
 * Render a minimal, theme-independent single-post view.
 *
 * Uses a real post loop and the_content(), which also enforces the
 * password form for protected posts.
 */
function llm_post_render_single_fallback_template(): void
{
	$queried = get_queried_object();

	if (!$queried instanceof WP_Post) {
		return;
	}

	llm_post_fallback_open(llm_post_plain_text(get_the_title($queried)));

	llm_post_echo_common('header');

	while (have_posts()) {
		the_post();
	?>
		<article>
			<h1><?php the_title(); ?></h1>
			<div class="entry-content">
				<?php the_content(); ?>
			</div>
		</article>
	<?php
	}

	llm_post_echo_common('footer');

	llm_post_fallback_close();
}

/**
 * Render a minimal, theme-independent archive view for LLM posts.
 */
function llm_post_render_archive_fallback_template(): void
{
	$title = llm_post_plain_text(llm_post_get_archive_title());

	llm_post_fallback_open($title);

	?>
	<header>
		<h1><?php echo esc_html($title); ?></h1>
	</header>
	<?php

	llm_post_echo_common('header');

	?>
	<main>
		<?php if (have_posts()) : ?>
			<table class="llm-post-archive-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e('Title', 'llm-post'); ?></th>
						<th scope="col"><?php esc_html_e('URL', 'llm-post'); ?></th>
						<th scope="col"><?php esc_html_e('ID', 'llm-post'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					while (have_posts()) :
						the_post();
					?>
						<tr>
							<td><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></td>
							<td><code><?php echo esc_html(wp_make_link_relative(get_permalink())); ?></code></td>
							<td><code><?php echo esc_html(llm_post_get_identifier(get_post())); ?></code></td>
						</tr>
					<?php
					endwhile;
					?>
				</tbody>
			</table>
			<?php
			the_posts_pagination(
				array(
					'mid_size'  => 2,
					'prev_text' => '&larr;',
					'next_text' => '&rarr;',
				)
			);
			?>
		<?php else : ?>
			<p><?php esc_html_e('No LLM posts yet.', 'llm-post'); ?></p>
		<?php endif; ?>
	</main>
<?php

	llm_post_echo_common('footer');

	llm_post_fallback_close();
}

function llm_post_render_fallback_styles(): void
{
?>
	<style>
		body {
			font: 16px/1.6 system-ui, sans-serif;
			max-width: 40rem;
			margin: 2rem auto;
			padding: 0 1rem;
			color: #222;
		}

		a {
			color: #0645ad;
		}

		h1 {
			line-height: 1.25;
		}

		table {
			border-collapse: collapse;
		}

		th,
		td {
			border: 1px solid #ddd;
			padding: .35em .6em;
			text-align: left;
		}
	</style>
<?php
}

/* ------------------------------------------------------------------------
 * 6. Stripping third-party output on fallback pages
 * --------------------------------------------------------------------- */

/**
 * Handles the admin bar needs. `admin-bar` depends on `hoverintent-js`
 * (hyphenated), so both spellings are allowed.
 *
 * @return string[]
 */
function llm_post_admin_bar_script_handles(): array
{
	return array('admin-bar', 'hoverintent-js', 'hoverintent', 'hoverIntent');
}

/**
 * Remove plugin-registered output callbacks on the LLM fallback pages.
 *
 * Cookie banners, chat widgets, tracking pixels and similar plugins
 * typically echo their markup directly from a wp_footer / wp_body_open
 * callback rather than going through the enqueue system. This walks the
 * registered callbacks on the relevant output hooks and removes any whose
 * source file lives inside wp-content/plugins/ — i.e. every plugin except
 * our own. Core, theme, and mu-plugin callbacks are left alone.
 *
 * Runs at PHP_INT_MIN so it executes before any other callback on the
 * hook, and only when a fallback template is rendering.
 */
function llm_post_strip_plugin_output(): void
{
	if (empty($GLOBALS['llm_post_rendering_fallback'])) {
		return;
	}

	/*
	 * wp_head is not stripped by default: many SEO plugins legitimately
	 * add meta tags there. Add it via the filter below if needed.
	 */
	$hooks = (array) apply_filters(
		'llm_post_stripped_output_hooks',
		array('wp_footer', 'wp_body_open')
	);

	/*
	 * Plugin directory slugs to leave in place even on fallback pages.
	 * Example: array( 'wordpress-seo' ) to keep Yoast running.
	 */
	$keep_slugs = (array) apply_filters(
		'llm_post_keep_plugin_output',
		array()
	);

	$own_file   = wp_normalize_path(__FILE__);
	$own_dir    = trailingslashit(wp_normalize_path(plugin_dir_path(__FILE__)));
	$plugin_dir = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));

	/*
	 * If our own plugin is a single file dropped directly into
	 * wp-content/plugins/, plugin_dir_path() returns WP_PLUGIN_DIR itself.
	 * In that case compare the exact file path instead.
	 */
	$own_is_root = ($own_dir === $plugin_dir);

	foreach ($hooks as $hook) {
		llm_post_strip_plugin_hook_callbacks(
			$hook,
			$plugin_dir,
			$own_dir,
			$own_file,
			$own_is_root,
			$keep_slugs
		);
	}
}

/**
 * Remove plugin callbacks from one output hook.
 *
 * @param string   $hook         Hook name.
 * @param string   $plugin_dir   Normalized, trailing-slashed WP_PLUGIN_DIR.
 * @param string   $own_dir      Normalized, trailing-slashed dir of this plugin.
 * @param string   $own_file     Normalized path of this plugin's main file.
 * @param bool     $own_is_root  True if this plugin is a single file in the plugins root.
 * @param string[] $keep_slugs   Plugin directory slugs to leave in place.
 */
function llm_post_strip_plugin_hook_callbacks(
	string $hook,
	string $plugin_dir,
	string $own_dir,
	string $own_file,
	bool $own_is_root,
	array $keep_slugs
): void {
	global $wp_filter;

	if (empty($wp_filter[$hook]) || !$wp_filter[$hook] instanceof WP_Hook) {
		return;
	}

	foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
		foreach ($callbacks as $cb) {
			$file = llm_post_callback_file($cb['function']);

			if ('' === $file) {
				continue;
			}

			$file = wp_normalize_path($file);

			/* Our own plugin — never remove. */
			if ($own_is_root) {
				if ($file === $own_file) {
					continue;
				}
			} elseif (0 === strpos($file, $own_dir)) {
				continue;
			}

			/* Not a plugin callback (core, theme, mu-plugin). */
			if (0 !== strpos($file, $plugin_dir)) {
				continue;
			}

			/* Caller chose to keep this plugin on fallback pages. */
			$relative = substr($file, strlen($plugin_dir));
			$slug     = strstr($relative, '/', true);

			if (false !== $slug && in_array($slug, $keep_slugs, true)) {
				continue;
			}

			remove_action($hook, $cb['function'], $priority);
		}
	}
}

/**
 * Determine the source file of a callback, if it can be introspected.
 *
 * Returns '' for internal (built-in) functions, or for anything whose
 * reflection throws. Those are left in place.
 *
 * @param mixed $callback Callback as stored by WP_Hook.
 * @return string Normalized file path, or '' on failure.
 */
function llm_post_callback_file($callback): string
{
	try {
		if ($callback instanceof Closure) {
			$ref = new ReflectionFunction($callback);
		} elseif (is_string($callback)) {
			if (false !== strpos($callback, '::')) {
				list($class, $method) = explode('::', $callback, 2);
				$ref = new ReflectionMethod($class, $method);
			} else {
				$ref = new ReflectionFunction($callback);
			}
		} elseif (is_array($callback) && 2 === count($callback)) {
			$ref = new ReflectionMethod($callback[0], $callback[1]);
		} elseif (is_object($callback) && method_exists($callback, '__invoke')) {
			$ref = new ReflectionMethod($callback, '__invoke');
		} else {
			return '';
		}

		$file = $ref->getFileName();

		return is_string($file) ? $file : '';
	} catch (ReflectionException $e) {
		return '';
	}
}

add_action('wp_head',      'llm_post_strip_plugin_output', PHP_INT_MIN);
add_action('wp_footer',    'llm_post_strip_plugin_output', PHP_INT_MIN);
add_action('wp_body_open', 'llm_post_strip_plugin_output', PHP_INT_MIN);

/**
 * Dequeue all non-admin-bar styles and scripts on LLM fallback pages.
 *
 * Hooked on multiple late actions because themes and plugins enqueue at
 * various points.
 */
function llm_post_strip_asset_queues(): void
{
	if (empty($GLOBALS['llm_post_rendering_fallback'])) {
		return;
	}

	$keep_styles  = array();
	$keep_scripts = array();

	if (is_admin_bar_showing()) {
		$keep_styles  = array('admin-bar', 'dashicons');
		$keep_scripts = llm_post_admin_bar_script_handles();
	}

	global $wp_styles, $wp_scripts;

	if ($wp_styles instanceof WP_Styles) {
		foreach (array_keys($wp_styles->registered) as $handle) {
			if (!in_array($handle, $keep_styles, true)) {
				wp_dequeue_style($handle);
			}
		}
	}

	if ($wp_scripts instanceof WP_Scripts) {
		foreach (array_keys($wp_scripts->registered) as $handle) {
			if (!in_array($handle, $keep_scripts, true)) {
				wp_dequeue_script($handle);
			}
		}
	}
}

add_action('wp_enqueue_scripts',       'llm_post_strip_asset_queues', PHP_INT_MAX);
add_action('wp_print_styles',          'llm_post_strip_asset_queues', PHP_INT_MAX);
add_action('wp_print_scripts',         'llm_post_strip_asset_queues', PHP_INT_MAX);
add_action('wp_print_footer_scripts',  'llm_post_strip_asset_queues', PHP_INT_MAX);

/**
 * Final safety net: constrain the exact handle list that is about to be
 * printed, regardless of when or where the enqueue happened.
 *
 * Note: `print_scripts_array` runs after dependency resolution, so the
 * allowlist must include the admin bar's own dependencies.
 */
add_filter(
	'print_scripts_array',
	function ($handles) {
		if (empty($GLOBALS['llm_post_rendering_fallback'])) {
			return $handles;
		}

		if (!is_admin_bar_showing()) {
			return array();
		}

		return array_values(
			array_intersect(
				(array) $handles,
				llm_post_admin_bar_script_handles()
			)
		);
	},
	PHP_INT_MAX
);

add_filter(
	'print_styles_array',
	function ($handles) {
		if (empty($GLOBALS['llm_post_rendering_fallback'])) {
			return $handles;
		}

		if (!is_admin_bar_showing()) {
			return array();
		}

		return array_values(
			array_intersect(
				(array) $handles,
				array('admin-bar', 'dashicons')
			)
		);
	},
	PHP_INT_MAX
);

/**
 * Dequeue all script modules on LLM fallback pages.
 *
 * Script modules use a separate API (WP_Script_Modules) and are not
 * stored in $wp_scripts. They must be removed with
 * wp_dequeue_script_module(), available since WordPress 6.5.0.
 *
 * The queue is retrieved via WP_Script_Modules::get_queue() (since
 * WordPress 6.9.0) or, on older versions, by reading the private $queue
 * property through reflection.
 */
function llm_post_strip_script_modules(): void
{
	if (empty($GLOBALS['llm_post_rendering_fallback'])) {
		return;
	}

	if (!function_exists('wp_dequeue_script_module')) {
		return;
	}

	if (!function_exists('wp_script_modules')) {
		return;
	}

	$script_modules = wp_script_modules();

	if (!is_object($script_modules)) {
		return;
	}

	$queue = array();

	if (method_exists($script_modules, 'get_queue')) {
		$queue = (array) $script_modules->get_queue();
	} else {
		try {
			$reflection = new ReflectionClass($script_modules);
			$property   = $reflection->getProperty('queue');
			$property->setAccessible(true);
			$queue = (array) $property->getValue($script_modules);
		} catch (ReflectionException $e) {
			return;
		}
	}

	foreach ($queue as $module_id) {
		wp_dequeue_script_module($module_id);
	}
}

add_action('wp_enqueue_scripts',      'llm_post_strip_script_modules', PHP_INT_MAX);
add_action('wp_print_styles',         'llm_post_strip_script_modules', PHP_INT_MAX);
add_action('wp_print_scripts',        'llm_post_strip_script_modules', PHP_INT_MAX);
add_action('wp_print_footer_scripts', 'llm_post_strip_script_modules', PHP_INT_MAX);
