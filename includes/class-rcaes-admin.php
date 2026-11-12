<?php
defined( 'ABSPATH' ) || exit;

/**
 * Painel do administrador: configurações, estado da fila, teste da API e moderação.
 * Tudo exige a capacidade manage_options e nonce.
 */
class Rcaes_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'registrar' ) );
		add_action( 'admin_post_rcaes_testar_api', array( __CLASS__, 'testar_api' ) );
		add_action( 'admin_post_rcaes_processar_agora', array( __CLASS__, 'processar_agora' ) );
		add_action( 'admin_post_rcaes_moderar', array( __CLASS__, 'moderar' ) );
	}

	public static function menu() {
		add_menu_page( 'Reencontro de Cães', 'Reencontro de Cães', 'manage_options', 'rcaes', array( __CLASS__, 'pagina_painel' ), 'dashicons-pets', 58 );
		add_submenu_page( 'rcaes', 'Configurações', 'Configurações', 'manage_options', 'rcaes-config', array( __CLASS__, 'pagina_config' ) );
	}

	/* ---------------------------------------------------------------- configurações */

	private static function inteiro( $min, $max ) {
		return function ( $v ) use ( $min, $max ) {
			return max( $min, min( $max, (int) $v ) );
		};
	}

	public static function registrar() {
		$g = 'rcaes_opcoes';
		register_setting( $g, 'rcaes_api_url', array( 'sanitize_callback' => array( __CLASS__, 'san_url' ) ) );
		register_setting( $g, 'rcaes_api_token', array( 'sanitize_callback' => array( __CLASS__, 'san_token' ) ) );
		register_setting( $g, 'rcaes_api_timeout', array( 'sanitize_callback' => self::inteiro( 10, 180 ) ) );
		register_setting( $g, 'rcaes_distancia_max', array( 'sanitize_callback' => array( __CLASS__, 'san_distancia' ) ) );
		register_setting( $g, 'rcaes_max_fotos', array( 'sanitize_callback' => self::inteiro( 1, 5 ) ) );
		register_setting( $g, 'rcaes_max_mb', array( 'sanitize_callback' => self::inteiro( 1, 10 ) ) );
		register_setting( $g, 'rcaes_min_candidatos_filtro', array( 'sanitize_callback' => self::inteiro( 0, 1000 ) ) );
		register_setting( $g, 'rcaes_max_caes_ativos', array( 'sanitize_callback' => self::inteiro( 1, 20 ) ) );
		register_setting( $g, 'rcaes_listas_publicas', array( 'sanitize_callback' => function ( $v ) {
			return empty( $v ) ? 0 : 1;
		} ) );
		register_setting( $g, 'rcaes_remover_dados', array( 'sanitize_callback' => function ( $v ) {
			return empty( $v ) ? 0 : 1;
		} ) );

		// Pop-ups pós-evento (textos, destinos e vídeo ficam aqui, não no código).
		foreach ( array( 'rcaes_pop_cad_titulo', 'rcaes_pop_cao_titulo', 'rcaes_pop_cad_botao', 'rcaes_pop_cao_botao' ) as $o ) {
			register_setting( $g, $o, array( 'sanitize_callback' => array( __CLASS__, 'san_linha' ) ) );
		}
		foreach ( array( 'rcaes_pop_cad_texto', 'rcaes_pop_cao_texto' ) as $o ) {
			register_setting( $g, $o, array( 'sanitize_callback' => array( __CLASS__, 'san_texto' ) ) );
		}
		foreach ( array( 'rcaes_pop_cad_destino', 'rcaes_pop_cao_destino', 'rcaes_pop_cao_video' ) as $o ) {
			register_setting( $g, $o, array( 'sanitize_callback' => array( __CLASS__, 'san_url' ) ) );
		}
	}

	public static function san_linha( $v ) {
		return mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, 120 );
	}

	public static function san_texto( $v ) {
		return mb_substr( trim( sanitize_textarea_field( (string) $v ) ), 0, 500 );
	}

	public static function san_url( $v ) {
		return esc_url_raw( trim( (string) $v ), array( 'https' ) ); // só HTTPS
	}

	/** Campo vazio mantém o token atual (o valor nunca é reexibido). */
	public static function san_token( $v ) {
		$v = trim( sanitize_text_field( (string) $v ) );
		return '' === $v ? (string) get_option( 'rcaes_api_token', '' ) : $v;
	}

	public static function san_distancia( $v ) {
		return max( 0.30, min( 1.50, (float) str_replace( ',', '.', (string) $v ) ) );
	}

	public static function pagina_config() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url_const   = defined( 'RCAES_API_URL' );
		$token_const = defined( 'RCAES_API_TOKEN' );
		?>
		<div class="wrap">
			<h1>Reencontro de Cães — Configurações</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'rcaes_opcoes' ); ?>
				<table class="form-table" role="presentation">
					<tr><th>URL da API de inferência</th><td>
						<?php if ( $url_const ) : ?>
							<code>definida em wp-config.php (RCAES_API_URL)</code>
						<?php else : ?>
							<input type="url" class="regular-text" name="rcaes_api_url" value="<?php echo esc_attr( get_option( 'rcaes_api_url', '' ) ); ?>" placeholder="https://seu-servico.onrender.com">
							<p class="description">Somente HTTPS.</p>
						<?php endif; ?>
					</td></tr>
					<tr><th>Token da API</th><td>
						<?php if ( $token_const ) : ?>
							<code>definido em wp-config.php (RCAES_API_TOKEN)</code>
						<?php else : ?>
							<input type="password" class="regular-text" name="rcaes_api_token" value="" autocomplete="new-password" placeholder="<?php echo '' !== (string) get_option( 'rcaes_api_token', '' ) ? 'Já definido (deixe em branco para manter)' : ''; ?>">
							<p class="description">Prefira definir <code>RCAES_API_TOKEN</code> no wp-config.php: assim o segredo não fica no banco de dados.</p>
						<?php endif; ?>
					</td></tr>
					<tr><th>Tempo limite da API (s)</th><td><input type="number" min="10" max="180" name="rcaes_api_timeout" value="<?php echo (int) get_option( 'rcaes_api_timeout', 90 ); ?>">
						<p class="description">A 1ª chamada após hibernação do plano gratuito leva ~1 min.</p></td></tr>
					<tr><th>Distância máxima para sugerir (0,30 a 1,50)</th><td><input type="text" size="6" name="rcaes_distancia_max" value="<?php echo esc_attr( number_format( (float) get_option( 'rcaes_distancia_max', 0.90 ), 2, ',', '' ) ); ?>">
						<p class="description">Distância euclidiana entre embeddings. Use o limiar do EER da sua avaliação (ex.: 0,89). Valores maiores sugerem mais cães (mais falsos positivos).</p></td></tr>
					<tr><th>Máx. de fotos por cão</th><td><input type="number" min="1" max="5" name="rcaes_max_fotos" value="<?php echo (int) get_option( 'rcaes_max_fotos', 3 ); ?>"></td></tr>
					<tr><th>Tamanho máx. por foto (MB)</th><td><input type="number" min="1" max="10" name="rcaes_max_mb" value="<?php echo (int) get_option( 'rcaes_max_mb', 5 ); ?>"></td></tr>
					<tr><th>Mínimo de candidatos compatíveis</th><td><input type="number" min="0" max="1000" name="rcaes_min_candidatos_filtro" value="<?php echo (int) get_option( 'rcaes_min_candidatos_filtro', 30 ); ?>">
						<p class="description">Se o filtro por porte/raça/cor achar menos que isso, a comparação usa todos os cães do tipo oposto.</p></td></tr>
					<tr><th>Listas públicas</th><td><label><input type="checkbox" name="rcaes_listas_publicas" value="1" <?php checked( 1, (int) get_option( 'rcaes_listas_publicas', 1 ) ); ?>> Permitir os shortcodes <code>[rcaes_caes]</code> e <code>[rcaes_numeros]</code></label>
						<p class="description">Mostram foto principal (tamanho reduzido), raça, porte, cor e cidade dos cadastros que o tutor marcou como públicos. Nunca mostram nome, descrição nem dados do tutor. Desmarque para desligar tudo de uma vez.</p></td></tr>
					<tr><th>Máx. de cadastros ativos por usuário</th><td><input type="number" min="1" max="20" name="rcaes_max_caes_ativos" value="<?php echo (int) get_option( 'rcaes_max_caes_ativos', 5 ); ?>"></td></tr>
					<tr><th>Apagar dados ao desinstalar</th><td><label><input type="checkbox" name="rcaes_remover_dados" value="1" <?php checked( 1, (int) get_option( 'rcaes_remover_dados', 0 ) ); ?>> Remover tabelas, opções e fotos ao excluir o plugin</label></td></tr>
				</table>

				<h2>Pop-ups após o cadastro</h2>
				<p class="description">Aparecem depois de um evento. Deixe o destino em branco para usar a página padrão do plugin.</p>
				<table class="form-table" role="presentation">
					<tr><th colspan="2"><h3>Ao criar a conta</h3></th></tr>
					<tr><th>Título</th><td><input type="text" class="regular-text" name="rcaes_pop_cad_titulo" value="<?php echo esc_attr( get_option( 'rcaes_pop_cad_titulo', 'Cadastro enviado!' ) ); ?>"></td></tr>
					<tr><th>Texto</th><td><textarea class="large-text" rows="3" name="rcaes_pop_cad_texto"><?php echo esc_textarea( get_option( 'rcaes_pop_cad_texto', 'Seu cadastro foi enviado. Agora você precisa confirmar sua conta pelo e-mail que enviamos para você.' ) ); ?></textarea></td></tr>
					<tr><th>Texto do botão</th><td><input type="text" class="regular-text" name="rcaes_pop_cad_botao" value="<?php echo esc_attr( get_option( 'rcaes_pop_cad_botao', 'Entendi' ) ); ?>"></td></tr>
					<tr><th>Página de destino do botão</th><td><input type="url" class="regular-text" name="rcaes_pop_cad_destino" value="<?php echo esc_attr( get_option( 'rcaes_pop_cad_destino', '' ) ); ?>" placeholder="Em branco = página de login"></td></tr>

					<tr><th colspan="2"><h3>Ao cadastrar um cão (em análise)</h3></th></tr>
					<tr><th>Título</th><td><input type="text" class="regular-text" name="rcaes_pop_cao_titulo" value="<?php echo esc_attr( get_option( 'rcaes_pop_cao_titulo', 'Cadastro em análise' ) ); ?>"></td></tr>
					<tr><th>Texto</th><td><textarea class="large-text" rows="3" name="rcaes_pop_cao_texto"><?php echo esc_textarea( get_option( 'rcaes_pop_cao_texto', 'Seu cão foi cadastrado e está em análise. Vamos comparar as fotos com os outros cadastros e avisamos por e-mail se encontrarmos um possível reencontro.' ) ); ?></textarea></td></tr>
					<tr><th>Texto do botão</th><td><input type="text" class="regular-text" name="rcaes_pop_cao_botao" value="<?php echo esc_attr( get_option( 'rcaes_pop_cao_botao', 'Ok' ) ); ?>"></td></tr>
					<tr><th>Vídeo</th><td><input type="url" class="regular-text" name="rcaes_pop_cao_video" value="<?php echo esc_attr( get_option( 'rcaes_pop_cao_video', '' ) ); ?>" placeholder="https://www.youtube.com/watch?v=... ou link .mp4">
						<p class="description">YouTube, Vimeo ou arquivo .mp4/.webm, sempre em HTTPS. Em branco = sem vídeo. O vídeo inicia sem som.</p></td></tr>
					<tr><th>Página de destino do botão</th><td><input type="url" class="regular-text" name="rcaes_pop_cao_destino" value="<?php echo esc_attr( get_option( 'rcaes_pop_cao_destino', '' ) ); ?>" placeholder="Em branco = Meus cães (listagem)"></td></tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- painel */

	private static function aviso_admin( $texto, $tipo = 'info' ) {
		set_transient( 'rcaes_adm_' . get_current_user_id(), array( $tipo, $texto ), 120 );
	}

	public static function pagina_painel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$k = 'rcaes_adm_' . get_current_user_id();
		$a = get_transient( $k );
		if ( $a ) {
			delete_transient( $k );
		}
		$cont = Rcaes_DB::contar_por_status();
		$caes = Rcaes_DB::ultimos_caes( 50 );
		?>
		<div class="wrap">
			<h1>Reencontro de Cães</h1>
			<?php if ( is_array( $a ) ) : ?>
				<div class="notice notice-<?php echo 'erro' === $a[0] ? 'error' : 'success'; ?>"><p><?php echo esc_html( $a[1] ); ?></p></div>
			<?php endif; ?>

			<h2>Fila de processamento</h2>
			<p>
				<?php foreach ( Rcaes_Caes::listas()['status'] as $s => $r ) : ?>
					<strong><?php echo esc_html( $r ); ?>:</strong> <?php echo (int) ( isset( $cont[ $s ] ) ? $cont[ $s ] : 0 ); ?> &nbsp;
				<?php endforeach; ?>
			</p>
			<p>Próxima execução do cron: <?php $p = wp_next_scheduled( 'rcaes_processar_fila' ); echo $p ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $p ), 'd/m/Y H:i:s' ) ) : 'não agendada'; ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="rcaes_testar_api"><?php wp_nonce_field( 'rcaes_testar_api' ); ?>
				<?php submit_button( 'Testar API', 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="rcaes_processar_agora"><?php wp_nonce_field( 'rcaes_processar_agora' ); ?>
				<?php submit_button( 'Processar fila agora', 'secondary', 'submit', false ); ?>
			</form>

			<h2>Últimos cadastros</h2>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Tipo</th><th>Usuário</th><th>Raça/porte/cor</th><th>Status</th><th>Tent.</th><th>Criado (UTC)</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $caes as $c ) : ?>
					<?php $u = get_userdata( $c->user_id ); ?>
					<tr>
						<td><?php echo (int) $c->id; ?></td>
						<td><?php echo esc_html( Rcaes_Caes::tipo_rotulo( $c->tipo ) ); ?></td>
						<td><?php echo $u ? esc_html( $u->user_email ) : '—'; ?></td>
						<td><?php echo esc_html( $c->raca . ' / ' . $c->porte . ' / ' . $c->cor ); ?></td>
						<td><?php echo esc_html( $c->status ); ?></td>
						<td><?php echo (int) $c->tentativas; ?></td>
						<td><?php echo esc_html( $c->criado_em ); ?></td>
						<td>
							<?php if ( 'removido' !== $c->status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remover este cadastro e apagar as fotos?');">
									<input type="hidden" name="action" value="rcaes_moderar">
									<input type="hidden" name="cao_id" value="<?php echo (int) $c->id; ?>">
									<?php wp_nonce_field( 'rcaes_moderar_' . (int) $c->id ); ?>
									<button class="button button-link-delete">Remover</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- ações */

	public static function testar_api() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rcaes_testar_api' );
		$r = Rcaes_API::health();
		if ( is_wp_error( $r ) ) {
			self::aviso_admin( 'Falha ao acessar a API: ' . $r->get_error_message(), 'erro' );
		} else {
			self::aviso_admin( 'API respondeu: ' . ( isset( $r['status'] ) ? $r['status'] : '?' ) . ' (embedding de ' . ( isset( $r['embedding_dim'] ) ? (int) $r['embedding_dim'] : '?' ) . ' dimensões).', 'ok' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rcaes' ) );
		exit;
	}

	public static function processar_agora() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rcaes_processar_agora' );
		Rcaes_Fila::agendar_agora();
		self::aviso_admin( 'Processamento agendado.', 'ok' );
		wp_safe_redirect( admin_url( 'admin.php?page=rcaes' ) );
		exit;
	}

	public static function moderar() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.', '', array( 'response' => 403 ) );
		}
		$id = isset( $_POST['cao_id'] ) ? absint( $_POST['cao_id'] ) : 0;
		check_admin_referer( 'rcaes_moderar_' . $id );
		if ( $id && Rcaes_DB::cao( $id ) ) {
			Rcaes_DB::purgar_midia( $id );
			Rcaes_DB::atualizar_cao( $id, array( 'status' => 'removido' ) );
			Rcaes_DB::encerrar_matches_do_cao( $id );
			Rcaes_Seguranca::log( 'Cadastro ' . $id . ' removido por moderação (admin ' . get_current_user_id() . ').' );
			self::aviso_admin( 'Cadastro removido.', 'ok' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rcaes' ) );
		exit;
	}
}
