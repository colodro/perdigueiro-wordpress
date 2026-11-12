<?php
defined( 'ABSPATH' ) || exit;

/**
 * Processamento assíncrono. O usuário cadastra o cão e pode sair: o WP-Cron
 * chama a API, grava os embeddings, cria os pares e envia os e-mails.
 *
 * Para confiabilidade em produção, use um cron real (veja o README):
 *   define( 'DISABLE_WP_CRON', true ) + cron do servidor chamando wp-cron.php a cada minuto.
 */
class Rcaes_Fila {

	const MAX_TENTATIVAS = 3;
	const LOTE           = 1000; // candidatos por requisição à API
	const MAX_RESULTADOS = 10;   // correspondências guardadas por cão

	public static function intervalos( $s ) {
		$s['rcaes_5min'] = array( 'interval' => 300, 'display' => 'A cada 5 minutos (Reencontro de Cães)' );
		return $s;
	}

	public static function init() {
		add_action( 'rcaes_processar_fila', array( __CLASS__, 'processar_fila' ) );
		if ( ! wp_next_scheduled( 'rcaes_processar_fila' ) ) {
			wp_schedule_event( time() + 60, 'rcaes_5min', 'rcaes_processar_fila' );
		}
	}

	/** Dispara uma execução imediata (além da recorrente). */
	public static function agendar_agora() {
		wp_schedule_single_event( time() + 2, 'rcaes_processar_fila', array( bin2hex( random_bytes( 4 ) ) ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	public static function processar_fila() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore
		}
		Rcaes_DB::liberar_travados( 20 );
		foreach ( Rcaes_DB::proximos_pendentes( 3 ) as $id ) {
			self::processar_cao( (int) $id );
		}
		if ( Rcaes_DB::proximos_pendentes( 1 ) ) {
			self::agendar_agora();
		}
	}

	public static function processar_cao( $cao_id ) {
		if ( ! Rcaes_DB::reservar( $cao_id ) ) {
			return;
		}
		$cao = Rcaes_DB::cao( $cao_id );
		if ( ! $cao ) {
			return;
		}
		$res = self::executar( $cao );
		if ( is_wp_error( $res ) ) {
			self::falhou( $cao, $res );
			return;
		}
		Rcaes_DB::atualizar_cao( $cao_id, array( 'status' => 'ativo', 'processado_em' => Rcaes_DB::agora(), 'proxima_tentativa' => null ) );
		Rcaes_Email::processo_concluido( $cao, $res['total'] );
		foreach ( $res['contrapartes'] as $outro_id ) {
			$outro = Rcaes_DB::cao( $outro_id );
			if ( $outro && 'ativo' === $outro->status ) {
				Rcaes_Email::novo_encontro( $outro );
			}
		}
	}

	private static function executar( $cao ) {
		$fotos = Rcaes_DB::fotos_do_cao( $cao->id );
		if ( ! $fotos ) {
			return new WP_Error( 'sem_fotos', 'Cão sem fotos.' );
		}
		$oposto     = 0 === (int) $cao->tipo ? 1 : 0;
		$candidatos = self::selecionar_candidatos( $cao, $oposto );
		$melhor     = array(); // id do cão candidato => menor distância

		foreach ( $fotos as $foto ) {
			$b64 = Rcaes_Imagens::base64( $foto->arquivo );
			if ( is_wp_error( $b64 ) ) {
				return $b64;
			}
			if ( ! $candidatos ) {
				$emb = Rcaes_API::embedding( $b64 );
				if ( is_wp_error( $emb ) ) {
					return $emb;
				}
				Rcaes_DB::salvar_embedding( $cao->id, $foto->id, $emb );
				continue;
			}
			$salvo = false;
			foreach ( array_chunk( $candidatos, self::LOTE ) as $lote ) {
				$r = Rcaes_API::buscar( $b64, $lote, 100 );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				if ( ! $salvo ) {
					Rcaes_DB::salvar_embedding( $cao->id, $foto->id, $r['embedding'] );
					$salvo = true;
				}
				foreach ( $r['ranking'] as $i ) {
					if ( ! isset( $melhor[ $i['id_cao'] ] ) || $i['distancia'] < $melhor[ $i['id_cao'] ] ) {
						$melhor[ $i['id_cao'] ] = $i['distancia'];
					}
				}
			}
		}

		$limiar       = (float) get_option( 'rcaes_distancia_max', 0.90 );
		$total        = 0;
		$contrapartes = array();
		asort( $melhor );
		foreach ( $melhor as $outro_id => $d ) {
			if ( $d > $limiar || $total >= self::MAX_RESULTADOS ) {
				break;
			}
			$total++;
			$perdido    = 0 === (int) $cao->tipo ? (int) $cao->id : (int) $outro_id;
			$encontrado = 0 === (int) $cao->tipo ? (int) $outro_id : (int) $cao->id;
			if ( Rcaes_DB::registrar_match( $perdido, $encontrado, $d ) ) {
				$contrapartes[] = (int) $outro_id;
			}
		}
		return array( 'total' => $total, 'contrapartes' => $contrapartes );
	}

	/**
	 * Filtro por metadados (porte/raça/cor). Se houver poucos compatíveis,
	 * compara com todos (RF05): em bases pequenas o filtro só atrapalharia o recall.
	 */
	private static function selecionar_candidatos( $cao, $oposto ) {
		$min   = max( 0, (int) get_option( 'rcaes_min_candidatos_filtro', 30 ) );
		$caes  = Rcaes_DB::compativeis( $oposto, $cao->raca, $cao->porte, $cao->cor, $cao->user_id, 3000 );
		if ( count( $caes ) < $min ) {
			$caes = Rcaes_DB::todos_do_tipo( $oposto, $cao->user_id, 3000 );
		}
		$ids = array();
		foreach ( $caes as $c ) {
			$ids[] = (int) $c->id;
		}
		$vetores = Rcaes_DB::embeddings_por_caes( $ids );
		$out     = array();
		foreach ( $vetores as $id => $lista ) {
			foreach ( $lista as $v ) {
				$out[] = array( 'id_cao' => (int) $id, 'embedding' => $v );
			}
		}
		return $out;
	}

	private static function falhou( $cao, WP_Error $erro ) {
		Rcaes_Seguranca::log( 'Falha ao processar cão ' . (int) $cao->id . ': ' . $erro->get_error_message() );
		$tent = (int) $cao->tentativas + 1;
		if ( $tent >= self::MAX_TENTATIVAS ) {
			Rcaes_DB::atualizar_cao( $cao->id, array( 'status' => 'falha', 'tentativas' => $tent ) );
			Rcaes_Email::falha( $cao );
			return;
		}
		Rcaes_DB::atualizar_cao(
			$cao->id,
			array(
				'status'            => 'pendente',
				'tentativas'        => $tent,
				'proxima_tentativa' => gmdate( 'Y-m-d H:i:s', time() + 300 * $tent ),
			)
		);
	}
}
