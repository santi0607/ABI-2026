@extends('tablar::page')

@section('title', 'Historial de versiones')

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
                            <li class="breadcrumb-item active" aria-current="page">Historial</li>
                        </ol>
                    </nav>
                    <h2 class="page-title d-flex align-items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg me-2 text-primary" width="32" height="32" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M12 8l0 4l2 2" />
                            <path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5" />
                        </svg>
                        Historial de versiones
                        <span class="badge bg-primary ms-2">{{ $totalVersions }}</span>
                    </h2>
                    <p class="text-muted mb-0">Consulta las versiones registradas para <strong>{{ $project->title }}</strong>.</p>
                </div>
                <div class="col-auto ms-auto d-print-none">
                    <a href="{{ route('projects.show', $project) }}" class="btn btn-outline-secondary">Volver al proyecto</a>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            @if(config('tablar.display_alert'))
                @include('tablar::common.alert')
            @endif

            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="text-secondary small">Titulo actual</div>
                            <div class="fw-semibold">{{ $project->title }}</div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="text-secondary small">Estado actual</div>
                            <div class="fw-semibold">{{ $project->projectStatus->name ?? 'Sin estado' }}</div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="text-secondary small">Area tematica</div>
                            <div class="fw-semibold">{{ $project->thematicArea->name ?? 'No definida' }}</div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="text-secondary small">Linea de investigacion</div>
                            <div class="fw-semibold">{{ $project->thematicArea->investigationLine->name ?? 'No definida' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Listado de versiones</h3>
                    <div class="card-actions">
                        <span class="badge bg-primary-lt">{{ $versions->total() }} visibles</span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table card-table table-vcenter align-middle">
                        <thead>
                            <tr>
                                <th class="w-1">Version</th>
                                <th>Fecha</th>
                                <th>Registrada por</th>
                                <th>Estado</th>
                                <th>Resumen</th>
                                <th class="w-1 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($versions as $version)
                                <tr>
                                    <td><span class="badge bg-primary-lt">V{{ $version->history_number }}</span></td>
                                    <td>{{ optional($version->created_at)->format('d/m/Y H:i') }}</td>
                                    <td>{{ $version->history_author }}</td>
                                    <td>{{ $version->history_status }}</td>
                                    <td>
                                        <div class="text-secondary small">{{ $version->history_contents_count }} contenidos y {{ $version->history_frameworks_count }} marcos.</div>
                                    </td>
                                    <td>
                                        <div class="btn-list flex-nowrap justify-content-center">
                                            <a href="{{ route('projects.versions.show', [$project, $version]) }}" class="btn btn-sm btn-outline-primary" title="Ver detalle">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="2" />
                                                    <path d="M22 12c-2.667 4.667-6 7-10 7s-7.333-2.333-10-7c2.667-4.667 6-7 10-7s7.333 2.333 10 7" />
                                                </svg>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">
                                        <div class="empty py-5">
                                            <p class="empty-title">Este proyecto todavia no tiene historial de versiones.</p>
                                            <p class="empty-subtitle text-secondary">La primera version se crea al registrar el proyecto y las siguientes al editarlo.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($versions->hasPages())
                    <div class="card-footer d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                        <div class="text-muted small">Mostrando {{ $versions->firstItem() ?? 0 }}-{{ $versions->lastItem() ?? 0 }} de {{ $versions->total() }} registros</div>
                        <nav aria-label="Paginacion del historial de versiones">
                            {{ $versions->withQueryString()->onEachSide(1)->links('pagination::bootstrap-5') }}
                        </nav>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
