<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cadastro, confirmação de e-mail e login (shortcodes [rcaes_cadastro] e [rcaes_login]).
 * Usa as funções nativas do WordPress para senha (hash) e sessão (cookies).
 */
class Rcaes_Conta {

	public static function init() {
		add_shortcode( 'rcaes_cadastro', array( __CLASS__, 'sc_cadastro' ) );
		add_shortcode( 'rcaes_login', array( __CLASS__, 'sc_login' ) );

		foreach ( array( 'rcaes_cadastro' => 'processar_cadastro', 'rcaes_login' => 'processar_login', 'rcaes_reenviar' => 'processar_reenvio' ) as $acao => $metodo ) {
			add_action( 'admin_post_nopriv_' . $acao, array( __CLASS__, $metodo ) );
			add_action( 'admin_post_' . $acao, array( __CLASS__, $metodo ) );
		}

		add_action( 'template_redirect', array( __CLASS__, 'verificar_email' ), 5 );
		add_filter( 'authenticate', array( __CLASS__, 'bloquear_nao_verificado' ), 30 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'barra_admin' ) );
		add_action( 'admin_init', array( __CLASS__, 'restringir_admin' ) );
		add_action( 'delete_user', array( 'Rcaes_DB', 'excluir_dados_usuario' ) );
	}

	/* ---------------------------------------------------------------- formulários */

	public static function sc_cadastro() {
		Rcaes_Seguranca::nocache();
		if ( is_user_logged_in() ) {
			return '<div class="rcaes-aviso rcaes-info">Você já está conectado.</div>';
		}
		ob_start();
		if ( 'cadastro_enviado' === Rcaes_Popup::atual() ) {
			echo Rcaes_Popup::html( 'cadastro_enviado' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo Rcaes_Seguranca::aviso(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<div class="rcaes-auth">
			<div class="rcaes-auth-icone" aria-hidden="true">🐾</div>
			<h2>Criar sua conta</h2>
			<p class="rcaes-sub">Cadastre-se gratuitamente e proteja seu cão</p>
		<form class="rcaes-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rcaes_cadastro">
			<?php wp_nonce_field( 'rcaes_cadastro' ); ?>
			<?php echo Rcaes_Seguranca::campos_anti_bot(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<p><label>Nome<br><input type="text" name="nome" maxlength="60" required autocomplete="name" placeholder="Seu nome completo"></label></p>
			<p><label>E-mail<br><input type="email" name="email" maxlength="100" required autocomplete="email" placeholder="seu@email.com.br"></label></p>
			<p><label>Telefone (opcional, nunca é exibido a outros usuários)<br><input type="tel" name="telefone" maxlength="20" autocomplete="tel" placeholder="(11) 99999-0000"></label></p>
			<p><label>Senha (mínimo de 10 caracteres)<br><?php echo Rcaes_Seguranca::campo_senha( 'senha', 'Mínimo de 10 caracteres', 'new-password', 'minlength="10" maxlength="128" required' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label></p>
			<p><label>Repita a senha<br><?php echo Rcaes_Seguranca::campo_senha( 'senha2', 'Repita sua senha', 'new-password', 'minlength="10" maxlength="128" required' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label></p>
			<p><label><input type="checkbox" name="aceite" value="1" required>
				Li e aceito os termos de uso e a política de privacidade, e autorizo o tratamento das fotos e dados informados para a finalidade de reencontrar cães.</label></p>
			<p><button type="submit" class="rcaes-btn rcaes-btn-escuro">Criar conta</button></p>
			<p class="rcaes-peq">Já tem conta? <a href="<?php echo esc_url( Rcaes_Seguranca::pagina_url( 'login' ) ); ?>">Entrar</a></p>
		</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function sc_login() {
		Rcaes_Seguranca::nocache();
		if ( is_user_logged_in() ) {
			return '<div class="rcaes-aviso rcaes-info">Você já está conectado. <a href="' . esc_url( Rcaes_Seguranca::pagina_url( 'meus_caes' ) ) . '">Meus cães</a> · <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">Sair</a></div>';
		}
		$redir = '';
		if ( isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ) {
			$redir = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' ); // só destinos do próprio site
		}
		ob_start();
		echo Rcaes_Seguranca::aviso(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<div class="rcaes-auth">
			<div class="rcaes-auth-icone rcaes-laranja" aria-hidden="true">🐾</div>
			<h2>Entrar na sua conta</h2>
			<p class="rcaes-sub">Bem-vindo de volta</p>
		<form class="rcaes-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rcaes_login">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redir ); ?>">
			<?php wp_nonce_field( 'rcaes_login' ); ?>
			<p><label>E-mail<br><input type="email" name="email" required autocomplete="username" placeholder="seu@email.com.br"></label></p>
			<p><label>Senha<br><?php echo Rcaes_Seguranca::campo_senha( 'senha', 'Sua senha', 'current-password', 'required' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label></p>
			<p><label><input type="checkbox" name="lembrar" value="1"> Manter conectado</label></p>
			<p><button type="submit" class="rcaes-btn rcaes-btn-escuro">Entrar</button></p>
			<p class="rcaes-peq">
				<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Esqueci minha senha</a> ·
				<a href="<?php echo esc_url( Rcaes_Seguranca::pagina_url( 'cadastro' ) ); ?>">Criar conta</a>
			</p>
		</form>

		<details class="rcaes-detalhes">
			<summary>Não recebi o e-mail de confirmação</summary>
			<form class="rcaes-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rcaes_reenviar">
				<?php wp_nonce_field( 'rcaes_reenviar' ); ?>
				<p><label>E-mail<br><input type="email" name="email" required></label></p>
				<p><button type="submit" class="rcaes-btn rcaes-btn-sec">Reenviar confirmação</button></p>
			</form>
		</details>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------- cadastro */

	public static function processar_cadastro() {
		check_admin_referer( 'rcaes_cadastro' );
		$url = Rcaes_Seguranca::pagina_url( 'cadastro' );

		if ( ! Rcaes_Seguranca::anti_bot_ok() ) {
			Rcaes_Seguranca::voltar( $url, 'falha_geral' );
		}
		if ( ! Rcaes_Seguranca::permitir( 'cadastro_ip_' . Rcaes_Seguranca::ip(), 5, HOUR_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( $url, 'muitas_tentativas' );
		}

		$nome   = Rcaes_Seguranca::post_texto( 'nome', 60 );
		$email  = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$tel    = preg_replace( '/\D+/', '', Rcaes_Seguranca::post_texto( 'telefone', 20 ) );
		$senha  = isset( $_POST['senha'] ) && is_string( $_POST['senha'] ) ? wp_unslash( $_POST['senha'] ) : '';
		$senha2 = isset( $_POST['senha2'] ) && is_string( $_POST['senha2'] ) ? wp_unslash( $_POST['senha2'] ) : '';

		if ( mb_strlen( $nome ) < 2 || ! is_email( $email ) || strlen( $email ) > 100 || ( '' !== $tel && ( strlen( $tel ) < 10 || strlen( $tel ) > 13 ) ) ) {
			Rcaes_Seguranca::voltar( $url, 'campos_invalidos' );
		}
		if ( strlen( $senha ) < 10 || strlen( $senha ) > 128 ) {
			Rcaes_Seguranca::voltar( $url, 'senha_fraca' );
		}
		if ( ! hash_equals( $senha, $senha2 ) ) {
			Rcaes_Seguranca::voltar( $url, 'senhas_diferentes' );
		}
		if ( empty( $_POST['aceite'] ) ) {
			Rcaes_Seguranca::voltar( $url, 'aceite_necessario' );
		}

		// Evita usar o formulário para disparar e-mails em massa contra um endereço.
		if ( ! Rcaes_Seguranca::permitir( 'cadastro_email_' . strtolower( $email ), 3, HOUR_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar_popup( $url, 'cadastro_enviado' );
		}

		// Mesma resposta para e-mail novo ou já existente: não revela quem tem conta.
		if ( email_exists( $email ) ) {
			Rcaes_Email::conta_existente( $email );
			Rcaes_Seguranca::voltar_popup( $url, 'cadastro_enviado' );
		}

		// Login aleatório: o e-mail não aparece em URLs de autor nem no nome de usuário.
		$uid = wp_insert_user(
			array(
				'user_login'   => 'tutor_' . bin2hex( random_bytes( 6 ) ),
				'user_pass'    => $senha,
				'user_email'   => $email,
				'first_name'   => $nome,
				'display_name' => $nome,
				'nickname'     => $nome,
				'role'         => 'rcaes_tutor',
			)
		);
		if ( is_wp_error( $uid ) ) {
			Rcaes_Seguranca::log( 'Falha ao criar usuário: ' . $uid->get_error_message() );
			Rcaes_Seguranca::voltar( $url, 'falha_geral' );
		}
		update_user_meta( $uid, 'rcaes_telefone', $tel );
		update_user_meta( $uid, 'rcaes_email_verificado', 0 );
		update_user_meta( $uid, 'rcaes_aceite_em', time() );

		Rcaes_Email::verificacao( $uid, self::criar_link_verificacao( $uid ) );
		Rcaes_Seguranca::voltar_popup( $url, 'cadastro_enviado' );
	}

	private static function criar_link_verificacao( $uid ) {
		$k = bin2hex( random_bytes( 32 ) );
		update_user_meta( $uid, 'rcaes_verif_hash', hash( 'sha256', $k ) ); // só o hash fica no banco
		update_user_meta( $uid, 'rcaes_verif_exp', time() + 2 * DAY_IN_SECONDS );
		return add_query_arg( array( 'rcaes_verificar' => (int) $uid, 'k' => $k ), Rcaes_Seguranca::pagina_url( 'login' ) );
	}

	public static function verificar_email() {
		if ( empty( $_GET['rcaes_verificar'] ) || empty( $_GET['k'] ) || ! is_string( $_GET['k'] ) ) {
			return;
		}
		$login = Rcaes_Seguranca::pagina_url( 'login' );
		if ( ! Rcaes_Seguranca::permitir( 'verif_ip_' . Rcaes_Seguranca::ip(), 20, HOUR_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( $login, 'muitas_tentativas' );
		}
		$uid = absint( $_GET['rcaes_verificar'] );
		$k   = sanitize_text_field( wp_unslash( $_GET['k'] ) );
		if ( ! $uid || 1 !== preg_match( '/^[a-f0-9]{64}$/', $k ) ) {
			Rcaes_Seguranca::voltar( $login, 'verif_invalida' );
		}
		$hash = (string) get_user_meta( $uid, 'rcaes_verif_hash', true );
		$exp  = (int) get_user_meta( $uid, 'rcaes_verif_exp', true );
		if ( '' !== $hash && time() < $exp && hash_equals( $hash, hash( 'sha256', $k ) ) ) {
			update_user_meta( $uid, 'rcaes_email_verificado', 1 );
			delete_user_meta( $uid, 'rcaes_verif_hash' );
			delete_user_meta( $uid, 'rcaes_verif_exp' );
			Rcaes_Seguranca::voltar( $login, 'verificado' );
		}
		Rcaes_Seguranca::voltar( $login, 'verif_invalida' );
	}

	public static function processar_reenvio() {
		check_admin_referer( 'rcaes_reenviar' );
		$url   = Rcaes_Seguranca::pagina_url( 'login' );
		$email = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email )
			|| ! Rcaes_Seguranca::permitir( 'reenvio_ip_' . Rcaes_Seguranca::ip(), 5, HOUR_IN_SECONDS )
			|| ! Rcaes_Seguranca::permitir( 'reenvio_email_' . strtolower( $email ), 3, HOUR_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( $url, 'reenviado' ); // resposta igual: não revela nada
		}
		$u = get_user_by( 'email', $email );
		if ( $u && in_array( 'rcaes_tutor', (array) $u->roles, true ) && '1' !== (string) get_user_meta( $u->ID, 'rcaes_email_verificado', true ) ) {
			Rcaes_Email::verificacao( $u->ID, self::criar_link_verificacao( $u->ID ) );
		}
		Rcaes_Seguranca::voltar( $url, 'reenviado' );
	}

	/* ---------------------------------------------------------------- login */

	public static function bloquear_nao_verificado( $user ) {
		if ( $user instanceof WP_User
			&& in_array( 'rcaes_tutor', (array) $user->roles, true )
			&& '1' !== (string) get_user_meta( $user->ID, 'rcaes_email_verificado', true ) ) {
			return new WP_Error( 'rcaes_nao_verificado', 'E-mail não confirmado.' );
		}
		return $user;
	}

	public static function processar_login() {
		check_admin_referer( 'rcaes_login' );
		$url = Rcaes_Seguranca::pagina_url( 'login' );

		if ( ! Rcaes_Seguranca::permitir( 'login_ip_' . Rcaes_Seguranca::ip(), 10, 15 * MINUTE_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( $url, 'muitas_tentativas' );
		}
		$email = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$senha = isset( $_POST['senha'] ) && is_string( $_POST['senha'] ) ? wp_unslash( $_POST['senha'] ) : '';

		if ( ! is_email( $email ) || '' === $senha || strlen( $senha ) > 256 ) {
			Rcaes_Seguranca::voltar( $url, 'erro_login' );
		}
		if ( ! Rcaes_Seguranca::permitir( 'login_email_' . strtolower( $email ), 5, 15 * MINUTE_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( $url, 'muitas_tentativas' );
		}

		$u = get_user_by( 'email', $email );
		if ( ! $u ) {
			wp_hash_password( $senha ); // custo semelhante, para não vazar por tempo de resposta se o e-mail existe
			Rcaes_Seguranca::voltar( $url, 'erro_login' );
		}
		$r = wp_signon(
			array( 'user_login' => $u->user_login, 'user_password' => $senha, 'remember' => ! empty( $_POST['lembrar'] ) ),
			is_ssl()
		);
		if ( is_wp_error( $r ) ) {
			// "Não verificado" só aparece depois de a senha estar correta (o filtro roda após a checagem da senha).
			Rcaes_Seguranca::voltar( $url, 'rcaes_nao_verificado' === $r->get_error_code() ? 'nao_verificado' : 'erro_login' );
		}

		$destino = Rcaes_Seguranca::pagina_url( 'meus_caes' );
		if ( ! empty( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ) {
			$destino = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ), $destino );
		}
		wp_safe_redirect( $destino );
		exit;
	}

	/* ---------------------------------------------------------------- tutores não usam o painel */

	public static function barra_admin( $mostrar ) {
		$u = wp_get_current_user();
		return ( $u && in_array( 'rcaes_tutor', (array) $u->roles, true ) ) ? false : $mostrar;
	}

	public static function restringir_admin() {
		global $pagenow;
		if ( wp_doing_ajax() || in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		$u = wp_get_current_user();
		if ( $u && in_array( 'rcaes_tutor', (array) $u->roles, true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}
}
