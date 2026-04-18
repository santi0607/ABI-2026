<?php

namespace App\Http\Controllers;

use App\Helpers\AuthUserHelper;
use App\Models\Professor;
use App\Models\Project;
use App\Models\Student;
use App\Models\User;
use App\Models\Version;
use Illuminate\View\View;

/**
 * Exposes the immutable version history of each project idea in the idea bank.
 *
 * Versions are never created or edited manually here. This controller only reads
 * the snapshots generated automatically when an author submits or corrects an idea.
 */
class ProjectVersionController extends Controller
{
    /**
     * Shows the paginated version history for a project.
     */
    public function index(Project $project): View
    {
        $user = AuthUserHelper::fullUser();
        $this->authorizeHistoryAccess($project, $user);

        // Load the project context once so the history view can render academic data
        // and participants without issuing extra queries from Blade.
        $project->load([
            'projectStatus',
            'thematicArea.investigationLine',
            'professors.user',
            'students.user',
        ]);

        $totalVersions = Version::query()->where('project_id', $project->id)->count();

        $versions = Version::query()
            ->with(['createdBy.professor', 'createdBy.student', 'createdBy.researchstaff'])
            ->where('project_id', $project->id)
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        // Enrich every item with precomputed metadata so the Blade template can render
        // a readable timeline without recomputing author, status, or counters.
        $versions->setCollection(
            $versions->getCollection()->values()->map(function (Version $version, int $index) use ($versions, $project, $totalVersions) {
                $position = (($versions->currentPage() - 1) * $versions->perPage()) + $index;
                $snapshot = $this->resolveSnapshot($project, $version);

                // Keep a continuous human-friendly numbering even when the list is paginated.
                $version->history_number = max($totalVersions - $position, 1);
                $version->history_author = $this->resolveAuthorLabel($version);
                $version->history_status = data_get($snapshot, 'project_status.name', 'Sin estado');
                $version->history_contents_count = count($snapshot['contents'] ?? []);
                $version->history_frameworks_count = count($snapshot['frameworks'] ?? []);

                return $version;
            })
        );

        return view('project-versions.index', [
            'project' => $project,
            'versions' => $versions,
            'totalVersions' => $totalVersions,
        ]);
    }

    /**
     * Shows an informational screen because versions are generated automatically.
     */
    public function create(Project $project): View
    {
        $user = AuthUserHelper::fullUser();
        $this->authorizeHistoryAccess($project, $user);

        // Keep the REST resource shape intact while making it explicit that versions
        // are created indirectly from project submissions and corrections.
        $project->load(['projectStatus', 'thematicArea.investigationLine']);

        return view('project-versions.create', [
            'project' => $project,
        ]);
    }

    /**
     * Shows the full snapshot stored for a specific project version.
     */
    public function show(Project $project, Version $version): View
    {
        $user = AuthUserHelper::fullUser();
        $this->authorizeHistoryAccess($project, $user);

        // Ensure the requested version actually belongs to the project in the URL.
        if ((int) $version->project_id !== (int) $project->id) {
            abort(404);
        }

        $project->load([
            'projectStatus',
            'thematicArea.investigationLine',
            'professors.user',
            'students.user',
            'contentFrameworks.framework',
        ]);

        $version->load(['createdBy.professor', 'createdBy.student', 'createdBy.researchstaff', 'contentVersions.content']);

        // Always consume a unified snapshot shape so the view does not care whether
        // the data comes from a stored JSON snapshot or reconstructed legacy records.
        $snapshot = $this->resolveSnapshot($project, $version);
        $totalVersions = Version::query()->where('project_id', $project->id)->count();
        $historyNumber = Version::query()
            ->where('project_id', $project->id)
            ->where('id', '<=', $version->id)
            ->count();

        return view('project-versions.show', [
            'project' => $project,
            'version' => $version,
            'snapshot' => $snapshot,
            'totalVersions' => $totalVersions,
            'historyNumber' => $historyNumber,
            'authorLabel' => $this->resolveAuthorLabel($version),
        ]);
    }

    /**
     * Shows an informational screen because versions are read-only.
     */
    public function edit(Project $project, Version $version): View
    {
        $user = AuthUserHelper::fullUser();
        $this->authorizeHistoryAccess($project, $user);

        // Apply the same ownership guard used in show() so unrelated versions cannot be opened.
        if ((int) $version->project_id !== (int) $project->id) {
            abort(404);
        }

        return view('project-versions.edit', [
            'project' => $project,
            'version' => $version,
        ]);
    }

    /**
     * Allows history access to any authenticated user who can reach the project detail.
     */
    protected function authorizeHistoryAccess(Project $project, ?User $user): void
    {
        // For now authentication is enough. Keeping the check isolated makes it easy
        // to centralize stricter role- or author-based rules later.
        if (! $user) {
            abort(403);
        }
    }

    /**
     * Builds a snapshot shape that works for both legacy and current versions.
     */
    protected function resolveSnapshot(Project $project, Version $version): array
    {
        // Newer versions store a complete immutable JSON snapshot. When present, it is
        // the source of truth because it matches the exact state submitted at that moment.
        if (is_array($version->snapshot) && ! empty($version->snapshot)) {
            return $version->snapshot;
        }

        // Backward compatibility: rebuild older versions from their section rows so
        // historical traceability is preserved even before JSON snapshots existed.
        $contents = $version->relationLoaded('contentVersions')
            ? $version->contentVersions
            : $version->contentVersions()->with('content')->get();

        return [
            'title' => $project->title,
            'evaluation_criteria' => $project->evaluation_criteria,
            'project_status' => [
                'id' => $project->projectStatus?->id,
                'name' => $project->projectStatus?->name,
            ],
            'thematic_area' => [
                'id' => $project->thematicArea?->id,
                'name' => $project->thematicArea?->name,
            ],
            'investigation_line' => [
                'id' => $project->thematicArea?->investigationLine?->id,
                'name' => $project->thematicArea?->investigationLine?->name,
            ],
            'contents' => $contents
                ->filter(static fn ($contentVersion) => $contentVersion->content !== null)
                ->mapWithKeys(function ($contentVersion) {
                    return [$this->contentDisplayName($contentVersion->content->name) => $contentVersion->value];
                })
                ->toArray(),
            // Older versions did not store frameworks in the snapshot, so reuse the
            // current project relation to keep the methodological context visible.
            'frameworks' => $project->contentFrameworks
                ->map(function ($contentFramework) {
                    return [
                        'id' => $contentFramework->id,
                        'name' => $contentFramework->name,
                        'framework' => [
                            'id' => $contentFramework->framework?->id,
                            'name' => $contentFramework->framework?->name,
                        ],
                    ];
                })
                ->values()
                ->all(),
            'participants' => [
                'professors' => $project->professors
                    ->map(function (Professor $professor) {
                        return [
                            'id' => $professor->id,
                            'name' => trim(($professor->name ?? '') . ' ' . ($professor->last_name ?? '')),
                            'email' => $professor->mail ?? $professor->user?->email,
                        ];
                    })
                    ->values()
                    ->all(),
                'students' => $project->students
                    ->map(function (Student $student) {
                        return [
                            'id' => $student->id,
                            'name' => trim(($student->name ?? '') . ' ' . ($student->last_name ?? '')),
                            'card_id' => $student->card_id,
                        ];
                    })
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Presents readable labels even when the catalog was stored without accents.
     */
    protected function contentDisplayName(?string $name): string
    {
        $normalizedName = str($name)->ascii()->lower()->replace('-', ' ')->squish()->toString();

        return [
            'titulo' => 'Titulo',
            'cantidad de estudiantes' => 'Cantidad de estudiantes',
            'tiempo de ejecucion' => 'Tiempo de ejecucion',
            'viabilidad' => 'Viabilidad',
            'pertinencia con el grupo de investigacion y con el programa' => 'Pertinencia con el grupo de investigacion y con el programa',
            'disponibilidad de docentes para su direccion y calificacion' => 'Disponibilidad de docentes para su direccion y calificacion',
            'calidad y correspondencia entre titulo y objetivo' => 'Calidad y correspondencia entre titulo y objetivo',
            'objetivo general del proyecto' => 'Objetivo general del proyecto',
            'descripcion del proyecto de investigacion' => 'Descripcion del proyecto de investigacion',
            'comentarios' => 'Comentarios',
        ][$normalizedName] ?? (string) $name;
    }

    /**
     * Resolves the most readable label for the user who generated the version.
     */
    protected function resolveAuthorLabel(Version $version): string
    {
        $user = $version->createdBy;
        if (! $user) {
            return 'No disponible';
        }

        // Resolve the author from the user's functional profile so the history makes
        // clear who generated each submission in the workflow.
        if ($user->student) {
            return trim($user->student->name . ' ' . $user->student->last_name) . ' (Student)';
        }

        if ($user->professor) {
            return trim($user->professor->name . ' ' . $user->professor->last_name) . ' (Professor)';
        }

        if ($user->researchstaff) {
            return trim($user->researchstaff->name . ' ' . $user->researchstaff->last_name) . ' (Research staff)';
        }

        return $user->email;
    }
}
