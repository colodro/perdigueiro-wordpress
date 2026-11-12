<?php
defined( 'ABSPATH' ) || exit;

/**
 * Listas públicas para a página inicial.
 *
 *   [rcaes_caes tipo="encontrados|perdidos|recuperados" limite="6" colunas="3" titulo="" subtitulo=""
 *               ver_todos="URL" texto_botao="" link=""]
 *   [rcaes_numeros titulo="cães já voltaram para casa"]
 *
 * Privacidade: só aparecem cadastros que o tutor marcou como públicos. Mostram foto principal
 * (miniatura de 640 px, sem EXIF), raça, porte, cor, cidade/UF e data. NUNCA mostram nome do cão
 * (exceto nos recuperados), descrição, pergunta de verificação ou qualquer dado do tutor.
 */
class Rcaes_Publico {

	public static function init() {
		add_shortcode( 'rcaes_caes', array( __CLASS__, 'sc_caes' ) );
		add_shortcode( 'rcaes_numeros', array( __CLASS__, 'sc_numeros' ) );
		add_shortcode( 'rcaes_destaque', array( __CLASS__, 'sc_destaque' ) );
		add_action( 'template_redirect', array( __CLASS__, 'servir_foto' ), 1 );
	}

	public static function ativo() {
		return (bool) get_option( 'rcaes_listas_publicas', 1 );
	}

	/** O cão pode aparecer em lista pública? */
	public static function listavel( $cao ) {
		if ( 1 !== (int) $cao->publico ) {
			return false;
		}
		return 'ativo' === $cao->status
			|| ( 'resolvido' === $cao->status && in_array( $cao->desfecho, array( 'pelo_site', 'outro_meio' ), true ) );
	}

	public static function url_foto( $foto_id ) {
		return add_query_arg( 'rcaes_pub', (int) $foto_id, home_url( '/' ) );
	}

	/* ---------------------------------------------------------------- foto pública (miniatura) */

	private static function miniatura( $arquivo ) {
		$orig = Rcaes_Imagens::pasta() . '/' . $arquivo;
		$dir  = Rcaes_Imagens::pasta() . '/pub';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$mini = $dir . '/' . substr( $arquivo, 0, 32 ) . '_640.jpg';
		if ( is_readable( $mini ) ) {
			return $mini;
		}
		add_filter( 'wp_image_editors', array( 'Rcaes_Imagens', 'somente_gd' ), 99 );
		$ed = wp_get_image_editor( $orig );
		remove_filter( 'wp_image_editors', array( 'Rcaes_Imagens', 'somente_gd' ), 99 );
		if ( is_wp_error( $ed ) ) {
			return $orig;
		}
		$ed->resize( 640, 640, false ); // se já for menor, o resize só devolve aviso: seguimos e regravamos
		$ed->set_quality( 80 );
		$r = $ed->save( $mini, 'image/jpeg' );
		if ( is_wp_error( $r ) || ! file_exists( $mini ) ) {
			return $orig;
		}
		@chmod( $mini, 0640 );
		return $mini;
	}

	public static function servir_foto() {
		if ( ! isset( $_GET['rcaes_pub'] ) || ! is_string( $_GET['rcaes_pub'] ) ) {
			return;
		}
		if ( ! self::ativo() ) {
			status_header( 404 );
			exit;
		}
		if ( ! Rcaes_Seguranca::permitir( 'pubimg_' . Rcaes_Seguranca::ip(), 300, MINUTE_IN_SECONDS ) ) {
			status_header( 429 );
			exit;
		}
		$id   = absint( wp_unslash( $_GET['rcaes_pub'] ) );
		$foto = $id ? Rcaes_DB::foto( $id ) : null;
		$cao  = $foto ? Rcaes_DB::cao( $foto->cao_id ) : null;
		// Só a foto PRINCIPAL de cães liberados pelo tutor; as demais fotos continuam privadas.
		if ( ! $foto || ! $cao || ! self::listavel( $cao ) || (int) $foto->id !== Rcaes_DB::primeira_foto_id( $cao->id ) || ! Rcaes_Imagens::nome_valido( $foto->arquivo ) ) {
			status_header( 404 );
			exit;
		}
		$arq = self::miniatura( $foto->arquivo );
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
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Robots-Tag: noimageindex' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) filemtime( $arq ) ) . ' GMT' );
		readfile( $arq );
		exit;
	}

	/* ---------------------------------------------------------------- [rcaes_caes] */

	private static function local( $c ) {
		if ( '' === (string) $c->cidade ) {
			return '';
		}
		return $c->cidade . ( '' !== (string) $c->uf ? ' – ' . $c->uf : '' );
	}

	private static function dias_texto( $c ) {
		$s = strtotime( $c->resolvido_em . ' UTC' ) - strtotime( $c->criado_em . ' UTC' );
		$d = (int) floor( max( 0, $s ) / DAY_IN_SECONDS );
		if ( 0 === $d ) {
			return 'menos de 1 dia';
		}
		return 1 === $d ? '1 dia' : $d . ' dias';
	}

	private static function cartao( $c, $lista, $link, $texto ) {
		$foto_id = Rcaes_DB::primeira_foto_id( $c->id );
		$img     = $foto_id ? '<img src="' . esc_url( self::url_foto( $foto_id ) ) . '" alt="Foto de cão cadastrado" loading="lazy">' : '';
		$local   = self::local( $c );
		ob_start();
		if ( 'recuperados' === $lista ) :
			?>
			<div class="rcaes-cartao rcaes-publico rcaes-cartao-rec">
				<div class="rcaes-foto">
					<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="rcaes-selo rcaes-st-resolvido">Reencontrado</span>
					<div class="rcaes-foto-info">
						<strong><?php echo '' !== (string) $c->nome ? esc_html( $c->nome ) : esc_html( Rcaes_Caes::rotulo( 'raca', $c->raca ) ); ?></strong>
						<?php if ( '' !== (string) $c->nome ) : ?><span><?php echo esc_html( Rcaes_Caes::rotulo( 'raca', $c->raca ) ); ?></span><?php endif; ?>
					</div>
				</div>
				<?php if ( '' !== $local ) : ?><p class="rcaes-meta"><?php echo esc_html( $local ); ?></p><?php endif; ?>
				<p class="rcaes-meta rcaes-coracao">♥ <strong>Reencontrado em <?php echo esc_html( self::dias_texto( $c ) ); ?></strong></p>
			</div>
			<?php
		else :
			$perdido = 0 === (int) $c->tipo;
			?>
			<div class="rcaes-cartao rcaes-publico">
				<div class="rcaes-foto">
					<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span class="rcaes-selo <?php echo $perdido ? 'rcaes-tipo-perdido' : 'rcaes-tipo-encontrado'; ?>"><?php echo $perdido ? 'Perdido' : 'Encontrado'; ?></span>
				</div>
				<h4><?php echo esc_html( Rcaes_Caes::rotulo( 'raca', $c->raca ) ); ?></h4>
				<p class="rcaes-meta">Porte: <strong><?php echo esc_html( Rcaes_Caes::rotulo( 'porte', $c->porte ) ); ?></strong></p>
				<p class="rcaes-meta">Cor: <strong><?php echo esc_html( Rcaes_Caes::rotulo( 'cor', $c->cor ) ); ?></strong></p>
				<?php if ( '' !== $local ) : ?><p class="rcaes-meta">Local: <strong><?php echo esc_html( $local ); ?></strong></p><?php endif; ?>
				<p class="rcaes-meta"><?php echo $perdido ? 'Perdido' : 'Encontrado'; ?> · cadastrado em <strong><?php echo esc_html( get_date_from_gmt( $c->criado_em, 'd/m/Y' ) ); ?></strong></p>
				<a class="rcaes-btn rcaes-btn-bloco" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $texto ); ?></a>
			</div>
			<?php
		endif;
		return ob_get_clean();
	}

	public static function sc_caes( $atts ) {
		if ( ! self::ativo() ) {
			return current_user_can( 'manage_options' )
				? '<div class="rcaes-aviso rcaes-info">As listas públicas estão desligadas em <em>Reencontro de Cães → Configurações</em> (só você vê este aviso).</div>'
				: '';
		}
		$a = shortcode_atts(
			array( 'tipo' => 'encontrados', 'limite' => 6, 'colunas' => 3, 'titulo' => '', 'subtitulo' => '', 'ver_todos' => '', 'texto_botao' => '', 'link' => '' ),
			$atts,
			'rcaes_caes'
		);
		$lista = in_array( $a['tipo'], array( 'encontrados', 'perdidos', 'recuperados' ), true ) ? $a['tipo'] : 'encontrados';
		$lim   = max( 1, min( 48, (int) $a['limite'] ) );
		$cols  = max( 1, min( 4, (int) $a['colunas'] ) );
		$caes  = Rcaes_DB::listar_publicos( $lista, $lim );

		// Botão: quem viu um cão perdido registra "encontrado"; quem vê um encontrado registra "perdido".
		$link = '' !== $a['link'] ? $a['link'] : add_query_arg( 'tipo', 'perdidos' === $lista ? 'encontrado' : 'perdido', Rcaes_Seguranca::pagina_url( 'cadastrar' ) );
		$txt  = '' !== $a['texto_botao'] ? $a['texto_botao'] : ( 'perdidos' === $lista ? 'Tenho informações' : 'É o meu cão' );

		ob_start();
		if ( '' !== $a['titulo'] ) {
			echo '<h2 class="cdr-titulo">' . esc_html( $a['titulo'] ) . '</h2>';
		}
		if ( '' !== $a['subtitulo'] ) {
			echo '<p class="cdr-subtitulo">' . esc_html( $a['subtitulo'] ) . '</p>';
		}
		if ( ! $caes ) {
			echo '<p class="rcaes-vazio">Nenhum cão por aqui ainda.</p>';
		} else {
			echo '<div class="rcaes-grade rcaes-grade-col" style="--rc-cols:' . (int) $cols . '">';
			foreach ( $caes as $c ) {
				echo self::cartao( $c, $lista, $link, $txt ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div>';
		}
		if ( '' !== $a['ver_todos'] ) {
			echo '<p class="rcaes-centro"><a class="rcaes-btn rcaes-btn-sec" href="' . esc_url( $a['ver_todos'] ) . '">Ver todos &rarr;</a></p>';
		}
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------- [rcaes_destaque] */

	/**
	 * Cartão de destaque do reencontro mais recente (foto, nome, tempo e cidade), para a hero da home.
	 *   [rcaes_destaque]
	 *   [rcaes_destaque texto="voltou para casa!" selo="Reconhecido por IA"]
	 * Usa as mesmas regras de privacidade da lista de recuperados: só cadastros que o tutor marcou como
	 * públicos, só a foto principal em miniatura, sem dados do tutor. Sem reencontro público, não mostra nada.
	 */
	public static function sc_destaque( $atts ) {
		if ( ! self::ativo() ) {
			return '';
		}
		$a = shortcode_atts(
			array(
				'texto'   => 'voltou para casa!',
				'sem_nome' => 'Este aqui já voltou pra casa',
				'selo'    => 'Reconhecido por IA',
			),
			$atts,
			'rcaes_destaque'
		);

		$lista = Rcaes_DB::listar_publicos( 'recuperados', 1 );
		$cao   = $lista ? $lista[0] : null;
		$foto  = $cao ? Rcaes_DB::primeira_foto_id( $cao->id ) : 0;
		if ( ! $cao || ! $foto ) {
			return current_user_can( 'manage_options' )
				? '<div class="rcaes-aviso rcaes-info">O destaque aparece aqui quando houver um reencontro público (só você vê este aviso).</div>'
				: '';
		}

		// Dados (nome e foto) são SEMPRE do cão que o tutor marcou como perdido.
		// Se o reencontro foi feito por uma correspondência, o perdido é o outro cão dela.
		$perdido = null;
		if ( 0 === (int) $cao->tipo ) {
			$perdido = $cao;
		} elseif ( (int) $cao->match_desfecho_id > 0 ) {
			$m       = Rcaes_DB::match_completo( (int) $cao->match_desfecho_id );
			$perdido = $m ? Rcaes_DB::cao( $m->cao_perdido_id ) : null;
		}
		// Só usa o cão perdido se o tutor dele o deixou público (mesma regra da lista pública).
		$usa_perdido = $perdido && self::listavel( $perdido ) && Rcaes_DB::primeira_foto_id( $perdido->id );
		$nome        = $usa_perdido ? trim( (string) $perdido->nome ) : '';
		if ( $usa_perdido ) {
			$foto = Rcaes_DB::primeira_foto_id( $perdido->id );
		}

		$tempo = human_time_diff( strtotime( $cao->resolvido_em . ' UTC' ), time() );
		$cid   = (string) $cao->cidade;
		$meta  = 'Há ' . $tempo . ( '' !== $cid ? ' · ' . $cid : '' );
		// "Reconhecido por IA" só quando o reencontro veio de uma correspondência feita pelo site.
		$ia    = ( (int) $cao->match_desfecho_id > 0 ) && '' !== trim( $a['selo'] );

		$titulo = '' !== $nome ? $nome . ' ' . $a['texto'] : $a['sem_nome'];
		$alt    = '' !== $nome ? 'Foto de ' . $nome . ', reencontrado' : 'Foto de cão reencontrado';

		ob_start();
		?>
		<figure class="rcaes-destaque">
			<div class="rcaes-destaque-foto">
				<img src="<?php echo esc_url( self::url_foto( $foto ) ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="eager" decoding="async">
			</div>
			<?php if ( $ia ) : ?>
				<span class="rcaes-destaque-selo"><?php echo esc_html( $a['selo'] ); ?></span>
			<?php endif; ?>
			<figcaption class="rcaes-destaque-cartao">
				<span class="rcaes-destaque-check" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M2 13l4 4L14 9"/><path d="M12 15l1.5 1.5L22 8"/></svg>
				</span>
				<span class="rcaes-destaque-texto">
					<strong><?php echo esc_html( $titulo ); ?></strong>
					<small><?php echo esc_html( $meta ); ?></small>
				</span>
			</figcaption>
		</figure>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------- [rcaes_numeros] */

	private static function mediana( array $v ) {
		sort( $v );
		$n = count( $v );
		if ( ! $n ) {
			return null;
		}
		$m = intdiv( $n, 2 );
		return ( 1 === $n % 2 ) ? $v[ $m ] : ( $v[ $m - 1 ] + $v[ $m ] ) / 2;
	}

	public static function sc_numeros( $atts ) {
		if ( ! self::ativo() ) {
			return '';
		}
		$a = shortcode_atts( array( 'titulo' => 'cães já voltaram para casa' ), $atts, 'rcaes_numeros' );
		$n = get_transient( 'rcaes_numeros' );
		if ( ! is_array( $n ) ) {
			global $wpdb;
			$t    = Rcaes_DB::t( 'caes' );
			$rec  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'resolvido' AND desfecho IN ('pelo_site','outro_meio')" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$ati  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'ativo'" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$segs = array_map( 'floatval', (array) $wpdb->get_col( "SELECT TIMESTAMPDIFF(SECOND, criado_em, resolvido_em) FROM {$t} WHERE status = 'resolvido' AND desfecho IN ('pelo_site','outro_meio') AND resolvido_em IS NOT NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$n    = array( 'rec' => $rec, 'ativos' => $ati, 'mediana' => self::mediana( $segs ) );
			set_transient( 'rcaes_numeros', $n, 10 * MINUTE_IN_SECONDS );
		}
		$med = '—';
		if ( null !== $n['mediana'] ) {
			$h   = $n['mediana'] / 3600;
			$med = $h < 24 ? ( $h < 1 ? 'menos de 1 h' : round( $h ) . ' h' ) : round( $h / 24, 1 ) . ' dias';
		}
		ob_start();
		?>
		<div class="cdr-faixa">
			<div>
				<div class="cdr-grande"><?php echo esc_html( number_format_i18n( $n['rec'] ) ); ?></div>
				<strong><?php echo esc_html( $a['titulo'] ); ?></strong>
			</div>
			<div class="cdr-numeros">
				<div><strong><?php echo esc_html( number_format_i18n( $n['ativos'] ) ); ?></strong><span>cadastros ativos</span></div>
				<div><strong><?php echo esc_html( $med ); ?></strong><span>tempo mediano até o reencontro</span></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
