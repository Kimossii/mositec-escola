<?php

namespace Modules\Usuario\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Log;
use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
use Modules\Core\Traits\LogService;

class UsuarioController extends Controller
{
    use LogService;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('usuario::index');
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(CriarUsuarioRequest $request, UsuarioAction $action)
    {
        $dto = UsuarioDTO::fromArray($request->validated());
        $usuario = $action->criar($dto);
        $this->logInfo('Utilizador criado', ['usuario_id' => $usuario->id]);
        return response()->json([
            'message' => 'Usuário criado com sucesso',
            'data' => $usuario
        ], 201);
    }

}
