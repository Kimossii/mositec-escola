<?php

namespace Modules\Licenciamento\Models;

use Illuminate\Database\Eloquent\Model;

/** Identidade desta instalação. Ver CodigoDaInstalacao. */
class Instalacao extends Model
{
    protected $table = 'licenciamento_instalacao';

    protected $fillable = ['codigo'];
}
