# Licenciamento — design

Estado: RASCUNHO para aprovação do dono. Nada implementado.

## Intenção

A MosiTec precisa de controlar o direito de uso do MosiTec Escola em três modalidades de instalação (Online/SaaS, Local, Híbrido) com **um único módulo e um único formato de licença**. A licença é um instrumento comercial e contratual, não apenas um interruptor.

## Objectivos

- Um módulo `Licenciamento`, separado do `Tenant`.
- Licença = direitos concedidos a uma escola por um período; **Plano** = catálogo do que se pode contratar. A licença guarda uma cópia dos direitos na emissão (alterar o plano depois não altera licenças emitidas).
- Formato único: documento assinado com Ed25519 (`sodium_crypto_sign_*`, sem pacote novo), com `versao_formato` e `kid` (id da chave, permite rotação). Chave privada só no servidor da MosiTec; chave pública distribuída com o código.
- Licença funciona offline (verificação local). A sincronização online é só transporte (renovar/revogar).
- Limites (alunos, utilizadores, estabelecimentos, módulos) aplicados na camada de negócio (Actions), dentro da transacção que cria o registo.
- Estado comercial da licença **separado** do estado da escola (`Tenant.estado`).

## Não-objectivos / anti-objectivos

- Protecção absoluta contra um cliente que edita o código: impossível; o contrato é a protecção forte.
- Sincronização de dados e cópias na nuvem do modo Híbrido: projecto à parte, fora do âmbito. No licenciamento, Híbrido = renovação/revogação por API.
- Entidade "Contrato" própria: fora da V1 (basta histórico de renovações e alterações).
- A licença nunca deve fazer a escola perder acesso aos seus dados sem o administrador ter exportação.

## Decisões do dono (fechadas)

1. **Expirou (e a tolerância acabou) → bloqueia tudo.**
2. **Administrador (`Perfil::ADMIN_ESCOLA`)**: entra, mas só acede às excepções e é enviado para a página de licença (estado, renovar, contacto). **Excepção mantida: exportação de dados** (decisão consciente, risco contratual/legal).
3. **Todos os outros perfis, incluindo aluno**: resposta genérica HTTP 503 "Ocorreu um erro. Tente mais tarde." Sem pistas no texto, cabeçalhos ou nome de rota. Cobre web, API e sessões já abertas (expulsos no pedido seguinte). **Sem redireccionamento para nenhuma página: basta um toast genérico** com essa mensagem.
4. **Super Admin da Plataforma**: não afectado.
5. **Tolerância configurável, nunca em cadeia** (ver abaixo).
6. Tarefas agendadas e filas (backups, saúde, etc.) **não** param; só pedidos de utilizadores são bloqueados.

## Tolerância (regra anti-"mais 30 dias")

- `tolerancia_dias` é um campo **dentro da licença assinada**, definido pelo Super Admin na emissão (0 permitido). Valor por omissão vem de configuração da plataforma, sobreponível por licença.
- A janela conta **uma só vez**, a partir de `expira_em`: `bloqueia_em = expira_em + tolerancia_dias`. Não é renovável pelo cliente nem se repete.
- Como está assinada, o cliente não a pode alterar. Qualquer prolongamento exige **nova licença emitida pela MosiTec** (extensão de cortesia), registada no histórico com motivo e autor (auditoria). Cada extensão é um acto explícito do vendedor, nunca automático.
- Durante a tolerância só o administrador vê avisos (crescentes); os restantes perfis não notam nada.

## Modos de operação (derivados, não persistidos como estado da escola)

| Modo | Condição | Efeito |
|---|---|---|
| Normal | válida, longe de expirar | nenhum |
| Aviso | a `aviso_dias` de expirar, ou já em tolerância | faixa só para o administrador |
| Bloqueado | `agora > bloqueia_em`, licença inválida/adulterada/revogada | regras 1–3 acima |

Licença `EXPIRADA` + escola `ACTIVA` + instalação `OPERACIONAL` coexistem. `Tenant.estado=SUSPENSO` fica para decisão manual (abuso); não é usado pela expiração.

## Limites (separados do bloqueio da escola)

Verificados nas Actions (`matricular`, criar utilizador, criar estabelecimento, activar módulo) por um serviço único (ex.: `Licenca::garantirLimite('alunos')`), dentro da transacção. Excedido → recusa só aquela acção com mensagem clara ao administrador. Módulos não contratados → rotas/menus indisponíveis.

## Esquema conceptual

- `planos`: nome, limites e módulos por omissão, `tolerancia_dias` por omissão.
- `licencas`: id, tenant (ou instalação), plano de origem, estado comercial (activa/suspensa/expirada/cancelada), emitida_em, inicio, expira_em, tolerancia_dias, limites (alunos, utilizadores, estabelecimentos), módulos, modalidade (online/local/hibrido), instalacao_id, documento assinado, kid.
- `licenca_eventos` (append-only): emissão, renovação, extensão, suspensão, revogação, alteração de limites; autor, motivo.
- `instalacoes` (Fases 3–4).
Uma tabela por migração.

## Formato da licença e código da instalação (decisões do dono)

- **PASETO v4.public** (`paragonie/paseto`, Ed25519 via ext-sodium) como formato do documento assinado; sem negociação de algoritmo. Antes de fixar, verificar no plano que o pacote está mantido e é compatível com PHP 8.4; se não, fallback JWT EdDSA (`firebase/php-jwt`) com algoritmo fixado. Soluções de licenciamento prontas (serviços/pacotes) rejeitadas: dependem de online/serviço externo ou não encaixam no multi-tenant e nas regras de bloqueio.
- **Código da instalação**: identificador aleatório gerado na primeira execução e guardado na instalação; o administrador copia-o da página Licença e envia-o ao vendedor. Só identifica, não autoriza. Não é um hash: a licença é um documento assinado que **contém** esse código; a instalação verifica a assinatura com a chave pública e que o código do documento é o seu.
- **Ligação à instalação**: obrigatória por omissão no self-hosted; desligável por licença para clientes de confiança que mudam de servidor.
- **Entrega**: o administrador pode **carregar o ficheiro ou colar o texto** da licença.
- O que as libs não resolvem fica por nossa conta: modos, tolerância, limites, relógio, gate.

## Duas metades do módulo

- **Emissor** (gera/assina/renova): só opera onde existe a chave privada (servidor MosiTec). Nunca no `.env` nem no repositório distribuído. O Super Admin local de uma instalação self-hosted **não emite**; só **importa** o ficheiro.
- **Verificador/aplicador**: em todas as instalações; valida assinatura, calcula o modo, aplica limites e o gate de pedidos.

## Gate de pedidos

Um único middleware de pedidos de utilizadores decide o modo, com excepções explícitas (lista curta: página de licença, exportação, logout, recursos estáticos). Testável isoladamente. Super Admin e comandos/filas ficam fora.

## Anti-adulteração do relógio (Fase 3)

Guardar o maior instante já visto; relógio que recua = suspeito (tratado como bloqueado após limite). Quando houver internet, usar carimbo de tempo do servidor central.

## Painel Super Admin (módulo Plataforma)

Planos, Licenças (emitir, renovar, estender, suspender, cancelar, descarregar ficheiro assinado), Módulos/Limites, Renovações e próximas expirações, Auditoria. "Instalações" a partir da Fase 3.

## Abordagens consideradas

1. Licença em campos do Tenant: rápida, mas mistura responsabilidades e obriga a refazer para Local/Híbrido. Rejeitada.
2. Dois mecanismos (SaaS por verificação central, Local por ficheiro): duplica lógica. Rejeitada.
3. **Escolhida:** documento assinado único para as três modalidades; fases acrescentam só transporte.

## Fases

1. Fundação: planos, licenças, eventos, formato assinado, verificador, modos, limites nas Actions, gate, ecrã de Super Admin, página de licença do administrador, exportação como excepção.
2. SaaS completo: avisos, renovação, histórico.
3. Self-hosted: importação, instalação, anti-recuo do relógio.
4. Híbrido: API de renovação/revogação, tolerância sem conectividade.

## A confirmar / a cargo do construtor

- Valor por omissão de `tolerancia_dias` e de `aviso_dias` (proposta: configuráveis; o dono decide números).
- Âmbito exacto da "exportação" disponível ao administrador em modo bloqueado (proposta: os exports já existentes dos módulos, sem edição).
- Como a licença do SaaS se liga ao Tenant (1 tenant = 1 escola) e, no self-hosted, à instalação.
- Perfil de administrador = `Perfil::ADMIN_ESCOLA` (Modules/Permissao/app/Enums/Perfil.php).
