# Notificações por email: decisões fechadas (2026-11-26)

## Estado
- **Feito (Fase 1, em stage):** `RemetenteTenant`, `NotificacaoDeTenant`, `CategoriaEmail`, `LimitadorDeEmailsPorTenant`,
  contratos `EnderecoDoTenant` e `ContactoDaEscola`, módulo licenciável `notificacoes`, Resend instalado (mailer `log` em dev).
- **Ainda sem consumidores:** não há emails reais. **Decisão (2026-11-26, revista):** não há «esqueci a senha» geral. A
  recuperação de senha por email existe **só para os encarregados de educação, e só se tiverem email configurado**; os restantes
  perfis continuam a depender do administrador. Perfis a ampliar depois: a decidir (ver «Recuperação de senha»).
- **Feito depois (2026-11-26):** rodapé com contactos da escola, tempo mínimo de resposta no «esqueci a senha», links do modo
  único com o `APP_URL` inteiro (com porta). Guia de operação em `docs/notificacoes-email.md`.
- **Feito (2026-11-26):** ecrã «Envios de email» da escola (`/envios-email`, permissão `envio-email.ver`, só administrador por defeito) e cartão «Falhas de email» no painel da Plataforma.
- **Feito (2026-11-27, em stage): infra própria V1 por SMTP.** Tabela `configuracoes_email`, `MailerDaEscola` (mailer `escola` em tempo de execução), `ValidadorDeServidorSmtp` (portas 25/465/587/2525, recusa IPs privados fora do modo único), Actions Guardar/Testar/Desactivar, ecrã «Email da escola» (`/configuracao-email`, módulo `configuracao-email` = 37, `ver`/`editar`, só administrador por defeito; teste de envio limitado a 5 por hora por escola), cartão «Escolas com infra própria de email» no painel e botão «Forçar regresso à central» no detalhe da escola.
- **DNS rebinding no SMTP próprio: FECHADO (2026-11-28).** A ligação é fixada ao IP validado (`TransporteSmtpFixado`, mailer `smtp-fixado`); o certificado TLS é verificado contra o nome do servidor. Confirmado contra um servidor real (STARTTLS 587 e SSL 465): nome certo passa, nome errado falha. O texto abaixo é o histórico da decisão anterior (risco aceite), já superada.
- ~~Risco aceite (2026-11-27, decisão do dono): DNS rebinding no SMTP próprio.~~ O servidor é validado (IP público, porta SMTP) ao guardar, ao testar e antes de cada envio; se passar a apontar para a rede interna a escola cai na central. A janela entre a resolução e a ligação não se fecha: o transporte do Laravel não deixa fixar o IP validado mantendo o nome para o TLS, e um transporte próprio custa mais do que o risco (exige uma escola maliciosa que controle o DNS; só sai em portas SMTP; o erro devolvido é curto e sem credenciais). Reavaliar se houver muitas escolas desconhecidas; alternativa barata: lista de fornecedores permitidos.
- **Feito (2026-11-28): tempo de resposta do «esqueci a senha».** O envio corre em `defer()`, depois da resposta, para que com `QUEUE_CONNECTION=sync` só as contas elegíveis não fiquem mais lentas (o piso de `tempo_minimo_ms` já existia, mas o envio SMTP síncrono ultrapassava-o). Lista de perfis mantida só em encarregados, por decisão do dono.
- **Feito depois (2026-11-28):** consumo, subscrição e domínio próprio verificado. Falta só validar o domínio contra o Resend real (precisa de chave e domínio).

## Duas coisas independentes
| | Controla | Depende de |
|---|---|---|
| Contrato `notificacoes` | Se a escola recebe os emails **opcionais** | Do que a escola contratou |
| Infra de envio | **Por onde** saem os emails (central ou própria) | Do que o administrador configurou e verificou |

Ter infra própria não dá direito aos opcionais; contratar não obriga a usar a central. O objectivo da infra própria é
poupar a infra da MosiTec: se a escola a verificou, usa-a.

## Categorias
- **Essencial:** se não chegar, a pessoa perde o acesso ou a segurança (recuperação de senha dos encarregados; avisos de
  segurança: senha ou email alterados, novo login suspeito; convite/criação de acesso e códigos de verificação, se vierem a
  existir). Sai sempre, sem contrato.
- **Opcional:** avisos, cobranças, faltas, notas, comunicados. Só sai com o módulo `notificacoes` contratado.

## Cenários
| Escola | Essenciais | Opcionais |
|---|---|---|
| A: sem contrato, SMTP/domínio próprio verificado | pela infra dela | não saem |
| B: com contrato, sem infra própria | central | central |
| C: com contrato e infra própria verificada | infra dela | infra dela |
| D: sem contrato, sem infra própria | central | não saem |

## Infra própria
- Dois caminhos: **A) domínio verificado** no Resend (a escola cria os registos SPF/DKIM no DNS; recomendado) ou
  **B) SMTP próprio** (servidor, porta, utilizador, senha, encriptação).
- Estados: `por verificar`, `verificado`, `com falha`. Só **verificado** (teste de envio ou verificação DNS aprovada) activa.
- **Autonomia total:** passou no teste, fica activo. Sem aprovação da MosiTec (evita gargalo de suporte).
- Com infra própria verificada, os emails saem por ela **por conta e risco da escola**: sem fallback automático para a central.
  Falha: fica registada e visível ao administrador da escola **e ao super admin** (painel da Plataforma: estado, modo e
  últimas falhas por escola, nunca a senha). A redefinição manual pelo administrador continua a existir.
- Enquanto não verificada, os essenciais saem pela central.
- A escola pode voltar ao central a qualquer momento; a MosiTec pode forçar esse regresso em caso de abuso.
- Configurar a infra própria **não exige** o contrato `notificacoes`.

## Recuperação de senha (só encarregados, para já)
- Só perfis numa lista explícita e configurável (hoje: encarregados). Não incluídos: administrador da escola e perfis com
  poder financeiro ou administrativo; quem tem 2FA não contorna o 2FA por esta via.
- Só com email configurado **e confirmado** pelo encarregado (uma vez, por ligação enviada ao email). A confirmação só condiciona
  a recuperação por email: **nunca bloqueia o uso do sistema**. Encarregado sem email, ou com email por confirmar, usa tudo
  normalmente e, se esquecer a senha, o administrador redefine. Hoje `users.email_verified_at` existe mas nada o preenche.
- O email é único por escola (`unique(tenant_id, email)`), e a validação dos formulários já é filtrada por tenant
  (`VerificadorPresencaTenant`): o mesmo email pode existir em escolas diferentes. O pedido de recuperação tem de identificar
  a escola (domínio do pedido), e o token só vale para o utilizador dessa escola. Resposta sempre genérica, limite de pedidos por IP e por email,
  ligação de uso único e de curta duração, presa ao tenant. Ao concluir: sessões e tokens terminados e aviso «senha alterada».

## Email disponível (instalações sem email, incl. instalação própria)
Caso isolado: escola sem email institucional, fora do servidor da MosiTec (modo `TENANCY_MODO=unico`) e sem conta de email.
Nada do resto do sistema depende de email; os emails vão para a fila e nunca atrasam nem partem a acção do utilizador.
- Conceito de **«email disponível»** por escola: verdadeiro se o canal central está configurado (mailer real + chave) ou se a infra
  própria está verificada. Tudo o que depende de email (recuperação de senha, confirmação de email, notificações) liga-se a ele.
- Sem «email disponível»: esconder «esqueci a senha» e a confirmação de email (não prometer o que não chega) e mostrar ao
  administrador um aviso («o email não está configurado; os encarregados pedem a redefinição ao administrador»).
- Num servidor próprio, «central» é o `.env` dessa instalação, nunca a conta Resend da MosiTec (sem fallback).
- Mailer mal configurado: as falhas ficam registadas e visíveis ao administrador (e ao super admin quando aplicável), não a
  acumular em silêncio em `failed_jobs`.
- **A verificar na primeira instalação própria:** o domínio principal guardado pode ser IP/nome de rede interno; um link de
  email com esse endereço não abre fora da rede da escola. Se os encarregados acedem de fora, o domínio principal tem de ser
  alcançável.

## Destinatário: um contacto, não uma conta
As notificações (sobretudo as opcionais: avisos, cobranças) endereçam-se a um **endereço de email**, nunca exigem que o
destinatário tenha conta na plataforma: um pai sem acesso ao sistema recebe-as na mesma. Só os emails de segurança da conta
(confirmar email, recuperar senha) exigem conta. A infra já o suporta (destinatário avulso, testado). **Por decidir quando
existir o primeiro aviso opcional:** de onde vem o email do encarregado sem conta (hoje o encarregado só existe como
utilizador, ligado ao aluno por `encarregados_alunos`; não há um contacto do encarregado fora de uma conta). Os emails
informativos não devem depender de links para a plataforma quando esta só é acessível dentro da rede da escola.

## Segurança e permissões
- Permissão própria (slug real + Acao + seed), atribuída por defeito ao administrador; a escola pode dá-la a perfis
  personalizados (ex.: «Técnico de TI»). Não é fixa ao perfil administrador.
- Senha SMTP cifrada (Crypt), nunca devolvida ao frontend, ao Inertia nem aos logs; para mudar, escreve-se uma nova.
- Cada alteração vai para o registo de auditoria (quem, quando, o quê), sem a senha.
- Sem teste bem-sucedido não activa.

## Consumo, subscrição e domínio Resend (feito em 2026-11-28)
Desenho em [2026-11-28-email-consumo-subscricao-resend-design.md](2026-11-28-email-consumo-subscricao-resend-design.md). A subscrição é a licença:
o plano define a quota mensal de opcionais, a % de aviso e se bloqueia ao exceder (só chega às escolas na licença seguinte). Consumo em
`consumo_email_mensal`; domínio verificado em `dominios_email` (sem chave Resend real só funciona o cliente simulado, em dev/testes).
O limite técnico por hora (300 essenciais / 1000 opcionais) mantém-se.

## Tabelas
- `configuracoes_email` (nova, só nesta fase): 1:1 com o tenant. Modo, caminho (domínio/SMTP), estado, nome do remetente, dados SMTP
  (senha cifrada), data da última verificação.
- Reply-To actual: `estabelecimentos.email`. Auditoria: tabela de auditoria já existente.

## Infra própria V1: SMTP (aprovado pelo dono em 2026-11-27)
Só SMTP; o caminho por domínio verificado fica para quando houver cliente (precisa da chave Resend real). Serve qualquer servidor SMTP
(Gmail, Outlook, Zoho, servidor próprio, relays como SendGrid/Mailgun/SES por SMTP, servidores internos sem autenticação).

**`configuracoes_email`** (1:1 com `tenants`, `tenant_id` único; autoria por `RegistaAutoria`):
- Ligação: `servidor`, `porta`, `encriptacao` (0 nenhuma, 1 STARTTLS, 2 SSL/TLS implícito) + `encriptacao_descricao`, `utilizador` (opcional),
  `senha` (cifrada, opcional: sem utilizador/senha = servidor sem autenticação), `validar_certificado` (por omissão sim; desligar só para
  certificados autoassinados, com aviso), `dominio_ehlo` (opcional), `tempo_limite` (segundos, 5–30, por omissão 15).
- Remetente: `remetente_nome` (por omissão o nome da escola), `remetente_endereco` (obrigatório), `responder_para` (opcional; por omissão o email do estabelecimento).
- Estado: `estado` (0 por verificar, 1 verificado, 2 com falha) + `estado_descricao`, `activo`, `verificada_em`, `ultimo_erro` (curto, sem segredos).
- Alterar qualquer campo de ligação ou do remetente volta o estado a «por verificar» e desactiva; sem teste bem-sucedido não activa.

**Segurança:** portas só 25, 465, 587, 2525; recusar IPs privados/loopback/link-local (resolvendo o nome do servidor) excepto com `TENANCY_MODO=unico`;
teste de envio limitado a 5/hora por escola; senha nunca devolvida ao frontend, ao Inertia nem aos logs; auditoria sem a senha.

**Envio:** verificada e activa → todos os emails da escola saem pelo servidor dela com o remetente dela (`canal` = infra própria em `envios_email`), sem fallback.
Essenciais saem sem contrato `notificacoes`; opcionais continuam a exigi-lo. Verificada e activa conta para `EmailDisponivel`.

**Permissão:** módulo `configuracao-email` (37; `ver`, `editar`), administrador por defeito. Ecrã `/configuracao-email` no menu Sistema.
**Super admin:** cartão do painel mostra modo e estado por escola; botão «Forçar regresso à central» na página da escola (auditado em `plataforma_auditoria`).
**Fatias:** 1) tabela, modelo, envio, `EmailDisponivel`; 2) ecrã, teste, permissão; 3) super admin.
