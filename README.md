# Reencontro de Cães (plugin WordPress)

Reconhecimento de cães perdidos por similaridade visual. Todas as telas são **shortcodes**. A IA roda em um serviço
separado (API Flask/TFLite, pasta `api_render`); o plugin só chama essa API, a partir do servidor.

> **Status:** o código foi escrito e revisado, mas **ainda não foi executado dentro de um WordPress**. Antes de abrir
> ao público, rode a lista de testes da seção 7 em um site de testes com `WP_DEBUG` e `WP_DEBUG_LOG` ligados.

## Novidades da versão 1.4.2 e 1.4.1 — destaque da home

`[rcaes_destaque]` mostra o reencontro público mais recente (foto, nome, tempo e cidade), para colocar na hero da home:

```
[rcaes_destaque]
[rcaes_destaque texto="voltou para casa!" selo="Reconhecido por IA"]
```

- Usa as mesmas regras de privacidade da lista de recuperados: só cadastros públicos, foto principal em miniatura, sem dados do tutor.
- O selo aparece só quando o reencontro veio de uma correspondência feita pelo site.
- Sem reencontro público, não mostra nada para o visitante (o administrador vê um aviso).
- O nome e a foto são sempre do cão que o tutor marcou como **perdido** (e só se ele também estiver público). Sem nome, aparece "Este aqui já voltou pra casa" (configurável com `sem_nome="..."`).

## Novidades da versão 1.4.0 — conversas em três colunas e "cão resgatado"

- **Página de conversas** (`[rcaes_conversas]`): à esquerda, as pessoas com quem você conversa (e pedidos de contato);
  no centro, a conversa; à direita, a foto e os dados do cão da outra pessoa, com o botão **Marcar como cão resgatado**
  na parte de baixo (no mesmo lugar em que ficaria o botão de ação).
- **Cão resgatado**: quem marca precisa ser confirmado pela outra pessoa. Ao confirmar, a conversa é encerrada, os dois
  cadastros viram *resolvidos* (desfecho "pelo site") e as demais correspondências desses cães são desligadas, para que
  ninguém consiga mais falar com os tutores por esse cadastro. Cães resolvidos com desfecho "pelo site" aparecem na lista pública de recuperados (se o tutor tiver marcado como público).
- **Botão "Mensagens"** no canto inferior direito, em todas as páginas do site para tutores verificados (exceto na própria
  página de conversas). Fica azul; pisca em laranja quando alguém entra em contato. Ao clicar, abre uma janela só com o
  botão de fechar; fechar deixa a conversa minimizada.

**Atualizando da 1.3.0:** o plugin acrescenta duas colunas na tabela de correspondências (`resgate_por`, `resgate_em`) ao
abrir o painel. Nada é apagado.

## Novidades da versão 1.3.0 — pop-ups, senha e caixa de mensagens

- **Pop-up após criar a conta**: "Seu cadastro foi enviado…" com botão **Entendi**, que leva à página de login.
  Também aparece quando o e-mail já existe (a resposta continua igual, para não revelar contas).
- **Pop-up após cadastrar um cão**: "Cadastro em análise" com vídeo opcional e botão **Ok**, que leva à listagem *Meus cães*.
- **Mostrar/ocultar senha** (ícone de olho) nos campos de senha do login e do cadastro.
- **Caixa de mensagens estilo LinkedIn** para tutores verificados, em todo o site:
  - Computador: barra **Mensagens** no canto inferior direito, com contador. Itens não lidos ficam em destaque e pedidos de contato novos aparecem em **azul**. Ao clicar, abre uma janela com a conversa (ou com o pedido, para aceitar/recusar).
  - Celular: balão no canto inferior direito que abre a lista de pessoas em tela cheia.
  - Indicador **"está digitando…"** na caixa e na página de conversas (atualização a cada ~3 s).
- **Cards das listas públicas** (`[rcaes_caes]`): fotos quadradas e cartões com a mesma altura.

**Configurações → Pop-ups após o cadastro**: títulos, textos, textos dos botões, páginas de destino e o **link do vídeo**
(YouTube, Vimeo ou arquivo .mp4/.webm em HTTPS; o vídeo inicia sem som). Nada disso precisa ser alterado no código.

## Novidades da versão 1.2.0 — listas públicas

Shortcodes para a página inicial (colar em um bloco "Shortcode" do editor):

```
[rcaes_numeros]

[rcaes_caes tipo="encontrados" limite="6" colunas="3" titulo="Cães Encontrados recentemente"
            subtitulo="Alguém encontrou esses cães. Será que um deles é o seu?" ver_todos="/encontrados/"]

[rcaes_caes tipo="recuperados" limite="4" colunas="4" titulo="Cães Recuperados com sucesso"]

[rcaes_caes tipo="perdidos" limite="6" titulo="Cães Perdidos"]
```

| Atributo de `[rcaes_caes]` | Valores | Padrão |
|---|---|---|
| `tipo` | `encontrados`, `perdidos`, `recuperados` | `encontrados` |
| `limite` | 1 a 48 | 6 |
| `colunas` | 1 a 4 (no celular vira 1 ou 2 automaticamente) | 3 |
| `titulo`, `subtitulo` | texto opcional acima da lista | vazio |
| `ver_todos` | endereço de uma página com a lista completa (ex.: outra página com `limite="48"`) | sem botão |
| `texto_botao`, `link` | personalizam o botão do cartão | "É o meu cão" / "Tenho informações" |

`[rcaes_numeros]` mostra números **reais** do site (cães reencontrados, cadastros ativos e tempo mediano até o reencontro), atualizados a cada 10 minutos.

O botão de um cão encontrado leva ao formulário já em "Perdi meu cão"; o de um cão perdido leva ao formulário em "Encontrei um cão". Quem não está logado passa pelo login e volta.

**Privacidade (o que é público):**
- Só aparecem cadastros que o **tutor marcou** como públicos (caixa marcada por padrão no formulário; dá para ocultar/exibir depois em *Meus cães*).
  Os cadastros anteriores à 1.2.0 **não** viram públicos sozinhos.
- Mostram: foto principal em miniatura de 640 px (sem EXIF), raça, porte, cor, cidade/UF e data de cadastro.
- **Nunca** mostram: nome do cão (exceto nos recuperados), descrição, pergunta de verificação, outras fotos, nome ou contato do tutor.
- As miniaturas são geradas sob demanda em `uploads/rcaes-privado/pub/` e removidas junto com o cadastro.
- *Reencontro de Cães → Configurações → Listas públicas* desliga tudo de uma vez (as fotos públicas passam a dar 404).
- Novos campos opcionais no cadastro: **cidade** e **estado** (também aparecem na lista privada de correspondências).
- Se usar plugin de cache/CDN, essas listas podem ser cacheadas normalmente (não dependem de login).

## Novidades da versão 1.1.0

- **Identidade visual "Cão Encontrado"** (azul-marinho `#1e3a5f`, laranja `#ff6a31`, Outfit + Inter), responsiva:
  arquivo **`assets/rcaes.css`**, carregado em **todas** as páginas do site. Cadastro e login viram cartão central,
  "Meus cães" vira tabela (cartões no celular), cartões de cães e chat.
  - Fontes: carregadas do Google Fonts. Para não usar fonte externa (LGPD), defina no `wp-config.php`:
    `define( 'RCAES_SEM_FONTE_EXTERNA', true );` (cai para as fontes do sistema).
  - Classes opcionais para o restante do site (Gutenberg: bloco → *Avançado* → *Classes CSS adicionais*):
    `cdr-header`, `cdr-hero`, `cdr-selo`, `cdr-laranja`, `cdr-numeros`, `cdr-secao`, `cdr-titulo`, `cdr-subtitulo`,
    `cdr-faixa`, `cdr-cta`, `cdr-depoimento`, `cdr-footer`, `cdr-container`.
  - Os botões usam `!important` de propósito, para vencer o CSS do tema.
- **Desfecho ao marcar "resolvido"**: pelo site / outro meio / sem necessidade, e com quem foi o reencontro.
  Isso grava `resolvido_em` e permite medir o tempo divulgado e a posição real do cão correto na lista.

**Atualizando da 1.0.0:** substitua a pasta do plugin e abra qualquer página do WordPress. A versão muda e o plugin
atualiza as estruturas necessárias sem apagar dados existentes.

## 1. Instalação

1. Compacte a pasta `reencontro-caes` em `.zip` (ou use o `.zip` entregue) e envie em *Plugins → Adicionar novo → Enviar plugin*.
2. Ative. Na ativação o plugin cria: 5 tabelas (`wp_rcaes_*`), o papel `Tutor`, a pasta privada `uploads/rcaes-privado`
   e **6 páginas** já com os shortcodes (abaixo).
3. Em *Configurações → Permalinks*, use qualquer estrutura que **não** seja "Simples" (o endereço `/lista/<código>` precisa de links amigáveis).
4. No `wp-config.php`, antes da linha "That's all, stop editing!":

```php
define( 'RCAES_API_URL',   'https://SEU-SERVICO.onrender.com' );
define( 'RCAES_API_TOKEN', 'A-MESMA-CHAVE-DEFINIDA-NO-RENDER' );
```

5. Em *Reencontro de Cães*, clique em **Testar API**.
6. **E-mail:** instale um plugin de SMTP (ex.: WP Mail SMTP) com um remetente do seu domínio. Sem isso, os e-mails
   de confirmação e de resultado costumam cair no spam ou nem sair.

## 2. Shortcodes e páginas

| Shortcode | Página criada | Função |
|---|---|---|
| `[rcaes_cadastro]` | Cadastro | Criar conta (com confirmação de e-mail) |
| `[rcaes_login]` | Entrar | Login, reenvio da confirmação |
| `[rcaes_cadastrar_cao]` | Cadastrar cão | Formulário perdido/encontrado + prévia por características |
| `[rcaes_meus_caes]` | Meus cães | Status, resolver, remover, reprocessar |
| `[rcaes_lista]` | Lista de possíveis correspondências | Resultado da IA; solicitar/aceitar contato |
| `[rcaes_conversas]` | Conversas | Caixa de entrada e chat |
| `[rcaes_caes]`, `[rcaes_numeros]` | (você escolhe, ex.: início) | Listas públicas e números reais |

Você pode renomear as páginas e colocar os shortcodes em outras, desde que mantenha uma página com `[rcaes_lista]`
(o endereço `/lista/<código>` aponta para a página registrada na ativação).

## 3. Fluxo

1. A pessoa se cadastra e **confirma o e-mail** (sem isso não consegue entrar).
2. Cadastra um cão (perdido ou encontrado) com 1 a 3 fotos. A tela responde na hora; **o processamento é em segundo plano** (WP-Cron).
   A pessoa pode sair do site ou da conta.
3. Ao terminar, ela recebe um **e-mail**. Se houver ao menos uma correspondência, o e-mail traz o link
   `https://seusite.com/lista/<uuid>`. Sem correspondência, o e-mail avisa e o cadastro segue ativo.
4. Ao abrir o link: **logada** e dona do cadastro → vê a lista. **Não logada** → vai para o login e, depois de entrar,
   volta para a lista. Logada em outra conta → mensagem genérica de link inválido.
5. A outra parte de cada par também é avisada (no máximo 1 e-mail a cada 6 h por cão) com o link da lista **dela**.
6. Contato: solicitação (com mensagem e, se houver, resposta à pergunta de verificação) → a outra pessoa **aceita ou recusa**
   → só então abre a conversa. Nenhum e-mail ou telefone é exibido.

## 4. Segurança implementada

- **Nonce** em todos os formulários e ações; **verificação de propriedade/participação** em cada objeto (cão, par, conversa, foto).
- **SQL**: tudo com `$wpdb->prepare`; valores de selects, tipos e ações por **lista branca**.
- **XSS**: toda saída escapada; mensagens de aviso vêm de códigos fixos (nada da URL é impresso); chat em texto puro (`textContent` no JS).
- **Login**: funções nativas do WP (hash de senha e cookies); limite de tentativas por IP e por e-mail; mensagem única de erro;
  redirecionamento só para o próprio site; senha de 10+ caracteres; e-mail confirmado obrigatório.
- **Cadastro**: honeypot + tempo mínimo assinado; limite por IP e por e-mail; resposta **igual** para e-mail novo ou já existente
  (não revela quem tem conta); login interno aleatório (`tutor_xxxx`), sem expor o e-mail em URLs de autor.
- **Link da lista**: UUID v4 gerado com `random_bytes` (não adivinhável). **O código sozinho não dá acesso**: exige login e
  ser o dono. Páginas com `noindex`, sem cache e `Referrer-Policy: no-referrer`.
- **Uploads**: tipo real (`getimagesize`), tamanho e dimensões limitados; recodificados em JPEG com GD (**descarta EXIF/GPS** e
  qualquer carga embutida); nome aleatório; pasta privada negada pelo servidor; entrega só por endpoint autenticado.
- **API de inferência**: chamada só pelo servidor, com `Authorization: Bearer`, HTTPS obrigatório, `wp_safe_remote_request`
  (bloqueia destinos internos), resposta validada. O token fica no `wp-config.php`.
- **Contato seguro**: solicitação → aceite; recusa definitiva; limite diário de solicitações; encerrar conversa; avisos de golpe.
- **Privacidade**: e-mails nunca trazem texto de outros usuários; ao excluir um usuário do WP, cães, fotos e conversas são apagados.
- **Painel**: só `manage_options`; moderação para remover cadastros.

## 5. O que você precisa fazer no servidor (o plugin não consegue)

1. **HTTPS** em todo o site e `define( 'FORCE_SSL_ADMIN', true );`.
2. **Nginx**: o `.htaccess` da pasta privada só vale no Apache. No Nginx adicione:
   `location ~* /wp-content/uploads/rcaes-privado/ { deny all; }`
3. **WP-Cron confiável**: no `wp-config.php` `define( 'DISABLE_WP_CRON', true );` e um cron do servidor a cada minuto:
   `* * * * * curl -s https://seusite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1`
4. Atrás de proxy/CDN, filtre o IP real (senão o limite de tentativas enxerga o IP do proxy):
   `add_filter( 'rcaes_client_ip', function ( $ip ) { return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip; } );`
   (só faça isso se o servidor aceitar conexões **apenas** do CDN).
5. Proteja também o `wp-login.php` e o `xmlrpc.php` (plugin de limite de tentativas/WAF), pois o limite do plugin vale para o formulário próprio.
6. Mantenha WordPress, PHP e plugins atualizados e faça backups (inclusive de `uploads/rcaes-privado`).
7. **LGPD**: publique Termos e Política de Privacidade e vincule-os à frase de consentimento do cadastro (edite o texto em `class-rcaes-conta.php`).

## 6. Configurações importantes

- **Distância máxima**: use o limiar do EER da sua avaliação (ex.: 0,89 no modelo `imagenet` Fase 2). Recalcule para o modelo final.
- **Mínimo de candidatos compatíveis**: com poucos cães, a busca compara com todos (RF05); o filtro por porte/raça/cor só atua quando há muitos.
- O rótulo exibido ("Muito provável / Provável / Possível") é derivado da distância; o plugin não mostra porcentagem de "certeza".

## 7. Testes antes de publicar

1. Cadastro com e-mail novo → e-mail de confirmação → login. Repita com e-mail existente: a tela deve ser idêntica.
2. Tentar entrar antes de confirmar: deve ser recusado só depois de acertar a senha.
3. Cadastrar um cão perdido e outro encontrado (contas diferentes) com fotos do mesmo cão. Aguarde o cron (ou use *Processar fila agora*).
4. Conferir os e-mails (conclusão e "novo cão parecido") e o link `/lista/<uuid>` **deslogado** (deve ir ao login e voltar) e **com outra conta** (deve negar).
5. Solicitar contato → aceitar → trocar mensagens; encerrar. Recusar: o solicitante não pode pedir de novo.
6. Enviar arquivo `.php` renomeado para `.jpg`, imagem enorme e imagem com GPS no EXIF: devem ser rejeitados/limpos.
7. Abrir `/?rcaes_img=ID` deslogado: 403. Abrir o arquivo direto em `uploads/rcaes-privado/`: bloqueado.
8. Derrubar a API (token errado): o cão deve ir a "pendente" com nova tentativa e, após 3 falhas, "falha" com e-mail ao usuário.
9. Rodar `php -l` em todos os arquivos e ativar `WP_DEBUG` procurando avisos.

## 8. Limitações conhecidas

- Fotos de cães **ativos** são visíveis a qualquer tutor logado e verificado (as da lista pública, só a principal e só se o tutor permitiu, a qualquer visitante) (necessário para a prévia por características). Se preferir restringir,
  altere `Rcaes_Imagens::pode_ver()` para só permitir o dono e os participantes de um par.
- Nas listas públicas não há página de detalhes por cão nem paginação (use `limite` até 48).
- Não há geolocalização, nem notificações push, nem denúncia formal (apenas encerrar conversa e moderação pelo painel).
- Fotos de cães resolvidos permanecem armazenadas até o dono remover o cadastro.
- Idioma fixo em português (sem arquivos `.po`).

## Versão 1.4.3 — remoção da avaliação do site

O formulário de satisfação/NPS/SUS, o shortcode `[rcaes_avaliacao]`, o botão de avaliação e o painel administrativo de avaliações e métricas foram removidos. O registro de desfecho do cão permanece disponível. A tabela histórica `rcaes_avaliacoes` é preservada em instalações existentes para evitar perda de dados. Se a página antiga “Avaliar o site” já existir, remova-a manualmente em **Páginas** do WordPress; a atualização não exclui páginas existentes automaticamente.
