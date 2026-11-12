<?php
defined( 'ABSPATH' ) || exit;

/**
 * Utilitários de segurança: limite de taxa, tokens, anti-bot, mensagens fixas, URLs.
 */
class Rcaes_Seguranca {

	/** Mensagens exibidas por código (nunca se imprime texto vindo da URL). */
	const MENSAGENS = array(
		'cadastro_ok'       => array( 'ok', 'Se os dados forem válidos, enviamos um e-mail para confirmar seu cadastro. Verifique também a caixa de spam.' ),
		'verificado'        => array( 'ok', 'E-mail confirmado! Agora você já pode entrar.' ),
		'verif_invalida'    => array( 'erro', 'Link de confirmação inválido ou expirado. Peça um novo link abaixo.' ),
		'reenviado'         => array( 'ok', 'Se existir uma conta pendente para este e-mail, enviamos um novo link de confirmação.' ),
		'erro_login'        => array( 'erro', 'E-mail ou senha incorretos.' ),
		'nao_verificado'    => array( 'erro', 'Confirme seu e-mail antes de entrar. Você pode pedir um novo link abaixo.' ),
		'muitas_tentativas' => array( 'erro', 'Muitas tentativas. Aguarde alguns minutos e tente novamente.' ),
		'campos_invalidos'  => array( 'erro', 'Confira os campos: algum dado está ausente ou inválido.' ),
		'senha_fraca'       => array( 'erro', 'A senha deve ter entre 10 e 128 caracteres.' ),
		'senhas_diferentes' => array( 'erro', 'As senhas não conferem.' ),
		'aceite_necessario' => array( 'erro', 'É necessário aceitar os termos de uso e a política de privacidade.' ),
		'falha_geral'       => array( 'erro', 'Não foi possível concluir a operação. Tente novamente.' ),
		'cao_ok'            => array( 'ok', 'Cão cadastrado! Estamos processando as fotos. Você receberá um e-mail quando terminar; pode fechar o site ou sair da conta.' ),
		'foto_invalida'     => array( 'erro', 'Uma das fotos é inválida. Use JPG, PNG ou WebP dentro do tamanho máximo.' ),
		'limite_caes'       => array( 'erro', 'Você atingiu o limite de cães ativos. Marque algum como resolvido ou remova-o para cadastrar outro.' ),
		'acao_ok'           => array( 'ok', 'Ação realizada.' ),
		'reprocessando'     => array( 'ok', 'Reenviado para processamento. Você receberá um e-mail ao final.' ),
		'contato_enviado'   => array( 'ok', 'Solicitação enviada. A outra pessoa será avisada e poderá aceitar ou não.' ),
		'contato_aceito'    => array( 'ok', 'Contato aceito. A conversa já está disponível em "Conversas".' ),
		'contato_recusado'  => array( 'ok', 'Solicitação recusada.' ),
		'estado_invalido'   => array( 'erro', 'Esta ação não está mais disponível para este item.' ),
		'msg_invalida'      => array( 'erro', 'Mensagem vazia ou longa demais.' ),
		'conversa_encerrada' => array( 'ok', 'Conversa encerrada.' ),
		'resgate_pedido'    => array( 'ok', 'Pedido enviado. Quando a outra pessoa confirmar, a conversa é encerrada e os cadastros dos dois cães viram resolvidos.' ),
		'resgate_cancelado' => array( 'info', 'Pedido de cão resgatado cancelado.' ),
		'resgate_confirmado' => array( 'ok', 'Pronto! Os cadastros foram marcados como resolvidos e a conversa foi encerrada. Obrigado por usar o Reencontro de Cães.' ),
	);

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'carregar_assets' ) );
	}

	/* ---------------------------------------------------------------- rede / limite de taxa */

	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		// Atrás de proxy/CDN, use este filtro para devolver o IP real (nunca confie em X-Forwarded-For sem validar a origem).
		$ip = apply_filters( 'rcaes_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Janela fixa por chave. Retorna true se a ação é permitida (e a contabiliza).
	 */
	public static function permitir( $chave, $max, $janela ) {
		$k     = 'rcaes_rl_' . md5( (string) $chave );
		$agora = time();
		$d     = get_transient( $k );
		if ( ! is_array( $d ) || ( $agora - (int) $d['ini'] ) >= $janela ) {
			$d = array( 'n' => 0, 'ini' => $agora );
		}
		if ( (int) $d['n'] >= $max ) {
			return false;
		}
		$d['n']++;
		set_transient( $k, $d, max( 1, $janela - ( $agora - (int) $d['ini'] ) ) );
		return true;
	}

	/* ---------------------------------------------------------------- tokens */

	/** UUID v4 com aleatoriedade criptográfica (wp_generate_uuid4 usa mt_rand e não serve para isto). */
	public static function uuid_v4() {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		$h    = bin2hex( $b );
		return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-' . substr( $h, 12, 4 ) . '-' . substr( $h, 16, 4 ) . '-' . substr( $h, 20, 12 );
	}

	public static function token_valido( $t ) {
		return is_string( $t ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $t );
	}

	/* ---------------------------------------------------------------- anti-bot (honeypot + tempo mínimo assinado) */

	public static function campos_anti_bot() {
		$t     = time();
		$assin = hash_hmac( 'sha256', (string) $t, wp_salt( 'nonce' ) );
		return '<div class="rcaes-hp" aria-hidden="true"><label>Não preencha este campo'
			. '<input type="text" name="rcaes_site" value="" tabindex="-1" autocomplete="off"></label></div>'
			. '<input type="hidden" name="rcaes_t" value="' . esc_attr( $t . '.' . $assin ) . '">';
	}

	public static function anti_bot_ok( $min = 3, $max = 3600 ) {
		if ( ! empty( $_POST['rcaes_site'] ) ) {
			return false;
		}
		$v = isset( $_POST['rcaes_t'] ) && is_string( $_POST['rcaes_t'] ) ? sanitize_text_field( wp_unslash( $_POST['rcaes_t'] ) ) : '';
		$p = explode( '.', $v );
		if ( 2 !== count( $p ) || ! ctype_digit( $p[0] ) ) {
			return false;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $p[0], wp_salt( 'nonce' ) ), $p[1] ) ) {
			return false;
		}
		$dec = time() - (int) $p[0];
		return $dec >= $min && $dec <= $max;
	}

	/* ---------------------------------------------------------------- entrada de dados */

	public static function post_texto( $campo, $max, $multilinha = false ) {
		if ( ! isset( $_POST[ $campo ] ) || ! is_string( $_POST[ $campo ] ) ) {
			return '';
		}
		$v = wp_unslash( $_POST[ $campo ] );
		$v = $multilinha ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
		return trim( mb_substr( $v, 0, $max ) );
	}

	/** Valor de um campo que só pode ser uma das chaves de $permitidos (lista branca). */
	public static function post_escolha( $campo, array $permitidos ) {
		if ( ! isset( $_POST[ $campo ] ) || ! is_string( $_POST[ $campo ] ) ) {
			return '';
		}
		$v = sanitize_key( wp_unslash( $_POST[ $campo ] ) );
		return array_key_exists( $v, $permitidos ) ? $v : '';
	}

	/* ---------------------------------------------------------------- páginas / URLs / avisos */

	public static function pagina_id( $chave ) {
		$p = get_option( 'rcaes_paginas', array() );
		return ( is_array( $p ) && isset( $p[ $chave ] ) ) ? (int) $p[ $chave ] : 0;
	}

	public static function pagina_url( $chave ) {
		$id  = self::pagina_id( $chave );
		$url = $id ? get_permalink( $id ) : '';
		return $url ? $url : home_url( '/' );
	}

	public static function url_login( $redirect = '' ) {
		$u = self::pagina_url( 'login' );
		return $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $u ) : $u;
	}

	public static function voltar( $url, $codigo ) {
		wp_safe_redirect( add_query_arg( 'rcaes_msg', $codigo, $url ) );
		exit;
	}

	/** Volta para a página com um pop-up pós-evento (ver Rcaes_Popup). */
	public static function voltar_popup( $url, $popup ) {
		wp_safe_redirect( add_query_arg( 'rcaes_popup', $popup, $url ) );
		exit;
	}

	/**
	 * Campo de senha com botão de olho (mostrar/ocultar). $atributos é texto fixo do próprio código,
	 * nunca dado do usuário.
	 */
	public static function campo_senha( $nome, $placeholder, $autocomplete, $atributos = '' ) {
		return '<span class="rcaes-senha"><input type="password" name="' . esc_attr( $nome ) . '" ' . $atributos
			. ' autocomplete="' . esc_attr( $autocomplete ) . '" placeholder="' . esc_attr( $placeholder ) . '">'
			. '<button type="button" class="rcaes-olho" aria-pressed="false" aria-label="Mostrar senha" title="Mostrar senha">'
			. '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>'
			. '<line class="rcaes-barra" x1="3" y1="3" x2="21" y2="21"/></svg></button></span>';
	}

	public static function aviso() {
		if ( empty( $_GET['rcaes_msg'] ) || ! is_string( $_GET['rcaes_msg'] ) ) {
			return '';
		}
		$c = sanitize_key( wp_unslash( $_GET['rcaes_msg'] ) );
		if ( ! isset( self::MENSAGENS[ $c ] ) ) {
			return '';
		}
		list( $tipo, $txt ) = self::MENSAGENS[ $c ];
		return '<div class="rcaes-aviso rcaes-' . esc_attr( $tipo ) . '" role="status">' . esc_html( $txt ) . '</div>';
	}

	public static function aviso_login( $redirect = '' ) {
		return '<div class="rcaes-aviso rcaes-info">Para continuar, <a href="' . esc_url( self::url_login( $redirect ) ) . '">entre na sua conta</a> ou <a href="' . esc_url( self::pagina_url( 'cadastro' ) ) . '">cadastre-se</a>.</div>';
	}

	/* ---------------------------------------------------------------- sessão / permissões */

	/** Logado e, quando for tutor, com e-mail confirmado. */
	public static function usuario_verificado() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$u = wp_get_current_user();
		if ( in_array( 'rcaes_tutor', (array) $u->roles, true ) ) {
			return '1' === (string) get_user_meta( $u->ID, 'rcaes_email_verificado', true );
		}
		return true;
	}

	/** Usado nos handlers de formulário: sem sessão válida, manda para o login. */
	public static function exigir_usuario() {
		if ( ! self::usuario_verificado() ) {
			wp_safe_redirect( self::url_login() );
			exit;
		}
		return get_current_user_id();
	}

	public static function nome_publico( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return 'Tutor';
		}
		$n = $u->first_name ? $u->first_name : $u->display_name;
		$n = trim( (string) strtok( sanitize_text_field( $n ), ' ' ) );
		return '' !== $n ? $n : 'Tutor';
	}

	public static function nocache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	public static function log( $msg ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[rcaes] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/* ---------------------------------------------------------------- assets */

	public static function carregar_assets() {
		// CSS em TODAS as páginas (identidade visual + classes .cdr-*). Para não usar a fonte do Google
		// (por privacidade/LGPD), defina no wp-config.php: define( 'RCAES_SEM_FONTE_EXTERNA', true );
		$deps = array();
		if ( ! ( defined( 'RCAES_SEM_FONTE_EXTERNA' ) && RCAES_SEM_FONTE_EXTERNA ) ) {
			wp_enqueue_style( 'rcaes-fontes', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Outfit:wght@500;600;700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
			$deps[] = 'rcaes-fontes';
		}
		wp_enqueue_style( 'rcaes', RCAES_URL . 'assets/rcaes.css', $deps, (string) @filemtime( RCAES_DIR . 'assets/rcaes.css' ) ); // versão = data do arquivo: o navegador sempre pega o CSS novo

		// JavaScript: nas páginas com shortcodes do plugin e, para tutores verificados, em todo o site
		// (caixa de mensagens estilo LinkedIn).
		$dock = Rcaes_Chat::dock_ativo();
		$usa  = false;
		if ( is_singular() ) {
			global $post;
			if ( $post instanceof WP_Post ) {
				foreach ( array( 'rcaes_cadastro', 'rcaes_login', 'rcaes_cadastrar_cao', 'rcaes_meus_caes', 'rcaes_lista', 'rcaes_conversas' ) as $sc ) {
					if ( has_shortcode( $post->post_content, $sc ) ) {
						$usa = true;
						break;
					}
				}
			}
		}
		if ( ! $usa && ! $dock ) {
			return;
		}
		wp_enqueue_script( 'rcaes', RCAES_URL . 'assets/rcaes.js', array(), RCAES_VERSION, true );
		wp_localize_script(
			'rcaes',
			'RCAES',
			array(
				'rest'       => esc_url_raw( rest_url( 'rcaes/v1/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'adminPost'  => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'maxFotos'   => (int) get_option( 'rcaes_max_fotos', 3 ),
				'maxMb'      => (int) get_option( 'rcaes_max_mb', 5 ),
				'dock'       => $dock ? 1 : 0,
				'urlConversas' => esc_url_raw( Rcaes_Seguranca::pagina_url( 'conversas' ) ),
			)
		);
	}
}
