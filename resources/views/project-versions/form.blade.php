@php
    $snapshot = $snapshot ?? [];
    $contents = $snapshot['contents'] ?? [];
    $frameworks = $snapshot['frameworks'] ?? [];
    $participants = $snapshot['participants'] ?? ['professors' => [], 'students' => []];
@endphp

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Resumen de la version</h3>
            </div>
            <div class="card-body">
                <dl class="row g-3 mb-0">
                    <dt class="col-sm-4">Titulo</dt>
                    <dd class="col-sm-8">{{ $snapshot['title'] ?? $project->title }}</dd>

                    <dt class="col-sm-4">Estado</dt>
                    <dd class="col-sm-8">{{ data_get($snapshot, 'project_status.name', 'Sin estado') }}</dd>

                    <dt class="col-sm-4">Area tematica</dt>
                    <dd class="col-sm-8">{{ data_get($snapshot, 'thematic_area.name', 'No definida') }}</dd>

                    <dt class="col-sm-4">Linea de investigacion</dt>
                    <dd class="col-sm-8">{{ data_get($snapshot, 'investigation_line.name', 'No definida') }}</dd>

                    @if (!empty($snapshot['evaluation_criteria']))
                        <dt class="col-sm-4">Criterios de evaluacion</dt>
                        <dd class="col-sm-8 text-prewrap">{{ $snapshot['evaluation_criteria'] }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Contenidos registrados</h3>
                <span class="badge bg-primary">{{ count($contents) }}</span>
            </div>
            <div class="card-body">
                @if (count($contents))
                    <dl class="row g-3 mb-0">
                        @foreach ($contents as $label => $value)
                            <dt class="col-sm-4">{{ $label }}</dt>
                            <dd class="col-sm-8 text-prewrap">{{ $value }}</dd>
                        @endforeach
                    </dl>
                @else
                    <p class="text-secondary mb-0">Esta version no tiene contenidos registrados.</p>
                @endif
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Marcos aplicados</h3>
                <span class="badge bg-primary">{{ count($frameworks) }}</span>
            </div>
            <div class="card-body">
                @if (count($frameworks))
                    <div class="row g-3">
                        @foreach ($frameworks as $framework)
                            <div class="col-12">
                                <div class="fw-semibold">{{ data_get($framework, 'framework.name', 'Marco') }}</div>
                                <div class="text-secondary small">{{ $framework['name'] ?? 'Sin contenido de marco' }}</div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-secondary mb-0">Esta version no registra marcos aplicados.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Profesores</h3>
                <span class="badge bg-primary">{{ count($participants['professors'] ?? []) }}</span>
            </div>
            <div class="card-body">
                @forelse (($participants['professors'] ?? []) as $professor)
                    <div class="mb-3">
                        <div class="fw-semibold">{{ $professor['name'] ?? 'Profesor' }}</div>
                        <div class="text-secondary small">{{ $professor['email'] ?? 'Correo no disponible' }}</div>
                    </div>
                @empty
                    <p class="text-secondary mb-0">Sin profesores asociados.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Estudiantes</h3>
                <span class="badge bg-primary">{{ count($participants['students'] ?? []) }}</span>
            </div>
            <div class="card-body">
                @forelse (($participants['students'] ?? []) as $student)
                    <div class="mb-3">
                        <div class="fw-semibold">{{ $student['name'] ?? 'Estudiante' }}</div>
                        <div class="text-secondary small">Documento: {{ $student['card_id'] ?? 'No disponible' }}</div>
                    </div>
                @empty
                    <p class="text-secondary mb-0">Sin estudiantes asociados.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
