<?php
/**
 * Plugin Name: WP-AI Assistant Premium
 * Plugin URI:  https://example.com/wp-ai-assistant
 * Description: On-site AI-style content assistant and knowledge chat — SEO generation, FAQ/summary tools, and a shared knowledge base search widget. No external API calls required.
 * Version:     2.0.0
 * Author:      Your Name
 * License:     GPL v2 or later
 * Text Domain: wp-ai-assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WPAIA_VERSION', '2.0.0' );
define( 'WPAIA_PLUGIN_FILE', __FILE__ );
define( 'WPAIA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * =========================================================================
 * ACTIVATION / DEACTIVATION
 * =========================================================================
 */
register_activation_hook( __FILE__, 'wpaia_activate' );
function wpaia_activate() {
	$defaults = array(
		'brand_name'   => 'AI Assistant',
		'primary_color' => '#6366f1',
		'tone'         => 'professional',
		'enable_chat'  => 1,
		'enable_seo'   => 1,
		'enable_faq'   => 1,
	);
	if ( false === get_option( 'wpaia_settings' ) ) {
		add_option( 'wpaia_settings', $defaults );
	}
	if ( false === get_option( 'wpaia_search_index' ) ) {
		add_option( 'wpaia_search_index', array() );
	}
	if ( false === get_option( 'wpaia_index_meta' ) ) {
		add_option( 'wpaia_index_meta', array( 'count' => 0, 'built_at' => 0 ) );
	}
	if ( false === get_option( 'wpaia_stats' ) ) {
		add_option(
			'wpaia_stats',
			array(
				'total_questions' => 0,
				'questions'       => array(), // question_text => count
				'daily'           => array(), // Y-m-d => count
			)
		);
	}
}

/**
 * =========================================================================
 * MAIN PLUGIN CLASS
 * =========================================================================
 */
final class WPAIA_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_chat_widget' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_editor_meta_box' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// AJAX handlers (admin side).
		add_action( 'wp_ajax_wpaia_build_index', array( $this, 'ajax_build_index' ) );
		add_action( 'wp_ajax_wpaia_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_wpaia_get_stats', array( $this, 'ajax_get_stats' ) );

		// AJAX handlers (editor assistant — logged-in editors only).
		add_action( 'wp_ajax_wpaia_generate_seo', array( $this, 'ajax_generate_seo' ) );
		add_action( 'wp_ajax_wpaia_summarize', array( $this, 'ajax_summarize' ) );
		add_action( 'wp_ajax_wpaia_generate_faq', array( $this, 'ajax_generate_faq' ) );
		add_action( 'wp_ajax_wpaia_change_tone', array( $this, 'ajax_change_tone' ) );
	}

	/** Helpers ------------------------------------------------------- */

	public function get_settings() {
		$defaults = array(
			'brand_name'    => 'AI Assistant',
			'primary_color' => '#6366f1',
			'tone'          => 'professional',
			'enable_chat'   => 1,
			'enable_seo'    => 1,
			'enable_faq'    => 1,
		);
		return wp_parse_args( get_option( 'wpaia_settings', array() ), $defaults );
	}

	private function is_plugin_admin_page() {
		$screen = get_current_screen();
		return $screen && strpos( $screen->id, 'wpaia' ) !== false;
	}

	/** Admin menu ------------------------------------------------------ */

	public function admin_menu() {
		add_menu_page(
			'AI Assistant',
			'AI Assistant',
			'manage_options',
			'wpaia-dashboard',
			array( $this, 'render_dashboard_page' ),
			'dashicons-admin-generic',
			26
		);

		add_submenu_page( 'wpaia-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'wpaia-dashboard', array( $this, 'render_dashboard_page' ) );
		add_submenu_page( 'wpaia-dashboard', 'Knowledge Base', 'Knowledge Base', 'manage_options', 'wpaia-knowledge', array( $this, 'render_knowledge_page' ) );
		add_submenu_page( 'wpaia-dashboard', 'Settings', 'Settings', 'manage_options', 'wpaia-settings', array( $this, 'render_settings_page' ) );
	}

	public function enqueue_admin_assets( $hook ) {
		if ( ! $this->is_plugin_admin_page() ) {
			return;
		}

		wp_enqueue_style( 'wpaia-admin-css', WPAIA_PLUGIN_URL . 'assets/css/wp-ai-assistant.css', array(), WPAIA_VERSION );
		wp_enqueue_script( 'wpaia-admin-dashboard', WPAIA_PLUGIN_URL . 'assets/js/admin-dashboard.js', array( 'jquery' ), WPAIA_VERSION, true );

		wp_localize_script(
			'wpaia-admin-dashboard',
			'WPAIA_ADMIN',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpaia_admin_nonce' ),
				'stats'   => $this->get_dashboard_stats(),
				'settings' => $this->get_settings(),
			)
		);
	}

	public function enqueue_frontend_assets() {
		$settings = $this->get_settings();
		if ( empty( $settings['enable_chat'] ) ) {
			return;
		}

		wp_enqueue_style( 'wpaia-chat-widget-css', WPAIA_PLUGIN_URL . 'assets/css/chat-widget.css', array(), WPAIA_VERSION );
		wp_enqueue_script( 'wpaia-knowledge-chat', WPAIA_PLUGIN_URL . 'assets/js/knowledge-chat.js', array(), WPAIA_VERSION, true );

		wp_localize_script(
			'wpaia-knowledge-chat',
			'WPAIA_CHAT',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'wpaia/v1' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'brandName'   => sanitize_text_field( $settings['brand_name'] ),
				'primaryColor' => sanitize_hex_color( $settings['primary_color'] ) ? $settings['primary_color'] : '#6366f1',
			)
		);
	}

	public function render_chat_widget() {
		$settings = $this->get_settings();
		if ( empty( $settings['enable_chat'] ) ) {
			return;
		}
		?>
		<div id="wpaia-chat-root" aria-live="polite"></div>
		<?php
	}

	/** Editor Assistant meta box --------------------------------------- */

	public function add_editor_meta_box() {
		$settings = $this->get_settings();
		if ( empty( $settings['enable_seo'] ) ) {
			return;
		}
		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'wpaia_editor_assistant',
				'✨ ' . esc_html( $settings['brand_name'] ) . ' — Editor Assistant',
				array( $this, 'render_editor_meta_box' ),
				$pt,
				'normal',
				'high'
			);
		}
	}

	public function render_editor_meta_box( $post ) {
		wp_nonce_field( 'wpaia_editor_nonce', 'wpaia_editor_nonce_field' );
		$settings = $this->get_settings();
		?>
		<div class="wpaia-panel" id="wpaia-editor-panel" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<div class="wpaia-tabs">
				<button type="button" class="wpaia-tab active" data-tab="seo">SEO &amp; Copy</button>
				<button type="button" class="wpaia-tab" data-tab="summary">Summary</button>
				<?php if ( ! empty( $settings['enable_faq'] ) ) : ?>
					<button type="button" class="wpaia-tab" data-tab="faq">FAQ Generator</button>
				<?php endif; ?>
				<button type="button" class="wpaia-tab" data-tab="tone">Tone Changer</button>
			</div>

			<div class="wpaia-tab-panel active" data-panel="seo">
				<label for="wpaia-brief">Brief (what is this page about?)</label>
				<textarea id="wpaia-brief" rows="3" placeholder="e.g. A landing page for our new eco-friendly water bottles, targeting outdoor enthusiasts"></textarea>
				<button type="button" class="wpaia-btn wpaia-btn-primary" data-action="generate-seo">
					<span class="wpaia-btn-label">Generate SEO &amp; Copy</span>
					<span class="wpaia-spinner" hidden></span>
				</button>

				<div class="wpaia-results" id="wpaia-seo-results"></div>
			</div>

			<div class="wpaia-tab-panel" data-panel="summary">
				<p class="wpaia-hint">Generates a short AI Summary from this post's current content.</p>
				<button type="button" class="wpaia-btn wpaia-btn-primary" data-action="summarize">
					<span class="wpaia-btn-label">Generate Summary</span>
					<span class="wpaia-spinner" hidden></span>
				</button>
				<div class="wpaia-results" id="wpaia-summary-results"></div>
			</div>

			<?php if ( ! empty( $settings['enable_faq'] ) ) : ?>
			<div class="wpaia-tab-panel" data-panel="faq">
				<p class="wpaia-hint">Generates 3–5 FAQs from this post's current content.</p>
				<button type="button" class="wpaia-btn wpaia-btn-primary" data-action="generate-faq">
					<span class="wpaia-btn-label">Generate FAQs</span>
					<span class="wpaia-spinner" hidden></span>
				</button>
				<div class="wpaia-results" id="wpaia-faq-results"></div>
			</div>
			<?php endif; ?>

			<div class="wpaia-tab-panel" data-panel="tone">
				<label for="wpaia-tone-select">Rewrite tone</label>
				<select id="wpaia-tone-select">
					<option value="professional">Professional</option>
					<option value="casual">Casual</option>
					<option value="friendly">Friendly</option>
					<option value="sales">Sales</option>
				</select>
				<button type="button" class="wpaia-btn wpaia-btn-primary" data-action="change-tone">
					<span class="wpaia-btn-label">Rewrite Content</span>
					<span class="wpaia-spinner" hidden></span>
				</button>
				<div class="wpaia-results" id="wpaia-tone-results"></div>
			</div>
		</div>
		<?php
	}

	/** Dashboard / Knowledge / Settings pages --------------------------- */

	private function get_dashboard_stats() {
		$index_meta = get_option( 'wpaia_index_meta', array( 'count' => 0, 'built_at' => 0 ) );
		$stats      = get_option( 'wpaia_stats', array( 'total_questions' => 0, 'questions' => array(), 'daily' => array() ) );

		arsort( $stats['questions'] );
		$top_questions = array_slice( $stats['questions'], 0, 5, true );

		// Last 7 days of usage, oldest first.
		$daily = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$day           = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$daily[ $day ] = isset( $stats['daily'][ $day ] ) ? (int) $stats['daily'][ $day ] : 0;
		}

		return array(
			'indexed_count'    => (int) $index_meta['count'],
			'indexed_at'       => $index_meta['built_at'] ? date_i18n( 'M j, Y g:ia', $index_meta['built_at'] ) : 'Never',
			'total_questions'  => (int) $stats['total_questions'],
			'top_questions'    => $top_questions,
			'daily'            => $daily,
		);
	}

	public function render_dashboard_page() {
		$stats    = $this->get_dashboard_stats();
		$max_day  = max( 1, max( $stats['daily'] ) );
		?>
		<div class="wrap wpaia-wrap">
			<div class="wpaia-header">
				<div>
					<h1>AI Assistant Dashboard</h1>
					<p class="wpaia-subtitle">Overview of your knowledge base and visitor activity.</p>
				</div>
				<button type="button" class="wpaia-btn wpaia-btn-primary" id="wpaia-build-index-btn">
					<span class="wpaia-btn-label">🔄 Rebuild Index</span>
					<span class="wpaia-spinner" hidden></span>
				</button>
			</div>

			<div class="wpaia-stat-grid">
				<div class="wpaia-stat-card wpaia-gradient-1">
					<div class="wpaia-stat-value" id="wpaia-stat-indexed"><?php echo esc_html( $stats['indexed_count'] ); ?></div>
					<div class="wpaia-stat-label">Pages/Posts Indexed</div>
					<div class="wpaia-stat-sub" id="wpaia-stat-indexed-at">Last built: <?php echo esc_html( $stats['indexed_at'] ); ?></div>
				</div>
				<div class="wpaia-stat-card wpaia-gradient-2">
					<div class="wpaia-stat-value" id="wpaia-stat-questions"><?php echo esc_html( $stats['total_questions'] ); ?></div>
					<div class="wpaia-stat-label">Questions Asked</div>
					<div class="wpaia-stat-sub">All time</div>
				</div>
				<div class="wpaia-stat-card wpaia-gradient-3">
					<div class="wpaia-stat-value"><?php echo count( $stats['top_questions'] ); ?></div>
					<div class="wpaia-stat-label">Unique Topics Tracked</div>
					<div class="wpaia-stat-sub">Last 5 shown below</div>
				</div>
			</div>

			<div class="wpaia-panels-row">
				<div class="wpaia-card">
					<h2>Usage — Last 7 Days</h2>
					<div class="wpaia-bar-chart" id="wpaia-bar-chart">
						<?php foreach ( $stats['daily'] as $day => $count ) : ?>
							<div class="wpaia-bar-col">
								<div class="wpaia-bar" style="height: <?php echo esc_attr( max( 4, round( ( $count / $max_day ) * 100 ) ) ); ?>%;" title="<?php echo esc_attr( $count ); ?> questions"></div>
								<span class="wpaia-bar-label"><?php echo esc_html( date_i18n( 'D', strtotime( $day ) ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="wpaia-card">
					<h2>Top Questions</h2>
					<ul class="wpaia-top-questions" id="wpaia-top-questions">
						<?php if ( empty( $stats['top_questions'] ) ) : ?>
							<li class="wpaia-empty">No questions asked yet.</li>
						<?php else : ?>
							<?php foreach ( $stats['top_questions'] as $q => $count ) : ?>
								<li>
									<span class="wpaia-q-text"><?php echo esc_html( $q ); ?></span>
									<span class="wpaia-q-count"><?php echo esc_html( $count ); ?>×</span>
								</li>
							<?php endforeach; ?>
						<?php endif; ?>
					</ul>
				</div>
			</div>

			<div id="wpaia-toast-root"></div>
		</div>
		<?php
	}

	public function render_knowledge_page() {
		$index_meta = get_option( 'wpaia_index_meta', array( 'count' => 0, 'built_at' => 0 ) );
		?>
		<div class="wrap wpaia-wrap">
			<div class="wpaia-header">
				<div>
					<h1>Knowledge Base</h1>
					<p class="wpaia-subtitle">Build a searchable index from your published pages and posts. The chat widget answers visitor questions using this index — no external API required.</p>
				</div>
			</div>

			<div class="wpaia-card">
				<h2>Index Status</h2>
				<p><strong><?php echo esc_html( $index_meta['count'] ); ?></strong> items indexed. Last built:
					<strong><?php echo $index_meta['built_at'] ? esc_html( date_i18n( 'M j, Y g:ia', $index_meta['built_at'] ) ) : 'Never'; ?></strong>
				</p>
				<button type="button" class="wpaia-btn wpaia-btn-primary" id="wpaia-build-index-btn">
					<span class="wpaia-btn-label">🔄 Build / Rebuild Index</span>
					<span class="wpaia-spinner" hidden></span>
				</button>
				<p class="wpaia-hint">The index is stored in your WordPress database and shared by all visitors via a REST API endpoint.</p>
			</div>

			<div id="wpaia-toast-root"></div>
		</div>
		<?php
	}

	public function render_settings_page() {
		$settings = $this->get_settings();
		?>
		<div class="wrap wpaia-wrap">
			<div class="wpaia-header">
				<div>
					<h1>Settings</h1>
					<p class="wpaia-subtitle">Customize how the assistant looks and behaves for your visitors.</p>
				</div>
			</div>

			<div class="wpaia-card" id="wpaia-settings-form">
				<div class="wpaia-form-row">
					<label for="wpaia-brand-name">Brand Name</label>
					<input type="text" id="wpaia-brand-name" value="<?php echo esc_attr( $settings['brand_name'] ); ?>" placeholder="AI Assistant">
				</div>

				<div class="wpaia-form-row">
					<label for="wpaia-primary-color">Primary Color</label>
					<input type="color" id="wpaia-primary-color" value="<?php echo esc_attr( $settings['primary_color'] ); ?>">
				</div>

				<div class="wpaia-form-row">
					<label for="wpaia-tone">Default Tone</label>
					<select id="wpaia-tone">
						<option value="professional" <?php selected( $settings['tone'], 'professional' ); ?>>Professional</option>
						<option value="casual" <?php selected( $settings['tone'], 'casual' ); ?>>Casual</option>
						<option value="friendly" <?php selected( $settings['tone'], 'friendly' ); ?>>Friendly</option>
						<option value="sales" <?php selected( $settings['tone'], 'sales' ); ?>>Sales</option>
					</select>
				</div>

				<div class="wpaia-form-row wpaia-form-row-checkbox">
					<label><input type="checkbox" id="wpaia-enable-chat" <?php checked( ! empty( $settings['enable_chat'] ) ); ?>> Enable Knowledge Chat widget</label>
				</div>
				<div class="wpaia-form-row wpaia-form-row-checkbox">
					<label><input type="checkbox" id="wpaia-enable-seo" <?php checked( ! empty( $settings['enable_seo'] ) ); ?>> Enable SEO Generator (Editor Assistant)</label>
				</div>
				<div class="wpaia-form-row wpaia-form-row-checkbox">
					<label><input type="checkbox" id="wpaia-enable-faq" <?php checked( ! empty( $settings['enable_faq'] ) ); ?>> Enable FAQ Generator</label>
				</div>

				<button type="button" class="wpaia-btn wpaia-btn-primary" id="wpaia-save-settings-btn">
					<span class="wpaia-btn-label">Save Settings</span>
					<span class="wpaia-spinner" hidden></span>
				</button>
			</div>

			<div id="wpaia-toast-root"></div>
		</div>
		<?php
	}

	/** ===================================================================
	 * AJAX: Admin
	 * =================================================================== */

	public function ajax_build_index() {
		check_ajax_referer( 'wpaia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$index = wpaia_build_index_data();
		update_option( 'wpaia_search_index', $index, false );
		update_option(
			'wpaia_index_meta',
			array(
				'count'    => count( $index ),
				'built_at' => time(),
			)
		);

		wp_send_json_success(
			array(
				'count'   => count( $index ),
				'message' => 'Index built successfully!',
			)
		);
	}

	public function ajax_save_settings() {
		check_ajax_referer( 'wpaia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$settings = array(
			'brand_name'    => isset( $_POST['brand_name'] ) ? sanitize_text_field( wp_unslash( $_POST['brand_name'] ) ) : 'AI Assistant',
			'primary_color' => isset( $_POST['primary_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['primary_color'] ) ) : '#6366f1',
			'tone'          => isset( $_POST['tone'] ) ? sanitize_text_field( wp_unslash( $_POST['tone'] ) ) : 'professional',
			'enable_chat'   => ! empty( $_POST['enable_chat'] ) ? 1 : 0,
			'enable_seo'    => ! empty( $_POST['enable_seo'] ) ? 1 : 0,
			'enable_faq'    => ! empty( $_POST['enable_faq'] ) ? 1 : 0,
		);

		if ( ! in_array( $settings['tone'], array( 'professional', 'casual', 'friendly', 'sales' ), true ) ) {
			$settings['tone'] = 'professional';
		}
		if ( ! $settings['primary_color'] ) {
			$settings['primary_color'] = '#6366f1';
		}
		if ( '' === trim( $settings['brand_name'] ) ) {
			$settings['brand_name'] = 'AI Assistant';
		}

		update_option( 'wpaia_settings', $settings );

		wp_send_json_success( array( 'message' => 'Settings saved!', 'settings' => $settings ) );
	}

	public function ajax_get_stats() {
		check_ajax_referer( 'wpaia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		wp_send_json_success( $this->get_dashboard_stats() );
	}

	/** ===================================================================
	 * AJAX: Editor Assistant
	 * =================================================================== */

	private function verify_editor_request() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wpaia_editor_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid request.' ), 403 );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
	}

	public function ajax_generate_seo() {
		$this->verify_editor_request();

		$brief = isset( $_POST['brief'] ) ? sanitize_textarea_field( wp_unslash( $_POST['brief'] ) ) : '';
		if ( '' === trim( $brief ) ) {
			wp_send_json_error( array( 'message' => 'Please enter a brief first.' ) );
		}

		wp_send_json_success( wpaia_generate_seo_copy( $brief ) );
	}

	public function ajax_summarize() {
		$this->verify_editor_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post not found.' ) );
		}

		$summary = wpaia_summarize_content( $post->post_content, 3 );
		wp_send_json_success( array( 'summary' => $summary ) );
	}

	public function ajax_generate_faq() {
		$this->verify_editor_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post not found.' ) );
		}

		$faqs = wpaia_generate_faqs( $post->post_content );
		wp_send_json_success( array( 'faqs' => $faqs ) );
	}

	public function ajax_change_tone() {
		$this->verify_editor_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$tone    = isset( $_POST['tone'] ) ? sanitize_text_field( wp_unslash( $_POST['tone'] ) ) : 'professional';
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post not found.' ) );
		}

		$rewritten = wpaia_change_tone( wp_strip_all_tags( $post->post_content ), $tone );
		wp_send_json_success( array( 'content' => $rewritten ) );
	}

	/** ===================================================================
	 * REST API
	 * =================================================================== */

	public function register_rest_routes() {
		register_rest_route(
			'wpaia/v1',
			'/index',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_index' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wpaia/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			'wpaia/v1',
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_ask' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wpaia/v1',
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_stats' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public function rest_get_index( WP_REST_Request $request ) {
		$index = get_option( 'wpaia_search_index', array() );
		return rest_ensure_response( array( 'items' => $index ) );
	}

	public function rest_search( WP_REST_Request $request ) {
		$q = sanitize_text_field( $request->get_param( 'q' ) );
		if ( '' === trim( $q ) ) {
			return new WP_Error( 'wpaia_bad_query', 'Missing search query.', array( 'status' => 400 ) );
		}
		$results = wpaia_search_index( $q );
		return rest_ensure_response( array( 'results' => $results ) );
	}

	public function rest_ask( WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$question = isset( $body['question'] ) ? sanitize_text_field( $body['question'] ) : sanitize_text_field( $request->get_param( 'question' ) );

		if ( '' === trim( (string) $question ) ) {
			return new WP_Error( 'wpaia_bad_question', 'Missing question.', array( 'status' => 400 ) );
		}

		$answer = wpaia_answer_question( $question );
		wpaia_record_question( $question );

		return rest_ensure_response( $answer );
	}

	public function rest_stats( WP_REST_Request $request ) {
		return rest_ensure_response( $this->get_dashboard_stats() );
	}
}

WPAIA_Plugin::instance();

/**
 * =========================================================================
 * INDEX BUILDING & SEARCH (no external API — pure PHP heuristics)
 * =========================================================================
 */

function wpaia_build_index_data() {
	$query = new WP_Query(
		array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'no_found_rows'  => true,
		)
	);

	$index = array();
	foreach ( $query->posts as $post ) {
		$content = wp_strip_all_tags( $post->post_content );
		$content = preg_replace( '/\s+/', ' ', $content );

		$index[] = array(
			'id'      => $post->ID,
			'title'   => get_the_title( $post ),
			'url'     => get_permalink( $post ),
			'excerpt' => mb_substr( $content, 0, 220 ),
			'content' => mb_substr( $content, 0, 2000 ),
		);
	}
	wp_reset_postdata();

	return $index;
}

function wpaia_stopwords() {
	return array(
		'the', 'a', 'an', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
		'to', 'of', 'in', 'on', 'for', 'with', 'as', 'at', 'by', 'from', 'that', 'this', 'these',
		'those', 'it', 'its', 'we', 'you', 'your', 'i', 'he', 'she', 'they', 'them', 'his', 'her',
		'their', 'our', 'what', 'which', 'who', 'when', 'where', 'why', 'how', 'do', 'does', 'did',
		'can', 'could', 'will', 'would', 'should', 'may', 'might', 'not', 'no', 'so', 'if', 'then',
		'than', 'also', 'about', 'into', 'over', 'up', 'down', 'out', 'have', 'has', 'had',
	);
}

function wpaia_tokenize( $text ) {
	$text = strtolower( $text );
	preg_match_all( '/[a-z0-9]+/', $text, $m );
	$stop = array_flip( wpaia_stopwords() );
	return array_values( array_filter( $m[0], function ( $t ) use ( $stop ) {
		return strlen( $t ) > 2 && ! isset( $stop[ $t ] );
	} ) );
}

function wpaia_search_index( $query, $limit = 5 ) {
	$index = get_option( 'wpaia_search_index', array() );
	if ( empty( $index ) ) {
		return array();
	}

	$tokens = wpaia_tokenize( $query );
	if ( empty( $tokens ) ) {
		return array();
	}

	$scored = array();
	foreach ( $index as $item ) {
		$title_lc   = strtolower( $item['title'] );
		$content_lc = strtolower( $item['content'] );
		$score      = 0;

		foreach ( $tokens as $t ) {
			$score += substr_count( $title_lc, $t ) * 3;
			$score += substr_count( $content_lc, $t );
		}

		if ( $score > 0 ) {
			$scored[] = array_merge( $item, array( 'score' => $score ) );
		}
	}

	usort( $scored, function ( $a, $b ) {
		return $b['score'] <=> $a['score'];
	} );

	return array_slice( $scored, 0, $limit );
}

function wpaia_best_sentence( $content, $tokens ) {
	$sentences = preg_split( '/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY );
	$best      = '';
	$best_score = 0;

	foreach ( $sentences as $s ) {
		$lc    = strtolower( $s );
		$score = 0;
		foreach ( $tokens as $t ) {
			$score += substr_count( $lc, $t );
		}
		if ( $score > $best_score ) {
			$best_score = $score;
			$best       = trim( $s );
		}
	}

	return $best;
}

function wpaia_answer_question( $question ) {
	$tokens  = wpaia_tokenize( $question );
	$matches = wpaia_search_index( $question, 3 );

	if ( empty( $matches ) ) {
		return array(
			'answer'  => "I couldn't find anything about that in the site's content yet. Try rephrasing your question, or ask about a topic covered on this site.",
			'sources' => array(),
		);
	}

	$top      = $matches[0];
	$sentence = wpaia_best_sentence( $top['content'], $tokens );
	$answer   = $sentence ? $sentence : $top['excerpt'];

	$sources = array();
	foreach ( $matches as $m ) {
		$sources[] = array(
			'title' => $m['title'],
			'url'   => $m['url'],
		);
	}

	return array(
		'answer'  => $answer,
		'sources' => $sources,
	);
}

function wpaia_record_question( $question ) {
	$stats = get_option(
		'wpaia_stats',
		array(
			'total_questions' => 0,
			'questions'       => array(),
			'daily'           => array(),
		)
	);

	$stats['total_questions'] = (int) $stats['total_questions'] + 1;

	$key = mb_strtolower( trim( $question ) );
	$key = mb_substr( $key, 0, 120 );
	if ( ! isset( $stats['questions'][ $key ] ) ) {
		$stats['questions'][ $key ] = 0;
	}
	$stats['questions'][ $key ]++;

	// Keep the questions map from growing without bound.
	if ( count( $stats['questions'] ) > 200 ) {
		arsort( $stats['questions'] );
		$stats['questions'] = array_slice( $stats['questions'], 0, 200, true );
	}

	$today = gmdate( 'Y-m-d' );
	if ( ! isset( $stats['daily'][ $today ] ) ) {
		$stats['daily'][ $today ] = 0;
	}
	$stats['daily'][ $today ]++;

	// Keep only the last 30 days.
	if ( count( $stats['daily'] ) > 30 ) {
		$cutoff = strtotime( '-30 days' );
		foreach ( $stats['daily'] as $day => $count ) {
			if ( strtotime( $day ) < $cutoff ) {
				unset( $stats['daily'][ $day ] );
			}
		}
	}

	update_option( 'wpaia_stats', $stats, false );
}

/**
 * =========================================================================
 * SMART "AI" FEATURES — rule-based, no external API calls
 * =========================================================================
 */

// --- Editor Assistant: SEO/copy generation from a short brief -------------

function wpaia_generate_seo_copy( $brief ) {
	$brief       = trim( preg_replace( '/\s+/', ' ', $brief ) );
	$brief_lc    = strtolower( $brief );
	$words       = preg_split( '/\s+/', $brief );
	$key_phrase  = implode( ' ', array_slice( $words, 0, min( 6, count( $words ) ) ) );
	$key_phrase  = rtrim( $key_phrase, '.,;:' );
	$capitalized = ucfirst( $key_phrase );

	$title_templates = array(
		$capitalized . ' | Everything You Need to Know',
		'The Complete Guide to ' . $capitalized,
		$capitalized . ' — Trusted by Thousands',
	);

	$meta_templates = array(
		'Discover ' . lcfirst( $key_phrase ) . '. Learn more and get started today.',
		'Everything about ' . lcfirst( $key_phrase ) . ', explained simply — benefits, features, and how to get started.',
	);

	$hero_templates = array(
		$capitalized,
		'Meet ' . $capitalized,
		$capitalized . ', Reimagined',
	);

	$summary = ucfirst( $brief );
	if ( substr( $summary, -1 ) !== '.' ) {
		$summary .= '.';
	}
	$summary .= ' Built to deliver real results, this page brings together everything visitors need in one clear, focused experience.';

	return array(
		'seo_title'       => wpaia_truncate_words( $title_templates[ array_rand( $title_templates ) ], 60 ),
		'meta_description' => wpaia_truncate_words( $meta_templates[ array_rand( $meta_templates ) ], 160 ),
		'hero_headline'   => $hero_templates[ array_rand( $hero_templates ) ],
		'summary'         => $summary,
	);
}

function wpaia_truncate_words( $text, $max_chars ) {
	if ( mb_strlen( $text ) <= $max_chars ) {
		return $text;
	}
	return rtrim( mb_substr( $text, 0, $max_chars ) ) . '…';
}

// --- Content Summarizer: frequency-scored extractive summary --------------

function wpaia_summarize_content( $html_content, $num_sentences = 3 ) {
	$content = wp_strip_all_tags( $html_content );
	$content = preg_replace( '/\s+/', ' ', trim( $content ) );

	if ( '' === $content ) {
		return 'No content to summarize yet — add some text to this post first.';
	}

	$sentences = preg_split( '/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY );
	if ( count( $sentences ) <= $num_sentences ) {
		return implode( ' ', $sentences );
	}

	// Word-frequency scoring (stopwords excluded).
	$stop = array_flip( wpaia_stopwords() );
	$freq = array();
	foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $content ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
		if ( strlen( $w ) > 2 && ! isset( $stop[ $w ] ) ) {
			$freq[ $w ] = isset( $freq[ $w ] ) ? $freq[ $w ] + 1 : 1;
		}
	}

	$scored = array();
	foreach ( $sentences as $i => $s ) {
		$score = 0;
		foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $s ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( isset( $freq[ $w ] ) ) {
				$score += $freq[ $w ];
			}
		}
		// Slightly favor earlier sentences (intros tend to matter).
		$score += max( 0, 3 - $i ) * 0.5;
		$scored[ $i ] = $score;
	}

	arsort( $scored );
	$top_indexes = array_slice( array_keys( $scored ), 0, $num_sentences );
	sort( $top_indexes ); // restore original order

	$summary = array();
	foreach ( $top_indexes as $i ) {
		$summary[] = trim( $sentences[ $i ] );
	}

	return implode( ' ', $summary );
}

// --- FAQ Generator: rule-based Q&A templating ------------------------------

function wpaia_generate_faqs( $html_content, $max_faqs = 5 ) {
	$content = wp_strip_all_tags( $html_content );
	$content = preg_replace( '/\s+/', ' ', trim( $content ) );

	if ( '' === $content ) {
		return array();
	}

	$sentences = preg_split( '/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY );

	$stop = array_flip( wpaia_stopwords() );
	$freq = array();
	foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $content ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
		if ( strlen( $w ) > 3 && ! isset( $stop[ $w ] ) ) {
			$freq[ $w ] = isset( $freq[ $w ] ) ? $freq[ $w ] + 1 : 1;
		}
	}
	arsort( $freq );
	$keywords = array_slice( array_keys( $freq ), 0, 12 );

	$question_templates = array(
		'What is %s?',
		'How does %s work?',
		'Why does %s matter?',
		'What are the benefits of %s?',
		'When should you consider %s?',
	);

	$faqs      = array();
	$used_sent = array();
	$t_i       = 0;

	foreach ( $keywords as $kw ) {
		if ( count( $faqs ) >= $max_faqs ) {
			break;
		}

		// Find the best sentence mentioning this keyword that we haven't used yet.
		$best_i = -1;
		foreach ( $sentences as $i => $s ) {
			if ( isset( $used_sent[ $i ] ) ) {
				continue;
			}
			if ( stripos( $s, $kw ) !== false ) {
				$best_i = $i;
				break;
			}
		}
		if ( -1 === $best_i ) {
			continue;
		}

		$used_sent[ $best_i ] = true;
		$template              = $question_templates[ $t_i % count( $question_templates ) ];
		$t_i++;

		$faqs[] = array(
			'question' => sprintf( $template, ucwords( str_replace( '-', ' ', $kw ) ) ),
			'answer'   => trim( $sentences[ $best_i ] ),
		);
	}

	return $faqs;
}

// --- Tone Changer: word-substitution + sentence-style templates -----------

function wpaia_tone_map( $tone ) {
	$maps = array(
		'professional' => array(
			'get'    => 'obtain',
			'buy'    => 'purchase',
			'help'   => 'assist',
			'show'   => 'demonstrate',
			'big'    => 'substantial',
			'a lot of' => 'a significant amount of',
			'stuff'  => 'materials',
			'great'  => 'excellent',
			'good'   => 'effective',
		),
		'casual'       => array(
			'obtain'  => 'get',
			'purchase' => 'buy',
			'assist'  => 'help',
			'demonstrate' => 'show',
			'substantial' => 'big',
			'utilize' => 'use',
			'excellent' => 'awesome',
			'effective' => 'good',
		),
		'friendly'     => array(
			'obtain'  => 'grab',
			'purchase' => 'pick up',
			'assist'  => 'help out',
			'utilize' => 'use',
			'excellent' => 'wonderful',
			'customer' => 'friend',
		),
		'sales'        => array(
			'good'    => 'game-changing',
			'great'   => 'incredible',
			'help'    => 'supercharge',
			'get'     => 'unlock',
			'buy'     => 'grab yours now',
			'use'     => 'take advantage of',
		),
	);

	return isset( $maps[ $tone ] ) ? $maps[ $tone ] : $maps['professional'];
}

function wpaia_change_tone( $content, $tone ) {
	$allowed = array( 'professional', 'casual', 'friendly', 'sales' );
	if ( ! in_array( $tone, $allowed, true ) ) {
		$tone = 'professional';
	}

	$map     = wpaia_tone_map( $tone );
	$result  = $content;

	foreach ( $map as $from => $to ) {
		$result = preg_replace( '/\b' . preg_quote( $from, '/' ) . '\b/i', $to, $result );
	}

	$sentences = preg_split( '/(?<=[.!?])\s+/', $result, -1, PREG_SPLIT_NO_EMPTY );

	if ( 'sales' === $tone && ! empty( $sentences ) ) {
		$sentences[] = "Don't wait — this is the opportunity you've been looking for!";
	} elseif ( 'friendly' === $tone && ! empty( $sentences ) ) {
		$sentences[] = "We're always here if you have any questions — happy to help! 😊";
	} elseif ( 'professional' === $tone && ! empty( $sentences ) ) {
		$sentences[] = 'For further information, please reach out to our team directly.';
	}

	return implode( ' ', $sentences );
}
