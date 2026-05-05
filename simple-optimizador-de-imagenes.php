<?php
/**
 * Plugin Name: Simple Optimizador de Imágenes
 * Description: Optimiza automáticamente imágenes: convierte JPG/PNG a WebP, limita tamaño y dimensiones, valida nombres, y elimina tamaños intermedios. Cada función es activable de forma independiente.
 * Version: 1.10
 * Author: Paco Castilla
 * Author URI: https://macgrafic.com
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

// ─── Actualizaciones automáticas desde GitHub ─────────────────────────────
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/load-v5p5.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$pkUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/macgrafic/simple-optimizador-de-imagenes/',
	__FILE__,
	'simple-optimizador-de-imagenes'
);
$pkUpdateChecker->setBranch('main');
// ──────────────────────────────────────────────────────────────────────────

class PK_Restringir_Imagenes {

	private $defaults = array(
		// Módulos activos
		'mod_webp'        => 1,
		'mod_tamanos'     => 1,
		'mod_dimensiones' => 1,
		'mod_peso'        => 1,
		'mod_nombres'     => 1,
		// Valores
		'max_file_size'    => 1.5,
		'max_width'        => 1200,
		'max_height'       => 1200,
		'max_width_banner' => 1700,
		'max_height_banner'=> 1700,
		// Palabras prohibidas en nombres de archivo (una por línea)
		'palabras_prohibidas' => "WhatsApp\nCaptura-de-pantalla\nCaptura\nScreenshot\nIMG_\npexels-moose",
	);

	private $opts;

	public function __construct() {
		$saved      = get_option('pk_optimizador_settings', array());
		$this->opts = wp_parse_args($saved, $this->defaults);

		// Siempre: permitir WebP como tipo MIME (necesario aunque no se convierta)
		add_filter('mime_types',   array($this, 'permitir_webp'));
		add_filter('upload_mimes', array($this, 'permitir_webp'));

		// Validación al subir (peso, dimensiones, nombres — según módulos)
		add_filter('wp_handle_upload_prefilter', array($this, 'validar_imagen_subida'));

		// Módulo: Convertir a WebP
		if ($this->opts['mod_webp']) {
			add_filter('wp_handle_upload', array($this, 'convertir_a_webp'));
		}

		// Módulo: Deshabilitar tamaños intermedios
		if ($this->opts['mod_tamanos']) {
			add_filter('intermediate_image_sizes_advanced',          array($this, 'deshabilitar_tamanos_imagen'));
			add_filter('woocommerce_get_image_size_gallery_thumbnail', array($this, 'deshabilitar_tamano_woocommerce'));
			add_filter('woocommerce_get_image_size_thumbnail',         array($this, 'deshabilitar_tamano_woocommerce'));
			add_filter('woocommerce_get_image_size_single',            array($this, 'deshabilitar_tamano_woocommerce'));
			add_filter('big_image_size_threshold', '__return_false');
		}

		// Admin
		add_action('admin_menu',             array($this, 'registrar_menu'));
		add_action('admin_init',             array($this, 'registrar_ajustes'));
		add_action('admin_enqueue_scripts',  array($this, 'enqueue_assets'));
	}

	// ─────────────────────────────────────────────
	// ADMIN
	// ─────────────────────────────────────────────

	public function registrar_menu() {
		add_options_page(
			'Optimizador de Imágenes',
			'Optimizador de Imágenes',
			'manage_options',
			'simple-optimizador-de-imagenes',
			array($this, 'pagina_ajustes')
		);
	}

	public function registrar_ajustes() {
		register_setting('pk_optimizador_group', 'pk_optimizador_settings', array($this, 'sanitizar_ajustes'));
	}

	public function sanitizar_ajustes($input) {
		$clean = array();
		// Módulos (checkbox: 1 si está marcado, 0 si no)
		foreach (array('mod_webp', 'mod_tamanos', 'mod_dimensiones', 'mod_peso', 'mod_nombres') as $mod) {
			$clean[$mod] = !empty($input[$mod]) ? 1 : 0;
		}
		// Valores numéricos
		$clean['max_file_size']     = isset($input['max_file_size'])     ? floatval($input['max_file_size'])  : $this->defaults['max_file_size'];
		$clean['max_width']         = isset($input['max_width'])         ? absint($input['max_width'])         : $this->defaults['max_width'];
		$clean['max_height']        = isset($input['max_height'])        ? absint($input['max_height'])        : $this->defaults['max_height'];
		$clean['max_width_banner']  = isset($input['max_width_banner'])  ? absint($input['max_width_banner'])  : $this->defaults['max_width_banner'];
		$clean['max_height_banner'] = isset($input['max_height_banner']) ? absint($input['max_height_banner']) : $this->defaults['max_height_banner'];
		// Palabras prohibidas: sanitizar línea a línea
		$raw    = isset($input['palabras_prohibidas']) ? $input['palabras_prohibidas'] : '';
		$lineas = array_filter(array_map('sanitize_text_field', explode("\n", $raw)));
		$clean['palabras_prohibidas'] = implode("\n", $lineas);
		set_transient( 'pk_settings_saved_' . get_current_user_id(), true, 60 );
		return $clean;
	}

	public function enqueue_assets($hook) {
		if ($hook !== 'settings_page_simple-optimizador-de-imagenes') return;
		wp_add_inline_style('wp-admin', $this->get_admin_css());
		wp_add_inline_script('jquery', $this->get_admin_js());
	}

	private function get_admin_css() {
		return '
		.pk-wrap {
			max-width: 700px;
			margin-top: 30px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
		}
		.pk-header {
			background: linear-gradient(135deg, #1e1e2e 0%, #2d2d44 100%);
			border-radius: 12px 12px 0 0;
			padding: 28px 32px;
			display: flex;
			align-items: center;
			gap: 16px;
		}
		.pk-header-icon { font-size: 36px; line-height: 1; }
		.pk-header h1 {
			color: #fff !important;
			font-size: 22px !important;
			font-weight: 600 !important;
			margin: 0 !important;
			padding: 0 !important;
			line-height: 1.3 !important;
		}
		.pk-header p {
			color: rgba(255,255,255,0.6);
			margin: 4px 0 0 !important;
			font-size: 13px;
		}
		.pk-body {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-top: none;
			border-radius: 0 0 12px 12px;
			padding: 32px;
		}
		.pk-section { margin-bottom: 28px; }
		.pk-section-title {
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.08em;
			color: #aaa;
			margin: 0 0 14px 0;
			padding-bottom: 8px;
			border-bottom: 1px solid #f0f0f0;
		}

		/* ── Módulos ── */
		.pk-modules { display: flex; flex-direction: column; gap: 10px; }
		.pk-module {
			display: flex;
			align-items: center;
			justify-content: space-between;
			background: #f8f8fb;
			border: 1.5px solid #e8e8f0;
			border-radius: 10px;
			padding: 14px 18px;
			transition: border-color 0.2s;
		}
		.pk-module.active { border-color: #c5bfff; background: #f3f1ff; }
		.pk-module-info { flex: 1; }
		.pk-module-info strong {
			display: block;
			font-size: 14px;
			font-weight: 600;
			color: #1e1e2e;
			margin-bottom: 2px;
		}
		.pk-module-info span {
			font-size: 12px;
			color: #888;
		}

		/* Toggle switch */
		.pk-toggle { position: relative; width: 44px; height: 24px; flex-shrink: 0; margin-left: 16px; }
		.pk-toggle input { opacity: 0; width: 0; height: 0; }
		.pk-toggle-slider {
			position: absolute;
			inset: 0;
			background: #ddd;
			border-radius: 24px;
			cursor: pointer;
			transition: background 0.25s;
		}
		.pk-toggle-slider:before {
			content: "";
			position: absolute;
			width: 18px;
			height: 18px;
			left: 3px;
			top: 3px;
			background: #fff;
			border-radius: 50%;
			transition: transform 0.25s;
			box-shadow: 0 1px 3px rgba(0,0,0,0.2);
		}
		.pk-toggle input:checked + .pk-toggle-slider { background: #6c63ff; }
		.pk-toggle input:checked + .pk-toggle-slider:before { transform: translateX(20px); }

		/* ── Campos numéricos ── */
		.pk-fields {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 14px;
		}
		.pk-fields.single { grid-template-columns: 1fr; max-width: 260px; }
		.pk-field label {
			display: block;
			font-size: 13px;
			font-weight: 600;
			color: #3c3c3c;
			margin-bottom: 5px;
		}
		.pk-field .pk-desc {
			font-size: 11px;
			color: #999;
			margin-bottom: 7px;
			display: block;
		}
		.pk-input-wrap { position: relative; display: flex; align-items: center; }
		.pk-input-wrap input[type="number"] {
			width: 100%;
			padding: 9px 46px 9px 13px;
			border: 1.5px solid #ddd;
			border-radius: 8px;
			font-size: 15px;
			font-weight: 500;
			color: #1e1e2e;
			background: #fafafa;
			box-sizing: border-box;
			transition: border-color 0.2s, box-shadow 0.2s;
			-moz-appearance: textfield;
		}
		.pk-input-wrap input[type="number"]::-webkit-inner-spin-button,
		.pk-input-wrap input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; }
		.pk-input-wrap input[type="number"]:focus {
			outline: none;
			border-color: #6c63ff;
			box-shadow: 0 0 0 3px rgba(108,99,255,0.12);
			background: #fff;
		}
		.pk-input-wrap input:disabled {
			opacity: 0.4;
			cursor: not-allowed;
		}
		.pk-unit {
			position: absolute;
			right: 11px;
			font-size: 11px;
			font-weight: 700;
			color: #bbb;
			pointer-events: none;
		}
		.pk-subsection {
			margin-top: 14px;
			padding: 16px;
			background: #fafafa;
			border: 1px solid #eee;
			border-radius: 8px;
			transition: opacity 0.2s;
		}
		.pk-subsection.disabled { opacity: 0.4; pointer-events: none; }
		.pk-subsection-label {
			font-size: 11px;
			font-weight: 700;
			color: #bbb;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			margin-bottom: 12px;
			display: block;
		}

		/* Banner note */
		.pk-banner-note {
			background: #f0f7ff;
			border: 1px solid #cce0ff;
			border-radius: 8px;
			padding: 10px 14px;
			font-size: 12px;
			color: #3a6ea8;
			margin-bottom: 14px;
			line-height: 1.5;
		}

		/* Submit */
		.pk-submit-row {
			display: flex;
			align-items: center;
			gap: 16px;
			padding-top: 8px;
			border-top: 1px solid #f0f0f0;
			margin-top: 28px;
		}
		.pk-btn {
			background: linear-gradient(135deg, #6c63ff, #5a52d5);
			color: #fff !important;
			border: none;
			border-radius: 8px;
			padding: 10px 24px;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
			transition: opacity 0.2s, transform 0.1s;
		}
		.pk-btn:hover { opacity: 0.9; transform: translateY(-1px); }
		.pk-btn:active { transform: translateY(0); }
		.pk-reset {
			font-size: 12px;
			color: #bbb;
			text-decoration: none;
			cursor: pointer;
			background: none;
			border: none;
			padding: 0;
		}
		.pk-reset:hover { color: #666; }
		.pk-notice-ok {
			background: #f0faf4;
			border-left: 4px solid #46b450;
			color: #2a6e33;
			padding: 10px 16px;
			border-radius: 0 8px 8px 0;
			font-size: 13px;
			margin-bottom: 24px;
		}
		.pk-textarea {
			width: 100%;
			min-height: 120px;
			padding: 10px 13px;
			border: 1.5px solid #ddd;
			border-radius: 8px;
			font-size: 13px;
			font-family: "SFMono-Regular", Consolas, monospace;
			color: #1e1e2e;
			background: #fafafa;
			box-sizing: border-box;
			resize: vertical;
			line-height: 1.7;
			transition: border-color 0.2s, box-shadow 0.2s;
		}
		.pk-textarea:focus {
			outline: none;
			border-color: #6c63ff;
			box-shadow: 0 0 0 3px rgba(108,99,255,0.12);
			background: #fff;
		}
		.pk-textarea:disabled { opacity: 0.4; cursor: not-allowed; }
		.pk-tag-hint {
			font-size: 11px;
			color: #aaa;
			margin-top: 6px;
			display: block;
		}
		';
	}

	private function get_admin_js() {
		return '
		jQuery(function($) {
			function toggleSubsection(checkbox, subsectionId) {
				var $sub = $("#" + subsectionId);
				if ($(checkbox).is(":checked")) {
					$sub.removeClass("disabled");
				} else {
					$sub.addClass("disabled");
				}
			}
			function toggleModule(checkbox) {
				var $mod = $(checkbox).closest(".pk-module");
				if ($(checkbox).is(":checked")) {
					$mod.addClass("active");
				} else {
					$mod.removeClass("active");
				}
			}

			// Inicializar estado al cargar
			$(".pk-module input[type=checkbox]").each(function() {
				toggleModule(this);
			});
			$("#mod_peso").each(function() { toggleSubsection(this, "sub-peso"); });
			$("#mod_dimensiones").each(function() { toggleSubsection(this, "sub-dimensiones"); });
			$("#mod_nombres").each(function() { toggleSubsection(this, "sub-nombres"); });

			// Eventos
			$(".pk-module input[type=checkbox]").on("change", function() {
				toggleModule(this);
			});
			$("#mod_peso").on("change", function() { toggleSubsection(this, "sub-peso"); });
			$("#mod_dimensiones").on("change", function() { toggleSubsection(this, "sub-dimensiones"); });
			$("#mod_nombres").on("change", function() { toggleSubsection(this, "sub-nombres"); });
		});
		';
	}

	public function pagina_ajustes() {
		if (!current_user_can('manage_options')) return;
		$o     = $this->opts;
		$saved = (bool) get_transient( 'pk_settings_saved_' . get_current_user_id() );
		if ( $saved ) {
			delete_transient( 'pk_settings_saved_' . get_current_user_id() );
		}
		?>
		<div class="pk-wrap">

			<div class="pk-header">
				<div class="pk-header-icon">🖼️</div>
				<div>
					<h1>Optimizador de Imágenes</h1>
					<p>Activa o desactiva cada módulo de forma independiente</p>
				</div>
			</div>

			<div class="pk-body">

				<?php if ($saved): ?>
				<div class="pk-notice-ok">✅ Ajustes guardados correctamente.</div>
				<?php endif; ?>

				<form method="post" action="options.php">
					<?php settings_fields('pk_optimizador_group'); ?>

					<!-- MÓDULOS -->
					<div class="pk-section">
						<div class="pk-section-title">Módulos</div>
						<div class="pk-modules">

							<?php $this->render_modulo(
								'mod_webp',
								'🔄  Convertir a WebP',
								'Convierte automáticamente JPG y PNG a WebP (calidad 85) al subir.',
								$o['mod_webp']
							); ?>

							<?php $this->render_modulo(
								'mod_tamanos',
								'✂️  Deshabilitar tamaños intermedios',
								'Evita que WordPress genere thumbnail, medium, large, etc. Solo se guarda el original.',
								$o['mod_tamanos']
							); ?>

							<!-- Módulo nombres con subsección -->
							<div class="pk-module <?php echo $o['mod_nombres'] ? 'active' : ''; ?>" style="flex-wrap:wrap;">
								<div class="pk-module-info">
									<strong>✏️  Validar y limpiar nombres</strong>
									<span>Rechaza nombres que contengan palabras prohibidas y limpia acentos, espacios y caracteres especiales.</span>
								</div>
								<label class="pk-toggle">
									<input type="checkbox" id="mod_nombres" name="pk_optimizador_settings[mod_nombres]" value="1" <?php checked($o['mod_nombres'], 1); ?>>
									<span class="pk-toggle-slider"></span>
								</label>
								<div class="pk-subsection <?php echo !$o['mod_nombres'] ? 'disabled' : ''; ?>" id="sub-nombres" style="width:100%;margin-top:14px;">
									<span class="pk-subsection-label">Palabras prohibidas</span>
									<textarea
										id="pk_palabras_prohibidas"
										name="pk_optimizador_settings[palabras_prohibidas]"
										class="pk-textarea"
									><?php echo esc_textarea($o['palabras_prohibidas']); ?></textarea>
									<span class="pk-tag-hint">Una palabra por línea. Si el nombre del archivo contiene alguna de estas palabras será rechazado.</span>
								</div>
							</div>

							<!-- Módulo peso con subsección -->
							<div class="pk-module <?php echo $o['mod_peso'] ? 'active' : ''; ?>" style="flex-wrap:wrap;">
								<div class="pk-module-info">
									<strong>⚖️  Limitar peso del archivo</strong>
									<span>Rechaza archivos que superen el tamaño máximo configurado.</span>
								</div>
								<label class="pk-toggle">
									<input type="checkbox" id="mod_peso" name="pk_optimizador_settings[mod_peso]" value="1" <?php checked($o['mod_peso'], 1); ?>>
									<span class="pk-toggle-slider"></span>
								</label>
								<div class="pk-subsection <?php echo !$o['mod_peso'] ? 'disabled' : ''; ?>" id="sub-peso" style="width:100%;margin-top:14px;">
									<span class="pk-subsection-label">Configuración de peso</span>
									<div class="pk-fields single">
										<div class="pk-field">
											<label for="pk_max_file_size">Peso máximo</label>
											<div class="pk-input-wrap">
												<input type="number" id="pk_max_file_size" name="pk_optimizador_settings[max_file_size]"
													value="<?php echo esc_attr($o['max_file_size']); ?>" min="0.1" max="50" step="0.1">
												<span class="pk-unit">MB</span>
											</div>
										</div>
									</div>
								</div>
							</div>

							<!-- Módulo dimensiones con subsección -->
							<div class="pk-module <?php echo $o['mod_dimensiones'] ? 'active' : ''; ?>" style="flex-wrap:wrap;">
								<div class="pk-module-info">
									<strong>📐  Validar dimensiones</strong>
									<span>Rechaza imágenes que superen el ancho o alto máximo configurado.</span>
								</div>
								<label class="pk-toggle">
									<input type="checkbox" id="mod_dimensiones" name="pk_optimizador_settings[mod_dimensiones]" value="1" <?php checked($o['mod_dimensiones'], 1); ?>>
									<span class="pk-toggle-slider"></span>
								</label>
								<div class="pk-subsection <?php echo !$o['mod_dimensiones'] ? 'disabled' : ''; ?>" id="sub-dimensiones" style="width:100%;margin-top:14px;">
									<span class="pk-subsection-label">Imágenes normales</span>
									<div class="pk-fields" style="margin-bottom:16px;">
										<div class="pk-field">
											<label for="pk_max_width">Ancho máximo</label>
											<div class="pk-input-wrap">
												<input type="number" id="pk_max_width" name="pk_optimizador_settings[max_width]"
													value="<?php echo esc_attr($o['max_width']); ?>" min="100" max="10000" step="10">
												<span class="pk-unit">px</span>
											</div>
										</div>
										<div class="pk-field">
											<label for="pk_max_height">Alto máximo</label>
											<div class="pk-input-wrap">
												<input type="number" id="pk_max_height" name="pk_optimizador_settings[max_height]"
													value="<?php echo esc_attr($o['max_height']); ?>" min="100" max="10000" step="10">
												<span class="pk-unit">px</span>
											</div>
										</div>
									</div>
									<div class="pk-banner-note">
										Archivos con nombre que empiece por <code>banner-</code> usan los límites de abajo.
									</div>
									<span class="pk-subsection-label">Banners</span>
									<div class="pk-fields">
										<div class="pk-field">
											<label for="pk_max_width_banner">Ancho máximo</label>
											<div class="pk-input-wrap">
												<input type="number" id="pk_max_width_banner" name="pk_optimizador_settings[max_width_banner]"
													value="<?php echo esc_attr($o['max_width_banner']); ?>" min="100" max="10000" step="10">
												<span class="pk-unit">px</span>
											</div>
										</div>
										<div class="pk-field">
											<label for="pk_max_height_banner">Alto máximo</label>
											<div class="pk-input-wrap">
												<input type="number" id="pk_max_height_banner" name="pk_optimizador_settings[max_height_banner]"
													value="<?php echo esc_attr($o['max_height_banner']); ?>" min="100" max="10000" step="10">
												<span class="pk-unit">px</span>
											</div>
										</div>
									</div>
								</div>
							</div>

						</div>
					</div>

					<div class="pk-submit-row">
						<button type="submit" class="pk-btn">Guardar ajustes</button>
						<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('page' => 'simple-optimizador-de-imagenes', 'pk-reset' => '1'), admin_url('options-general.php')), 'pk_reset_settings')); ?>" class="pk-reset">Restaurar valores por defecto</a>
					</div>

				</form>
			</div>
		</div>
		<?php
	}

	private function render_modulo($id, $titulo, $descripcion, $activo) {
		?>
		<div class="pk-module <?php echo $activo ? 'active' : ''; ?>">
			<div class="pk-module-info">
				<strong><?php echo esc_html($titulo); ?></strong>
				<span><?php echo esc_html($descripcion); ?></span>
			</div>
			<label class="pk-toggle">
				<input type="checkbox" id="<?php echo esc_attr($id); ?>" name="pk_optimizador_settings[<?php echo esc_attr($id); ?>]" value="1" <?php checked($activo, 1); ?>>
				<span class="pk-toggle-slider"></span>
			</label>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────
	// FUNCIONALIDAD
	// ─────────────────────────────────────────────

	public function permitir_webp($mimes) {
		$mimes['webp'] = 'image/webp';
		return $mimes;
	}

	public function deshabilitar_tamanos_imagen($sizes) {
		return array();
	}

	public function deshabilitar_tamano_woocommerce($size) {
		return array('width' => 0, 'height' => 0, 'crop' => 0);
	}

	public function limpiar_nombre_archivo($filename) {
		$file_info = pathinfo($filename);
		$nombre    = $file_info['filename'];
		$extension = isset($file_info['extension']) ? $file_info['extension'] : '';

		$nombre  = strtolower($nombre);
		$acentos = array(
			'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a',
			'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
			'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
			'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o',
			'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
			'ñ'=>'n','ç'=>'c',
		);
		$nombre = strtr($nombre, $acentos);
		$nombre = str_replace(' ', '-', $nombre);
		$nombre = preg_replace('/[^a-z0-9\-]/', '', $nombre);
		$nombre = preg_replace('/-+/', '-', $nombre);
		$nombre = trim($nombre, '-');

		if (strlen($nombre) > 30) {
			return array('error' => sprintf(
				'El nombre del archivo es demasiado largo (%d caracteres). El máximo es 30. Por favor, acórtalo antes de subirlo.',
				strlen($nombre)
			));
		}
		if (empty($nombre)) {
			return array('error' => 'El nombre del archivo no es válido. Usa letras, números o guiones.');
		}

		// Rechazar si el nombre no contiene ninguna letra (ej: "45812541251", "52541-58745")
		if (!preg_match('/[a-z]/', $nombre)) {
			return array('error' => sprintf(
				'El nombre "%s" no es descriptivo. Usa un nombre con letras que describa la imagen (por ejemplo: "producto-rojo-1.jpg").',
				$nombre
			));
		}

		return array('nombre' => $extension ? $nombre . '.' . $extension : $nombre);
	}

	/**
	 * Redimensiona una imagen al tamaño máximo manteniendo la proporción.
	 * Opera directamente sobre el archivo temporal antes de que WordPress lo guarde.
	 */
	private function redimensionar_imagen($file, $max_w, $max_h, $info) {
		list($w, $h) = $info;
		$mime = $info['mime'];

		// Protección decompression bomb
		if (($w * $h) > 25000000) {
			$file['error'] = 'La imagen es demasiado grande para procesarla automáticamente. Por favor, redúcela manualmente.';
			return $file;
		}

		// Calcular nuevas dimensiones manteniendo proporción
		$ratio = min($max_w / $w, $max_h / $h);
		$new_w = (int) round($w * $ratio);
		$new_h = (int) round($h * $ratio);

		// Cargar imagen original
		$src = false;
		switch ($mime) {
			case 'image/jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
			case 'image/png':  $src = @imagecreatefrompng($file['tmp_name']); break;
			case 'image/webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
		}
		if (!$src) return $file;

		// Crear imagen destino
		$dst = imagecreatetruecolor($new_w, $new_h);

		// Preservar transparencia en PNG
		if ($mime === 'image/png') {
			imagealphablending($dst, false);
			imagesavealpha($dst, true);
			$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
			imagefilledrectangle($dst, 0, 0, $new_w, $new_h, $transparent);
		}

		imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
		imagedestroy($src);

		// Guardar de vuelta al archivo temporal
		$ok = false;
		switch ($mime) {
			case 'image/jpeg': $ok = imagejpeg($dst, $file['tmp_name'], 90); break;
			case 'image/png':  $ok = imagepng($dst, $file['tmp_name']); break;
			case 'image/webp': $ok = imagewebp($dst, $file['tmp_name'], 85); break;
		}
		imagedestroy($dst);

		if ($ok) {
			$file['size'] = filesize($file['tmp_name']);
		}

		return $file;
	}

	/**
	 * Sanitiza un SVG eliminando scripts y atributos de evento para prevenir XSS.
	 */
	private function sanitizar_svg($contenido) {
		// Eliminar etiquetas <script>
		$contenido = preg_replace('/<script[\s\S]*?<\/script>/i', '', $contenido);

		// Eliminar atributos de evento (onclick, onload, onerror, etc.)
		$contenido = preg_replace('/\s+on\w+=["\'][^"\']*["\']/i', '', $contenido);

		// Eliminar javascript: en atributos href y xlink:href
		$contenido = preg_replace('/(href|xlink:href)\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', '$1=""', $contenido);

		// Eliminar use de fuentes externas (data: y http en src)
		$contenido = preg_replace('/<use[^>]+href=["\'](?:https?:\/\/|\/\/)[^"\']*["\']/i', '<use', $contenido);

		return $contenido;
	}

	/**
	 * Detecta si un archivo PNG tiene transparencia.
	 * Lee el color type del header IHDR (byte 25):
	 *   - Tipo 4 (Grayscale+Alpha) y 6 (RGBA): siempre tienen canal alpha.
	 *   - Tipo 3 (indexado): puede tener un color transparente; se verifica con GD.
	 *   - Tipos 0 y 2 (Grayscale/RGB puro): sin transparencia.
	 */
	private function png_tiene_transparencia($file_path) {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$contents = $wp_filesystem->get_contents( $file_path );
		if ( false === $contents || strlen( $contents ) < 26 ) {
			return true; // Si no podemos leer, conservar como PNG por precaución
		}
		$color_type = ord( $contents[25] );

		// Alpha nativo
		if ($color_type === 4 || $color_type === 6) return true;

		// Indexado: comprobar si tiene color transparente definido
		if ($color_type === 3) {
			$image = @imagecreatefrompng($file_path);
			if ($image) {
				$tiene = imagecolortransparent($image) >= 0;
				imagedestroy($image);
				return $tiene;
			}
		}

		return false;
	}

	public function convertir_a_webp($upload) {
		if (isset($upload['error']) && $upload['error']) return $upload;
		if (strpos($upload['type'], 'image') === false) return $upload;
		if ($upload['type'] === 'image/webp') return $upload;

		$file_path = $upload['file'];
		$file_info = pathinfo($file_path);
		$webp_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.webp';

		// Protección decompression bomb: verificar dimensiones reales antes de cargar con GD.
		// Límite fijo de 25 megapíxeles (~100MB de RAM), independiente de los ajustes del plugin.
		$size_info = @getimagesize($file_path);
		if ($size_info !== false) {
			list($img_w, $img_h) = $size_info;
			if (($img_w * $img_h) > 25000000) {
				return $upload; // No procesar: demasiados píxeles para GD
			}
		}

		$image = false;
		switch ($upload['type']) {
			case 'image/jpeg':
			case 'image/jpg':
				$image = @imagecreatefromjpeg($file_path);
				break;
			case 'image/png':
				// No convertir PNGs con transparencia: conservarlos como PNG
				if ($this->png_tiene_transparencia($file_path)) {
					return $upload;
				}
				$image = @imagecreatefrompng($file_path);
				break;
		}

		if (!$image) return $upload;

		$ok = imagewebp($image, $webp_path, 85);
		imagedestroy($image);

		if ($ok) {
			wp_delete_file( $file_path );
			$upload['file'] = $webp_path;
			$upload['url']  = str_replace($file_info['basename'], $file_info['filename'] . '.webp', $upload['url']);
			$upload['type'] = 'image/webp';
		}

		return $upload;
	}

	public function validar_imagen_subida($file) {
		$extensiones_ok = array('jpg','jpeg','png','webp','pdf','zip','xml','txt');
		$extension      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

		// Añadir SVG dinámicamente solo si otro plugin (ej. Bricks) lo ha habilitado
		// para el rol del usuario actual. Si Bricks lo prohíbe, SVG no estará aquí.
		$allowed_mimes = get_allowed_mime_types();
		if (isset($allowed_mimes['svg'])) {
			$extensiones_ok[] = 'svg';
		}

		// Lista cerrada: rechazar cualquier extensión no contemplada
		if (!in_array($extension, $extensiones_ok)) {
			$file['error'] = sprintf(
				'Tipo de archivo no permitido. Solo se aceptan JPG, PNG, WebP, PDF y ZIP. El archivo "%s" fue rechazado.',
				esc_html($file['name'])
			);
			return $file;
		}

		// PDF, ZIP, XML, TXT: no son imágenes, saltar todas las validaciones de imagen
		if (in_array($extension, array('pdf', 'zip', 'xml', 'txt'))) return $file;

		// SVG: sanitizar contenido para eliminar scripts maliciosos y dejar pasar
		if ($extension === 'svg') {
			$svg_content = file_get_contents($file['tmp_name']);
			$svg_content = $this->sanitizar_svg($svg_content);
			file_put_contents($file['tmp_name'], $svg_content);
			return $file;
		}

		// Verificar MIME real leyendo el contenido del archivo (no el enviado por el cliente)
		$tipos_ok  = array('image/jpeg','image/png','image/webp','application/pdf','application/zip','application/x-zip-compressed');
		$mime_real = '';
		if (function_exists('finfo_open')) {
			$finfo     = finfo_open(FILEINFO_MIME_TYPE);
			$mime_real = finfo_file($finfo, $file['tmp_name']);
			finfo_close($finfo);
		} elseif (function_exists('mime_content_type')) {
			$mime_real = mime_content_type($file['tmp_name']);
		}

		if ($mime_real && !in_array($mime_real, $tipos_ok)) {
			$file['error'] = sprintf(
				'El contenido del archivo no coincide con su extensión. El archivo "%s" fue rechazado.',
				esc_html($file['name'])
			);
			return $file;
		}

		// Módulo: validar nombres
		if ($this->opts['mod_nombres']) {
			$prohibidas = array_filter(array_map('trim', explode("\n", $this->opts['palabras_prohibidas'])));
			foreach ($prohibidas as $p) {
				if (stripos($file['name'], $p) !== false) {
					$file['error'] = sprintf(
						'Cambia el nombre del archivo. "%s" contiene "%s". Usa nombres descriptivos como "logo-empresa.jpg".',
						esc_html($file['name']), esc_html($p)
					);
					return $file;
				}
			}

			$res = $this->limpiar_nombre_archivo($file['name']);
			if (isset($res['error'])) { $file['error'] = $res['error']; return $file; }
			$file['name'] = $res['nombre'];
		}

		// Módulo: validar peso
		if ($this->opts['mod_peso']) {
			$max_bytes = $this->opts['max_file_size'] * 1024 * 1024;
			if ($file['size'] > $max_bytes) {
				$file['error'] = sprintf(
					'El archivo pesa %s y supera el máximo de %s MB.',
					size_format($file['size']),
					$this->opts['max_file_size']
				);
				return $file;
			}
		}

		// Módulo: validar dimensiones — redimensiona automáticamente si supera el máximo
		if ($this->opts['mod_dimensiones']) {
			$info = getimagesize($file['tmp_name']);
			if ($info !== false) {
				list($w, $h) = $info;
				$nombre_limpio = strtolower(pathinfo($file['name'], PATHINFO_FILENAME));
				$es_banner     = (strpos($nombre_limpio, 'banner-') === 0);
				$max_w = $es_banner ? $this->opts['max_width_banner']  : $this->opts['max_width'];
				$max_h = $es_banner ? $this->opts['max_height_banner'] : $this->opts['max_height'];

				if ($w > $max_w || $h > $max_h) {
					$file = $this->redimensionar_imagen($file, $max_w, $max_h, $info);
					if (isset($file['error'])) return $file;
				}
			}
		}

		return $file;
	}
}

// Reset a valores por defecto
add_action('admin_init', function() {
	if (
		isset($_GET['pk-reset'], $_GET['page']) &&
		$_GET['pk-reset'] === '1' &&
		$_GET['page'] === 'simple-optimizador-de-imagenes' &&
		current_user_can('manage_options') &&
		check_admin_referer('pk_reset_settings')
	) {
		delete_option('pk_optimizador_settings');
		set_transient( 'pk_settings_saved_' . get_current_user_id(), true, 60 );
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'simple-optimizador-de-imagenes' ),
			admin_url( 'options-general.php' )
		) );
		exit;
	}
});

new PK_Restringir_Imagenes();
