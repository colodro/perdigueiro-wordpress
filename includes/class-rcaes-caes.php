<?php
defined( 'ABSPATH' ) || exit;

/**
 * [rcaes_cadastrar_cao] e [rcaes_meus_caes], pré-visualização por metadados (REST)
 * e ações sobre os cães do próprio usuário.
 */
class Rcaes_Caes {

	/* ---------------------------------------------------------------- listas fechadas (lista branca) */

	public static function listas() {
		return array(
			'raca'   => array(
				'srd' => 'Sem raça definida / não sei', 'labrador' => 'Labrador', 'golden' => 'Golden Retriever', 'pastor-alemao' => 'Pastor Alemão',
				'poodle' => 'Poodle', 'shihtzu' => 'Shih Tzu', 'yorkshire' => 'Yorkshire', 'lhasa' => 'Lhasa Apso', 'pinscher' => 'Pinscher',
				'bulldog' => 'Bulldog', 'pitbull' => 'Pitbull', 'rottweiler' => 'Rottweiler', 'husky' => 'Husky', 'border-collie' => 'Border Collie',
				'dachshund' => 'Dachshund (salsicha)', 'maltes' => 'Maltês', 'spitz' => 'Spitz Alemão (lulu)', 'chihuahua' => 'Chihuahua',
				'boxer' => 'Boxer', 'beagle' => 'Beagle', 'outra' => 'Outra raça',
			),
			'porte'  => array( 'pequeno' => 'Pequeno (até ~10 kg)', 'medio' => 'Médio (~10 a 25 kg)', 'grande' => 'Grande (acima de ~25 kg)' ),
			'cor'    => array(
				'preto' => 'Preto', 'branco' => 'Branco', 'caramelo' => 'Caramelo', 'marrom' => 'Marrom', 'cinza' => 'Cinza',
				'tigrado' => 'Tigrado', 'manchado' => 'Duas ou mais cores', 'outra' => 'Outra',
			),
			'status' => array(
				'pendente' => 'Aguardando processamento', 'processando' => 'Processando as fotos', 'ativo' => 'Ativo',
				'falha' => 'Falha no processamento', 'resolvido' => 'Resolvido', 'removido' => 'Removido',
			),
		);
	}

	public static function ufs() {
		return array(
			'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal',
			'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais',
			'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
			'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
		);
	}

	public static function rotulo( $grupo, $chave ) {
		$l = self::listas();
		return isset( $l[ $grupo ][ $chave ] ) ? $l[ $grupo ][ $chave ] : '';
	}

	public static function tipo_rotulo( $tipo ) {
		return 0 === (int) $tipo ? 'Cão perdido' : 'Cão encontrado';
	}

	/* ---------------------------------------------------------------- init */

	public static function init() {
		add_shortcode( 'rcaes_cadastrar_cao', array( __CLASS__, 'sc_cadastrar' ) );
		add_shortcode( 'rcaes_meus_caes', array( __CLASS__, 'sc_meus_caes' ) );
		add_action( 'admin_post_rcaes_cadastrar_cao', array( __CLASS__, 'processar_cadastro' ) );
		add_action( 'admin_post_rcaes_cao_acao', array( __CLASS__, 'processar_acao' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rotas_rest' ) );
	}

	/* ---------------------------------------------------------------- [rcaes_cadastrar_cao] */

	public static function sc_cadastrar() {
		Rcaes_Seguranca::nocache();
		$tipo_pre = ( isset( $_GET['tipo'] ) && is_string( $_GET['tipo'] ) && 'encontrado' === sanitize_key( wp_unslash( $_GET['tipo'] ) ) ) ? 'encontrado' : 'perdido';
		if ( ! Rcaes_Seguranca::usuario_verificado() ) {
			return Rcaes_Seguranca::aviso_login( add_query_arg( 'tipo', $tipo_pre, Rcaes_Seguranca::pagina_url( 'cadastrar' ) ) );
		}
		$pop     = ( 'cao_analise' === Rcaes_Popup::atual() ) ? Rcaes_Popup::html( 'cao_analise' ) : '';
		$l       = self::listas();
		$max     = (int) get_option( 'rcaes_max_fotos', 3 );
		$max_mb  = (int) get_option( 'rcaes_max_mb', 5 );
		ob_start();
		echo $pop; // phpcs:ignore WordPress.Security.EscapeOutput -- pop-up já escapado em Rcaes_Popup::html()
		echo Rcaes_Seguranca::aviso(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<form class="rcaes-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rcaes-form-cao">
			<input type="hidden" name="action" value="rcaes_cadastrar_cao">
			<?php wp_nonce_field( 'rcaes_cadastrar_cao' ); ?>

			<fieldset>
				<legend>O que você está cadastrando?</legend>
				<label><input type="radio" name="tipo" value="perdido" <?php checked( 'perdido', $tipo_pre ); ?> data-rcaes-meta> Perdi meu cão</label><br>
				<label><input type="radio" name="tipo" value="encontrado" <?php checked( 'encontrado', $tipo_pre ); ?> data-rcaes-meta> Encontrei um cão</label>
			</fieldset>

			<p><label>Nome do cão (opcional)<br><input type="text" name="nome" maxlength="80"></label></p>

			<p><label>Raça<br>
				<select name="raca" required data-rcaes-meta>
					<?php foreach ( $l['raca'] as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select></label></p>

			<p><label>Porte<br>
				<select name="porte" required data-rcaes-meta>
					<?php foreach ( $l['porte'] as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select></label></p>

			<p><label>Cor predominante<br>
				<select name="cor" required data-rcaes-meta>
					<?php foreach ( $l['cor'] as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select></label></p>

			<p><label>Cidade (opcional)<br><input type="text" name="cidade" maxlength="60" placeholder="Ex.: Pato Branco"></label></p>
			<p><label>Estado (opcional)<br>
				<select name="uf"><option value="">Selecione…</option>
					<?php foreach ( self::ufs() as $sigla => $nome_uf ) : ?>
						<option value="<?php echo esc_attr( $sigla ); ?>"><?php echo esc_html( $nome_uf ); ?></option>
					<?php endforeach; ?></select></label></p>

			<p><label>Descrição (opcional)<br><textarea name="descricao" rows="3" maxlength="500" placeholder="Características, onde foi visto pela última vez (bairro), etc. Não inclua telefone nem endereço."></textarea></label></p>

			<p class="rcaes-so-encontrado" hidden><label>Pergunta de verificação
				<span class="rcaes-peq">(recomendado)</span><br>
				<input type="text" name="pergunta_verificacao" maxlength="200" placeholder="Ex.: Qual é o nome do cão? Que marca ele tem na pata?">
				<span class="rcaes-peq">Pergunte algo que só o dono saberia e que <strong>não aparece nas fotos</strong>. Não escreva a resposta aqui: quem pedir contato terá de respondê-la, e você decide se aceita.</span></label></p>

			<p><label>Fotos do cão (de 1 a <?php echo (int) $max; ?>; JPG, PNG ou WebP; até <?php echo (int) $max_mb; ?> MB cada)<br>
				<input type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple required id="rcaes-fotos"></label><br>
				<span class="rcaes-peq">Dica: foto de frente, com o rosto bem visível e boa iluminação.</span></p>

			<div id="rcaes-previa" class="rcaes-previa" hidden>
				<h4>Cães já cadastrados com características parecidas</h4>
				<p class="rcaes-peq">Se algum deles parecer ser o seu (ou o dono do que você encontrou), marque. Depois do processamento, ele aparecerá na sua lista para você pedir contato.</p>
				<div id="rcaes-previa-itens" class="rcaes-grade"></div>
			</div>

			<?php if ( get_option( 'rcaes_listas_publicas', 1 ) ) : ?>
				<p><label><input type="checkbox" name="publico" value="1" checked> Exibir este cadastro na lista pública do site (somente foto principal em tamanho reduzido, raça, porte, cor e cidade; nunca nome ou contato). Recomendado: aumenta as chances de reencontro. Você pode ocultar depois em "Meus cães".</label></p>
			<?php endif; ?>
			<p><label><input type="checkbox" name="confirma" value="1" required> Confirmo que as fotos são do cão informado e que posso compartilhá-las.</label></p>
			<p><button type="submit" class="rcaes-btn">Cadastrar e analisar</button></p>
			<p class="rcaes-peq">A análise é feita em segundo plano. Você receberá um e-mail ao final e pode sair do site.</p>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function arquivos( $campo ) {
		if ( empty( $_FILES[ $campo ] ) || ! is_array( $_FILES[ $campo ]['name'] ) ) {
			return array();
		}
		$f   = $_FILES[ $campo ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$out = array();
		foreach ( array_keys( $f['name'] ) as $i ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $f['error'][ $i ] ) {
				continue;
			}
			$out[] = array( 'tmp_name' => $f['tmp_name'][ $i ], 'error' => (int) $f['error'][ $i ], 'size' => (int) $f['size'][ $i ] );
		}
		return $out;
	}

	public static function processar_cadastro() {
		$uid = Rcaes_Seguranca::exigir_usuario();
		check_admin_referer( 'rcaes_cadastrar_cao' );
		$url = Rcaes_Seguranca::pagina_url( 'cadastrar' );

		if ( ! Rcaes_Seguranca::permitir( 'cao_user_' . $uid, 10, DAY_IN_SECONDS )
			|| ! Rcaes_Seguranca::permitir( 'cao_ip_' . Rcaes_Seguranca::ip(), 30, DAY_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( $url, 'muitas_tentativas' );
		}
		if ( Rcaes_DB::contar_ativos_do_usuario( $uid ) >= (int) get_option( 'rcaes_max_caes_ativos', 5 ) ) {
			Rcaes_Seguranca::voltar( $url, 'limite_caes' );
		}

		$l     = self::listas();
		$tipo  = Rcaes_Seguranca::post_escolha( 'tipo', array( 'perdido' => 1, 'encontrado' => 1 ) );
		$raca  = Rcaes_Seguranca::post_escolha( 'raca', $l['raca'] );
		$porte = Rcaes_Seguranca::post_escolha( 'porte', $l['porte'] );
		$cor   = Rcaes_Seguranca::post_escolha( 'cor', $l['cor'] );
		if ( '' === $tipo || '' === $raca || '' === $porte || '' === $cor || empty( $_POST['confirma'] ) ) {
			Rcaes_Seguranca::voltar( $url, 'campos_invalidos' );
		}
		$tipo_n   = 'perdido' === $tipo ? 0 : 1;
		$nome     = Rcaes_Seguranca::post_texto( 'nome', 80 );
		$desc     = Rcaes_Seguranca::post_texto( 'descricao', 500, true );
		$pergunta = 1 === $tipo_n ? Rcaes_Seguranca::post_texto( 'pergunta_verificacao', 200 ) : '';
		$cidade   = Rcaes_Seguranca::post_texto( 'cidade', 60 );
		$uf       = strtoupper( Rcaes_Seguranca::post_escolha( 'uf', array_change_key_case( self::ufs(), CASE_LOWER ) ) );
		$publico  = ( get_option( 'rcaes_listas_publicas', 1 ) && ! empty( $_POST['publico'] ) ) ? 1 : 0;

		// Fotos: valida e recodifica todas ANTES de gravar qualquer coisa no banco.
		$arquivos = self::arquivos( 'fotos' );
		$max      = max( 1, (int) get_option( 'rcaes_max_fotos', 3 ) );
		if ( count( $arquivos ) < 1 || count( $arquivos ) > $max ) {
			Rcaes_Seguranca::voltar( $url, 'foto_invalida' );
		}
		$salvos = array();
		foreach ( $arquivos as $a ) {
			$n = Rcaes_Imagens::processar( $a );
			if ( is_wp_error( $n ) ) {
				foreach ( $salvos as $s ) {
					Rcaes_Imagens::excluir( $s );
				}
				Rcaes_Seguranca::voltar( $url, 'foto_invalida' );
			}
			$salvos[] = $n;
		}

		$cao_id = Rcaes_DB::criar_cao(
			array(
				'user_id'              => $uid,
				'token'                => Rcaes_Seguranca::uuid_v4(),
				'tipo'                 => $tipo_n,
				'nome'                 => $nome,
				'raca'                 => $raca,
				'porte'                => $porte,
				'cor'                  => $cor,
				'descricao'            => $desc,
				'pergunta_verificacao' => $pergunta,
				'cidade'               => $cidade,
				'uf'                   => $uf,
				'publico'              => $publico,
			)
		);
		if ( ! $cao_id ) {
			foreach ( $salvos as $s ) {
				Rcaes_Imagens::excluir( $s );
			}
			Rcaes_Seguranca::voltar( $url, 'falha_geral' );
		}
		foreach ( $salvos as $s ) {
			Rcaes_DB::criar_foto( $cao_id, $s );
		}

		// Sugestões marcadas na pré-visualização: só valem se o cão estiver de fato na lista de compatíveis.
		if ( ! empty( $_POST['possiveis'] ) && is_array( $_POST['possiveis'] ) ) {
			$marcados = array_slice( array_map( 'absint', wp_unslash( $_POST['possiveis'] ) ), 0, 5 );
			$validos  = array();
			foreach ( Rcaes_DB::compativeis( 1 === $tipo_n ? 0 : 1, $raca, $porte, $cor, $uid, 50 ) as $c ) {
				$validos[ (int) $c->id ] = true;
			}
			foreach ( $marcados as $outro ) {
				if ( isset( $validos[ $outro ] ) ) {
					0 === $tipo_n ? Rcaes_DB::registrar_match_manual( $cao_id, $outro ) : Rcaes_DB::registrar_match_manual( $outro, $cao_id );
				}
			}
		}

		Rcaes_Fila::agendar_agora();
		// Pop-up "em análise": o botão Ok leva à listagem de Meus cães (configurável).
		Rcaes_Seguranca::voltar_popup( Rcaes_Seguranca::pagina_url( 'cadastrar' ), 'cao_analise' );
	}

	/* ---------------------------------------------------------------- [rcaes_meus_caes] */

	public static function sc_meus_caes() {
		Rcaes_Seguranca::nocache();
		if ( ! Rcaes_Seguranca::usuario_verificado() ) {
			return Rcaes_Seguranca::aviso_login( Rcaes_Seguranca::pagina_url( 'meus_caes' ) );
		}
		$uid  = get_current_user_id();
		$caes = Rcaes_DB::caes_do_usuario( $uid );
		ob_start();
		echo Rcaes_Seguranca::aviso(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<div class="rcaes-topo">
			<div>
				<h2 class="rcaes-titulo">Meus cães cadastrados</h2>
				<p class="rcaes-sub">Cães cadastrados: <strong><?php echo (int) count( $caes ); ?></strong></p>
			</div>
			<div class="rcaes-topo-acoes">
				<a class="rcaes-btn" href="<?php echo esc_url( Rcaes_Seguranca::pagina_url( 'cadastrar' ) ); ?>">+ Cadastrar novo cão</a>
				<a class="rcaes-btn rcaes-btn-sec" href="<?php echo esc_url( Rcaes_Seguranca::pagina_url( 'conversas' ) ); ?>">Conversas</a>
				<a class="rcaes-btn rcaes-btn-sec" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sair</a>
			</div>
		</div>

		<?php if ( ! $caes ) : ?>
			<div class="rcaes-aviso rcaes-info">Você ainda não cadastrou nenhum cão.</div>
		<?php else : ?>
		<div class="rcaes-tabela-wrap">
			<table class="rcaes-tabela">
				<thead>
					<tr><th>Cão</th><th>Raça</th><th>Porte</th><th>Tipo</th><th>Status</th><th>Data</th><th>Ações</th></tr>
				</thead>
				<tbody>
				<?php foreach ( $caes as $c ) : ?>
					<?php $foto = Rcaes_Imagens::url_principal( $c->id ); ?>
					<tr>
						<td data-label="Cão">
							<div class="rcaes-celula-cao">
								<?php if ( $foto ) : ?><img src="<?php echo esc_url( $foto ); ?>" alt="" loading="lazy"><?php endif; ?>
								<strong><?php echo '' !== $c->nome ? esc_html( $c->nome ) : 'Sem nome'; ?></strong>
							</div>
						</td>
						<td data-label="Raça"><?php echo esc_html( self::rotulo( 'raca', $c->raca ) ); ?></td>
						<td data-label="Porte"><?php echo esc_html( self::rotulo( 'porte', $c->porte ) ); ?></td>
						<td data-label="Tipo"><span class="rcaes-etiqueta rcaes-tipo-<?php echo 0 === (int) $c->tipo ? 'perdido' : 'encontrado'; ?>"><?php echo 0 === (int) $c->tipo ? 'Perdido' : 'Encontrado'; ?></span></td>
						<td data-label="Status"><span class="rcaes-etiqueta rcaes-st-<?php echo esc_attr( $c->status ); ?>"><?php echo esc_html( self::rotulo( 'status', $c->status ) ); ?></span><?php if ( (int) $c->publico ) : ?> <span class="rcaes-peq">· na lista pública</span><?php endif; ?></td>
						<td data-label="Data"><?php echo esc_html( get_date_from_gmt( $c->criado_em, 'd/m/Y' ) ); ?></td>
						<td data-label="Ações" class="rcaes-acoes-cel">
							<?php if ( 'ativo' === $c->status ) : ?>
								<a class="rcaes-btn rcaes-btn-peq" href="<?php echo esc_url( Rcaes_Lista::url( $c->token ) ); ?>">Ver correspondências</a>
							<?php endif; ?>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="rcaes_cao_acao">
								<input type="hidden" name="cao_id" value="<?php echo (int) $c->id; ?>">
								<?php wp_nonce_field( 'rcaes_cao_acao_' . (int) $c->id ); ?>
								<?php if ( get_option( 'rcaes_listas_publicas', 1 ) && in_array( $c->status, array( 'ativo', 'resolvido' ), true ) ) : ?>
									<button name="acao" value="<?php echo (int) $c->publico ? 'ocultar' : 'exibir'; ?>" class="rcaes-btn rcaes-btn-sec rcaes-btn-peq"><?php echo (int) $c->publico ? 'Ocultar da lista pública' : 'Exibir na lista pública'; ?></button>
								<?php endif; ?>
								<?php if ( 'falha' === $c->status ) : ?>
									<button name="acao" value="reprocessar" class="rcaes-btn rcaes-btn-sec rcaes-btn-peq">Processar novamente</button>
								<?php endif; ?>
								<button name="acao" value="remover" class="rcaes-btn rcaes-btn-perigo rcaes-btn-peq" data-rcaes-confirmar="Remover este cadastro e apagar as fotos? Não dá para desfazer.">Remover</button>
							</form>

							<?php if ( in_array( $c->status, array( 'ativo', 'pendente', 'processando', 'falha' ), true ) ) : ?>
								<?php $aceitos = Rcaes_DB::matches_aceitos_do_cao( $c->id ); ?>
								<details class="rcaes-detalhes rcaes-resolver">
									<summary>Marcar como resolvido</summary>
									<form class="rcaes-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="rcaes_cao_acao">
										<input type="hidden" name="cao_id" value="<?php echo (int) $c->id; ?>">
										<?php wp_nonce_field( 'rcaes_cao_acao_' . (int) $c->id ); ?>
										<label>Como foi o desfecho?
											<select name="desfecho" required>
												<option value="pelo_site">Reencontrei por meio deste site</option>
												<option value="outro_meio">Reencontrei por outro meio</option>
												<option value="sem_necessidade">Não preciso mais divulgar</option>
											</select>
										</label>
										<?php if ( $aceitos ) : ?>
											<label>Se foi pelo site, com quem?
												<select name="match_id">
													<option value="0">Não sei / outra pessoa</option>
													<?php foreach ( $aceitos as $m ) : ?>
														<?php $outro_user = 'perdido' === Rcaes_Lista::lado( $m, $uid ) ? $m->encontrado_user : $m->perdido_user; ?>
														<option value="<?php echo (int) $m->id; ?>">Conversa com <?php echo esc_html( Rcaes_Seguranca::nome_publico( $outro_user ) ); ?></option>
													<?php endforeach; ?>
												</select>
											</label>
										<?php endif; ?>
										<button name="acao" value="resolver" class="rcaes-btn rcaes-btn-sec rcaes-btn-peq" data-rcaes-confirmar="Marcar como resolvido? As conversas serão encerradas.">Confirmar</button>
									</form>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	public static function processar_acao() {
		$uid = Rcaes_Seguranca::exigir_usuario();
		$id  = isset( $_POST['cao_id'] ) ? absint( $_POST['cao_id'] ) : 0;
		check_admin_referer( 'rcaes_cao_acao_' . $id );
		$url  = Rcaes_Seguranca::pagina_url( 'meus_caes' );
		$acao = Rcaes_Seguranca::post_escolha( 'acao', array( 'resolver' => 1, 'remover' => 1, 'reprocessar' => 1, 'ocultar' => 1, 'exibir' => 1 ) );

		$cao = $id ? Rcaes_DB::cao( $id ) : null;
		// Checagem de propriedade (evita IDOR): só o dono mexe no próprio cão.
		if ( ! $cao || (int) $cao->user_id !== $uid || 'removido' === $cao->status || '' === $acao ) {
			Rcaes_Seguranca::voltar( $url, 'estado_invalido' );
		}

		if ( 'resolver' === $acao ) {
			if ( 'resolvido' === $cao->status ) {
				Rcaes_Seguranca::voltar( $url, 'estado_invalido' );
			}
			$desf = Rcaes_Seguranca::post_escolha( 'desfecho', array( 'pelo_site' => 1, 'outro_meio' => 1, 'sem_necessidade' => 1 ) );
			if ( '' === $desf ) {
				$desf = 'sem_necessidade';
			}
			// A conversa informada precisa ser uma das conversas aceitas DESTE cão.
			$mid_ok = 0;
			$mid    = isset( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0;
			if ( 'pelo_site' === $desf && $mid ) {
				foreach ( Rcaes_DB::matches_aceitos_do_cao( $id ) as $m ) {
					if ( (int) $m->id === $mid ) {
						$mid_ok = $mid;
					}
				}
			}
			Rcaes_DB::resolver_cao( $id, $desf, $mid_ok );
			Rcaes_DB::encerrar_matches_do_cao( $id );
			Rcaes_Seguranca::voltar( $url, 'acao_ok' );
		}
		if ( 'ocultar' === $acao || 'exibir' === $acao ) {
			if ( 'exibir' === $acao && ! get_option( 'rcaes_listas_publicas', 1 ) ) {
				Rcaes_Seguranca::voltar( $url, 'estado_invalido' );
			}
			Rcaes_DB::atualizar_cao( $id, array( 'publico' => 'exibir' === $acao ? 1 : 0 ) );
			delete_transient( 'rcaes_numeros' );
			Rcaes_Seguranca::voltar( $url, 'acao_ok' );
		}
		if ( 'remover' === $acao ) {
			Rcaes_DB::purgar_midia( $id );
			Rcaes_DB::atualizar_cao( $id, array( 'status' => 'removido' ) );
			Rcaes_DB::encerrar_matches_do_cao( $id );
			Rcaes_Seguranca::voltar( $url, 'acao_ok' );
		}
		// reprocessar
		if ( 'falha' !== $cao->status || ! Rcaes_Seguranca::permitir( 'reproc_' . $uid, 5, DAY_IN_SECONDS ) ) {
			Rcaes_Seguranca::voltar( $url, 'estado_invalido' );
		}
		Rcaes_DB::atualizar_cao( $id, array( 'status' => 'pendente', 'tentativas' => 0, 'proxima_tentativa' => null ) );
		Rcaes_Fila::agendar_agora();
		Rcaes_Seguranca::voltar( $url, 'reprocessando' );
	}

	/* ---------------------------------------------------------------- REST: pré-visualização por metadados (RF04) */

	public static function rotas_rest() {
		register_rest_route(
			'rcaes/v1',
			'/previa',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_previa' ),
				'permission_callback' => function () {
					return Rcaes_Seguranca::usuario_verificado();
				},
			)
		);
	}

	public static function rest_previa( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		if ( ! Rcaes_Seguranca::permitir( 'previa_' . $uid, 60, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'rcaes_limite', 'Muitas requisições.', array( 'status' => 429 ) );
		}
		$l     = self::listas();
		$tipo  = sanitize_key( (string) $req->get_param( 'tipo' ) );
		$raca  = sanitize_key( (string) $req->get_param( 'raca' ) );
		$porte = sanitize_key( (string) $req->get_param( 'porte' ) );
		$cor   = sanitize_key( (string) $req->get_param( 'cor' ) );
		if ( ! in_array( $tipo, array( 'perdido', 'encontrado' ), true ) || ! isset( $l['raca'][ $raca ], $l['porte'][ $porte ], $l['cor'][ $cor ] ) ) {
			return new WP_Error( 'rcaes_param', 'Parâmetros inválidos.', array( 'status' => 400 ) );
		}
		$oposto = 'perdido' === $tipo ? 1 : 0;
		$itens  = array();
		foreach ( Rcaes_DB::compativeis( $oposto, $raca, $porte, $cor, $uid, 12 ) as $c ) {
			$foto = Rcaes_Imagens::url_principal( $c->id );
			if ( ! $foto ) {
				continue;
			}
			$itens[] = array(
				'id'    => (int) $c->id,
				'foto'  => $foto,
				'texto' => self::tipo_rotulo( $c->tipo ) . ' · ' . self::rotulo( 'raca', $c->raca ) . ' · ' . self::rotulo( 'porte', $c->porte ) . ' · ' . self::rotulo( 'cor', $c->cor ),
			);
		}
		return rest_ensure_response( $itens );
	}
}
