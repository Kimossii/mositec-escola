<?php

use Illuminate\Support\Facades\Route;
use Modules\Docente\Http\Controllers\AreaProfessorController;
use Modules\Docente\Http\Controllers\AtribuicaoDocenteController;
use Modules\Docente\Http\Controllers\DocenteController;

Route::middleware(['auth'])->prefix('docentes/atribuicoes')->name('atribuicoes-docentes.')->group(function () {
    Route::get('/', [AtribuicaoDocenteController::class, 'index'])->middleware('can:atribuicao-docente.ver')->name('index');
    Route::get('/exportar', [AtribuicaoDocenteController::class, 'exportar'])->middleware(['can:atribuicao-docente.exportar', 'throttle:operacao-pesada'])->name('exportar');
    Route::post('/', [AtribuicaoDocenteController::class, 'store'])->middleware('can:atribuicao-docente.criar')->name('store');
    Route::post('/substituir', [AtribuicaoDocenteController::class, 'substituir'])->middleware('can:atribuicao-docente.criar')->name('substituir');
    Route::put('/{atribuicao}', [AtribuicaoDocenteController::class, 'update'])->middleware('can:atribuicao-docente.editar')->name('update');
    Route::patch('/{atribuicao}/encerrar', [AtribuicaoDocenteController::class, 'encerrar'])->middleware('can:atribuicao-docente.encerrar')->name('encerrar');
    Route::delete('/{atribuicao}', [AtribuicaoDocenteController::class, 'destroy'])->middleware('can:atribuicao-docente.eliminar')->name('destroy');
});

// Depois das de atribuições: `/docentes/atribuicoes…` nunca é um docente (`{docente}` só aceita ids numéricos).
Route::middleware(['auth'])->prefix('docentes')->name('docentes.')->group(function () {
    Route::get('/', [DocenteController::class, 'index'])->middleware('can:docente.ver')->name('index');
    Route::get('/exportar', [DocenteController::class, 'exportar'])->middleware(['can:docente.exportar', 'throttle:operacao-pesada'])->name('exportar');
    Route::post('/', [DocenteController::class, 'store'])->middleware('can:docente.criar')->name('store');
    Route::put('/{docente}', [DocenteController::class, 'update'])->whereNumber('docente')->middleware('can:docente.editar')->name('update');
    Route::patch('/{docente}/estado', [DocenteController::class, 'alterarEstado'])->whereNumber('docente')->middleware('can:docente.editar')->name('alterar-estado');
    // docente.editar na rota; usuario.criar também, no CriarAcessoDocenteRequest.
    Route::post('/{docente}/acesso', [DocenteController::class, 'criarAcesso'])->whereNumber('docente')->middleware('can:docente.editar')->name('acesso');
    Route::delete('/{docente}', [DocenteController::class, 'destroy'])->whereNumber('docente')->middleware('can:docente.eliminar')->name('destroy');
});

// Área do Professor: consulta das turmas/disciplinas que o utilizador lecciona hoje e dos respectivos alunos.
Route::middleware(['auth', 'can:area-professor.ver'])->prefix('professor/turmas')->name('area-professor.')->group(function () {
    Route::get('/', [AreaProfessorController::class, 'index'])->name('index');
    Route::get('/{turma}/disciplinas/{disciplina}/alunos', [AreaProfessorController::class, 'alunos'])->whereNumber(['turma', 'disciplina'])->name('alunos');
});

// Lista única dos alunos do professor e ficha limitada: só alunos das suas turmas/disciplinas em vigor.
Route::middleware(['auth', 'can:area-professor.ver'])->prefix('professor/alunos')->name('area-professor.alunos-')->group(function () {
    Route::get('/', [AreaProfessorController::class, 'meusAlunos'])->name('index');
    Route::get('/{aluno}/foto', [AreaProfessorController::class, 'foto'])->whereNumber('aluno')->name('foto');
    Route::get('/{aluno}', [AreaProfessorController::class, 'ficha'])->whereNumber('aluno')->name('ficha');
});
