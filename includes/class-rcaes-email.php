<?php
defined( 'ABSPATH' ) || exit;

/**
 * E-mails em texto simples. Nunca incluem texto digitado por outros usuários
 * (mensagens, respostas): o conteúdo só é lido dentro do site, com login.
 */
class Rcaes_Email {

	private static function enviar( $para, $assunto, array $linhas ) {
		$para = sanitize_email( $para );
		if ( ! is_email( $para ) ) {
			return false;
		}
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$sub   = '[' . $site . '] ' . $assunto;
		$corpo = implode( "\n\n", $linhas ) . "\n\n--\n" . $site . "\n" . home_url( '/' );
		return wp_mail( $para, $sub, $corpo, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	private static function saudacao( $user_id ) {
		return 'Olá, ' . Rcaes_Seguranca::nome_publico( $user_id ) . '!';
	}

	private static function email_do_usuario( $user_id ) {
		$u = get_userdata( (int) $user_id );
		return $u ? $u->user_email : '';
	}

	public static function verificacao( $user_id, $url ) {
		return self::enviar(
			self::email_do_usuario( $user_id ),
			'Confirme seu e-mail',
			array( self::saudacao( $user_id ), "Para ativar sua conta, confirme seu e-mail neste link (válido por 48 horas):\n" . $url, 'Se você não criou esta conta, ignore esta mensagem.' )
		);
	}

	/** Mesma resposta na tela para quem tenta cadastrar um e-mail já existente (evita enumeração de contas). */
	public static function conta_existente( $email ) {
		return self::enviar(
			$email,
			'Tentativa de cadastro',
			array( "Alguém tentou criar uma conta com este e-mail, mas ele já está cadastrado.\nSe foi você, entre normalmente ou use \"Esqueci minha senha\":\n" . Rcaes_Seguranca::pagina_url( 'login' ), 'Se não foi você, ignore esta mensagem.' )
		);
	}

	/** Aviso de fim do processamento. O link só vai se houver ao menos um cão identificado. */
	public static function processo_concluido( $cao, $total ) {
		$nome = '' !== $cao->nome ? ' (' . sanitize_text_field( $cao->nome ) . ')' : '';
		if ( $total > 0 ) {
			$l = array(
				self::saudacao( $cao->user_id ),
				'Terminamos de analisar as fotos do cão cadastrado' . $nome . ' e encontramos ' . (int) $total . ' possível(is) correspondência(s).',
				"Para ver a lista (é necessário estar conectado):\n" . Rcaes_Lista::url( $cao->token ),
			);
			$a = 'Encontramos possíveis correspondências';
		} else {
			$l = array(
				self::saudacao( $cao->user_id ),
				'Terminamos de analisar as fotos do cão cadastrado' . $nome . '. Por enquanto não encontramos nenhum cão parecido.',
				'Seu cadastro continua ativo: avisaremos por e-mail se um cão semelhante for cadastrado.',
			);
			$a = 'Análise concluída';
		}
		return self::enviar( self::email_do_usuario( $cao->user_id ), $a, $l );
	}

	/** Outro usuário cadastrou um cão parecido com o seu (no máximo um aviso a cada 6 h por cão). */
	public static function novo_encontro( $cao ) {
		$k = 'rcaes_ne_' . (int) $cao->id;
		if ( get_transient( $k ) ) {
			return false;
		}
		set_transient( $k, 1, 6 * HOUR_IN_SECONDS );
		return self::enviar(
			self::email_do_usuario( $cao->user_id ),
			'Novo cão parecido com o seu cadastro',
			array(
				self::saudacao( $cao->user_id ),
				'Um cão recém-cadastrado parece ser compatível com um cadastro seu.',
				"Para ver (é necessário estar conectado):\n" . Rcaes_Lista::url( $cao->token ),
			)
		);
	}

	public static function falha( $cao ) {
		return self::enviar(
			self::email_do_usuario( $cao->user_id ),
			'Não foi possível processar as fotos',
			array( self::saudacao( $cao->user_id ), "Não conseguimos analisar as fotos do seu cadastro agora. Acesse \"Meus cães\" e peça um novo processamento:\n" . Rcaes_Seguranca::pagina_url( 'meus_caes' ) )
		);
	}

	public static function contato_solicitado( $cao_destino ) {
		return self::enviar(
			self::email_do_usuario( $cao_destino->user_id ),
			'Você recebeu uma solicitação de contato',
			array( self::saudacao( $cao_destino->user_id ), "Alguém pediu contato sobre um dos seus cadastros. Para ver e responder (é necessário estar conectado):\n" . Rcaes_Lista::url( $cao_destino->token ) )
		);
	}

	public static function contato_aceito( $user_id, $url_conversa ) {
		return self::enviar(
			self::email_do_usuario( $user_id ),
			'Contato aceito',
			array( self::saudacao( $user_id ), "Sua solicitação de contato foi aceita. A conversa está disponível aqui (é necessário estar conectado):\n" . $url_conversa )
		);
	}

	/** No máximo um aviso a cada 30 min por conversa. */
	public static function nova_mensagem( $user_id, $match_id, $url_conversa ) {
		$k = 'rcaes_nm_' . (int) $match_id . '_' . (int) $user_id;
		if ( get_transient( $k ) ) {
			return false;
		}
		set_transient( $k, 1, 30 * MINUTE_IN_SECONDS );
		return self::enviar(
			self::email_do_usuario( $user_id ),
			'Você recebeu uma nova mensagem',
			array( self::saudacao( $user_id ), "Há uma nova mensagem na sua conversa (é necessário estar conectado):\n" . $url_conversa )
		);
	}
}
