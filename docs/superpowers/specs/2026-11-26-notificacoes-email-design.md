# Notificações por email: decisões fechadas (2026-11-26)

## Estado
- **Feito (Fase 1, em stage):** `RemetenteTenant`, `NotificacaoDeTenant`, `CategoriaEmail`, `LimitadorDeEmailsPorTenant`,
  contratos `EnderecoDoTenant` e `ContactoDaEscola`, módulo licenciável `notificacoes`, Resend instalado (mailer `log` em dev).
- **Ainda sem consumidores:** não há emails reais. **Decisão (2026-11-26, revista):** não há «esqueci a senha» geral. A
  recuperação de senha por email existe **só para os encarregados de educação, e só se tiverem email configurado**; os restantes
  perfis continuam a depender do administrador. Perfis a ampliar depois: a decidir (ver «Recuperação de senha»).
- **Por fazer:** configuração de infra própria (abaixo). Espera a chave Resend real e o primeiro cliente interessado.

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

## Segurança e permissões
- Permissão própria (slug real + Acao + seed), atribuída por defeito ao administrador; a escola pode dá-la a perfis
  personalizados (ex.: «Técnico de TI»). Não é fixa ao perfil administrador.
- Senha SMTP cifrada (Crypt), nunca devolvida ao frontend, ao Inertia nem aos logs; para mudar, escreve-se uma nova.
- Cada alteração vai para o registo de auditoria (quem, quando, o quê), sem a senha.
- Sem teste bem-sucedido não activa.

## Adiado (precisa de decisões comerciais)
Subscrição, limites comerciais, consumo e histórico de envios. Hoje só existe o interruptor do módulo `notificacoes` e o limite
técnico por hora (300 essenciais / 1000 opcionais, por escola, em `config/comunicacao.php`).

## Tabelas
- `configuracoes_email` (nova, só nesta fase): 1:1 com o tenant. Modo, caminho (domínio/SMTP), estado, nome do remetente, dados SMTP
  (senha cifrada), data da última verificação.
- Reply-To actual: `estabelecimentos.email`. Auditoria: tabela de auditoria já existente.
