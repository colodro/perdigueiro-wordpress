<?php
defined( 'ABSPATH' ) || exit;

/**
 * [rcaes_lista] e o endereço /lista/<código>.
 *
 * O código é um UUID v4 aleatório (não adivinhável). Ele NÃO dá acesso sozinho:
 * é preciso estar conectado E ser o dono do cadastro ao qual o código pertence.
 * Sem login, o visitante é levado ao login e volta para a lista depois de entrar.
 *
 * Contato entre tutores: solicitação -> aceite da outra pessoa -> conversa.
 * Nenhum e-mail ou telefone é exibido em momento algum.
 */
class Rcaes_Lista {

	public static function init() {
		add_filter(
			'query_vars',
			function ( $v ) {
				$v[] = 'rcaes_token';
				return $v;
			}
		);
		add_action( 'init', array( __CLASS__, 'regras' ) );
		add_action( 'template_redirect', array( __CLASS__, 'proteger_pagina' ), 4 );
		add_shortcode( 'rcaes_lista', array( __CLASS__, 'sc_lista' ) );
		add_action( 'admin_post_rcaes_contato', array( __CLASS__, 'solicitar' ) );
		add_action( 'admin_post_rcaes_contato_resp', array( __CLASS__, 'responder' ) );
	}

	public static function regras() {
		$id = Rcaes_Seguranca::pagina_id( 'lista' );
		if ( $id ) {
			add_rewrite_rule( '^lista/([0-9a-f-]{36})/?$', 'index.php?page_id=' . $id . '&rcaes_token=$matches[1]', 'top' );
		}
	}

	public static function url( $token ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/lista/' . $token . '/' );
		}
		return add_query_arg( array( 'page_id' => Rcaes_Seguranca::pagina_id( 'lista' ), 'rcaes_token' => $token ), home_url( '/' ) );
	}

	/** Cabeçalhos de privacidade e redirecionamento para o login quando não há sessão. */
	public static function proteger_pagina() {
		$token = get_query_var( 'rcaes_token' );
		if ( ! is_string( $token ) || '' === $token ) {
			return;
		}
		$token = strtolower( $token );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' ); // o código não vaza em Referer
		if ( ! Rcaes_Seguranca::token_valido( $token ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( Rcaes_Seguranca::url_login( self::url( $token ) ) );
			exit;
		}
	}

	/** 'perdido' ou 'encontrado' conforme o lado do usuário no par; '' se não participa. */
	public static function lado( $m, $uid ) {
		if ( ! $m ) {
			return '';
		}
		if ( (int) $m->perdido_user === (int) $uid ) {
			return 'perdido';
		}
		if ( (int) $m->encontrado_user === (int) $uid ) {
			return 'encontrado';
		}
		return '';
	}

	/* ---------------------------------------------------------------- tela */

	private static function rotulo_correspondencia( $d ) {
		if ( null === $d ) {
			return 'Indicado por você';
		}
		$lim = max( 0.1, (float) get_option( 'rcaes_distancia_max', 0.90 ) );
		$d   = (float) $d;
		if ( $d <= 0.6 * $lim ) {
			return 'Muito provável';
		}
		if ( $d <= 0.85 * $lim ) {
			return 'Provável';
		}
		return 'Possível';
	}

	public static function sc_lista() {
		Rcaes_Seguranca::nocache();
		$token = strtolower( (string) get_query_var( 'rcaes_token' ) );

		if ( ! Rcaes_Seguranca::usuario_verificado() ) {
			return Rcaes_Seguranca::aviso_login( '' !== $token && Rcaes_Seguranca::token_valido( $token ) ? self::url( $token ) : '' );
		}
		if ( '' === $token ) {
			return self::indice();
		}
		$cao = Rcaes_Seguranca::token_valido( $token ) ? Rcaes_DB::cao_por_token( $token ) : null;
		// Mesma resposta para "não existe" e "não é seu": não confirma a existência de códigos.
		if ( ! $cao || (int) $cao->user_id !== get_current_user_id() || 'removido' === $cao->status ) {
			return '<div class="rcaes-aviso rcaes-erro">Link inválido ou não pertence a esta conta. Se você abriu o link com outra conta, saia e entre com a conta correta.</div>';
		}

		ob_start();
		echo Rcaes_Seguranca::aviso(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3><?php echo esc_html( Rcaes_Caes::tipo_rotulo( $cao->tipo ) ); ?><?php echo '' !== $cao->nome ? ' — ' . esc_html( $cao->nome ) : ''; ?></h3>
		<p class="rcaes-peq"><?php echo esc_html( Rcaes_Caes::rotulo( 'raca', $cao->raca ) . ' · ' . Rcaes_Caes::rotulo( 'porte', $cao->porte ) . ' · ' . Rcaes_Caes::rotulo( 'cor', $cao->cor ) ); ?></p>
		<?php

		if ( in_array( $cao->status, array( 'pendente', 'processando' ), true ) ) {
			echo '<div class="rcaes-aviso rcaes-info">Ainda estamos analisando as fotos. Você receberá um e-mail quando terminar; pode fechar esta página.</div>';
			return ob_get_clean();
		}
		if ( 'falha' === $cao->status ) {
			echo '<div class="rcaes-aviso rcaes-erro">Não foi possível analisar as fotos. Em <a href="' . esc_url( Rcaes_Seguranca::pagina_url( 'meus_caes' ) ) . '">Meus cães</a> você pode pedir um novo processamento.</div>';
			return ob_get_clean();
		}
		if ( 'ativo' !== $cao->status ) {
			echo '<div class="rcaes-aviso rcaes-info">Este cadastro está marcado como resolvido.</div>';
			return ob_get_clean();
		}

		$matches = Rcaes_DB::matches_do_cao( $cao->id );
		if ( ! $matches ) {
			echo '<div class="rcaes-aviso rcaes-info">Nenhuma correspondência por enquanto. Seu cadastro continua ativo e você será avisado por e-mail se aparecer um cão parecido.</div>';
			return ob_get_clean();
		}
		echo '<p class="rcaes-peq">O grau de correspondência é uma estimativa da IA, não uma confirmação. Compare as fotos com atenção antes de pedir contato.</p>';
		echo '<div class="rcaes-grade">';
		foreach ( $matches as $m ) {
			echo self::cartao( $m, $cao ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
		return ob_get_clean();
	}

	private static function indice() {
		$caes = array_filter(
			Rcaes_DB::caes_do_usuario( get_current_user_id() ),
			function ( $c ) {
				return 'ativo' === $c->status;
			}
		);
		if ( ! $caes ) {
			return '<div class="rcaes-aviso rcaes-info">Abra esta página pelo link enviado por e-mail, ou acesse <a href="' . esc_url( Rcaes_Seguranca::pagina_url( 'meus_caes' ) ) . '">Meus cães</a>.</div>';
		}
		$h = '<ul class="rcaes-lista-simples">';
		foreach ( $caes as $c ) {
			$h .= '<li><a href="' . esc_url( self::url( $c->token ) ) . '">' . esc_html( Rcaes_Caes::tipo_rotulo( $c->tipo ) . ( '' !== $c->nome ? ' — ' . $c->nome : '' ) ) . '</a></li>';
		}
		return $h . '</ul>';
	}

	private static function cartao( $m, $cao ) {
		$uid   = get_current_user_id();
		$lado  = self::lado( $m, $uid );
		$outro = Rcaes_DB::cao( 'perdido' === $lado ? $m->cao_encontrado_id : $m->cao_perdido_id );
		if ( '' === $lado || ! $outro ) {
			return '';
		}
		$foto = Rcaes_Imagens::url_principal( $outro->id );
		$mid  = (int) $m->id;

		ob_start();
		?>
		<div class="rcaes-cartao">
			<?php if ( $foto ) : ?>
				<img src="<?php echo esc_url( $foto ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<h4><?php echo esc_html( Rcaes_Caes::tipo_rotulo( $outro->tipo ) ); ?></h4>
			<p><span class="rcaes-etiqueta"><?php echo esc_html( self::rotulo_correspondencia( null === $m->distancia ? null : (float) $m->distancia ) ); ?></span></p>
			<p class="rcaes-peq"><?php echo esc_html( Rcaes_Caes::rotulo( 'raca', $outro->raca ) . ' · ' . Rcaes_Caes::rotulo( 'porte', $outro->porte ) . ' · ' . Rcaes_Caes::rotulo( 'cor', $outro->cor ) ); ?></p>
			<?php if ( '' !== (string) $outro->cidade ) : ?>
				<p class="rcaes-peq">Local: <?php echo esc_html( $outro->cidade . ( '' !== (string) $outro->uf ? ' – ' . $outro->uf : '' ) ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== (string) $outro->descricao ) : ?>
				<p><?php echo nl2br( esc_html( $outro->descricao ) ); ?></p>
			<?php endif; ?>

			<?php if ( 'sugerido' === $m->status ) : ?>
				<details class="rcaes-detalhes">
					<summary>Solicitar contato</summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rcaes-form">
						<input type="hidden" name="action" value="rcaes_contato">
						<input type="hidden" name="match_id" value="<?php echo $mid; ?>">
						<?php wp_nonce_field( 'rcaes_contato_' . $mid ); ?>
						<?php if ( 'perdido' === $lado && '' !== $outro->pergunta_verificacao ) : ?>
							<p><strong>Pergunta de verificação de quem encontrou:</strong><br><?php echo esc_html( $outro->pergunta_verificacao ); ?></p>
							<p><label>Sua resposta<br><textarea name="resposta" rows="2" maxlength="300" required></textarea></label></p>
						<?php endif; ?>
						<p><label>Mensagem para a outra pessoa<br><textarea name="mensagem" rows="3" minlength="5" maxlength="300" required></textarea></label></p>
						<p class="rcaes-peq">Não informe telefone, endereço ou outros dados pessoais agora. A outra pessoa decide se aceita; só então a conversa é aberta.</p>
						<p><button type="submit" class="rcaes-btn">Enviar solicitação</button></p>
					</form>
				</details>

			<?php elseif ( 'solicitado' === $m->status && (int) $m->iniciado_por === $uid ) : ?>
				<p class="rcaes-peq"><em>Solicitação enviada. Aguardando a resposta da outra pessoa.</em></p>

			<?php elseif ( 'solicitado' === $m->status ) : ?>
				<div class="rcaes-aviso rcaes-info">
					<strong><?php echo esc_html( Rcaes_Seguranca::nome_publico( $m->iniciado_por ) ); ?></strong> pediu contato:
					<blockquote><?php echo nl2br( esc_html( $m->mensagem_inicial ) ); ?></blockquote>
					<?php if ( '' !== (string) $m->resposta_verificacao ) : ?>
						<p class="rcaes-peq">Resposta à sua pergunta de verificação:</p>
						<blockquote><?php echo nl2br( esc_html( $m->resposta_verificacao ) ); ?></blockquote>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rcaes_contato_resp">
						<input type="hidden" name="match_id" value="<?php echo $mid; ?>">
						<?php wp_nonce_field( 'rcaes_contato_resp_' . $mid ); ?>
						<button name="decisao" value="aceitar" class="rcaes-btn">Aceitar</button>
						<button name="decisao" value="recusar" class="rcaes-btn rcaes-btn-sec" data-rcaes-confirmar="Recusar esta solicitação?">Recusar</button>
					</form>
				</div>

			<?php elseif ( 'aceito' === $m->status ) : ?>
				<p><a class="rcaes-btn" href="<?php echo esc_url( Rcaes_Chat::url( $mid ) ); ?>">Abrir conversa</a></p>

			<?php elseif ( 'recusado' === $m->status ) : ?>
				<p class="rcaes-peq"><em><?php echo (int) $m->iniciado_por === $uid ? 'Esta pessoa não está disponível para contato.' : 'Você recusou esta solicitação.'; ?></em></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------- ações */

	public static function solicitar() {
		$uid = Rcaes_Seguranca::exigir_usuario();
		$mid = isset( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0;
		check_admin_referer( 'rcaes_contato_' . $mid );
		$base = Rcaes_Seguranca::pagina_url( 'meus_caes' );

		$m    = $mid ? Rcaes_DB::match_completo( $mid ) : null;
		$lado = self::lado( $m, $uid ); // só participantes do par (evita IDOR)
		if ( ! $m || '' === $lado ) {
			Rcaes_Seguranca::voltar( $base, 'estado_invalido' );
		}
		$meu   = Rcaes_DB::cao( 'perdido' === $lado ? $m->cao_perdido_id : $m->cao_encontrado_id );
		$outro = Rcaes_DB::cao( 'perdido' === $lado ? $m->cao_encontrado_id : $m->cao_perdido_id );
		if ( ! $meu || ! $outro || 'ativo' !== $meu->status || 'ativo' !== $outro->status || 'sugerido' !== $m->status ) {
			Rcaes_Seguranca::voltar( $base, 'estado_invalido' );
		}
		$volta = self::url( $meu->token );

		if ( ! Rcaes_Seguranca::permitir( 'contato_' . $uid, 10, DAY_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( $volta, 'muitas_tentativas' );
		}
		$msg = Rcaes_Seguranca::post_texto( 'mensagem', 300, true );
		if ( mb_strlen( $msg ) < 5 ) {
			Rcaes_Seguranca::voltar( $volta, 'msg_invalida' );
		}
		$resp = '';
		if ( 'perdido' === $lado && '' !== (string) $outro->pergunta_verificacao ) {
			$resp = Rcaes_Seguranca::post_texto( 'resposta', 300, true );
			if ( '' === $resp ) {
				Rcaes_Seguranca::voltar( $volta, 'msg_invalida' );
			}
		}
		$ok = Rcaes_DB::mudar_status_match(
			$mid,
			'sugerido',
			array(
				'status'               => 'solicitado',
				'iniciado_por'         => $uid,
				'mensagem_inicial'     => $msg,
				'resposta_verificacao' => $resp,
				'solicitado_em'        => Rcaes_DB::agora(),
			)
		);
		if ( $ok ) {
			Rcaes_Email::contato_solicitado( $outro );
		}
		Rcaes_Seguranca::voltar( $volta, $ok ? 'contato_enviado' : 'estado_invalido' );
	}

	public static function responder() {
		$uid = Rcaes_Seguranca::exigir_usuario();
		$mid = isset( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0;
		check_admin_referer( 'rcaes_contato_resp_' . $mid );
		$base = Rcaes_Seguranca::pagina_url( 'meus_caes' );

		$m    = $mid ? Rcaes_DB::match_completo( $mid ) : null;
		$lado = self::lado( $m, $uid );
		// Só quem RECEBEU a solicitação pode responder.
		if ( ! $m || '' === $lado || 'solicitado' !== $m->status || (int) $m->iniciado_por === $uid ) {
			Rcaes_Seguranca::voltar( $base, 'estado_invalido' );
		}
		if ( ! Rcaes_Seguranca::permitir( 'resp_contato_' . $uid, 60, DAY_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( $base, 'muitas_tentativas' );
		}
		$meu   = Rcaes_DB::cao( 'perdido' === $lado ? $m->cao_perdido_id : $m->cao_encontrado_id );
		$volta = $meu ? self::url( $meu->token ) : $base;
		$dec   = Rcaes_Seguranca::post_escolha( 'decisao', array( 'aceitar' => 1, 'recusar' => 1 ) );

		if ( 'aceitar' === $dec ) {
			$ok = Rcaes_DB::mudar_status_match( $mid, 'solicitado', array( 'status' => 'aceito', 'respondido_em' => Rcaes_DB::agora() ) );
			if ( $ok ) {
				Rcaes_Email::contato_aceito( (int) $m->iniciado_por, Rcaes_Chat::url( $mid ) );
			}
			Rcaes_Seguranca::voltar( $volta, $ok ? 'contato_aceito' : 'estado_invalido' );
		}
		if ( 'recusar' === $dec ) {
			// Sem e-mail ao solicitante: evita desgaste e retaliação.
			$ok = Rcaes_DB::mudar_status_match( $mid, 'solicitado', array( 'status' => 'recusado', 'respondido_em' => Rcaes_DB::agora() ) );
			Rcaes_Seguranca::voltar( $volta, $ok ? 'contato_recusado' : 'estado_invalido' );
		}
		Rcaes_Seguranca::voltar( $volta, 'estado_invalido' );
	}
}
