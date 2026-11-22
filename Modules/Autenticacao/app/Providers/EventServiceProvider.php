<?php

namespace Modules\Autenticacao\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Modules\Autenticacao\Listeners\BloquearContaInactivaNoDesafio;
use Modules\Autenticacao\Listeners\RegistarEventoDoisFactores;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        TwoFactorAuthenticationEnabled::class => [RegistarEventoDoisFactores::class],
        TwoFactorAuthenticationConfirmed::class => [RegistarEventoDoisFactores::class],
        TwoFactorAuthenticationDisabled::class => [RegistarEventoDoisFactores::class],
        RecoveryCodeReplaced::class => [RegistarEventoDoisFactores::class],
        ValidTwoFactorAuthenticationCodeProvided::class => [BloquearContaInactivaNoDesafio::class],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = false;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
