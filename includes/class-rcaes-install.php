<?php
defined( 'ABSPATH' ) || exit;

class Rcaes_Install {

	public static function ativar() {
		self::criar_tabelas();
		self::criar_papel();
		self::criar_paginas();
		self::opcoes_padrao();
		Rcaes_Imagens::pasta();
		if ( ! wp_next_scheduled( 'rcaes_processar_fila' ) ) {
			wp_schedule_event( time() + 60, 'rcaes_5min', 'rcaes_processar_fila' );
		}
		Rcaes_Lista::regras();
		flush_rewrite_rules();
		update_option( 'rcaes_versao', RCAES_VERSION );
	}

	public static function desativar() {
		wp_unschedule_hook( 'rcaes_processar_fila' );
		flush_rewrite_rules();
	}

	public static function verificar_versao() {
		if ( get_option( 'rcaes_versao' ) !== RCAES_VERSION ) {
			self::criar_tabelas();
			self::criar_paginas(); // cria apenas as páginas essenciais ausentes
			update_option( 'rcaes_versao', RCAES_VERSION );
		}
	}

	private static function criar_tabelas() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'rcaes_';

		dbDelta( "CREATE TABLE {$p}caes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  token char(36) NOT NULL,
  tipo tinyint(1) NOT NULL DEFAULT 0,
  nome varchar(80) NOT NULL DEFAULT '',
  raca varchar(40) NOT NULL DEFAULT '',
  porte varchar(20) NOT NULL DEFAULT '',
  cor varchar(20) NOT NULL DEFAULT '',
  cidade varchar(60) NOT NULL DEFAULT '',
  uf char(2) NOT NULL DEFAULT '',
  publico tinyint(1) NOT NULL DEFAULT 0,
  descricao text NULL,
  pergunta_verificacao varchar(200) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pendente',
  tentativas tinyint(3) unsigned NOT NULL DEFAULT 0,
  proxima_tentativa datetime NULL,
  iniciado_em datetime NULL,
  criado_em datetime NOT NULL,
  processado_em datetime NULL,
  resolvido_em datetime NULL,
  desfecho varchar(16) NOT NULL DEFAULT '',
  match_desfecho_id bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY user_id (user_id),
  KEY busca (tipo,status),
  KEY publico (publico,status)
) {$c};" );

		dbDelta( "CREATE TABLE {$p}fotos (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cao_id bigint(20) unsigned NOT NULL,
  arquivo varchar(64) NOT NULL,
  criado_em datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY cao_id (cao_id)
) {$c};" );

		dbDelta( "CREATE TABLE {$p}embeddings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cao_id bigint(20) unsigned NOT NULL,
  foto_id bigint(20) unsigned NOT NULL,
  vetor_json longtext NOT NULL,
  criado_em datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY cao_id (cao_id),
  KEY foto_id (foto_id)
) {$c};" );

		dbDelta( "CREATE TABLE {$p}matches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cao_perdido_id bigint(20) unsigned NOT NULL,
  cao_encontrado_id bigint(20) unsigned NOT NULL,
  distancia float NULL,
  origem varchar(10) NOT NULL DEFAULT 'ia',
  status varchar(15) NOT NULL DEFAULT 'sugerido',
  iniciado_por bigint(20) unsigned NULL,
  mensagem_inicial varchar(400) NOT NULL DEFAULT '',
  resposta_verificacao varchar(400) NOT NULL DEFAULT '',
  solicitado_em datetime NULL,
  respondido_em datetime NULL,
  resgate_por bigint(20) unsigned NULL,
  resgate_em datetime NULL,
  criado_em datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY par (cao_perdido_id,cao_encontrado_id),
  KEY cao_encontrado_id (cao_encontrado_id)
) {$c};" );

		dbDelta( "CREATE TABLE {$p}mensagens (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  match_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  texto text NOT NULL,
  lida tinyint(1) NOT NULL DEFAULT 0,
  criado_em datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY match_id (match_id)
) {$c};" );

		dbDelta( "CREATE TABLE {$p}avaliacoes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  criado_em datetime NOT NULL,
  nps tinyint(2) NOT NULL,
  satisfacao tinyint(1) NOT NULL,
  sugestoes varchar(12) NOT NULL DEFAULT '',
  desfecho varchar(12) NOT NULL DEFAULT '',
  dispositivo varchar(12) NOT NULL DEFAULT '',
  como_procurava varchar(80) NOT NULL DEFAULT '',
  espera tinyint(1) NULL,
  sus varchar(40) NULL,
  sus_score float NULL,
  positivo text NULL,
  melhorar text NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) {$c};" );
	}

	private static function criar_papel() {
		// Papel mínimo: só "read". Tutores não acessam o painel do WordPress.
		if ( ! get_role( 'rcaes_tutor' ) ) {
			add_role( 'rcaes_tutor', 'Tutor (Reencontro de Cães)', array( 'read' => true ) );
		}
	}

	public static function criar_paginas() {
		$def = array(
			'cadastro'  => array( 'Cadastro', 'cadastro', '[rcaes_cadastro]' ),
			'login'     => array( 'Entrar', 'entrar', '[rcaes_login]' ),
			'cadastrar' => array( 'Cadastrar cão', 'cadastrar-cao', '[rcaes_cadastrar_cao]' ),
			'meus_caes' => array( 'Meus cães', 'meus-caes', '[rcaes_meus_caes]' ),
			'lista'     => array( 'Lista de possíveis correspondências', 'lista', '[rcaes_lista]' ),
			'conversas' => array( 'Conversas', 'conversas', '[rcaes_conversas]' ),
		);
		$paginas = get_option( 'rcaes_paginas', array() );
		if ( ! is_array( $paginas ) ) {
			$paginas = array();
		}
		foreach ( $def as $chave => $d ) {
			if ( ! empty( $paginas[ $chave ] ) && 'page' === get_post_type( $paginas[ $chave ] ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_title'     => $d[0],
					'post_name'      => $d[1],
					'post_content'   => $d[2],
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$paginas[ $chave ] = (int) $id;
			}
		}
		update_option( 'rcaes_paginas', $paginas );
	}

	private static function opcoes_padrao() {
		$padrao = array(
			'rcaes_api_timeout'            => 90,
			'rcaes_distancia_max'          => 0.90,
			'rcaes_max_fotos'              => 3,
			'rcaes_max_mb'                 => 5,
			'rcaes_min_candidatos_filtro'  => 30,
			'rcaes_max_caes_ativos'        => 5,
			'rcaes_listas_publicas'        => 1,
			'rcaes_remover_dados'          => 0,
		);
		foreach ( $padrao as $k => $v ) {
			if ( false === get_option( $k ) ) {
				add_option( $k, $v );
			}
		}
	}
}
