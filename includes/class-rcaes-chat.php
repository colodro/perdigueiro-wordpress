<?php
defined( 'ABSPATH' ) || exit;

/**
 * Conversas entre tutores. Só existem depois que as duas partes concordaram (solicitação aceita).
 * Mensagens são texto puro.
 *
 *   [rcaes_conversas] : página de conversas em três colunas (conversas | conversa | cão).
 *   Caixa flutuante "Mensagens": impressa no rodapé de todas as páginas para tutores verificados
 *   (menos na própria página de conversas). Alimentada pelas rotas REST rcaes/v1.
 */
class Rcaes_Chat {

	public static function init() {
		add_shortcode( 'rcaes_conversas', array( __CLASS__, 'sc_conversas' ) );
		add_action( 'admin_post_rcaes_enviar_msg', array( __CLASS__, 'enviar' ) );
		add_action( 'admin_post_rcaes_encerrar', array( __CLASS__, 'encerrar' ) );
		add_action( 'admin_post_rcaes_resgate', array( __CLASS__, 'resgate' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rotas_rest' ) );
		add_action( 'wp_footer', array( __CLASS__, 'rodape' ) );
	}

	public static function url( $match_id ) {
		return add_query_arg( 'conversa', (int) $match_id, Rcaes_Seguranca::pagina_url( 'conversas' ) );
	}

	/** A caixa flutuante aparece para tutores logados e verificados. */
	public static function dock_ativo() {
		if ( ! is_user_logged_in() || ! Rcaes_Seguranca::usuario_verificado() ) {
			return false;
		}
		$u = wp_get_current_user();
		return $u && in_array( 'rcaes_tutor', (array) $u->roles, true );
	}

	/** Marcação da caixa flutuante, impressa no rodapé. Não aparece na página de conversas (que já tem a lista). */
	public static function rodape() {
		if ( ! self::dock_ativo() ) {
			return;
		}
		global $post;
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'rcaes_conversas' ) ) {
			return;
		}
		?>
		<div class="rcaes-dock" id="rcaes-dock">
			<div class="rcaes-janela" id="rcaes-janela" role="dialog" aria-label="Mensagens" hidden>
				<div class="rcaes-janela-topo">
					<strong id="rcaes-janela-titulo">Mensagens</strong>
					<button type="button" class="rcaes-x" id="rcaes-janela-fechar" aria-label="Fechar">×</button>
				</div>
				<div class="rcaes-janela-corpo" id="rcaes-janela-corpo"></div>
			</div>
			<button type="button" class="rcaes-dock-btn" id="rcaes-dock-btn" aria-haspopup="dialog" aria-expanded="false">Mensagens<span class="rcaes-badge" id="rcaes-dock-badge" hidden></span></button>
		</div>
		<?php
	}

	/** Conversa ATIVA do usuário (status aceito). Devolve o match ou null. */
	private static function conversa_do_usuario( $mid, $uid ) {
		$m = $mid ? Rcaes_DB::match_completo( $mid ) : null;
		if ( ! $m || '' === Rcaes_Lista::lado( $m, $uid ) || 'aceito' !== $m->status ) {
			return null;
		}
		return $m;
	}

	/** Quem é o outro participante da conversa. */
	private static function outro_uid( $m, $uid ) {
		return 'perdido' === Rcaes_Lista::lado( $m, $uid ) ? (int) $m->encontrado_user : (int) $m->perdido_user;
	}

	/** Cão do outro participante. */
	private static function cao_do_outro( $m, $uid ) {
		return Rcaes_DB::cao( 'perdido' === Rcaes_Lista::lado( $m, $uid ) ? $m->cao_encontrado_id : $m->cao_perdido_id );
	}

	/** Chave do "está digitando" (expira sozinha em poucos segundos). */
	private static function chave_digitando( $mid, $uid ) {
		return 'rcaes_dig_' . (int) $mid . '_' . (int) $uid;
	}

	/** IDs de pedidos de contato que o usuário já abriu. */
	private static function vistos( $uid ) {
		$v = get_user_meta( (int) $uid, 'rcaes_pedidos_vistos', true );
		return is_array( $v ) ? array_map( 'intval', $v ) : array();
	}

	/* ---------------------------------------------------------------- página em três colunas */

	public static function sc_conversas() {
		Rcaes_Seguranca::nocache();
		$mid = isset( $_GET['conversa'] ) ? absint( $_GET['conversa'] ) : 0;
		if ( ! Rcaes_Seguranca::usuario_verificado() ) {
			return Rcaes_Seguranca::aviso_login( $mid ? self::url( $mid ) : Rcaes_Seguranca::pagina_url( 'conversas' ) );
		}
		$uid = get_current_user_id();
		$m   = $mid ? self::conversa_do_usuario( $mid, $uid ) : null;
		if ( $mid && ! $m ) {
			$mid = 0; // conversa encerrada, resolvida ou de outra pessoa: volta à caixa
		}
		$classe = $m ? ' rcaes-msn-com-conversa' : '';

		ob_start();
		echo '<div class="rcaes-msn' . $classe . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::coluna_lista( $mid ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<section class="rcaes-msn-centro">';
		echo $m ? self::tela_conversa( $m ) : self::vazio_centro(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</section>';
		echo self::coluna_dados( $m ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
		return ob_get_clean();
	}

	/** Coluna da esquerda: pedidos recebidos e conversas. */
	private static function coluna_lista( $mid_atual ) {
		$uid     = get_current_user_id();
		$pedidos = Rcaes_DB::pedidos_recebidos( $uid );
		$conv    = Rcaes_DB::conversas_do_usuario( $uid );
		$vistos  = self::vistos( $uid );

		ob_start();
		echo '<aside class="rcaes-msn-lista"><div class="rcaes-msn-titulo">Conversas</div>';
		if ( ! $pedidos && ! $conv ) {
			echo '<p class="rcaes-msn-vazio">Nenhuma conversa ainda. Elas aparecem aqui quando uma solicitação de contato é aceita.</p>';
		}
		foreach ( $pedidos as $p ) {
			$meu = Rcaes_DB::cao( 'perdido' === Rcaes_Lista::lado( $p, $uid ) ? $p->cao_perdido_id : $p->cao_encontrado_id );
			if ( ! $meu ) {
				continue;
			}
			$nova = ! in_array( (int) $p->id, $vistos, true );
			echo '<a class="rcaes-msn-item rcaes-msn-pedido' . ( $nova ? ' rcaes-azul' : '' ) . '" href="' . esc_url( Rcaes_Lista::url( $meu->token ) ) . '">'
				. '<span class="rcaes-avatar">' . esc_html( mb_strtoupper( mb_substr( Rcaes_Seguranca::nome_publico( $p->iniciado_por ), 0, 1 ) ) ) . '</span>'
				. '<span class="rcaes-msn-item-corpo"><strong>' . esc_html( Rcaes_Seguranca::nome_publico( $p->iniciado_por ) ) . '</strong>'
				. '<small>Pediu contato sobre um cão</small></span>'
				. '<span class="rcaes-msn-item-dir"><small>' . esc_html( get_date_from_gmt( $p->solicitado_em, 'd/m' ) ) . '</small></span></a>';
		}
		foreach ( $conv as $m ) {
			$outro_uid = self::outro_uid( $m, get_current_user_id() );
			$cao       = self::cao_do_outro( $m, get_current_user_id() );
			$classes   = '';
			if ( (int) $m->id === (int) $mid_atual ) {
				$classes .= ' rcaes-ativo';
			}
			if ( (int) $m->nao_lidas > 0 ) {
				$classes .= ' rcaes-novo';
			}
			$previa = (string) mb_substr( (string) $m->ult_texto, 0, 60 );
			echo '<a class="rcaes-msn-item' . $classes . '" href="' . esc_url( self::url( $m->id ) ) . '">'
				. '<span class="rcaes-avatar">' . esc_html( mb_strtoupper( mb_substr( Rcaes_Seguranca::nome_publico( $outro_uid ), 0, 1 ) ) ) . '</span>'
				. '<span class="rcaes-msn-item-corpo"><strong>' . esc_html( Rcaes_Seguranca::nome_publico( $outro_uid ) ) . '</strong>'
				. '<small>' . esc_html( ( $cao ? Rcaes_Caes::tipo_rotulo( $cao->tipo ) . ' · ' : '' ) . $previa ) . '</small></span>'
				. '<span class="rcaes-msn-item-dir"><small>' . esc_html( $m->ultima ? get_date_from_gmt( $m->ultima, 'd/m' ) : '' ) . '</small>'
				. ( (int) $m->nao_lidas > 0 ? '<span class="rcaes-item-n">' . (int) $m->nao_lidas . '</span>' : '' ) . '</span></a>';
		}
		echo '</aside>';
		return ob_get_clean();
	}

	/** Centro quando nenhuma conversa está aberta. */
	private static function vazio_centro() {
		ob_start();
		echo Rcaes_Seguranca::aviso(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="rcaes-msn-vazio-grande"><p>Escolha uma conversa à esquerda para ver as mensagens e os detalhes do cão.</p></div>';
		return ob_get_clean();
	}

	/** Coluna da direita: cão do outro participante e a ação "Marcar como cão resgatado". */
	private static function coluna_dados( $m ) {
		if ( ! $m ) {
			return '<aside class="rcaes-msn-dados rcaes-msn-dados-vazio"></aside>';
		}
		$uid   = get_current_user_id();
		$lado  = Rcaes_Lista::lado( $m, $uid );
		$cao   = self::cao_do_outro( $m, $uid );
		$nome  = Rcaes_Seguranca::nome_publico( self::outro_uid( $m, $uid ) );
		$foto  = $cao ? Rcaes_Imagens::url_principal( $cao->id ) : '';
		if ( ! $cao ) {
			return '<aside class="rcaes-msn-dados"></aside>';
		}
		$perdido = 0 === (int) $cao->tipo;
		$local   = '' !== (string) $cao->cidade ? $cao->cidade . ( '' !== (string) $cao->uf ? ' – ' . $cao->uf : '' ) : '';

		ob_start();
		?>
		<aside class="rcaes-msn-dados">
			<div class="rcaes-msn-foto">
				<?php if ( $foto ) : ?>
					<img src="<?php echo esc_url( $foto ); ?>" alt="Foto do cão" loading="lazy">
				<?php else : ?>
					<span class="rcaes-msn-foto-vazia">🐾</span>
				<?php endif; ?>
			</div>
			<span class="rcaes-etiqueta <?php echo $perdido ? 'rcaes-tipo-perdido' : 'rcaes-tipo-encontrado'; ?>"><?php echo $perdido ? 'Perdido' : 'Encontrado'; ?></span>
			<h3><?php echo '' !== (string) $cao->nome ? esc_html( $cao->nome ) : esc_html( Rcaes_Caes::rotulo( 'raca', $cao->raca ) ); ?></h3>
			<dl class="rcaes-msn-fich">
				<?php if ( '' !== (string) $cao->nome ) : ?><dt>Raça</dt><dd><?php echo esc_html( Rcaes_Caes::rotulo( 'raca', $cao->raca ) ); ?></dd><?php endif; ?>
				<dt>Porte</dt><dd><?php echo esc_html( Rcaes_Caes::rotulo( 'porte', $cao->porte ) ); ?></dd>
				<dt>Cor</dt><dd><?php echo esc_html( Rcaes_Caes::rotulo( 'cor', $cao->cor ) ); ?></dd>
				<?php if ( '' !== $local ) : ?><dt>Local</dt><dd><?php echo esc_html( $local ); ?></dd><?php endif; ?>
				<dt>Tutor</dt><dd><?php echo esc_html( $nome ); ?></dd>
			</dl>
			<div class="rcaes-msn-acao">
				<?php echo self::bloco_resgate( $m, $nome ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		</aside>
		<?php
		return ob_get_clean();
	}

	/** Estado do "cão resgatado": sem pedido, pedido meu ou pedido da outra pessoa. */
	private static function bloco_resgate( $m, $nome_outro ) {
		$uid      = get_current_user_id();
		$pediu    = (int) $m->resgate_por;
		$sec      = 'rcaes-btn rcaes-btn-sec rcaes-btn-bloco';
		$form_ini = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rcaes-msn-form-acao">'
			. '<input type="hidden" name="action" value="rcaes_resgate"><input type="hidden" name="match_id" value="' . (int) $m->id . '">'
			. wp_nonce_field( 'rcaes_resgate_' . (int) $m->id, '_wpnonce', true, false );
		ob_start();
		if ( 0 === $pediu ) {
			echo $form_ini; // phpcs:ignore WordPress.Security.EscapeOutput
			?>
			<input type="hidden" name="acao" value="pedir">
			<p class="rcaes-peq">Ao marcar, a outra pessoa precisa confirmar. Quando confirmar, a conversa é encerrada e os dois cadastros viram resolvidos.</p>
			<button type="submit" class="rcaes-btn rcaes-btn-bloco" data-rcaes-confirmar="Marcar como cão resgatado? A outra pessoa precisará confirmar.">Marcar como cão resgatado</button>
			</form>
			<?php
		} elseif ( $pediu === $uid ) {
			?>
			<p class="rcaes-peq">Você marcou este cão como resgatado. Aguardando a confirmação de <strong><?php echo esc_html( $nome_outro ); ?></strong>.</p>
			<?php
			echo $form_ini; // phpcs:ignore WordPress.Security.EscapeOutput
			?>
			<input type="hidden" name="acao" value="cancelar">
			<button type="submit" class="<?php echo esc_attr( $sec ); ?>">Cancelar pedido</button>
			</form>
			<?php
		} else {
			?>
			<div class="rcaes-aviso rcaes-info rcaes-msn-pergunta"><strong><?php echo esc_html( $nome_outro ); ?></strong> marcou este cão como resgatado. Confirmar encerra a conversa e marca os dois cadastros como resolvidos.</div>
			<?php
			echo $form_ini; // phpcs:ignore WordPress.Security.EscapeOutput
			?>
			<input type="hidden" name="acao" value="confirmar">
			<button type="submit" class="rcaes-btn rcaes-btn-bloco" data-rcaes-confirmar="Confirmar que este cão foi resgatado? A conversa será encerrada e os cadastros dos dois cães viram resolvidos.">Confirmar resgate</button>
			</form>
			<?php
			echo $form_ini; // phpcs:ignore WordPress.Security.EscapeOutput
			?>
			<input type="hidden" name="acao" value="cancelar">
			<button type="submit" class="<?php echo esc_attr( $sec ); ?>">Ainda não</button>
			</form>
			<?php
		}
		return ob_get_clean();
	}

	private static function html_msg( $g, $uid ) {
		$meu = (int) $g->user_id === (int) $uid;
		return '<div class="rcaes-msg ' . ( $meu ? 'rcaes-meu' : 'rcaes-outro' ) . '" data-id="' . (int) $g->id . '">'
			. '<div class="rcaes-msg-txt">' . esc_html( $g->texto ) . '</div>'
			. '<div class="rcaes-peq">' . esc_html( get_date_from_gmt( $g->criado_em, 'd/m H:i' ) ) . '</div></div>';
	}

	/** Centro da conversa: cabeçalho, mensagens, digitação e envio. */
	private static function tela_conversa( $m ) {
		$mid        = (int) $m->id;
		$uid        = get_current_user_id();
		$outro_user = self::outro_uid( $m, $uid );
		$outro_cao  = self::cao_do_outro( $m, $uid );
		$nome       = Rcaes_Seguranca::nome_publico( $outro_user );

		Rcaes_DB::marcar_lidas( $mid, $uid );
		$msgs   = Rcaes_DB::mensagens_do_match( $mid, 0, 300 );
		$ultimo = 0;
		foreach ( $msgs as $g ) {
			$ultimo = max( $ultimo, (int) $g->id );
		}

		ob_start();
		?>
		<header class="rcaes-msn-cabeca">
			<a class="rcaes-msn-voltar" href="<?php echo esc_url( Rcaes_Seguranca::pagina_url( 'conversas' ) ); ?>" aria-label="Voltar às conversas">&larr;</a>
			<span class="rcaes-avatar"><?php echo esc_html( mb_strtoupper( mb_substr( $nome, 0, 1 ) ) ); ?></span>
			<div><strong><?php echo esc_html( $nome ); ?></strong><small><?php echo $outro_cao ? esc_html( Rcaes_Caes::tipo_rotulo( $outro_cao->tipo ) ) : ''; ?></small></div>
		</header>
		<?php echo Rcaes_Seguranca::aviso(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="rcaes-aviso rcaes-info rcaes-peq rcaes-msn-aviso">
			Cuidado: nunca envie dinheiro nem dados bancários. Combine a entrega em local público e movimentado.
			Desconfie de quem se recusar a descrever detalhes do cão que não aparecem nas fotos.
		</div>

		<div id="rcaes-chat" data-match="<?php echo (int) $mid; ?>" data-ultimo="<?php echo (int) $ultimo; ?>">
			<div class="rcaes-msgs rcaes-msn-msgs" id="rcaes-msgs">
				<?php foreach ( $msgs as $g ) : ?>
					<?php echo self::html_msg( $g, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endforeach; ?>
			</div>
			<div class="rcaes-digitando" id="rcaes-digitando" hidden><span></span><span></span><span></span><em><?php echo esc_html( $nome ); ?> está digitando…</em></div>
			<span id="rcaes-fim"></span>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rcaes-msn-envio">
			<input type="hidden" name="action" value="rcaes_enviar_msg">
			<input type="hidden" name="match_id" value="<?php echo (int) $mid; ?>">
			<?php wp_nonce_field( 'rcaes_msg_' . (int) $mid ); ?>
			<textarea name="texto" rows="2" maxlength="1000" required placeholder="Escreva sua mensagem" aria-label="Mensagem" data-rcaes-digita></textarea>
			<button type="submit" class="rcaes-btn">Enviar</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rcaes-msn-encerrar">
			<input type="hidden" name="action" value="rcaes_encerrar">
			<input type="hidden" name="match_id" value="<?php echo (int) $mid; ?>">
			<?php wp_nonce_field( 'rcaes_enc_' . (int) $mid ); ?>
			<button type="submit" class="rcaes-btn-texto" data-rcaes-confirmar="Encerrar esta conversa? Ninguém poderá enviar novas mensagens.">Encerrar conversa</button>
		</form>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------- ações */

	public static function enviar() {
		$uid = Rcaes_Seguranca::exigir_usuario();
		$mid = isset( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0;
		check_admin_referer( 'rcaes_msg_' . $mid );

		$m = self::conversa_do_usuario( $mid, $uid );
		if ( ! $m ) {
			Rcaes_Seguranca::voltar( Rcaes_Seguranca::pagina_url( 'conversas' ), 'estado_invalido' );
		}
		if ( ! Rcaes_Seguranca::permitir( 'msg_min_' . $uid, 20, MINUTE_IN_SECONDS ) || ! Rcaes_Seguranca::permitir( 'msg_dia_' . $uid, 500, DAY_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( self::url( $mid ), 'muitas_tentativas' );
		}
		$txt = Rcaes_Seguranca::post_texto( 'texto', 1000, true );
		if ( '' === $txt ) {
			Rcaes_Seguranca::voltar( self::url( $mid ), 'msg_invalida' );
		}
		Rcaes_DB::criar_mensagem( $mid, $uid, $txt );
		delete_transient( self::chave_digitando( $mid, $uid ) );
		Rcaes_Email::nova_mensagem( self::outro_uid( $m, $uid ), $mid, self::url( $mid ) );

		wp_safe_redirect( self::url( $mid ) . '#rcaes-fim' );
		exit;
	}

	public static function encerrar() {
		$uid = Rcaes_Seguranca::exigir_usuario();
		$mid = isset( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0;
		check_admin_referer( 'rcaes_enc_' . $mid );
		$base = Rcaes_Seguranca::pagina_url( 'conversas' );

		if ( ! self::conversa_do_usuario( $mid, $uid ) ) {
			Rcaes_Seguranca::voltar( $base, 'estado_invalido' );
		}
		Rcaes_DB::mudar_status_match( $mid, 'aceito', array( 'status' => 'encerrado' ) );
		Rcaes_Seguranca::voltar( $base, 'conversa_encerrada' );
	}

	/**
	 * "Marcar como cão resgatado": pedir / cancelar / confirmar.
	 * Só a OUTRA pessoa pode confirmar. Confirmar resolve os dois cadastros, encerra a conversa
	 * e desliga todas as outras correspondências desses cães.
	 */
	public static function resgate() {
		$uid = Rcaes_Seguranca::exigir_usuario();
		$mid = isset( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0;
		check_admin_referer( 'rcaes_resgate_' . $mid );
		$base  = Rcaes_Seguranca::pagina_url( 'conversas' );
		$volta = self::url( $mid );

		$m = self::conversa_do_usuario( $mid, $uid );
		if ( ! $m ) {
			Rcaes_Seguranca::voltar( $base, 'estado_invalido' );
		}
		$acao    = Rcaes_Seguranca::post_escolha( 'acao', array( 'pedir' => 1, 'cancelar' => 1, 'confirmar' => 1 ) );
		$pediu   = (int) $m->resgate_por;

		if ( 'pedir' === $acao ) {
			if ( $pediu || ! Rcaes_DB::definir_resgate( $mid, $uid ) ) {
				Rcaes_Seguranca::voltar( $volta, 'estado_invalido' );
			}
			Rcaes_Seguranca::voltar( $volta, 'resgate_pedido' );
		}
		if ( 'cancelar' === $acao ) {
			if ( ! $pediu ) {
				Rcaes_Seguranca::voltar( $volta, 'estado_invalido' );
			}
			// Quem cancela é quem pediu, ou quem recebeu o pedido ("Ainda não").
			Rcaes_DB::definir_resgate( $mid, 0 );
			Rcaes_Seguranca::voltar( $volta, 'resgate_cancelado' );
		}
		if ( 'confirmar' === $acao ) {
			if ( ! $pediu || $pediu === $uid ) {
				Rcaes_Seguranca::voltar( $volta, 'estado_invalido' );
			}
			if ( ! self::finalizar_resgate( $m ) ) {
				Rcaes_Seguranca::voltar( $volta, 'estado_invalido' );
			}
			Rcaes_Seguranca::voltar( $base, 'resgate_confirmado' );
		}
		Rcaes_Seguranca::voltar( $volta, 'estado_invalido' );
	}

	/** Resolve os dois cães e encerra os vínculos. Devolve false se a conversa já não estava ativa. */
	private static function finalizar_resgate( $m ) {
		$ok = Rcaes_DB::mudar_status_match(
			(int) $m->id,
			'aceito',
			array( 'status' => 'resolvido', 'respondido_em' => Rcaes_DB::agora() )
		);
		if ( ! $ok ) {
			return false;
		}
		foreach ( array( (int) $m->cao_perdido_id, (int) $m->cao_encontrado_id ) as $cao_id ) {
			Rcaes_DB::resolver_cao( $cao_id, 'pelo_site', (int) $m->id );
			Rcaes_DB::encerrar_matches_do_cao( $cao_id ); // não mexe na correspondência já "resolvida"
		}
		delete_transient( 'rcaes_numeros' );
		return true;
	}

	/* ---------------------------------------------------------------- REST (caixa flutuante e chat) */

	public static function rotas_rest() {
		$participa = function ( WP_REST_Request $req ) {
			return Rcaes_Seguranca::usuario_verificado() && null !== self::conversa_do_usuario( absint( $req['id'] ), get_current_user_id() );
		};
		$verificado = function () {
			return Rcaes_Seguranca::usuario_verificado();
		};

		register_rest_route( 'rcaes/v1', '/caixa', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_caixa' ),
			'permission_callback' => $verificado,
		) );

		register_rest_route( 'rcaes/v1', '/mensagens/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_mensagens' ),
				'permission_callback' => $participa,
				'args'                => array( 'depois' => array( 'default' => 0, 'sanitize_callback' => 'absint' ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_enviar' ),
				'permission_callback' => $participa,
			),
		) );

		register_rest_route( 'rcaes/v1', '/digitando/(?P<id>\d+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_digitando' ),
			'permission_callback' => $participa,
		) );

		register_rest_route( 'rcaes/v1', '/pedido/(?P<id>\d+)/visto', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_pedido_visto' ),
			'permission_callback' => $verificado,
		) );
	}

	public static function rest_mensagens( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		if ( ! Rcaes_Seguranca::permitir( 'rest_msg_' . $uid, 240, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'rcaes_limite', 'Muitas requisições.', array( 'status' => 429 ) );
		}
		$mid  = absint( $req['id'] );
		$m    = self::conversa_do_usuario( $mid, $uid );
		$rows = Rcaes_DB::mensagens_do_match( $mid, absint( $req->get_param( 'depois' ) ), 100 );
		Rcaes_DB::marcar_lidas( $mid, $uid );
		$out = array();
		foreach ( $rows as $g ) {
			$out[] = array(
				'id'     => (int) $g->id,
				'meu'    => (int) $g->user_id === $uid,
				'texto'  => (string) $g->texto,
				'quando' => get_date_from_gmt( $g->criado_em, 'd/m H:i' ),
			);
		}
		return rest_ensure_response( array(
			'mensagens' => $out,
			'digitando' => (bool) get_transient( self::chave_digitando( $mid, self::outro_uid( $m, $uid ) ) ),
		) );
	}

	public static function rest_enviar( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		$mid = absint( $req['id'] );
		$m   = self::conversa_do_usuario( $mid, $uid );
		if ( ! $m ) {
			return new WP_Error( 'rcaes_indisponivel', 'Conversa indisponível.', array( 'status' => 404 ) );
		}
		if ( ! Rcaes_Seguranca::permitir( 'msg_min_' . $uid, 20, MINUTE_IN_SECONDS ) || ! Rcaes_Seguranca::permitir( 'msg_dia_' . $uid, 500, DAY_IN_SECONDS ) ) {
			return new WP_Error( 'rcaes_limite', 'Muitas mensagens. Aguarde um pouco.', array( 'status' => 429 ) );
		}
		$txt = trim( mb_substr( sanitize_textarea_field( (string) $req->get_param( 'texto' ) ), 0, 1000 ) );
		if ( '' === $txt ) {
			return new WP_Error( 'rcaes_vazia', 'Mensagem vazia.', array( 'status' => 400 ) );
		}
		Rcaes_DB::criar_mensagem( $mid, $uid, $txt );
		delete_transient( self::chave_digitando( $mid, $uid ) );
		Rcaes_Email::nova_mensagem( self::outro_uid( $m, $uid ), $mid, self::url( $mid ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_digitando( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		$mid = absint( $req['id'] );
		if ( ! Rcaes_Seguranca::permitir( 'dig_' . $uid, 120, MINUTE_IN_SECONDS ) ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}
		$k = self::chave_digitando( $mid, $uid );
		if ( rest_sanitize_boolean( $req->get_param( 'ativo' ) ) ) {
			set_transient( $k, 1, 6 );
		} else {
			delete_transient( $k );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_pedido_visto( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		$pid = absint( $req['id'] );
		$p   = $pid ? Rcaes_DB::match_completo( $pid ) : null;
		if ( ! $p || '' === Rcaes_Lista::lado( $p, $uid ) || (int) $p->iniciado_por === $uid ) {
			return new WP_Error( 'rcaes_indisponivel', 'Pedido indisponível.', array( 'status' => 404 ) );
		}
		$v = self::vistos( $uid );
		if ( ! in_array( $pid, $v, true ) ) {
			$v[] = $pid;
			update_user_meta( $uid, 'rcaes_pedidos_vistos', array_slice( $v, -200 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_caixa() {
		$uid = get_current_user_id();
		if ( ! Rcaes_Seguranca::permitir( 'caixa_' . $uid, 120, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'rcaes_limite', 'Muitas requisições.', array( 'status' => 429 ) );
		}
		$total = 0;

		$conversas = array();
		foreach ( Rcaes_DB::conversas_do_usuario( $uid ) as $m ) {
			$outro_uid = self::outro_uid( $m, $uid );
			$cao       = self::cao_do_outro( $m, $uid );
			$n         = (int) $m->nao_lidas;
			$total    += $n;
			$conversas[] = array(
				'id'        => (int) $m->id,
				'nome'      => Rcaes_Seguranca::nome_publico( $outro_uid ),
				'tipo'      => $cao ? Rcaes_Caes::tipo_rotulo( $cao->tipo ) : '',
				'ultima'    => (string) mb_substr( (string) $m->ult_texto, 0, 90 ),
				'quando'    => $m->ultima ? get_date_from_gmt( $m->ultima, 'd/m H:i' ) : '',
				'nao_lidas' => $n,
				'digitando' => (bool) get_transient( self::chave_digitando( $m->id, $outro_uid ) ),
				'url'       => self::url( (int) $m->id ),
			);
		}

		$vistos  = self::vistos( $uid );
		$pedidos = array();
		foreach ( Rcaes_DB::pedidos_recebidos( $uid ) as $p ) {
			$meu = Rcaes_DB::cao( 'perdido' === Rcaes_Lista::lado( $p, $uid ) ? $p->cao_perdido_id : $p->cao_encontrado_id );
			if ( ! $meu ) {
				continue;
			}
			$nova = ! in_array( (int) $p->id, $vistos, true );
			if ( $nova ) {
				++$total;
			}
			$pedidos[] = array(
				'id'       => (int) $p->id,
				'nome'     => Rcaes_Seguranca::nome_publico( $p->iniciado_por ),
				'mensagem' => (string) mb_substr( (string) $p->mensagem_inicial, 0, 300 ),
				'resposta' => (string) mb_substr( (string) $p->resposta_verificacao, 0, 300 ),
				'quando'   => $p->solicitado_em ? get_date_from_gmt( $p->solicitado_em, 'd/m H:i' ) : '',
				'nova'     => $nova,
				'lista'    => Rcaes_Lista::url( $meu->token ),
				'nonce'    => wp_create_nonce( 'rcaes_contato_resp_' . (int) $p->id ),
			);
		}

		return rest_ensure_response( array(
			'total'     => $total,
			'conversas' => $conversas,
			'pedidos'   => $pedidos,
		) );
	}
}
