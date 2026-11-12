<?php
defined( 'ABSPATH' ) || exit;

/**
 * Upload seguro e entrega privada de imagens.
 *  - valida tipo real (não confia em nome/Content-Type), tamanho e dimensões;
 *  - recodifica tudo como JPEG (GD): descarta EXIF/GPS e qualquer carga embutida;
 *  - grava em pasta privada com nome aleatório;
 *  - só entrega para usuário autenticado e autorizado.
 */
class Rcaes_Imagens {

	const MAX_PIXELS = 25000000;
	const LADO_MAX   = 1024;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'servir' ), 1 );
	}

	public static function pasta() {
		$u   = wp_upload_dir();
		$dir = trailingslashit( $u['basedir'] ) . 'rcaes-privado';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents(
				$dir . '/.htaccess',
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}
		return $dir;
	}

	public static function somente_gd() {
		return array( 'WP_Image_Editor_GD' );
	}

	/**
	 * @param array $arq tmp_name, error, size (item de $_FILES já normalizado)
	 * @return string|WP_Error nome do arquivo salvo
	 */
	public static function processar( array $arq ) {
		$erro = new WP_Error( 'imagem', 'Imagem inválida.' );

		if ( UPLOAD_ERR_OK !== (int) $arq['error'] || empty( $arq['tmp_name'] ) || ! is_uploaded_file( $arq['tmp_name'] ) ) {
			return $erro;
		}
		$max = max( 1, (int) get_option( 'rcaes_max_mb', 5 ) ) * 1048576;
		if ( (int) $arq['size'] <= 0 || (int) $arq['size'] > $max ) {
			return $erro;
		}
		$info = @getimagesize( $arq['tmp_name'] );
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
			return $erro;
		}
		if ( $info[0] < 100 || $info[1] < 100 || ( $info[0] * $info[1] ) > self::MAX_PIXELS ) {
			return $erro;
		}

		wp_raise_memory_limit( 'image' );
		add_filter( 'wp_image_editors', array( __CLASS__, 'somente_gd' ), 99 );
		$editor = wp_get_image_editor( $arq['tmp_name'], array( 'mime_type' => $info['mime'] ) );
		remove_filter( 'wp_image_editors', array( __CLASS__, 'somente_gd' ), 99 );
		if ( is_wp_error( $editor ) ) {
			return $erro;
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		if ( $info[0] > self::LADO_MAX || $info[1] > self::LADO_MAX ) {
			$editor->resize( self::LADO_MAX, self::LADO_MAX, false );
		}
		$editor->set_quality( 85 );

		$nome    = bin2hex( random_bytes( 16 ) ) . '.jpg';
		$destino = self::pasta() . '/' . $nome;
		$res     = $editor->save( $destino, 'image/jpeg' ); // GD recodifica: metadados não são copiados
		if ( is_wp_error( $res ) || ! file_exists( $destino ) ) {
			return $erro;
		}
		@chmod( $destino, 0640 );
		return $nome;
	}

	public static function nome_valido( $nome ) {
		return is_string( $nome ) && 1 === preg_match( '/^[a-f0-9]{32}\.jpg$/', $nome );
	}

	public static function excluir( $nome ) {
		if ( self::nome_valido( $nome ) ) {
			$f = self::pasta() . '/' . $nome;
			if ( is_file( $f ) ) {
				@unlink( $f );
			}
			$mini = self::pasta() . '/pub/' . substr( $nome, 0, 32 ) . '_640.jpg'; // miniatura da lista pública
			if ( is_file( $mini ) ) {
				@unlink( $mini );
			}
		}
	}

	/** Conteúdo em base64 para enviar à API de inferência. */
	public static function base64( $nome ) {
		if ( ! self::nome_valido( $nome ) ) {
			return new WP_Error( 'imagem', 'Arquivo inválido.' );
		}
		$f = self::pasta() . '/' . $nome;
		if ( ! is_readable( $f ) ) {
			return new WP_Error( 'imagem', 'Arquivo não encontrado.' );
		}
		$bin = file_get_contents( $f );
		return false === $bin ? new WP_Error( 'imagem', 'Falha ao ler o arquivo.' ) : base64_encode( $bin );
	}

	/* ---------------------------------------------------------------- entrega */

	public static function url( $foto_id ) {
		return add_query_arg( 'rcaes_img', (int) $foto_id, home_url( '/' ) );
	}

	public static function url_principal( $cao_id ) {
		$id = Rcaes_DB::primeira_foto_id( $cao_id );
		return $id ? self::url( $id ) : '';
	}

	/** Dono, administrador, ou (tutor verificado) cão ativo. Nunca para anônimos. */
	public static function pode_ver( $cao ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) || (int) $cao->user_id === get_current_user_id() ) {
			return true;
		}
		return Rcaes_Seguranca::usuario_verificado() && 'ativo' === $cao->status;
	}

	public static function servir() {
		if ( ! isset( $_GET['rcaes_img'] ) || ! is_string( $_GET['rcaes_img'] ) ) {
			return;
		}
		$id = absint( wp_unslash( $_GET['rcaes_img'] ) );

		if ( ! is_user_logged_in() ) {
			status_header( 403 );
			exit;
		}
		$foto = $id ? Rcaes_DB::foto( $id ) : null;
		$cao  = $foto ? Rcaes_DB::cao( $foto->cao_id ) : null;
		if ( ! $foto || ! $cao || ! self::pode_ver( $cao ) || ! self::nome_valido( $foto->arquivo ) ) {
			status_header( 404 );
			exit;
		}
		$arq = self::pasta() . '/' . $foto->arquivo;
		if ( ! is_readable( $arq ) ) {
			status_header( 404 );
			exit;
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $arq ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		header( 'Cache-Control: private, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );
		readfile( $arq );
		exit;
	}
}
