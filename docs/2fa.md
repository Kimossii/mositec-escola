# Autenticação em dois factores (2FA)

**Sempre opcional.** Ninguém é obrigado a activar; quem não activa entra como sempre.

## Como funciona
- Só TOTP (aplicação no telemóvel: Google/Microsoft Authenticator e semelhantes) + códigos de recuperação. Não usa email, SMS nem push, por isso **funciona offline**.
- Activar/desactivar: Definições → Segurança → «Autenticação em dois factores» (pede a senha; ler o QR; confirmar com um código; guardar os códigos de recuperação).
- Login web: depois da senha, quem tem 2FA vai para `/two-factor-challenge` (código da aplicação ou de recuperação). A sessão só abre depois disso. Limite de 5 tentativas por minuto (limitador `two-factor`).
- Login API: `POST /api/v1/autenticacaoApi/api/login` devolve `202 {requer_2fa, desafio}` (sem token) para quem tem 2FA. O `desafio` vale 5 minutos, é de uso único, é por escola e só o seu hash fica na cache. `POST /api/v1/autenticacaoApi/api/2fa` com `{desafio, code}` ou `{desafio, recovery_code}` devolve o token. 5 códigos errados por minuto → 429.
- Cada código de recuperação só funciona uma vez.

## Reposição (perdeu a aplicação e os códigos)
- `ADMIN_ESCOLA` (ou quem tem `senha-utilizador.editar`) → lista de utilizadores → «Repor 2FA». Nunca o próprio. Termina tokens e sessões do alvo.
- Último administrador bloqueado: `php artisan mosi:tenant:admin:reset` (`RecuperarAdministradorAction`) repõe a senha **e** limpa o 2FA.

## Relógio do servidor
O TOTP depende da hora. Offline não há fonte de hora de referência para o sistema se auto-vigiar, por isso: manter o relógio do servidor certo (NTP quando houver rede, ou acerto manual). O Fortify aceita uma pequena janela de tolerância (`window` em `config/fortify.php`).

## Fora desta fase
Painel de super admin (Fase 2, também opcional) e ecrã de Auditoria. Ver `docs/superpowers/specs/2026-11-22-2fa-fase1-design.md`.
