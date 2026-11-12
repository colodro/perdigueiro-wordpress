(function () {
  'use strict';
  var R = window.RCAES || {};

  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (txt !== undefined && txt !== null) { e.textContent = txt; } // textContent: nunca HTML
    return e;
  }

  function get(url, fallback) {
    return fetch(url, { headers: { 'X-WP-Nonce': R.nonce }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
      .catch(function () { return fallback; });
  }

  // Envia JSON para a API REST do plugin. Resolve sempre com {ok, data}.
  function enviar(caminho, corpo) {
    return fetch(R.rest + caminho, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': R.nonce },
      credentials: 'same-origin',
      body: JSON.stringify(corpo || {})
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, data: j }; }, function () { return { ok: r.ok, data: {} }; });
    }).catch(function () {
      return { ok: false, data: { message: 'Sem conexão. Tente novamente.' } };
    });
  }

  function inicial(nome) {
    return (nome || '?').charAt(0).toUpperCase();
  }

  function bolhaMsg(m) {
    var d = el('div', 'rcaes-msg ' + (m.meu ? 'rcaes-meu' : 'rcaes-outro'));
    d.appendChild(el('div', 'rcaes-msg-txt', m.texto));
    d.appendChild(el('div', 'rcaes-peq', m.quando));
    return d;
  }

  // Confirmação em botões destrutivos
  document.addEventListener('click', function (ev) {
    var b = ev.target && ev.target.closest ? ev.target.closest('[data-rcaes-confirmar]') : null;
    if (b && !window.confirm(b.getAttribute('data-rcaes-confirmar'))) { ev.preventDefault(); }
  });

  // Mostrar / ocultar senha (botão de olho)
  document.addEventListener('click', function (ev) {
    var b = ev.target && ev.target.closest ? ev.target.closest('.rcaes-olho') : null;
    if (!b || !b.parentNode) { return; }
    var campo = b.parentNode.querySelector('input');
    if (!campo) { return; }
    var mostrar = campo.type === 'password';
    campo.type = mostrar ? 'text' : 'password';
    b.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
    b.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
    b.setAttribute('title', mostrar ? 'Ocultar senha' : 'Mostrar senha');
  });

  // Pop-up: foco no botão para o teclado e leitores de tela
  var popup = document.querySelector('.rcaes-popup .rcaes-btn');
  if (popup) { popup.focus(); }

  // Indicador "digitando": avisa o outro lado enquanto o texto não está vazio
  function ligarDigitacao(ta, mid) {
    var ultimo = 0;
    ta.addEventListener('input', function () {
      if (!ta.value.trim()) {
        if (ultimo) { enviar('digitando/' + mid, { ativo: false }); ultimo = 0; }
        return;
      }
      var agora = Date.now();
      if (agora - ultimo > 2500) { ultimo = agora; enviar('digitando/' + mid, { ativo: true }); }
    });
    ta.addEventListener('blur', function () {
      if (ultimo) { enviar('digitando/' + mid, { ativo: false }); ultimo = 0; }
    });
  }

  // ---------- Formulário de cadastro de cão ----------
  var form = document.getElementById('rcaes-form-cao');
  if (form) {
    var soEncontrado = form.querySelectorAll('.rcaes-so-encontrado');
    var tipoAtual = function () {
      var r = form.querySelector('input[name="tipo"]:checked');
      return r ? r.value : 'perdido';
    };
    var alternar = function () {
      var enc = tipoAtual() === 'encontrado';
      Array.prototype.forEach.call(soEncontrado, function (n) { n.hidden = !enc; });
    };

    var fotos = document.getElementById('rcaes-fotos');
    if (fotos) {
      fotos.addEventListener('change', function () {
        var max = R.maxFotos || 3, limite = (R.maxMb || 5) * 1048576;
        if (fotos.files.length > max) { window.alert('Envie no máximo ' + max + ' fotos.'); fotos.value = ''; return; }
        for (var i = 0; i < fotos.files.length; i++) {
          if (fotos.files[i].size > limite) { window.alert('Cada foto pode ter até ' + (R.maxMb || 5) + ' MB.'); fotos.value = ''; return; }
        }
      });
    }

    var caixa = document.getElementById('rcaes-previa');
    var itens = document.getElementById('rcaes-previa-itens');
    var espera = null;
    var previa = function () {
      if (!caixa || !itens) { return; }
      var q = new URLSearchParams({ tipo: tipoAtual(), raca: form.raca.value, porte: form.porte.value, cor: form.cor.value });
      get(R.rest + 'previa?' + q.toString(), []).then(function (lista) {
        var marcados = {};
        Array.prototype.forEach.call(itens.querySelectorAll('input:checked'), function (c) { marcados[c.value] = true; });
        itens.textContent = '';
        lista.forEach(function (it) {
          var lab = el('label');
          var cb = el('input'); cb.type = 'checkbox'; cb.name = 'possiveis[]'; cb.value = String(it.id);
          if (marcados[String(it.id)]) { cb.checked = true; }
          var img = el('img'); img.src = it.foto; img.alt = ''; img.loading = 'lazy';
          lab.appendChild(img); lab.appendChild(cb); lab.appendChild(document.createTextNode(' ' + it.texto));
          itens.appendChild(lab);
        });
        caixa.hidden = lista.length === 0;
      });
    };
    var agendar = function () { alternar(); clearTimeout(espera); espera = setTimeout(previa, 400); };
    Array.prototype.forEach.call(form.querySelectorAll('[data-rcaes-meta]'), function (n) { n.addEventListener('change', agendar); });
    alternar();
    previa();
  }

  // ---------- Página de conversa ([rcaes_conversas] com ?conversa=ID) ----------
  var chat = document.getElementById('rcaes-chat');
  if (chat) {
    var msgs = document.getElementById('rcaes-msgs');
    var dig = document.getElementById('rcaes-digitando');
    var mid = chat.getAttribute('data-match');
    var ultimo = parseInt(chat.getAttribute('data-ultimo'), 10) || 0;
    var rolar = function () { msgs.scrollTop = msgs.scrollHeight; };
    rolar();
    var buscar = function () {
      if (document.hidden) { return; }
      get(R.rest + 'mensagens/' + encodeURIComponent(mid) + '?depois=' + ultimo, null).then(function (r) {
        if (!r) { return; }
        var lista = r.mensagens || [];
        lista.forEach(function (g) {
          msgs.appendChild(bolhaMsg(g));
          if (g.id > ultimo) { ultimo = g.id; }
        });
        if (lista.length) { rolar(); }
        if (dig) { dig.hidden = !r.digitando; }
      });
    };
    setInterval(buscar, 3000);
    var taPagina = document.querySelector('textarea[data-rcaes-digita]');
    if (taPagina) { ligarDigitacao(taPagina, mid); }
  }

  // ---------- Caixa de mensagens flutuante ----------
  // Um botão "Mensagens" no canto (azul; pisca laranja quando há contato novo). Ao clicar abre uma
  // janela com a lista. Ela só tem o botão de fechar: fechar deixa a conversa minimizada (só o botão).
  function iniciarCaixa() {
    var botao = document.getElementById('rcaes-dock-btn');
    var badge = document.getElementById('rcaes-dock-badge');
    var janela = document.getElementById('rcaes-janela');
    var corpo = document.getElementById('rcaes-janela-corpo');
    var titulo = document.getElementById('rcaes-janela-titulo');
    var fecharBtn = document.getElementById('rcaes-janela-fechar');
    if (!botao || !janela || !corpo) { return; }

    var estado = { chave: null, timer: null };
    var vista = 'lista';
    var ultimo = null;

    function setBadge(n) {
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.hidden = !n;
      botao.classList.toggle('rcaes-piscando', n > 0);
    }

    function parar() {
      clearInterval(estado.timer);
      estado.timer = null;
      estado.chave = null;
    }

    function fecharJanela() {
      parar();
      vista = 'lista';
      janela.hidden = true;
      botao.setAttribute('aria-expanded', 'false');
      atualizar();
    }

    function abrirJanela() {
      vista = 'lista';
      janela.hidden = false;
      botao.setAttribute('aria-expanded', 'true');
      if (ultimo) { renderLista(ultimo); }
      atualizar();
    }

    botao.addEventListener('click', function () { if (janela.hidden) { abrirJanela(); } else { fecharJanela(); } });
    fecharBtn.addEventListener('click', fecharJanela);
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !janela.hidden) { fecharJanela(); } });

    function novaVista(txt, classe) {
      parar();
      corpo.textContent = '';
      corpo.className = 'rcaes-janela-corpo' + (classe ? ' ' + classe : '');
      titulo.textContent = txt;
    }


    function abrirConversa(c) {
      novaVista(c.nome + (c.tipo ? ' · ' + c.tipo : ''), 'rcaes-janela-thread');
      vista = 'conversa';
      var chave = 'c' + c.id;
      estado.chave = chave;

      var msgsModal = el('div', 'rcaes-modal-msgs');
      var digitandoModal = el('div', 'rcaes-digitando');
      digitandoModal.hidden = true;
      digitandoModal.appendChild(el('span'));
      digitandoModal.appendChild(el('span'));
      digitandoModal.appendChild(el('span'));
      digitandoModal.appendChild(el('em', '', c.nome + ' está digitando…'));

      var erro = el('div', 'rcaes-aviso rcaes-erro');
      erro.hidden = true;
      erro.setAttribute('role', 'alert');

      var formModal = el('form', 'rcaes-modal-form');
      var ta = el('textarea');
      ta.rows = 2;
      ta.maxLength = 1000;
      ta.placeholder = 'Escreva uma mensagem';
      ta.setAttribute('aria-label', 'Mensagem');
      var enviarBtn = el('button', 'rcaes-btn rcaes-btn-peq', 'Enviar');
      enviarBtn.type = 'submit';
      formModal.appendChild(ta);
      formModal.appendChild(enviarBtn);

      corpo.appendChild(msgsModal);
      corpo.appendChild(digitandoModal);
      corpo.appendChild(erro);
      corpo.appendChild(formModal);

      var ultimoId = 0;
      var tick = function () {
        if (document.hidden) { return; }
        get(R.rest + 'mensagens/' + c.id + '?depois=' + ultimoId, null).then(function (r) {
          if (!r || estado.chave !== chave) { return; }
          (r.mensagens || []).forEach(function (m) {
            msgsModal.appendChild(bolhaMsg(m));
            if (m.id > ultimoId) { ultimoId = m.id; }
          });
          if ((r.mensagens || []).length) { msgsModal.scrollTop = msgsModal.scrollHeight; }
          digitandoModal.hidden = !r.digitando;
        });
      };
      estado.timer = setInterval(tick, 3000);
      tick();
      atualizar();

      ligarDigitacao(ta, c.id);
      formModal.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var texto = ta.value.trim();
        if (!texto) { return; }
        enviarBtn.disabled = true;
        enviar('mensagens/' + c.id, { texto: texto }).then(function (res) {
          enviarBtn.disabled = false;
          if (!res.ok) {
            erro.textContent = (res.data && res.data.message) || 'Não foi possível enviar a mensagem.';
            erro.hidden = false;
            return;
          }
          erro.hidden = true;
          ta.value = '';
          enviar('digitando/' + c.id, { ativo: false });
          tick();
          atualizar();
          ta.focus();
        });
      });
    }

    function abrirPedido(p) {
      novaVista('Pedido de contato', 'rcaes-janela-thread');
      vista = 'pedido';
      corpo.appendChild(el('p', '', p.nome + ' pediu contato sobre um cão parecido com o seu:'));
      corpo.appendChild(el('blockquote', 'rcaes-pedido-msg', p.mensagem));
      if (p.resposta) {
        corpo.appendChild(el('p', 'rcaes-peq', 'Resposta à sua pergunta de verificação:'));
        corpo.appendChild(el('blockquote', 'rcaes-pedido-msg', p.resposta));
      }
      corpo.appendChild(el('p', 'rcaes-peq', 'O grau de correspondência é uma estimativa da IA. Compare as fotos antes de aceitar.'));

      // Aceitar/recusar usa o mesmo fluxo seguro da lista (admin-post + nonce próprio do pedido).
      var f = el('form', 'rcaes-modal-form rcaes-modal-decisao');
      f.method = 'post';
      f.action = R.adminPost;
      var campo = function (nome, valor) {
        var i = el('input');
        i.type = 'hidden';
        i.name = nome;
        i.value = valor;
        return i;
      };
      f.appendChild(campo('action', 'rcaes_contato_resp'));
      f.appendChild(campo('match_id', String(p.id)));
      f.appendChild(campo('_wpnonce', p.nonce));
      var aceitar = el('button', 'rcaes-btn', 'Aceitar');
      aceitar.type = 'submit';
      aceitar.name = 'decisao';
      aceitar.value = 'aceitar';
      var recusar = el('button', 'rcaes-btn rcaes-btn-sec', 'Recusar');
      recusar.type = 'submit';
      recusar.name = 'decisao';
      recusar.value = 'recusar';
      recusar.setAttribute('data-rcaes-confirmar', 'Recusar esta solicitação?');
      f.appendChild(aceitar);
      f.appendChild(recusar);
      corpo.appendChild(f);

      var rodape = el('div', 'rcaes-modal-rodape');
      var ver = el('a', 'rcaes-peq', 'Ver o cão e as fotos');
      ver.href = p.lista;
      rodape.appendChild(ver);
      corpo.appendChild(rodape);

      enviar('pedido/' + p.id + '/visto', {}).then(atualizar);
    }

    function renderLista(d) {
      novaVista('Mensagens', '');
      var lista = el('div', 'rcaes-painel-lista');
      if (!d.pedidos.length && !d.conversas.length) {
        lista.appendChild(el('p', 'rcaes-painel-vazio', 'Nenhuma mensagem por enquanto. Quando alguém pedir contato ou aceitar um pedido, aparece aqui.'));
      }
      d.pedidos.forEach(function (p) {
        var b = el('button', 'rcaes-item' + (p.nova ? ' rcaes-item-azul' : ''));
        b.type = 'button';
        b.appendChild(el('span', 'rcaes-avatar', inicial(p.nome)));
        var corpoItem = el('span', 'rcaes-item-corpo');
        corpoItem.appendChild(el('span', 'rcaes-item-nome', p.nome));
        corpoItem.appendChild(el('span', 'rcaes-item-prev', p.nova ? 'Pediu contato' : 'Pedido de contato pendente'));
        b.appendChild(corpoItem);
        b.appendChild(el('span', 'rcaes-item-meta', p.quando));
        b.addEventListener('click', function () { abrirPedido(p); });
        lista.appendChild(b);
      });
      d.conversas.forEach(function (c) {
        var b = el('button', 'rcaes-item' + (c.nao_lidas ? ' rcaes-item-novo' : ''));
        b.type = 'button';
        b.appendChild(el('span', 'rcaes-avatar', inicial(c.nome)));
        var corpoItem = el('span', 'rcaes-item-corpo');
        corpoItem.appendChild(el('span', 'rcaes-item-nome', c.nome + (c.tipo ? ' · ' + c.tipo : '')));
        corpoItem.appendChild(el('span', 'rcaes-item-prev' + (c.digitando ? ' rcaes-item-digitando' : ''), c.digitando ? 'digitando…' : c.ultima));
        b.appendChild(corpoItem);
        var direita = el('span', 'rcaes-item-direita');
        direita.appendChild(el('span', 'rcaes-item-meta', c.quando));
        if (c.nao_lidas) { direita.appendChild(el('span', 'rcaes-item-n', String(c.nao_lidas))); }
        b.appendChild(direita);
        b.addEventListener('click', function () { abrirConversa(c); });
        lista.appendChild(b);
      });
      corpo.appendChild(lista);
    }

    function atualizar() {
      get(R.rest + 'caixa', null).then(function (d) {
        if (!d) { return; }
        ultimo = d;
        setBadge(d.total);
        if (!janela.hidden && vista === 'lista') { renderLista(d); }
      });
    }

    atualizar();
    setInterval(function () { if (!document.hidden) { atualizar(); } }, 10000);
  }

  if (R.dock && !chat) {
    iniciarCaixa();
  }
})();
