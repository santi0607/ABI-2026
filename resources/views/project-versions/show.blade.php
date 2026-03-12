@extends('tablar::page')

@section('title', 'Detalle de version')

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
                            <li class="breadcrumb-item active" aria-current="page">Version {{ $historyNumber }}</li>
                        </ol>
                    </nav>
                    <h2 class="page-title d-flex align-items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg me-2 text-primary" width="32" height="32" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M6 4h12" />
                            <path d="M6 8h12" />
                            <path d="M6 12h8" />
                            <path d="M6 16h8" />
                            <path d="M6 20h12" />
                        </svg>
                        Version {{ $historyNumber }} de {{ $totalVersions }}
                    </h2>
                    <p class="text-muted mb-0">Revision registrada el {{ optional($version->created_at)->format('d/m/Y H:i') }} por {{ $authorLabel }}.</p>
                </div>
                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <a href="{{ route('projects.versions.index', $project) }}" class="btn btn-outline-secondary">Volver al historial</a>
                        <a href="{{ route('projects.show', $project) }}" class="btn btn-primary">Ir al proyecto</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="text-secondary small">Version interna</div>
                            <div class="fw-semibold">#{{ $version->id }}</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="text-secondary small">Autor del cambio</div>
                            <div class="fw-semibold">{{ $authorLabel }}</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="text-secondary small">Proyecto</div>
                            <div class="fw-semibold">{{ $project->title }}</div>
                        </div>
                    </div>
                </div>
            </div>

            @include('project-versions.form', ['project' => $project, 'snapshot' => $snapshot])
        </div>
    </div>
@endsection

@push('css')
    <style>
        .text-prewrap {
            white-space: pre-wrap;
        }
    </style>
@endpush
