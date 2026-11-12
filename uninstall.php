<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Só apaga dados se o administrador marcou essa opção nas configurações.
if ( 1 !== (int) get_option( 'rcaes_remover_dados', 0 ) ) {
	return;
}

global $wpdb;
foreach ( array( 'avaliacoes', 'mensagens', 'matches', 'embeddings', 'fotos', 'caes' ) as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rcaes_' . $t ); // phpcs:ignore WordPress.DB
}

foreach ( array( 'rcaes_versao', 'rcaes_paginas', 'rcaes_api_url', 'rcaes_api_token', 'rcaes_api_timeout', 'rcaes_distancia_max', 'rcaes_max_fotos', 'rcaes_max_mb', 'rcaes_min_candidatos_filtro', 'rcaes_max_caes_ativos', 'rcaes_remover_dados', 'rcaes_listas_publicas', 'rcaes_pop_cad_titulo', 'rcaes_pop_cad_texto', 'rcaes_pop_cad_botao', 'rcaes_pop_cad_destino', 'rcaes_pop_cao_titulo', 'rcaes_pop_cao_texto', 'rcaes_pop_cao_botao', 'rcaes_pop_cao_destino', 'rcaes_pop_cao_video' ) as $o ) {
	delete_option( $o );
}

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'rcaes\\_%'" ); // phpcs:ignore WordPress.DB
remove_role( 'rcaes_tutor' );
wp_unschedule_hook( 'rcaes_processar_fila' );

$u   = wp_upload_dir();
$dir = trailingslashit( $u['basedir'] ) . 'rcaes-privado';
if ( is_dir( $dir ) ) {
	foreach ( (array) glob( $dir . '/*' ) as $f ) {
		if ( is_file( $f ) ) {
			@unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	foreach ( (array) glob( $dir . '/pub/*' ) as $f ) {
		if ( is_file( $f ) ) {
			@unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	@rmdir( $dir . '/pub' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	foreach ( array( '.htaccess' ) as $f ) {
		if ( is_file( $dir . '/' . $f ) ) {
			@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
