# Email: consumo, subscrição e domínio Resend — desenho

Data: 2026-11-28. Estado: **por aprovar**. Continua [2026-11-26-notificacoes-email-design.md](2026-11-26-notificacoes-email-design.md) (secção «Adiado»).

## Intenção
O dono decidiu em 2026-11-28 não adiar mais consumo, subscrição e domínio Resend. Quer poder cobrar o email por plano, com todas as regras
**configuráveis pelo super admin no plano**, e nunca bloquear os emails essenciais.

## Objectivos
- Quota mensal de emails opcionais por plano, editável no ecrã Planos da Plataforma (sem valores fixos no código).
- Medir o consumo por escola e mês; avisar e, se o plano o disser, bloquear só os opcionais ao exceder.
- Fluxo de verificação de domínio Resend por escola (registos SPF/DKIM), por trás de uma interface, testável sem chave real.

## Não-objectivos
- Cobrança automática de excedente (não há faturação definida).
- Mudar a quota de uma escola já licenciada antes da renovação: **a quota só muda quando comprarem o plano seguinte** (decisão do dono).
- Validar contra o Resend real: precisa de chave e domínio reais; entra só no `.env` quando existirem.

## Anti-objectivos
- Bloquear recuperação de senha ou confirmação de email por consumo.
- Um segundo sistema de planos em paralelo ao Licenciamento.

## Abordagens consideradas
- **A. Estender o Licenciamento (escolhida).** A subscrição é a licença que já existe (estados, renovação, tolerância, ecrã Planos).
  Dá uma só fonte de verdade. Custo: mudar um plano só chega às escolas na renovação — que é exactamente o que o dono quer.
- **B. Planos de email separados.** Mudam na hora, mas duplicam planos e ecrãs e podem ficar incoerentes com a licença. Rejeitada.

## Desenho (abordagem A)

### 1. Regras no plano e na licença
`licenciamento_planos` e `licencas_emitidas` ganham (nulos = sem limite / valores por omissão):
- `limite_emails_mes` (inteiro, nulo = sem limite) — quota mensal de **opcionais**.
- `emails_aviso_pct` (1–100, por omissão 80) — a partir daqui avisa.
- `emails_bloqueia_ao_exceder` (booleano, por omissão verdadeiro) — ao chegar a 100%, bloqueia opcionais; falso = só avisa.

Na licença assinada: `lim.emails_mes` (junto de `alunos`/`utilizadores`) e dois claims novos para o aviso e o bloqueio. O formato continua v1:
chaves desconhecidas já são ignoradas, por isso licenças antigas continuam válidas e ficam **sem limite**.
`EmitirLicencaAction` copia do plano (ou do DTO) como já faz com `limite_alunos`; `ValidaDireitosComerciais` valida os novos campos.
`Recurso` não ganha um caso: `emails_mes` é um fluxo mensal, não um stock como alunos/utilizadores, e não passa por `LimitesDeLicenca`.

### 2. Consumo
Tabela nova `consumo_email_mensal`: `tenant_id`, `mes` (AAAA-MM), `opcionais` (contagem), único por (tenant, mês). Incrementada atomicamente
no mesmo ponto onde o envio é aceite; `envios_email` continua a fonte de verdade e permite recalcular. Só conta opcionais enviados.
Mês civil em UTC.

### 3. Decisão no envio
Um serviço `ConsumoDeEmail` (Core) é consultado a partir do ponto de decisão do `MailerDaEscola`/canal antes de enviar um **opcional**:
- abaixo do aviso: envia;
- ≥ `emails_aviso_pct`: envia e dispara o aviso (uma vez por mês e limiar);
- ≥ 100% e `emails_bloqueia_ao_exceder`: **não envia**, regista a falha em `envios_email` com motivo «quota mensal excedida», dispara aviso de bloqueio (uma vez por mês).
Essenciais nunca consultam a quota. Sem licença/limite (nulo) = comportamento actual. O limite técnico por hora mantém-se.
Em instalação própria (sem tenant) a quota não se aplica.

### 4. Avisos
Notificação ao administrador da escola e linha visível ao super admin (painel e página da escola), nos dois limiares, sem repetir no mês.
Reutiliza `NotificacaoDeTenant` e `plataforma_auditoria`/`envios_email`; o aviso ao admin é essencial (nunca bloqueado pela própria quota).

### 5. Ecrãs
- Plataforma → Planos (`Planos/Form.vue`, `Index.vue`): três campos novos.
- Plataforma → Escola: consumo do mês, quota e estado (barra), e o botão de renovar já existente.
- Escola → Configuração de email: consumo do mês (só leitura) para o administrador.

### 6. Domínio Resend (fatia à parte)
`configuracoes_email` ganha o caminho «domínio verificado»: domínio, estado (`por verificar`/`verificado`/`com falha`), registos DNS devolvidos.
Interface `ClienteDeDominios` com duas implementações: `ClienteResend` (API real, usa `RESEND_API_KEY`) e uma simulada para testes/dev.
Fluxo: escola regista domínio → mostramos SPF/DKIM → botão «Verificar» → só `verificado` activa. Sem fallback para a central (regra existente).
Sem chave real não se valida contra o Resend; fica declarado como não verificado em produção.

## Fatias de entrega
1. Regras no plano/licença + ecrã Planos (migrações, DTO, Actions, validação, Form.vue).
2. Consumo + decisão no envio + avisos.
3. Ecrãs de consumo (Plataforma e escola).
4. Domínio Resend (interface, simulada, ecrã).

## Tabelas e relações
`licenciamento_planos` 1—N `licencas_emitidas` (colunas novas nas duas) · `tenants` 1—N `consumo_email_mensal` (nova) ·
`tenants` 1—1 `configuracoes_email` (colunas de domínio) · `envios_email` (fonte do consumo, sem alteração de esquema, salvo o motivo da falha).

## Deixado ao construtor (decisões minhas, a corrigir se não servirem)
- Mês civil UTC; aviso uma vez por mês e limiar; claims novos com nomes curtos.
- Um único limiar de aviso por plano (não uma lista).
- Uma migração por tabela/alteração (regra do projecto).
