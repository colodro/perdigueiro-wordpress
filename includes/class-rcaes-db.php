<?php
defined( 'ABSPATH' ) || exit;

/**
 * Acesso a dados. Toda consulta com valores externos usa $wpdb->prepare().
 */
class Rcaes_DB {

	public static function t( $nome ) {
		global $wpdb;
		return $wpdb->prefix . 'rcaes_' . $nome;
	}

	public static function agora() {
		return current_time( 'mysql', true ); // sempre em UTC
	}

	/* ---------------------------------------------------------------- cães */

	public static function criar_cao( array $d ) {
		global $wpdb;
		$ok = $wpdb->insert(
			self::t( 'caes' ),
			array(
				'user_id'              => (int) $d['user_id'],
				'token'                => $d['token'],
				'tipo'                 => (int) $d['tipo'],
				'nome'                 => $d['nome'],
				'raca'                 => $d['raca'],
				'porte'                => $d['porte'],
				'cor'                  => $d['cor'],
				'cidade'               => isset( $d['cidade'] ) ? $d['cidade'] : '',
				'uf'                   => isset( $d['uf'] ) ? $d['uf'] : '',
				'publico'              => empty( $d['publico'] ) ? 0 : 1,
				'descricao'            => $d['descricao'],
				'pergunta_verificacao' => $d['pergunta_verificacao'],
				'status'               => 'pendente',
				'criado_em'            => self::agora(),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function cao( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'caes' ) . ' WHERE id = %d', (int) $id ) );
	}

	public static function cao_por_token( $token ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'caes' ) . ' WHERE token = %s', $token ) );
	}

	public static function caes_do_usuario( $uid ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::t( 'caes' ) . " WHERE user_id = %d AND status <> 'removido' ORDER BY id DESC LIMIT 100", (int) $uid )
		);
	}

	public static function contar_ativos_do_usuario( $uid ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'caes' ) . " WHERE user_id = %d AND status IN ('pendente','processando','ativo','falha')", (int) $uid )
		);
	}

	public static function atualizar_cao( $id, array $dados ) {
		global $wpdb;
		return $wpdb->update( self::t( 'caes' ), $dados, array( 'id' => (int) $id ) );
	}

	/* ---------------------------------------------------------------- fila de processamento */

	/** Reserva atomicamente o cão para processar (evita dois processos no mesmo cão). */
	public static function reservar( $id ) {
		global $wpdb;
		$agora = self::agora();
		$r     = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::t( 'caes' ) . " SET status = 'processando', iniciado_em = %s
				 WHERE id = %d AND status = 'pendente' AND ( proxima_tentativa IS NULL OR proxima_tentativa <= %s )",
				$agora,
				(int) $id,
				$agora
			)
		);
		return 1 === $r;
	}

	public static function proximos_pendentes( $limite ) {
		global $wpdb;
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::t( 'caes' ) . " WHERE status = 'pendente' AND ( proxima_tentativa IS NULL OR proxima_tentativa <= %s ) ORDER BY id ASC LIMIT %d",
				self::agora(),
				(int) $limite
			)
		);
	}

	/** Cães presos em "processando" (ex.: processo morreu no meio) voltam para a fila. */
	public static function liberar_travados( $minutos ) {
		global $wpdb;
		$limite = gmdate( 'Y-m-d H:i:s', time() - ( (int) $minutos * 60 ) );
		$wpdb->query(
			$wpdb->prepare( 'UPDATE ' . self::t( 'caes' ) . " SET status = 'pendente' WHERE status = 'processando' AND iniciado_em < %s", $limite )
		);
	}

	public static function contar_por_status() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::t( 'caes' ) . ' GROUP BY status' );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	public static function ultimos_caes( $limite ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'caes' ) . ' ORDER BY id DESC LIMIT %d', (int) $limite ) );
	}

	/* ---------------------------------------------------------------- fotos e embeddings */

	public static function criar_foto( $cao_id, $arquivo ) {
		global $wpdb;
		$wpdb->insert( self::t( 'fotos' ), array( 'cao_id' => (int) $cao_id, 'arquivo' => $arquivo, 'criado_em' => self::agora() ) );
		return (int) $wpdb->insert_id;
	}

	public static function fotos_do_cao( $cao_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'fotos' ) . ' WHERE cao_id = %d ORDER BY id ASC', (int) $cao_id ) );
	}

	public static function foto( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'fotos' ) . ' WHERE id = %d', (int) $id ) );
	}

	public static function primeira_foto_id( $cao_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM ' . self::t( 'fotos' ) . ' WHERE cao_id = %d', (int) $cao_id ) );
	}

	public static function salvar_embedding( $cao_id, $foto_id, array $vetor ) {
		global $wpdb;
		$t = self::t( 'embeddings' );
		$wpdb->delete( $t, array( 'foto_id' => (int) $foto_id ) );
		$wpdb->insert(
			$t,
			array(
				'cao_id'     => (int) $cao_id,
				'foto_id'    => (int) $foto_id,
				'vetor_json' => wp_json_encode( array_map( 'floatval', $vetor ) ),
				'criado_em'  => self::agora(),
			)
		);
	}

	/** @return array cao_id => lista de vetores */
	public static function embeddings_por_caes( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT cao_id, vetor_json FROM ' . self::t( 'embeddings' ) . " WHERE cao_id IN ({$ph})", $ids ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$v = json_decode( $r->vetor_json, true );
			if ( is_array( $v ) ) {
				$out[ (int) $r->cao_id ][] = $v;
			}
		}
		return $out;
	}

	/** Remove fotos (arquivos), embeddings e registros de mídia do cão. */
	public static function purgar_midia( $cao_id ) {
		global $wpdb;
		foreach ( self::fotos_do_cao( $cao_id ) as $f ) {
			Rcaes_Imagens::excluir( $f->arquivo );
		}
		$wpdb->delete( self::t( 'fotos' ), array( 'cao_id' => (int) $cao_id ) );
		$wpdb->delete( self::t( 'embeddings' ), array( 'cao_id' => (int) $cao_id ) );
	}

	/* ---------------------------------------------------------------- candidatos (filtro por metadados) */

	/**
	 * Cães do tipo oposto, ativos e compatíveis em porte, raça e cor.
	 * "srd"/"outra" (raça) e "outra"/"manchado" (cor) funcionam como curinga.
	 */
	public static function compativeis( $tipo, $raca, $porte, $cor, $excluir_user, $limite ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::t( 'caes' ) . " WHERE status = 'ativo' AND tipo = %d AND user_id <> %d
				 AND porte = %s
				 AND ( raca = %s OR raca IN ('srd','outra') OR %s IN ('srd','outra') )
				 AND ( cor = %s OR cor IN ('outra','manchado') OR %s IN ('outra','manchado') )
				 ORDER BY id DESC LIMIT %d",
				(int) $tipo,
				(int) $excluir_user,
				$porte,
				$raca,
				$raca,
				$cor,
				$cor,
				(int) $limite
			)
		);
	}

	public static function todos_do_tipo( $tipo, $excluir_user, $limite ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::t( 'caes' ) . " WHERE status = 'ativo' AND tipo = %d AND user_id <> %d ORDER BY id DESC LIMIT %d",
				(int) $tipo,
				(int) $excluir_user,
				(int) $limite
			)
		);
	}

	/* ---------------------------------------------------------------- matches */

	/** @return bool true se o par é novo */
	public static function registrar_match( $perdido, $encontrado, $distancia ) {
		global $wpdb;
		$r = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::t( 'matches' ) . " (cao_perdido_id, cao_encontrado_id, distancia, origem, status, criado_em)
				 VALUES (%d, %d, %f, 'ia', 'sugerido', %s)
				 ON DUPLICATE KEY UPDATE distancia = IF(distancia IS NULL OR VALUES(distancia) < distancia, VALUES(distancia), distancia)",
				(int) $perdido,
				(int) $encontrado,
				(float) $distancia,
				self::agora()
			)
		);
		return 1 === $r;
	}

	public static function registrar_match_manual( $perdido, $encontrado ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::t( 'matches' ) . " (cao_perdido_id, cao_encontrado_id, origem, status, criado_em)
				 VALUES (%d, %d, 'manual', 'sugerido', %s)",
				(int) $perdido,
				(int) $encontrado,
				self::agora()
			)
		);
	}

	private static function sql_match_base() {
		$m = self::t( 'matches' );
		$c = self::t( 'caes' );
		return "SELECT m.*, pe.user_id AS perdido_user, en.user_id AS encontrado_user
				FROM {$m} m
				JOIN {$c} pe ON pe.id = m.cao_perdido_id
				JOIN {$c} en ON en.id = m.cao_encontrado_id";
	}

	public static function matches_do_cao( $cao_id, $limite = 30 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				self::sql_match_base() . " WHERE ( m.cao_perdido_id = %d OR m.cao_encontrado_id = %d )
				 AND pe.status = 'ativo' AND en.status = 'ativo' AND m.status <> 'encerrado'
				 ORDER BY ( m.distancia IS NULL ), m.distancia ASC, m.id DESC LIMIT %d",
				(int) $cao_id,
				(int) $cao_id,
				(int) $limite
			)
		);
	}

	public static function match_completo( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( self::sql_match_base() . ' WHERE m.id = %d', (int) $id ) );
	}

	/** Troca de estado só se o estado atual for o esperado (evita corrida e reenvio). */
	public static function mudar_status_match( $id, $de, array $dados ) {
		global $wpdb;
		$r = $wpdb->update( self::t( 'matches' ), $dados, array( 'id' => (int) $id, 'status' => $de ) );
		return ( false !== $r && $r > 0 );
	}

	public static function encerrar_matches_do_cao( $cao_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::t( 'matches' ) . " SET status = 'encerrado' WHERE ( cao_perdido_id = %d OR cao_encontrado_id = %d ) AND status NOT IN ('encerrado','resolvido')",
				(int) $cao_id,
				(int) $cao_id
			)
		);
	}

	/* ---------------------------------------------------------------- mensagens */

	public static function criar_mensagem( $match_id, $user_id, $texto ) {
		global $wpdb;
		$wpdb->insert(
			self::t( 'mensagens' ),
			array( 'match_id' => (int) $match_id, 'user_id' => (int) $user_id, 'texto' => $texto, 'lida' => 0, 'criado_em' => self::agora() )
		);
		return (int) $wpdb->insert_id;
	}

	public static function mensagens_do_match( $match_id, $depois = 0, $limite = 300 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::t( 'mensagens' ) . ' WHERE match_id = %d AND id > %d ORDER BY id ASC LIMIT %d',
				(int) $match_id,
				(int) $depois,
				(int) $limite
			)
		);
	}

	public static function marcar_lidas( $match_id, $user_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'UPDATE ' . self::t( 'mensagens' ) . ' SET lida = 1 WHERE match_id = %d AND user_id <> %d AND lida = 0', (int) $match_id, (int) $user_id )
		);
	}

	public static function conversas_do_usuario( $uid ) {
		global $wpdb;
		$g = self::t( 'mensagens' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, pe.user_id AS perdido_user, en.user_id AS encontrado_user,
				   ( SELECT COUNT(*) FROM {$g} g WHERE g.match_id = m.id AND g.user_id <> %d AND g.lida = 0 ) AS nao_lidas,
				   ( SELECT MAX(g2.criado_em) FROM {$g} g2 WHERE g2.match_id = m.id ) AS ultima,
				   ( SELECT g3.texto FROM {$g} g3 WHERE g3.match_id = m.id ORDER BY g3.id DESC LIMIT 1 ) AS ult_texto
				 FROM " . self::t( 'matches' ) . ' m
				 JOIN ' . self::t( 'caes' ) . ' pe ON pe.id = m.cao_perdido_id
				 JOIN ' . self::t( 'caes' ) . " en ON en.id = m.cao_encontrado_id
				 WHERE m.status = 'aceito' AND ( pe.user_id = %d OR en.user_id = %d )
				 ORDER BY ultima DESC, m.id DESC LIMIT 100",
				(int) $uid,
				(int) $uid,
				(int) $uid
			)
		);
	}

	/** Pedidos de contato que o usuário RECEBEU e ainda não respondeu (mais recentes primeiro). */
	public static function pedidos_recebidos( $uid ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				self::sql_match_base() . " WHERE m.status = 'solicitado' AND m.iniciado_por <> %d
				 AND ( pe.user_id = %d OR en.user_id = %d )
				 ORDER BY m.solicitado_em DESC, m.id DESC LIMIT 50",
				(int) $uid,
				(int) $uid,
				(int) $uid
			)
		);
	}

	/* ---------------------------------------------------------------- privacidade */

	/** Apaga tudo o que pertence ao usuário (usado ao excluir a conta). */
	public static function excluir_dados_usuario( $uid ) {
		global $wpdb;
		$caes = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'caes' ) . ' WHERE user_id = %d', (int) $uid ) );
		foreach ( (array) $caes as $id ) {
			self::purgar_midia( (int) $id );
			$mids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM ' . self::t( 'matches' ) . ' WHERE cao_perdido_id = %d OR cao_encontrado_id = %d', (int) $id, (int) $id )
			);
			foreach ( (array) $mids as $mid ) {
				$wpdb->delete( self::t( 'mensagens' ), array( 'match_id' => (int) $mid ) );
				$wpdb->delete( self::t( 'matches' ), array( 'id' => (int) $mid ) );
			}
			$wpdb->delete( self::t( 'caes' ), array( 'id' => (int) $id ) );
		}
		$wpdb->delete( self::t( 'avaliacoes' ), array( 'user_id' => (int) $uid ) );
	}

	/* ---------------------------------------------------------------- desfecho */

	/** Conversas aceitas em que o cão participa (para informar com quem houve o reencontro). */
	public static function matches_aceitos_do_cao( $cao_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				self::sql_match_base() . " WHERE ( m.cao_perdido_id = %d OR m.cao_encontrado_id = %d ) AND m.status = 'aceito' ORDER BY m.id ASC",
				(int) $cao_id,
				(int) $cao_id
			)
		);
	}

	/** Liga ($user_id) ou desliga (0) o pedido de "cão resgatado" numa conversa aceita. */
	public static function definir_resgate( $match_id, $user_id ) {
		global $wpdb;
		$t = self::t( 'matches' );
		if ( $user_id ) {
			return $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET resgate_por = %d, resgate_em = %s WHERE id = %d AND status = 'aceito' AND resgate_por IS NULL", (int) $user_id, self::agora(), (int) $match_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET resgate_por = NULL, resgate_em = NULL WHERE id = %d AND status = 'aceito'", (int) $match_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function resolver_cao( $cao_id, $desfecho, $match_id ) {
		global $wpdb;
		return $wpdb->update(
			self::t( 'caes' ),
			array(
				'status'            => 'resolvido',
				'resolvido_em'      => self::agora(),
				'desfecho'          => $desfecho,
				'match_desfecho_id' => $match_id ? (int) $match_id : null,
			),
			array( 'id' => (int) $cao_id )
		);
	}


	/* ---------------------------------------------------------------- listas públicas */

	/** Só cães que o tutor marcou como públicos. 'recuperados' = resolvidos com reencontro. */
	public static function listar_publicos( $lista, $limite ) {
		global $wpdb;
		$t = self::t( 'caes' );
		if ( 'recuperados' === $lista ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$t} WHERE publico = 1 AND status = 'resolvido' AND desfecho IN ('pelo_site','outro_meio') ORDER BY resolvido_em DESC LIMIT %d",
				(int) $limite
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$t} WHERE publico = 1 AND status = 'ativo' AND tipo = %d ORDER BY id DESC LIMIT %d",
				'perdidos' === $lista ? 0 : 1,
				(int) $limite
			);
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL -- já preparado acima
	}
}
