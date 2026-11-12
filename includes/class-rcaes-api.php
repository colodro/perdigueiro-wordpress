<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cliente da API de inferência (Flask/TFLite). Só o servidor PHP fala com ela:
 * o token nunca chega ao navegador.
 *
 * Defina no wp-config.php (preferível a gravar no banco):
 *   define( 'RCAES_API_URL',   'https://seu-servico.onrender.com' );
 *   define( 'RCAES_API_TOKEN', 'chave-longa-e-aleatoria' );
 */
class Rcaes_API {

	public static function base_url() {
		$u = defined( 'RCAES_API_URL' ) ? RCAES_API_URL : get_option( 'rcaes_api_url', '' );
		return untrailingslashit( esc_url_raw( (string) $u ) );
	}

	public static function token() {
		return defined( 'RCAES_API_TOKEN' ) ? (string) RCAES_API_TOKEN : (string) get_option( 'rcaes_api_token', '' );
	}

	private static function requisitar( $metodo, $rota, $corpo = null ) {
		$base = self::base_url();
		if ( '' === $base ) {
			return new WP_Error( 'api_config', 'API de inferência não configurada.' );
		}
		if ( 0 !== strpos( $base, 'https://' ) && ! ( defined( 'RCAES_PERMITIR_HTTP' ) && RCAES_PERMITIR_HTTP ) ) {
			return new WP_Error( 'api_config', 'A URL da API deve usar HTTPS.' );
		}
		$args = array(
			'method'              => $metodo,
			'timeout'             => max( 10, min( 180, (int) get_option( 'rcaes_api_timeout', 90 ) ) ), // 1ª chamada após hibernação é lenta
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 2 * MB_IN_BYTES,
			'headers'             => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
		);
		$t = self::token();
		if ( '' !== $t ) {
			$args['headers']['Authorization'] = 'Bearer ' . $t;
		}
		if ( null !== $corpo ) {
			$args['body'] = wp_json_encode( $corpo );
		}
		// wp_safe_*: recusa destinos internos/privados (mitiga SSRF se a URL for alterada indevidamente).
		$r = wp_safe_remote_request( $base . $rota, $args );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'api_http', 'A API retornou o status ' . $code . '.', array( 'status' => $code ) );
		}
		$dados = json_decode( wp_remote_retrieve_body( $r ), true );
		return is_array( $dados ) ? $dados : new WP_Error( 'api_json', 'Resposta inválida da API.' );
	}

	private static function vetor( $v ) {
		if ( ! is_array( $v ) || count( $v ) < 8 || count( $v ) > 4096 ) {
			return false;
		}
		foreach ( $v as $x ) {
			if ( ! is_int( $x ) && ! is_float( $x ) ) {
				return false;
			}
		}
		return array_map( 'floatval', $v );
	}

	public static function health() {
		return self::requisitar( 'GET', '/health' );
	}

	/** @return array|WP_Error vetor de embedding */
	public static function embedding( $foto_b64 ) {
		$r = self::requisitar( 'POST', '/embedding', array( 'foto' => $foto_b64 ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$v = isset( $r['embedding'] ) ? self::vetor( $r['embedding'] ) : false;
		return $v ? $v : new WP_Error( 'api_json', 'Embedding inválido.' );
	}

	/**
	 * @param array $candidatos lista de array( 'id_cao' => int, 'embedding' => float[] )
	 * @return array|WP_Error array( 'embedding' => float[], 'ranking' => array( array( 'id_cao', 'distancia' ) ) )
	 */
	public static function buscar( $foto_b64, array $candidatos, $top_k = 100 ) {
		$r = self::requisitar( 'POST', '/search', array( 'foto' => $foto_b64, 'candidatos' => $candidatos, 'top_k' => (int) $top_k ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$v = isset( $r['embedding_novo'] ) ? self::vetor( $r['embedding_novo'] ) : false;
		if ( ! $v || ! isset( $r['ranking'] ) || ! is_array( $r['ranking'] ) ) {
			return new WP_Error( 'api_json', 'Resposta de busca inválida.' );
		}
		$rank = array();
		foreach ( $r['ranking'] as $i ) {
			if ( is_array( $i ) && isset( $i['id_cao'], $i['distancia'] ) && is_numeric( $i['distancia'] ) ) {
				$rank[] = array( 'id_cao' => (int) $i['id_cao'], 'distancia' => (float) $i['distancia'] );
			}
		}
		return array( 'embedding' => $v, 'ranking' => $rank );
	}
}
