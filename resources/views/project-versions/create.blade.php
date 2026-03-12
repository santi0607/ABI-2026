@extends('tablar::page')

@section('title', 'Versiones automaticas')

@section('content')
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="{{ route('home') }}">Inicio</a></li>
                            <li class="breadcrumb-item"><a href="{{ route('projects.index') }}">Proyectos</a></li>
                            <li class="breadcrumb-item"><a href="{{ route('projects.show', $project) }}">Proyecto #{{ $project->id }}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Generacion de versiones</li>
                        </ol>
                    </nav>
                    <h2 class="page-title">Versiones automaticas del proyecto</h2>
                    <p class="text-muted mb-0">Las versiones de este modulo no se crean manualmente.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            <div class="card">
                <div class="card-body">
                    <p class="mb-3">Cada vez que el proyecto <strong>{{ $project->title }}</strong> se crea o se actualiza, el sistema genera una nueva version de forma automatica y la agrega al historial.</p>
                    <div class="btn-list">
                        <a href="{{ route('projects.versions.index', $project) }}" class="btn btn-primary">Ver historial</a>
                        <a href="{{ route('projects.show', $project) }}" class="btn btn-outline-secondary">Volver al proyecto</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
