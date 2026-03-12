@extends('tablar::page')

@section('title', 'Version de solo lectura')

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
                            <li class="breadcrumb-item"><a href="{{ route('projects.versions.index', $project) }}">Historial</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Solo lectura</li>
                        </ol>
                    </nav>
                    <h2 class="page-title">Las versiones no se editan manualmente</h2>
                    <p class="text-muted mb-0">La version #{{ $version->id }} permanece como evidencia historica del proyecto.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            <div class="card">
                <div class="card-body">
                    <p class="mb-3">Para generar una nueva version debes editar el proyecto. El sistema conservara esta version y creara una nueva entrada en el historial.</p>
                    <div class="btn-list">
                        <a href="{{ route('projects.versions.show', [$project, $version]) }}" class="btn btn-primary">Ver detalle de la version</a>
                        <a href="{{ route('projects.edit', $project) }}" class="btn btn-outline-secondary">Editar proyecto</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
