# 2FA Fase 1 (mundo da escola) — design curto

Estado: aprovado pelo dono em 2026-11-22.

## Intenção
Dar a quem quiser uma camada extra de segurança no login da escola, sem nunca a impor. Funciona offline (só TOTP).

## Objectivos
- Activar/desactivar 2FA em Definições (senha + QR + confirmação por código + códigos de recuperação).
- Desafio no login só para quem activou; os restantes entram como hoje.
- Login por API (Sanctum) com segundo passo de código.
- Reposição: `ADMIN_ESCOLA` repõe o de outros (nunca o próprio); comando `RecuperarAdministrador` limpa o do último administrador.
- Aviso do `laravel-health` se o relógio do servidor estiver desfasado.
- Banner dispensável em Definições a sugerir 2FA a perfis de administrador (decisão minha, removível).

## Não-objectivos / anti-objectivos
- **Nunca obrigatório**: sem middleware de bloqueio, sem 403 forçado, sem lista de perfis obrigatórios.
- Sem email/SMS/push (dependem de rede).
- Fora: painel de super admin (Fase 2, também opcional) e ecrã de Auditoria.

## Restrições
- Fortify já tem `Features::twoFactorAuthentication(confirm, confirmPassword)` e as colunas em `users` existem; falta UI, ligação ao login e API.
- Limitador `two-factor` já existe em `FortifyServiceProvider`.
- Controllers finos (Service/Action), uma tabela por migração, `use` em vez de FQN, Cancelar em vermelho, reaproveitar padrões visuais.
- Testar o fluxo real do ecrã e da API com payloads do Vue, não só Actions.

## Abordagens consideradas
1. **Fase 1 escola, Auditoria, Fase 2 super admin** (escolhida): valor mais cedo, Fase 2 menor.
2. Começar pelo super admin: protege a conta mais crítica mas exige fluxo TOTP novo sem ganho imediato.
3. 2FA obrigatório para `ADMIN_ESCOLA`: rejeitado pelo dono (risco de bloqueio, offline).

## Deixado ao implementador
Estrutura exacta das páginas Inertia, nomes de rotas, forma do JSON do segundo passo da API, formato do aviso de relógio.
