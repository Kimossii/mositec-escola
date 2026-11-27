# Notificações por email — guia de configuração e operação

Decisões de desenho: `docs/superpowers/specs/2026-11-26-notificacoes-email-design.md`.
Este documento é o **como pôr a funcionar** (produção, instalação própria, resolução de problemas).

## 1. O que o sistema faz hoje

- Todo email do sistema sai de uma `NotificacaoDeTenant` (`Modules/Core/app/Comunicacao/Email/`): vai sempre para a **fila**,
  com o tenant (escola) no payload, e é montado por `RemetenteTenant`.
- **Remetente:** nome da escola + endereço central da plataforma (`MAIL_FROM_ADDRESS`). **Reply-To:** o email do estabelecimento
  (Dados da escola), se existir. **Rodapé:** nome da escola e os contactos que ela tiver (telefone, email).
- **Links** nos emails apontam ao domínio principal da escola (subdomínio ou domínio próprio). Numa instalação única
  (`TENANCY_MODO=unico`) usam o `APP_URL` inteiro, com a porta.
- **Categorias:** `essencial` (confirmar email, recuperar senha, aviso «senha alterada»; sai sempre) e `opcional` (avisos,
  cobranças…; só sai se a escola tiver o módulo licenciável `notificacoes`).
- **Limite por hora e por escola** (`config/comunicacao.php`): 300 essenciais, 1000 opcionais.
- **Quem recebe:** as notificações enviam-se a um **endereço de email**, não a uma conta. Um encarregado sem conta na
  plataforma pode receber avisos (`Notification::route('mail', $email)->notify(...)`). Só os emails de **segurança da conta**
  (confirmar email, recuperar senha) exigem conta, por natureza.

## 2. Pôr em produção (instalação partilhada na cloud)

### 2.1 Conta e domínio de envio no Resend
1. Criar conta em <https://resend.com> e adicionar o domínio de envio (ex.: `mositec.ao`) em *Domains*.
2. O Resend mostra a lista **exacta** de registos DNS a criar (normalmente registos para SPF e para DKIM). Copiá-los tal como
   aparecem, no painel DNS de quem gere o domínio. Não inventar nem alterar valores.
3. Carregar em *Verify* no Resend. Só avançar quando o estado for *Verified* (pode demorar de minutos a horas).
4. Acrescentar o registo **DMARC** no DNS (ver 2.2).
5. Criar uma *API key* com permissão de envio.

### 2.2 O que são SPF, DKIM e DMARC
Qualquer pessoa pode escrever `noreply@mositec.ao` num email falso; por isso Gmail e Outlook mandam para o spam o que não
conseguir provar de onde vem.

| Registo | Para quê |
|---|---|
| **SPF** | Lista, no DNS, dos servidores autorizados a enviar em nome do domínio |
| **DKIM** | Assinatura digital em cada email: prova que não foi forjado nem alterado |
| **DMARC** | Diz ao destinatário o que fazer quando SPF ou DKIM falham |

Registo DMARC recomendado (começar só a observar, endurecer depois de algumas semanas sem problemas):

```
Tipo:  TXT
Nome:  _dmarc.mositec.ao
Valor: v=DMARC1; p=none; rua=mailto:dmarc@mositec.ao
```
Depois de estável, mudar `p=none` para `p=quarantine` e, mais tarde, `p=reject`.

### 2.3 Variáveis de ambiente (`.env` de produção)
```
MAIL_MAILER=resend
RESEND_API_KEY=re_xxxxxxxx
MAIL_FROM_ADDRESS=noreply@mositec.ao        # tem de ser do domínio verificado
MAIL_FROM_NAME="MosiTec"
QUEUE_CONNECTION=database                    # recomendado: os emails vão pela fila (o .env.example traz `sync`)
EMAIL_LIMITE_ESSENCIAL_HORA=300
EMAIL_LIMITE_OPCIONAL_HORA=1000
EMAIL_CONFIRMACAO_VALIDADE_MIN=60
EMAIL_CONFIRMACAO_PEDIDOS_HORA=3
EMAIL_RECUPERACAO_VALIDADE_MIN=60
EMAIL_RECUPERACAO_PEDIDOS_HORA=3
EMAIL_RECUPERACAO_TEMPO_MINIMO_MS=300
```
**Importante:** com `QUEUE_CONNECTION=database` tem de haver um *worker* da fila a correr (`php artisan queue:work`, sob
supervisor/systemd); sem worker nenhum email sai, ficam à espera na tabela `jobs`. Com `sync` (o valor do `.env.example`) o
email é enviado dentro do próprio pedido: funciona sem worker, mas atrasa a resposta ao utilizador e uma falha do provedor
chega-lhe como erro. Em produção use `database` com worker.

### 2.4 Testar o envio
Depois de configurar, enviar um email simples para um endereço vosso (não grava nada na base de dados):
```
php artisan tinker --execute 'Mail::raw("Teste de envio", fn ($m) => $m->to("o-vosso@email.com")->subject("Teste MosiTec"));'
```
Verificar se chegou à caixa de entrada (não ao spam). Depois, testar o fluxo real: pedir «Enviar confirmação» nas Definições
de uma conta de teste, abrir o email e carregar na ligação.

## 3. Instalação própria (`TENANCY_MODO=unico`)
- O `APP_URL` **tem de ser o endereço que os utilizadores usam** (com porta, se a houver): é o endereço dos links dos emails.
  Se a escola só for acessível dentro da rede, os links só abrem dentro da rede.
- O envio sai pelo mailer do `.env` **dessa** instalação, nunca pela conta da MosiTec. Sem mailer configurado (`log`), o
  sistema funciona normalmente mas **não envia emails**, e as opções que dependem de email (recuperação de senha,
  confirmação de email) ficam escondidas.
- Configurar o mailer da escola (SMTP próprio ou provedor) no `.env`: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`,
  `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`.

## 4. Segurança (resumo)
- Ligações de confirmação e de recuperação são assinadas, expiram e estão presas à escola e ao estado actual da conta
  (a de recuperação só serve uma vez).
- Recuperação de senha por email **só para encarregados** com email **confirmado** (lista em `config/comunicacao.php`).
  Resposta sempre genérica, limite por IP e por email, tempo mínimo de resposta.
- Nunca se envia senha por email, nem se regista o email pedido nos logs.

## 5. Resolução de problemas
| Sintoma | Causa provável |
|---|---|
| Nenhum email sai | Worker da fila parado (com `QUEUE_CONNECTION=database`); `MAIL_MAILER=log`; `RESEND_API_KEY` vazia |
| Emails no spam | Domínio não verificado no Resend; falta SPF/DKIM/DMARC; `MAIL_FROM_ADDRESS` fora do domínio verificado |
| Link do email não abre | `APP_URL` errado ou interno; domínio principal da escola incorrecto |
| Botão «Enviar confirmação» / «Esqueci a senha» não aparece | Não há canal de email configurado (ver 2.3) |
| Respostas dos pais não chegam à escola | A escola não tem email em *Dados da escola* (Reply-To) |
| Emails em `failed_jobs` | Erro do provedor ou do SMTP: `php artisan queue:failed` mostra a causa |

## 6. Servidor SMTP próprio da escola
Cada escola pode enviar pelo **seu** servidor SMTP em vez da central. Menu **Sistema → Email da escola** (`/configuracao-email`),
só para quem tem a permissão `configuracao-email` (o Administrador, por defeito).

**Como configurar**
1. Escolher uma predefinição (Gmail, Outlook, Zoho ou Outro): só preenche servidor, porta e encriptação.
2. Preencher utilizador e senha (opcionais: deixe em branco para servidores sem autenticação), nome e endereço do remetente
   e, se quiser, «responder para». Em «Outro», indicar servidor, porta e encriptação do fornecedor.
3. **Guardar** e depois **Testar envio** (para um email vosso). Só um teste bem-sucedido verifica e activa; a partir daí todos
   os emails da escola saem pelo servidor dela, sem recorrer à central. Em Gmail/Outlook é normalmente preciso uma *senha de aplicação*.
4. **Voltar à central** desliga o servidor próprio (a configuração fica guardada; para reactivar, testar outra vez).

**Regras**
- Portas permitidas: 25, 465, 587 e 2525. Encriptação: nenhuma, STARTTLS ou SSL/TLS. Tempo limite de 5 a 30 s (15 por omissão).
- «Validar o certificado» pode desligar-se para certificados autoassinados, mas a ligação deixa de estar protegida (o ecrã avisa).
- **Endereços privados são recusados** (`localhost`, `10.x`, `192.168.x`, `172.16–31.x`, `169.254.x`, `::1`), resolvendo o nome do
  servidor: uma escola não pode apontar o SMTP para a rede interna da plataforma. Numa instalação única (`TENANCY_MODO=unico`) são aceites.
- Alterar o servidor, a ligação ou o remetente volta o estado a «por verificar» e desliga o servidor próprio até novo teste.
- Só um servidor **verificado e activo** é usado. Em qualquer outro estado (por verificar, com falha, desligado) a escola envia pela central.
  Se o servidor próprio falhar depois de activo, a falha fica em «Envios de email» (canal «Infra própria da escola») e a fila repete; não há fallback para a central.
- Teste de envio: 5 por hora por escola. Se a configuração mudar durante o teste, este é recusado (não activa nada).
- **Bloqueio pela Plataforma:** «Forçar regresso à central» desliga o servidor e impede a escola de testar ou reactivar até a Plataforma levantar o bloqueio. Levantar não reactiva: a escola testa outra vez.
- Os erros de envio registados nunca levam o utilizador nem a senha do servidor da escola.
- Risco aceite: DNS rebinding entre a validação e a ligação (ver spec). A senha vai cifrada e nunca aparece no ecrã, nos logs nem na auditoria.
- A Plataforma vê, no painel, que escolas têm servidor próprio e o estado (nunca servidor nem credenciais) e pode **forçar o regresso à central**
  no detalhe da escola (fica na auditoria da Plataforma).

**Depois de actualizar o código:** `php artisan migrate` e actualizar permissões/módulos (`php artisan db:seed --force` ou o comando
habitual do Permissao) para criar a tabela e o módulo 37.

## 7. Ainda por construir (decidido, ver spec)
Domínio próprio verificado por DNS; consumo e subscrição comercial (regras por definir).
