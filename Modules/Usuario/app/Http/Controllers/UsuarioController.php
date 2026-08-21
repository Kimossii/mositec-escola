<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Log;
use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
class UsuarioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return \Inertia\Inertia::render('Usuario/Index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('usuario::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CriarUsuarioRequest $request, UsuarioAction $action)
    {
        $dto = UsuarioDTO::fromArray($request->validated());
        $usuario = $action->criar($dto);
        Log::info('Usuário criado: ' . $usuario->email, ['usuario' => $dto]);
        return response()->json([
            'message' => 'Usuário criado com sucesso',
            'data' => $usuario
        ], 201);
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('usuario::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('usuario::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
    }
}
