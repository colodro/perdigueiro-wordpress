<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pop-ups exibidos depois de um evento (ex.: cadastro de conta, cadastro de cão).
 *
 * O evento redireciona para a própria página com ?rcaes_popup=<chave>. A página renderiza o
 * pop-up (sem JavaScript) e o botão leva ao destino configurado em Reencontro de Cães → Configurações.
 * Textos, destinos e o vídeo ficam nas configurações; nada disso fica fixo no código.
 */
class Rcaes_Popup {

	const CHAVES = array( 'cadastro_enviado', 'cao_analise' );

	/** Chave do pop-up pedido na URL (lista branca) ou ''. */
	public static function atual() {
		if ( empty( $_GET['rcaes_popup'] ) || ! is_string( $_GET['rcaes_popup'] ) ) {
			return '';
		}
		$c = sanitize_key( wp_unslash( $_GET['rcaes_popup'] ) );
		return in_array( $c, self::CHAVES, true ) ? $c : '';
	}

	/** Valores vindos das configurações, com padrões seguros. */
	public static function config( $chave ) {
		if ( 'cao_analise' === $chave ) {
			$destino = (string) get_option( 'rcaes_pop_cao_destino', '' );
			return array(
				'titulo'  => (string) get_option( 'rcaes_pop_cao_titulo', 'Cadastro em análise' ),
				'texto'   => (string) get_option( 'rcaes_pop_cao_texto', 'Seu cão foi cadastrado e está em análise. Vamos comparar as fotos com os outros cadastros e avisamos por e-mail se encontrarmos um possível reencontro.' ),
				'botao'   => (string) get_option( 'rcaes_pop_cao_botao', 'Ok' ),
				'destino' => '' !== $destino ? $destino : Rcaes_Seguranca::pagina_url( 'meus_caes' ),
				'video'   => (string) get_option( 'rcaes_pop_cao_video', '' ),
			);
		}
		// cadastro_enviado (padrão)
		$destino = (string) get_option( 'rcaes_pop_cad_destino', '' );
		return array(
			'titulo'  => (string) get_option( 'rcaes_pop_cad_titulo', 'Cadastro enviado!' ),
			'texto'   => (string) get_option( 'rcaes_pop_cad_texto', 'Seu cadastro foi enviado. Agora você precisa confirmar sua conta pelo e-mail que enviamos para você.' ),
			'botao'   => (string) get_option( 'rcaes_pop_cad_botao', 'Entendi' ),
			'destino' => '' !== $destino ? $destino : Rcaes_Seguranca::pagina_url( 'login' ),
			'video'   => '',
		);
	}

	/** Marcação completa do pop-up (já escapada). */
	public static function html( $chave ) {
		$c     = self::config( $chave );
		$video = self::video_html( $c['video'] );
		ob_start();
		?>
		<div class="rcaes-popup-fundo">
			<div class="rcaes-popup" role="dialog" aria-modal="true" aria-labelledby="rcaes-popup-titulo">
				<div class="rcaes-auth-icone" aria-hidden="true">🐾</div>
				<h2 id="rcaes-popup-titulo" class="rcaes-popup-titulo"><?php echo esc_html( $c['titulo'] ); ?></h2>
				<p class="rcaes-popup-texto"><?php echo nl2br( esc_html( $c['texto'] ) ); ?></p>
				<?php echo $video; // phpcs:ignore WordPress.Security.EscapeOutput -- montado e escapado em video_html() ?>
				<p><a class="rcaes-btn rcaes-btn-bloco" href="<?php echo esc_url( $c['destino'] ); ?>"><?php echo esc_html( $c['botao'] ); ?></a></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Vídeo do pop-up: YouTube, Vimeo ou arquivo .mp4/.webm direto. Só HTTPS e domínios conhecidos.
	 * Retorna '' se o link não for aceito.
	 */
	public static function video_html( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
			return '';
		}
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$host  = strtolower( preg_replace( '/^www\./', '', (string) $p['host'] ) );
		$path  = isset( $p['path'] ) ? (string) $p['path'] : '';
		$qs    = array();
		if ( ! empty( $p['query'] ) ) {
			parse_str( (string) $p['query'], $qs );
		}
		$embed = '';

		if ( in_array( $host, array( 'youtube.com', 'm.youtube.com' ), true ) ) {
			$id = '';
			if ( isset( $qs['v'] ) && is_string( $qs['v'] ) ) {
				$id = $qs['v'];
			} elseif ( preg_match( '#^/(?:embed|shorts)/([A-Za-z0-9_-]{6,20})#', $path, $m ) ) {
				$id = $m[1];
			}
			if ( 1 === preg_match( '/^[A-Za-z0-9_-]{6,20}$/', $id ) ) {
				$embed = 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&mute=1&playsinline=1&rel=0';
			}
		} elseif ( 'youtu.be' === $host ) {
			if ( preg_match( '#^/([A-Za-z0-9_-]{6,20})#', $path, $m ) ) {
				$embed = 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&mute=1&playsinline=1&rel=0';
			}
		} elseif ( 'vimeo.com' === $host || 'player.vimeo.com' === $host ) {
			if ( preg_match( '#/(?:video/)?(\d+)#', $path, $m ) ) {
				$embed = 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&muted=1&playsinline=1';
			}
		}

		if ( '' !== $embed ) {
			return '<div class="rcaes-popup-video"><iframe src="' . esc_url( $embed ) . '" title="Vídeo" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>';
		}
		if ( 1 === preg_match( '/\.(mp4|webm)$/i', $path ) ) {
			return '<div class="rcaes-popup-video"><video src="' . esc_url( $url ) . '" autoplay muted loop playsinline controls></video></div>';
		}
		return '';
	}
}
