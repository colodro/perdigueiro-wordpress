<?php
/**
 * Plugin Name: Reencontro de Cães
 * Description: Reconhecimento de cães perdidos por similaridade visual (IA): cadastro, busca assíncrona, aviso por e-mail e contato seguro entre tutores. Todas as telas são shortcodes.
 * Version: 1.4.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: William de Souza Colodro
 * License: GPL-2.0-or-later
 * Text Domain: reencontro-caes
 */

defined( 'ABSPATH' ) || exit;

define( 'RCAES_VERSION', '1.4.3' );
define( 'RCAES_FILE', __FILE__ );
define( 'RCAES_DIR', plugin_dir_path( __FILE__ ) );
define( 'RCAES_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'seguranca', 'db', 'imagens', 'api', 'email', 'fila', 'conta', 'caes', 'lista', 'chat', 'publico', 'popup', 'admin', 'install' ) as $rcaes_arq ) {
	require_once RCAES_DIR . 'includes/class-rcaes-' . $rcaes_arq . '.php';
}
unset( $rcaes_arq );

// O intervalo do cron precisa existir já na ativação (antes do plugins_loaded).
add_filter( 'cron_schedules', array( 'Rcaes_Fila', 'intervalos' ) );

register_activation_hook( __FILE__, array( 'Rcaes_Install', 'ativar' ) );
register_deactivation_hook( __FILE__, array( 'Rcaes_Install', 'desativar' ) );

add_action(
	'plugins_loaded',
	function () {
		Rcaes_Install::verificar_versao();
		Rcaes_Seguranca::init();
		Rcaes_Conta::init();
		Rcaes_Caes::init();
		Rcaes_Lista::init();
		Rcaes_Chat::init();
		Rcaes_Publico::init();
		Rcaes_Fila::init();
		Rcaes_Imagens::init();
		if ( is_admin() ) {
			Rcaes_Admin::init();
		}
	}
);
