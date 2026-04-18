<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\CityProgram;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\InvestigationLine;
use App\Models\Professor;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Student;
use App\Models\ThematicArea;
use App\Models\User;
use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder; // Shared across participant listing endpoints.
use Illuminate\Http\JsonResponse; // Typed response for the participant picker endpoint.
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Helpers\AuthUserHelper;
use App\Models\Framework;

/**
 * Handles the full lifecycle of project ideas submitted to the degree project idea bank.
 *
 * This controller coordinates role-based listing, creation, correction,
 * resubmission, participant selection, and version snapshot generation.
 */
class ProjectController extends Controller
{
    /**
     * Cache of content identifiers indexed by their normalized display name.
     *
     * @var array<string, int>
     */
    protected array $contentCache = [];

    /**
     * Cached identifier for the waiting-evaluation status.
     */
    protected ?int $waitingStatusId = null;

    /**
     * Displays the paginated project list filtered by the authenticated user's role.
     */
    public function index(Request $request): View
    {
        $user = AuthUserHelper::fullUser();

        $query = Project::query()
            ->with([
                'thematicArea.investigationLine',
                'projectStatus',
                'professors' => static fn ($relation) => $relation
                    ->with(['user', 'cityProgram.program'])
                    ->orderBy('last_name')
                    ->orderBy('name'),
                'students' => static fn ($relation) => $relation
                    ->orderBy('last_name')
                    ->orderBy('name'),
            ])
            ->orderByDesc('created_at');

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where('title', 'like', "%{$search}%");
        }

        // Allow the listing to be narrowed down by workflow status.
        $statusFilter = $request->input('status_id');
        if ($statusFilter) {
            $query->where('project_status_id', $statusFilter);
        }

        $cityPrograms = CityProgram::with(['program', 'city'])->get();
        $selectedCityProgram = $request->integer('city_program_id');

        if ($user?->role === 'research_staff' && $selectedCityProgram) {
            $query->whereHas('professors', function ($q) use ($selectedCityProgram) {
                $q->where('city_program_id', $selectedCityProgram);
            });
        }

        if ($user?->role === 'professor' && $user->professor) {
            $professorId = $user->professor->id;
            $query->whereHas('professors', static function ($relation) use ($professorId) {
                $relation->where('professors.id', $professorId);
            });
        } elseif ($user?->role === 'student' && $user->student) {
            $studentId = $user->student->id;
            $query->whereHas('students', static function ($relation) use ($studentId) {
                $relation->where('students.id', $studentId);
            });
        } elseif ($user?->role === 'committee_leader' && $user->professor && $user->professor->cityProgram) {
            $programId = $user->professor->cityProgram->program_id;
            // Committee leaders can view ideas that include either a professor or a student
            // from the same academic program.
            $query->where(function ($q) use ($programId) {
                $q->whereHas('professors.cityProgram', function ($p) use ($programId) {
                    $p->where('program_id', $programId);
                })
                ->orWhereHas('students.cityProgram', function ($s) use ($programId) {
                    $s->where('program_id', $programId);
                });
            });
        }

        /** @var LengthAwarePaginator $projects */
        $projects = $query->paginate(10)->withQueryString();

        $programCatalog = collect();
        if ($user?->role === 'committee_leader') {
            $programCatalog = Program::query()->orderBy('name')->get();
        }

        // Populate the status filter shown in the index view.
        $projectStatuses = \App\Models\ProjectStatus::orderBy('name')->get();

        /**
         * Determines whether the student can submit or select another idea.
         */
        $enableButtonStudent = true;

        if ($user?->role === 'student' && $user->student) {
            $studentProjects = $user->student->projects()
                ->with('projectStatus')
                ->get();

            if ($studentProjects->isNotEmpty()) {
                // Students are blocked only while they still own a non-rejected idea.
                $enableButtonStudent = $studentProjects->every(function ($project) {
                    return strtolower($project->projectStatus->name) === 'rechazado';
                });
            }
        }

        return view('projects.index', [
            'projects' => $projects,
            'search' => $search,
            'isProfessor' => in_array($user?->role, ['professor', 'committee_leader'], true),
            'isStudent' => $user?->role === 'student',
            'isCommitteeLeader' => $user?->role === 'committee_leader',
            'isResearchStaff' => $user?->role === 'research_staff',
            'programCatalog' => $programCatalog,
            'enableButtonStudent' => $enableButtonStudent,
            'projectStatuses' => $projectStatuses,
            'selectedStatus' => $statusFilter,
            'cityPrograms' => $cityPrograms,
            'selectedCityProgram' => $selectedCityProgram,
        ]);
    }


    /**
     * Ensures the current user can access the project idea module.
     *
     * @return array{0: \App\Models\User, 1: bool, 2: bool, 3: bool, 4: bool}
     */
    protected function ensureRoleAccess(bool $allowResearchStaff = false): array
    {
        $user = AuthUserHelper::fullUser();
        $isProfessor = in_array($user?->role, ['professor', 'committee_leader'], true); // Committee leaders reuse the same authoring permissions as professors.
        $isStudent = $user?->role === 'student';
        $isCommitteeLeader = $user?->role === 'committee_leader'; // Preserve the exact role for view-specific UI decisions.
        $isResearchStaff = $user?->role === 'research_staff';

        if (! $isProfessor && ! $isStudent && ! ($allowResearchStaff && $isResearchStaff)) {
            abort(403, 'This action is only available for professors, committee leaders or students.'); // Keep the rejection message explicit about the allowed roles.
        }

        return [$user, $isProfessor, $isStudent, $isResearchStaff, $isCommitteeLeader]; // Return both grouped and exact role flags so downstream methods can adapt safely.
    }

    /**
     * Shows the creation form and preloads the data required for the current author role.
     */
    public function create(): View
    {
        [$user, $isProfessor, $isStudent, $isResearchStaff, $isCommitteeLeader] = $this->ensureRoleAccess(true); // Keep the committee leader flag available for the Blade view.
        $activeProfessor = $this->resolveProfessorProfile($user); // Resolve the professor record even when the relation was not eager loaded.

        if ($isResearchStaff) {
            abort(403, 'Research staff members cannot create project ideas.');
        }

        if ($isProfessor) {
            $researchGroupId = $activeProfessor?->cityProgram?->program?->research_group_id;
        } else {
             $student = $user->student;
            // Students cannot submit a new idea while they still have an active workflow.
            $blockedStatuses = [
                'Aprobado',
                'Asignado',
                'Pendiente de aprobacion',
                'Devuelto para correccion',
            ];


            $hasBlocked = $student->projects()
                ->whereHas('projectStatus', fn($q) => $q->whereIn('name', $blockedStatuses))
                ->exists();

            /**
             * Block creation when the student is already linked to a project that is
             * still active in the workflow.
             */
            if ($hasBlocked) {
                abort(403, 'No puedes crear una nueva idea porque ya tienes proyectos registrados.');
            }


            // If the student has no projects or only rejected ones, they can create a new idea.
            $researchGroupId = $student?->cityProgram?->program?->research_group_id;
        }

        $cities = City::query()->orderBy('name')->get();
        $programs = Program::query()->with('researchGroup')->orderBy('name')->get();
        $investigationLines = InvestigationLine::where('research_group_id', $researchGroupId)
            ->whereNull('deleted_at')
            ->get();
        $thematicAreas = ThematicArea::query()->orderBy('name')->get();

        $year = now()->year;

        $frameworks = \App\Models\Framework::with('contentFrameworks')
            ->where('start_year', '<=', $year)
            ->where('end_year', '>=', $year)
            ->orderBy('name')
            ->get();

        $prefill = [
            'delivery_date' => Carbon::now()->format('Y-m-d'),
        ];

        $availableStudents = collect();
        $availableProfessors = collect();

        if ($isProfessor) {
            $professor = $activeProfessor;
            if (! $professor) {
                abort(403, 'Professor profile required to submit proposals.');
            }

            $prefill = array_merge($prefill, [
                'first_name' => $professor->name,
                'last_name' => $professor->last_name,
                'email' => $professor->mail ?? $user->email,
                'phone' => $professor->phone,
                'city_id' => optional($professor->cityProgram)->city_id,
                'program_id' => optional($professor->cityProgram)->program_id,
            ]);

            $availableProfessors = $this->participantQuery($professor->id)
                ->get()
                ->map(fn (Professor $participant) => $this->presentParticipant($participant)); // Send the full catalog so the picker can render all eligible collaborators at once.
        } else {
            $student = $user->student;
            if (! $student) {
                abort(403, 'Student profile required to submit proposals.');
            }

            $cityProgram = $student->cityProgram;
            $program = $cityProgram?->program;
            $researchGroup = $program?->researchGroup;

            $prefill = array_merge($prefill, [
                'first_name' => $student->name,
                'last_name' => $student->last_name,
                'card_id' => $student->card_id,
                'email' => $user->email,
                'phone' => $student->phone,
                'city_id' => $cityProgram?->city_id,
                'program_id' => $program?->id,
                'research_group' => $researchGroup?->name,
            ]);

            // Fetch eligible teammates from the same city-program only.
            $availableStudents = Student::query()
                ->where('city_program_id', $student->city_program_id)
                ->where('id', '!=', $student->id)
                ->where(function ($q) {
                    $q->whereDoesntHave('projects') // Students without projects are always eligible teammates.
                    ->orWhere(function ($q2) {
                        $q2->whereHas('projects', fn($p) =>
                                $p->whereHas('projectStatus', fn($s) =>
                                    $s->where('name', 'Rechazado')
                                )
                            )
                            ->whereDoesntHave('projects', fn($p) =>
                                $p->whereHas('projectStatus', fn($s) =>
                                    $s->whereNot('name', 'Rechazado')
                                )
                            );
                    });
                })
                ->orderBy('last_name')
                ->orderBy('name')
                ->get();
        }

        return view('projects.create', [
            'cities' => $cities,
            'programs' => $programs,
            'investigationLines' => $investigationLines,
            'thematicAreas' => $thematicAreas,
            'frameworks' => $frameworks,
            'prefill' => $prefill,
            'isProfessor' => $isProfessor,
            'isStudent' => $isStudent,
            'isCommitteeLeader' => $isCommitteeLeader, // Expose the exact role so the Blade can keep role-specific controls consistent.
            'availableStudents' => $availableStudents,
            'availableProfessors' => $availableProfessors
        ]);
    }


    /**
     * Persists a new project idea using the workflow rules that apply to the current role.
     */
    public function store(Request $request): RedirectResponse
    {
        [$user, $isProfessor, $isStudent, $isResearchStaff] = $this->ensureRoleAccess(true); // Committee leaders immediately reuse the professor-specific persistence flow.

        try {
            if ($isProfessor) {
                $professorProfile = $this->resolveProfessorProfile($user); // Keep committee leaders tied to the same professor record used across the module.

                return $this->persistProfessorProject($request, $professorProfile);
            }

            if ($isResearchStaff) {
                abort(403, 'Research staff members cannot create project ideas.');
            }

            $blockedStatuses = [
                'Aprobado',
                'Asignado',
                'Pendiente de aprobacion',
                'Devuelto para correccion',
            ];

            $hasBlocked = $user->student->projects()
                ->whereHas('projectStatus', fn($q) => $q->whereIn('name', $blockedStatuses))
                ->exists();

            /**
             * Block creation when the student is already linked to a project that is
             * still active in the workflow.
             */
            if ($hasBlocked) {
                abort(403, 'No puedes crear una nueva idea porque ya tienes proyectos registrados.');
            }

            return $this->persistStudentProject($request, $user->student);
        } catch (\Throwable $exception) {
            Log::error('Failed to register project idea.', [
                'exception' => $exception,
            ]);

            return back()
                ->withInput()
                ->with('error', 'Unexpected error. Please try again later.');
        }
    }

    /**
     * Shows the current project state together with its latest submitted version.
     */
    public function show(Project $project): View
    {
        $project->load([
            'thematicArea.investigationLine',
            'projectStatus',
            'professors.user', // Needed to display a reliable contact email in the detail screen.
            'professors.cityProgram.program', // Needed to show academic context without extra queries.
            'students',
            'contentFrameworks.framework', // Include the selected frameworks in the detail view.
            'versions' => static fn ($relation) => $relation
                ->with(['contentVersions.content'])
                ->orderByDesc('created_at'),
        ]);

        $latestVersion = $project->versions->first();
        $contentValues = $this->mapContentValues($latestVersion);

        $normalizedStatus = $this->normalizeStatusName($project->projectStatus->name ?? '');
        $reviewComment = null;

        if ($normalizedStatus === 'devuelto para correccion' && $latestVersion) {
            $reviewContent = $latestVersion->contentVersions
                ->first(static function (ContentVersion $contentVersion): bool {
                    return Str::lower($contentVersion->content->name ?? '') === 'comentarios';
                });

            $reviewComment = $reviewContent?->value;
        }

        $user = AuthUserHelper::fullUser();

        $statusName = $project->projectStatus->name ?? 'Sin estado';
        $canEdit = $this->isReturnedForCorrection($project);

        return view('projects.show', [
            'project' => $project,
            'latestVersion' => $latestVersion,
            'contentValues' => $contentValues,
            'frameworksSelected' => $project->contentFrameworks,
            'isProfessor' => in_array($user?->role, ['professor', 'committee_leader'], true), // Committee leaders share the professor-facing actions in the detail screen.
            'isStudent' => $user?->role === 'student',
            'isCommitteeLeader' => $user?->role === 'committee_leader', // Keep the exact role available for any committee-only controls.
            'isResearchStaff' =>  $user?->role === 'research_staff',
            'reviewComment' => $reviewComment,
            'canEdit' => $canEdit,
            'statusName' => $statusName,
            'canViewVersionHistory' => $this->canViewVersionHistory($project, $user),
        ]); 
    }

    /**
     * Returns the eligible professor catalog for the AJAX participant picker.
     */
    public function participants(Request $request): JsonResponse
    {
        [$user, $isProfessor] = $this->ensureRoleAccess(); // Reuse the shared access check so only professors and committee leaders hit this endpoint.

        if (! $isProfessor) {
            abort(403, 'Only professors and committee leaders can browse participants.'); // Keep unauthorized roles from enumerating the catalog.
        }

        $requestedIds = collect($request->input('ids', []))
            ->filter(static fn ($id) => is_numeric($id))
            ->map(static fn ($id) => (int) $id)
            ->unique();

        if ($requestedIds->isNotEmpty()) {
            $prefetched = $this->participantQuery(null)
                ->whereIn('professors.id', $requestedIds)
                ->get();

            return response()->json([
                'data' => $prefetched
                    ->map(fn (Professor $professor) => $this->presentParticipant($professor))
                    ->values(),
                'meta' => null,
            ]); // Return a flat payload so the client can restore selections after validation errors while keeping numeric indexes in the JSON response.
        }

        $activeProfessor = $this->resolveProfessorProfile($user); // Resolve the active professor record so committee leaders follow the same exclusion rules.
        $excludeId = $activeProfessor?->id; // Exclude the authenticated professor from suggestions to avoid redundant self-selection.
        $term = trim((string) $request->input('q', ''));

        $query = $this->participantQuery($excludeId);

        $programFilter = $request->input('program_id');
        if ($programFilter !== null && $programFilter !== '') {
            $programId = (int) $programFilter;
            $query->whereHas('cityProgram', static function (Builder $builder) use ($programId) {
                $builder->where('program_id', $programId);
            });
        }

        if ($term !== '') {
            $normalizedTerm = mb_strtolower($term);

            $query->where(static function (Builder $builder) use ($normalizedTerm, $term) {
                $builder->whereRaw('LOWER(professors.name) like ?', ["%{$normalizedTerm}%"])
                    ->orWhereRaw('LOWER(professors.last_name) like ?', ["%{$normalizedTerm}%"])
                    ->orWhere('professors.card_id', 'like', "%{$term}%")
                    ->orWhereRaw('LOWER(professors.mail) like ?', ["%{$normalizedTerm}%"])
                    ->orWhereHas('user', static function (Builder $userQuery) use ($normalizedTerm) {
                        $userQuery->whereRaw('LOWER(email) like ?', ["%{$normalizedTerm}%"]);
                    });
            }); // Allow filtering by name, last name, document or email regardless of casing.

        }
        $participants = $query->get();

        return response()->json([
            'data' => $participants
                ->map(fn (Professor $professor) => $this->presentParticipant($professor))
                ->values(),
            'meta' => null,
        ]); // Return the full catalog so the frontend can render every participant without pagination and with consecutive indexes.
    }

    /**
     * Renders the lightweight page that consumes the participant JSON endpoint.
     */
    public function participantsPage(): View
    {
        [$user, $isProfessor] = $this->ensureRoleAccess();
        if (! $isProfessor) {
            abort(403);
        }

        // The page only boots filters and layout. The actual participant data still
        // comes from participants() so HTML and AJAX share one source of truth.
        $programs = Program::orderBy('name')->get();
        return view('participants.index', [
            'programs' => $programs,
        ]);
    }

    /**
     * Builds the base participant query, optionally excluding the authenticated professor.
     */
    protected function participantQuery(?int $excludeProfessorId = null): Builder
    {
        return Professor::query()
            ->select('professors.*')
            ->with(['user', 'cityProgram.program', 'cityProgram.city'])
            ->whereHas('user', static function (Builder $builder) {
                $builder->whereIn('role', ['professor', 'committee_leader', 'committe_leader']);
            })
            ->whereNull('professors.deleted_at') // Skip soft-deleted records so they do not appear in the picker or JSON endpoint.
            ->when($excludeProfessorId, static function (Builder $builder, int $exclude) {
                $builder->where('professors.id', '!=', $exclude);
            })
            ->orderBy('professors.last_name')
            ->orderBy('professors.name'); // Keep ordering stable between the initial payload and later AJAX searches.
    }
    /**
     * Normalizes participant data so Blade and JavaScript consume the same structure.
     */
    protected function presentParticipant(Professor $professor): array
    {
        return [
            'id' => $professor->id,
            'name' => trim(($professor->name ?? '') . ' ' . ($professor->last_name ?? '')),
            'document' => $professor->card_id,
            'email' => $professor->mail ?? $professor->user?->email,
            'program' => optional($professor->cityProgram?->program)->name,
            'program_id' => $professor->cityProgram?->program_id,
            'program_city' => optional($professor->cityProgram?->city)->name,
        ]; // Include email, program, and city so the UI can show enough context for collaborator selection.
    }

    /**
     * Resolves the professor profile attached to the authenticated user, including committee leaders.
     */
    protected function resolveProfessorProfile(?User $user): ?Professor
    {
        if (! $user) {
            return null;
        }

        if ($user->relationLoaded('professor') || array_key_exists('professor', $user->getRelations())) {
            if ($user->professor) {
                return $user->professor;
            }
        }

        return Professor::query()->where('user_id', $user->id)->first();
    }


    /**
     * Shows the correction form using the latest submitted version as the editing baseline.
     */
    public function edit(Project $project): View
    {
        // Ideas can only be edited after the committee has returned them for correction.
        $statusName = $this->normalizeStatusName($project->projectStatus->name ?? ''); // Normalize the status so historical labels are compared consistently.

        if ($statusName === 'pendiente de aprobacion') {
            abort(403, 'Projects pending approval cannot be edited.'); // Block editing attempts when the project is waiting for approval.
        }

        if (! $this->isReturnedForCorrection($project)) {
            abort(403, 'Solo los proyectos devueltos para correccion pueden ser editados.');
        }

        [$user, $isProfessor, $isStudent, $isResearchStaff, $isCommitteeLeader] = $this->ensureRoleAccess(true); // Keep committee leaders inside the same editing capabilities as professors.
        $activeProfessor = $this->resolveProfessorProfile($user); // Resolve the shared professor profile once and reuse it throughout the edit flow.
        $this->authorizeProjectAccess($project, $user->id, $isProfessor, $isStudent, $isResearchStaff);

        if ($isResearchStaff) {
            abort(403, 'El personal de investigaciones no puede editar proyectos.');
        }
        if ($isProfessor) {
            $researchGroupId = $activeProfessor?->cityProgram?->program?->research_group_id;
        } else {
            $researchGroupId = $user->student?->cityProgram?->program?->research_group_id;
        }

        
        $project->load([
            'thematicArea',
            'professors',
            'students',
            'versions' => static fn ($relation) => $relation
                ->with(['contentVersions.content'])
                ->orderByDesc('created_at'),
        ]);

        // Corrections always start from the latest submission because the next version
        // must be built as an updated snapshot of the last reviewed delivery.
        $latestVersion = $project->versions->first();
        $contentValues = $this->mapContentValues($latestVersion);

        // Surface the committee feedback from the reviewed version so authors can use it
        // as guidance while preparing the corrected resubmission.
        $versionComment = null;
        if ($latestVersion) {
            $commentContent = $latestVersion->contentVersions
                ->firstWhere(fn ($cv) => $cv->content->name === 'Comentarios');

            $versionComment = $commentContent->value ?? null;
        }

        $cities = City::query()->orderBy('name')->get();
        $programs = Program::query()->with('researchGroup')->orderBy('name')->get();
        $investigationLines = InvestigationLine::where('research_group_id', $researchGroupId)
            ->whereNull('deleted_at')
            ->get();
        $thematicAreas = ThematicArea::query()->orderBy('name')->get();
        $selectedInvestigationLineId = $project->thematicArea->investigation_line_id ?? null;
        $selectedThematicAreaId = $project->thematic_area_id ?? null;


        $prefill = [
            'delivery_date' => Carbon::now()->format('Y-m-d'),
        ];

        $availableStudents = collect();
        $availableProfessors = collect();

        $hasProfessorParticipants = $project->professors->isNotEmpty();
        $hasStudentParticipants = $project->students->isNotEmpty();

        $useProfessorForm = $isProfessor || ($isResearchStaff && $hasProfessorParticipants);
        $useStudentForm = $isStudent || ($isResearchStaff && ! $hasProfessorParticipants && $hasStudentParticipants);

        if ($useProfessorForm) {
            $contextProfessor = $isProfessor ? $activeProfessor : $project->professors->first();
            if (! $contextProfessor) {
                abort(403, 'Professor profile required to edit proposals.');
            }

            $prefill = array_merge($prefill, [
                'first_name' => $contextProfessor->name,
                'last_name' => $contextProfessor->last_name,
                'email' => $contextProfessor->mail ?? $contextProfessor->user?->email,
                'phone' => $contextProfessor->phone,
                'city_id' => optional($contextProfessor->cityProgram)->city_id,
                'program_id' => optional($contextProfessor->cityProgram)->program_id,
            ]);

            $frameworks = Framework::with('contentFrameworks')
                ->where('end_year', '>=', now()->year)
                ->orderBy('name')
                ->get();

            // Reopen the form with the same framework items already attached to the project.
            $selectedContentFrameworkIds = $project
                ->contentFrameworkProjects()
                ->pluck('content_framework_id')
                ->toArray();

            $availableProfessors = $this->participantQuery(optional($contextProfessor)->id)
                ->get()
                ->map(fn (Professor $participant) => $this->presentParticipant($participant)); // Share the full catalog so editing uses the same dataset as creation.
        } elseif ($useStudentForm) {
            $contextStudent = $isStudent ? $user->student : $project->students->first();
            if (! $contextStudent) {
                abort(403, 'Student profile required to edit proposals.');
            }

            $cityProgram = $contextStudent->cityProgram;
            $program = $cityProgram?->program;
            $researchGroup = $program?->researchGroup;

            // Student corrections can only choose from currently active framework catalogs.
            $frameworks = Framework::with('contentFrameworks')
                ->where('end_year', '>=', now()->year)
                ->orderBy('name')
                ->get();

            // Reuse the framework choices already attached to the live project record.
            $selectedContentFrameworkIds = $project
                ->contentFrameworkProjects()
                ->pluck('content_framework_id')
                ->toArray();

            $prefill = array_merge($prefill, [
                'first_name' => $contextStudent->name,
                'last_name' => $contextStudent->last_name,
                'card_id' => $contextStudent->card_id,
                'email' => $contextStudent->user?->email,
                'phone' => $contextStudent->phone,
                'city_id' => $cityProgram?->city_id,
                'program_id' => $program?->id,
                'research_group' => $researchGroup?->name,
            ]);

            $availableStudents = Student::query()
                ->whereHas('projects', function ($query) use ($project) {
                    $query->where('project_id', $project->id);
                })
                ->where('id', '!=', $contextStudent->id)
                ->orderBy('last_name')
                ->orderBy('name')
                ->get();

        } else {
            abort(403, 'Project participants are required to edit this proposal.');
        }

        return view('projects.edit', [
            'project' => $project,
            'cities' => $cities,
            'programs' => $programs,
            'investigationLines' => $investigationLines,
            'thematicAreas' => $thematicAreas,
            'prefill' => $prefill,
            'contentValues' => $contentValues,
            'isProfessor' => $useProfessorForm,
            'isStudent' => $useStudentForm,
            'isCommitteeLeader' => $isCommitteeLeader, // Keep the exact role available for committee-specific UI restrictions.
            'isResearchStaff' => $isResearchStaff,
            'availableStudents' => $availableStudents,
            'availableProfessors' => $availableProfessors,
            'frameworks' => $frameworks,
            'selectedContentFrameworkIds' => $selectedContentFrameworkIds,
            'selectedInvestigationLineId' => $selectedInvestigationLineId,
            'selectedThematicAreaId' => $selectedThematicAreaId,
            'versionComment' => $versionComment,
            'isEdit' => true,
        ]);
    }

    /**
     * Updates a returned project idea by saving a fresh immutable version snapshot.
     */
    public function update(Request $request, Project $project): RedirectResponse
    {
        // Updates never mutate previous versions. They refresh the current project state
        // and append a brand-new version that records the corrected submission.
        $statusName = $this->normalizeStatusName($project->projectStatus->name ?? ''); // Normalize again to avoid relying on raw catalog labels.

        if ($statusName === 'pendiente de aprobacion') {
            abort(403, 'Projects pending approval cannot be edited.'); // Prevent updates when the UI should hide the edit button.
        }

        if (! $this->isReturnedForCorrection($project)) {
            abort(403, 'Solo los proyectos devueltos para correccion pueden ser editados.');
        }

        [$user, $isProfessor, $isStudent, $isResearchStaff] = $this->ensureRoleAccess(true);
        $this->authorizeProjectAccess($project, $user->id, $isProfessor, $isStudent, $isResearchStaff);

        $project->loadMissing(['professors', 'students']);

        try {
            if ($isProfessor) {
                return $this->persistProfessorProject($request, $user->professor, $project);
            }

            if ($isStudent) {
                return $this->persistStudentProject($request, $user->student, $project);
            }

            if ($isResearchStaff) {
                abort(403, 'Pidele al creador del proyecto que lo edite y envie a revision de nuevo');
            }
        } catch (\Throwable $exception) {
            Log::error('Failed to update project idea.', [
                'project_id' => $project->id,
                'exception' => $exception,
            ]);

            return back()
                ->withInput()
                ->with('error', 'Unexpected error. Please try again later.');
        }
    }

    /**
     * Protects edit/update operations by ensuring the user belongs to the project team.
     */
    protected function authorizeProjectAccess(Project $project, int $userId, bool $isProfessor, bool $isStudent, bool $isResearchStaff): void
    {
        // Research staff may inspect the workflow as administrative support, but authorship
        // and corrections still belong to the participating team members.
        if ($isResearchStaff) {
            return;
        }

        if ($isProfessor) {
            $user =  AuthUserHelper::fullUser();
            $professor = $user->professor;

            // Only professors assigned to the project may correct or resubmit it.
            if (! $professor || ! $project->professors->contains('id', $professor->id)) {
                abort(403, 'You are not assigned to this project.');
            }
        } elseif ($isStudent) {
            $user =  AuthUserHelper::fullUser();
            $student = $user->student;

            // The same rule applies to students because the submission history belongs to the author team.
            if (! $student || ! $project->students->contains('id', $student->id)) {
                abort(403, 'You are not assigned to this project.');
            }
        } else {
            abort(403, 'Unauthorized access.');
        }
    }

    /**
     * Normalizes titles using the same formatting rules enforced by the Project model mutator.
     */
    protected function normalizeTitle(string $title): string
    {
        return Str::of($title)->squish()->title()->toString();
    }

    /**
     * Resolves a content identifier by name and keeps it cached for the current request.
     */
    protected function contentId(string $name): int
    {
        $normalizedName = $this->normalizeContentName($name);

        if (empty($this->contentCache)) {
            // Cache the catalog because version persistence resolves several section names
            // in sequence and repeated database hits would add unnecessary overhead.
            $this->contentCache = Content::query()
                ->get(['id', 'name'])
                ->mapWithKeys(function (Content $content) {
                    return [$this->normalizeContentName($content->name) => $content->id];
                })
                ->toArray();
        }

        if (! array_key_exists($normalizedName, $this->contentCache)) {
            throw new \RuntimeException("Content '{$name}' not found in catalog.");
        }

        return $this->contentCache[$normalizedName];
    }
    /**
     * Resolves the identifier of the status that represents the waiting-evaluation step.
     */
    protected function waitingEvaluationStatusId(): int
    {
        if ($this->waitingStatusId !== null) {
            return $this->waitingStatusId;
        }

        // Support historical variants so the workflow keeps working even if the catalog
        // was seeded in English or Spanish at different stages of the project.
        $status = ProjectStatus::query()
            ->whereIn('name', ['waiting evaluation', 'Pendiente de aprobacion', 'Pendiente de aprobación'])
            ->orderByRaw("CASE WHEN name = 'waiting evaluation' THEN 0 ELSE 1 END")
            ->first();

        if (! $status) {
            throw new \RuntimeException('Waiting evaluation status is missing from the catalog.');
        }

        $this->waitingStatusId = $status->id;

        return $this->waitingStatusId;
    }

    /**
     * Maps versioned content values into a simple label => value array.
     *
     * @return array<string, string>
     */
    protected function mapContentValues(?Version $version): array
    {
        if (! $version) {
            return [];
        }

        // Decouple the UI from raw catalog names so forms, project detail, and history
        // can all consume the same section-based structure.
        return $version->contentVersions
            ->filter(static fn (ContentVersion $contentVersion) => $contentVersion->content !== null)
            ->mapWithKeys(function (ContentVersion $contentVersion) {
                return [$this->contentDisplayName($contentVersion->content->name) => $contentVersion->value];
            })
            ->toArray();
    }
    /**
     * Persists a professor-authored project idea, either as a new record or as a correction.
     */
    protected function persistProfessorProject(Request $request, ?Professor $professor, ?Project $project = null): RedirectResponse
    {
        if (! $professor) {
            abort(403, 'Professor profile required to complete this action.');
        }

        $assignedProgramId = optional($professor->cityProgram)->program_id; // Retrieve the immutable program linked to the authenticated professor or committee leader.

        if (! $assignedProgramId) {
            abort(403, 'A program assignment is required before submitting projects.'); // Stop early when the profile is incomplete.
        }

        $request->merge(['program_id' => $assignedProgramId]); // Force the incoming request to honour the assigned program regardless of client-side manipulation.

        $baseRules = [
            'city_id' => ['required', 'exists:cities,id'],
            'program_id' => ['required', 'integer', Rule::in([$assignedProgramId])], // Prevent tampering with the professor's fixed academic program.
            'investigation_line_id' => ['required', 'exists:investigation_lines,id'],
            'thematic_area_id' => [
                'required',
                Rule::exists('thematic_areas', 'id')->where(fn ($query) => $query->where('investigation_line_id', $request->integer('investigation_line_id'))),
            ],
            'title' => ['required', 'string', 'max:255'],
            'evaluation_criteria' => ['required', 'string'],
            'students_count' => ['required', 'integer', 'min:1', 'max:3'],
            'execution_time' => ['required', 'string', 'max:255'],
            'viability' => ['required', 'string'],
            'relevance' => ['required', 'string'],
            'teacher_availability' => ['required', 'string'],
            'title_objectives_quality' => ['required', 'string'],
            'general_objective' => ['required', 'string'],
            'description' => ['required', 'string'],
            'contact_first_name' => ['required', 'string', 'max:50'],
            'contact_last_name' => ['required', 'string', 'max:50'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:20'],
            'associated_professors' => ['nullable', 'array'], // Collect collaborators selected through the dynamic participant picker.
            'associated_professors.*' => ['integer', Rule::exists('professors', 'id')->whereNull('deleted_at')], // Each provided id must point to an active professor profile.
            'content_frameworks' => ['required', 'array'],
            'content_frameworks.*' => ['required', Rule::exists('content_frameworks', 'id')],
        ];

        $validated = $request->validate($baseRules);
        $isUpdate = $project !== null;
        $normalizedTitle = $this->normalizeTitle($validated['title']);

        // The authenticated professor is always part of the team, even if the client does not
        // send it explicitly, so authorship and permissions remain internally consistent.
        $professorIds = collect($validated['associated_professors'] ?? [])
            ->filter(static fn ($id) => $id !== null) // Remove empty array slots left by the client script.
            ->push($professor->id)
            ->unique()
            ->values()
            ->all();

        $sortedProfessorIds = $professorIds;
        sort($sortedProfessorIds);

        $duplicateProject = Project::query()
            ->when($project, static fn ($query) => $query->where('id', '!=', $project->id))
            ->where('title', $normalizedTitle)
            ->get()
            ->first(static function (Project $existing) use ($sortedProfessorIds) {
                $existingProfessorIds = $existing->professors()->pluck('professors.id')->sort()->values()->all();

                return $existingProfessorIds === $sortedProfessorIds;
            });

        if ($duplicateProject) {
            return back()
                ->withInput()
                ->with('error', 'A project with the same title and professor team already exists.');
        }

        DB::beginTransaction();

        try {
            $professor->fill([
                'name' => $validated['contact_first_name'],
                'last_name' => $validated['contact_last_name'],
                'mail' => $validated['contact_email'],
                'phone' => $validated['contact_phone'],
            ])->save();

            if ($professor->user && $professor->user->email !== $validated['contact_email']) {
                $professor->user->email = $validated['contact_email'];
                $professor->user->save();
            }

            if ($project) {
                $project->fill([
                    'title' => $normalizedTitle,
                    'evaluation_criteria' => $validated['evaluation_criteria'],
                    'thematic_area_id' => $validated['thematic_area_id'],
                    'project_status_id' => $this->waitingEvaluationStatusId(),
                ])->save();
            } else {
                $project = Project::create([
                    'title' => $normalizedTitle,
                    'evaluation_criteria' => $validated['evaluation_criteria'],
                    'thematic_area_id' => $validated['thematic_area_id'],
                    'project_status_id' => $this->waitingEvaluationStatusId(),
                ]);
            }

            $project->professors()->sync($professorIds);

            // Keep framework selections on the live project and also capture them in the
            // immutable version snapshot created just below.
            $contentFrameworkIds = array_values(array_filter($validated['content_frameworks'] ?? []));
            $project->contentFrameworks()->sync($contentFrameworkIds);

            // Persist each relevant section independently so the history remains
            // section-oriented instead of being tied to a specific form layout.
            $contentMap = [
                'Título' => $project->title,
                'Cantidad de estudiantes' => (string) $validated['students_count'],
                'Tiempo de ejecucion' => $validated['execution_time'],
                'Viabilidad' => $validated['viability'],
                'Pertinencia con el grupo de investigación y con el programa' => $validated['relevance'],
                'Disponibilidad de docentes para su direccion y calificacion' => $validated['teacher_availability'],
                'Calidad y correspondencia entre título y objetivo' => $validated['title_objectives_quality'],
                'Objetivo general del proyecto' => $validated['general_objective'],
                'Descripcion del proyecto de investigacion' => $validated['description'],
            ];

            $this->storeProjectVersion($project, $contentMap, $professor->user_id);

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        $message = $isUpdate
            ? 'Project idea updated and set to waiting evaluation'
            : 'Project idea registered and set to waiting evaluation';

        return redirect()
            ->route('projects.index')
            ->with('success', $message);
    }

    /**
     * Persists a student-authored project idea, either as a new record or as a correction.
     */
    protected function persistStudentProject(Request $request, ?Student $student, ?Project $project = null): RedirectResponse
    {
        if (! $student) {
            abort(403, 'Student profile required to complete this action.');
        }

        $baseRules = [
            'city_id' => ['required', 'exists:cities,id'],
            'investigation_line_id' => ['required', 'exists:investigation_lines,id'],
            'thematic_area_id' => [
                'required',
                Rule::exists('thematic_areas', 'id')->where(fn ($query) => $query->where('investigation_line_id', $request->integer('investigation_line_id'))),
            ],
            'title' => ['required', 'string', 'max:255'],
            'general_objective' => ['required', 'string'],
            'description' => ['required', 'string'],
            'teammate_ids' => ['nullable', 'array', 'max:2'],
            'teammate_ids.*' => [
                'integer',
                Rule::exists('students', 'id')->where(static function ($query) use ($student) {
                    $query->where('city_program_id', $student->city_program_id);
                }),
            ],
            'student_first_name' => ['required', 'string', 'max:50'],
            'student_last_name' => ['required', 'string', 'max:50'],
            'student_card_id' => [
                'required',
                'string',
                'max:25',
                Rule::unique('students', 'card_id')->ignore($student->id),
            ],
            'student_email' => ['required', 'email', 'max:255'],
            'student_phone' => ['nullable', 'string', 'max:20'],
            'content_frameworks' => ['required', 'array'],
            'content_frameworks.*' => ['required', Rule::exists('content_frameworks', 'id')],
        ];

        $validated = $request->validate($baseRules);
        $isUpdate = $project !== null;

        // Selected teammates must be free of active ideas so no student becomes attached
        // to two simultaneous project proposals.
        if (!empty($validated['teammate_ids'])) {
            $hasOtherProjects = Student::query()
                ->whereIn('id', $validated['teammate_ids'])
                ->whereHas('projects', function ($query) use ($project) {
                    $query->where('project_id', '!=', $project?->id)
                        ->whereHas('projectStatus', function ($statusQuery) {
                            $statusQuery->whereNotIn('name', ['Rechazado']);
                        });
                })
                ->exists();

            if ($hasOtherProjects) {
                return back()
                    ->withInput()
                    ->with('error', 'Uno o más compañeros seleccionados ya tienen un proyecto registrado.');
            }
        }

        $cityProgram = $student->cityProgram;
        if ($cityProgram && (int) $validated['city_id'] !== (int) $cityProgram->city_id) {
            return back()
                ->withInput()
                ->with('error', 'The selected city does not match your program assignment.');
        }

        $normalizedTitle = $this->normalizeTitle($validated['title']);

        // The authenticated student is forced into the team to keep authorship,
        // permissions, and version history aligned with the real submitter.
        $studentIds = collect($validated['teammate_ids'] ?? [])
            ->push($student->id)
            ->unique()
            ->values()
            ->all();

        $sortedStudentIds = $studentIds;
        sort($sortedStudentIds);

        if (count($studentIds) > 3) {
            return back()
                ->withInput()
                ->with('error', 'A project can only have up to 3 participating students.');
        }

        $activeStatusIds = ProjectStatus::query()
            ->whereIn('name', ['waiting evaluation', 'Pendiente de aprobacion', 'Pendiente de aprobación'])
            ->pluck('id');

        $hasActive = $student->projects()
            ->when($project, static fn ($query) => $query->where('projects.id', '!=', $project->id))
            ->whereIn('project_status_id', $activeStatusIds)
            ->exists();

        if ($hasActive) {
            return back()
                ->withInput()
                ->with('error', 'You already have a project idea waiting evaluation.');
        }

        $duplicateProject = Project::query()
            ->when($project, static fn ($query) => $query->where('id', '!=', $project->id))
            ->where('title', $normalizedTitle)
            ->get()
            ->first(static function (Project $existing) use ($sortedStudentIds) {
                $existingStudentIds = $existing->students()->pluck('students.id')->sort()->values()->all();

                return $existingStudentIds === $sortedStudentIds;
            });

        if ($duplicateProject) {
            return back()
                ->withInput()
                ->with('error', 'A project with the same title and student team already exists.');
        }

        DB::beginTransaction();

        try {
            $student->fill([
                'name' => $validated['student_first_name'],
                'last_name' => $validated['student_last_name'],
                'card_id' => $validated['student_card_id'],
                'phone' => $validated['student_phone'],
            ])->save();

            if ($student->user && $student->user->email !== $validated['student_email']) {
                $student->user->email = $validated['student_email'];
                $student->user->save();
            }

            if ($project) {
                $project->fill([
                    'title' => $normalizedTitle,
                    'evaluation_criteria' => null,
                    'thematic_area_id' => $validated['thematic_area_id'],
                    'project_status_id' => $this->waitingEvaluationStatusId(),
                ])->save();
            } else {
                $project = Project::create([
                    'title' => $normalizedTitle,
                    'evaluation_criteria' => null,
                    'thematic_area_id' => $validated['thematic_area_id'],
                    'project_status_id' => $this->waitingEvaluationStatusId(),
                ]);
            }

            $project->students()->sync($studentIds);

            // Keep the selected frameworks on the live project and mirror them into the snapshot.
            $contentFrameworkIds = array_values(array_filter($validated['content_frameworks'] ?? []));
            $project->contentFrameworks()->sync($contentFrameworkIds);

            // Student proposals only version the sections that exist in the student form,
            // avoiding duplicate data for fields that do not apply to that workflow.
            $contentMap = [
                'Título' => $project->title,
                'Objetivo general del proyecto' => $validated['general_objective'],
                'Descripcion del proyecto de investigacion' => $validated['description'],
            ];

            $this->storeProjectVersion($project, $contentMap, $student->user_id);

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        $message = $isUpdate
            ? 'Project idea updated and set to waiting evaluation'
            : 'Project idea registered and set to waiting evaluation';

        return redirect()
            ->route('projects.index')
            ->with('success', $message);
    }

    /**
     * Determines whether the authenticated user may view the version history.
     */
    protected function canViewVersionHistory(Project $project, ?User $user): bool
    {
        return $user !== null;
    }

    /**
     * Normalizes status names so comparisons survive accents and case differences.
     */
    protected function normalizeStatusName(?string $name): string
    {
        return Str::of((string) $name)
            ->ascii()
            ->lower()
            ->squish()
            ->toString();
    }

    /**
     * Checks whether the project is currently waiting for committee evaluation.
     */
    protected function isPendingApproval(Project $project): bool
    {
        return $this->normalizeStatusName($project->projectStatus->name ?? '') === 'pendiente de aprobacion';
    }

    /**
     * Checks whether the project is in the returned-for-correction state.
     */
    protected function isReturnedForCorrection(Project $project): bool
    {
        return $this->normalizeStatusName($project->projectStatus->name ?? '') === 'devuelto para correccion';
    }
    /**
     * Normalizes content names so catalog labels with or without accents behave the same.
     */
    protected function normalizeContentName(?string $name): string
    {
        return Str::of((string) $name)
            ->ascii()
            ->lower()
            ->replace('-', ' ')
            ->squish()
            ->toString();
    }

    /**
     * Converts stored catalog names into the labels expected by forms and history views.
     */
    protected function contentDisplayName(?string $name): string
    {
        $normalizedName = $this->normalizeContentName($name);

        return [
            'titulo' => 'Título',
            'cantidad de estudiantes' => 'Cantidad de estudiantes',
            'tiempo de ejecucion' => 'Tiempo de ejecucion',
            'viabilidad' => 'Viabilidad',
            'pertinencia con el grupo de investigacion y con el programa' => 'Pertinencia con el grupo de investigación y con el programa',
            'disponibilidad de docentes para su direccion y calificacion' => 'Disponibilidad de docentes para su direccion y calificacion',
            'calidad y correspondencia entre titulo y objetivo' => 'Calidad y correspondencia entre título y objetivo',
            'objetivo general del proyecto' => 'Objetivo general del proyecto',
            'descripcion del proyecto de investigacion' => 'Descripcion del proyecto de investigacion',
            'comentarios' => 'Comentarios',
        ][$normalizedName] ?? (string) $name;
    }

    /**
     * Creates an immutable version record that captures the current project snapshot.
     */
    protected function storeProjectVersion(Project $project, array $contentMap, ?int $createdByUserId): Version
    {
        $project->load([
            'projectStatus',
            'thematicArea.investigationLine',
            'contentFrameworks.framework',
            'professors.user',
            'students.user',
        ]);

        // Store a self-contained snapshot first so the exact state of the idea survives,
        // then persist the per-section rows used by section-level screens.
        $version = $project->versions()->create([
            'created_by_user_id' => $createdByUserId,
            'snapshot' => $this->sanitizeSnapshot($this->buildProjectVersionSnapshot($project, $contentMap)),
        ]);

        $this->storeContentValues($version, $contentMap);

        return $version;
    }

    /**
     * Builds a portable snapshot so every version preserves the full state of the idea.
     */
    protected function buildProjectVersionSnapshot(Project $project, array $contentMap): array
    {
        // The snapshot packages the full submission state at the time of delivery:
        // status, academic metadata, participants, contents, and selected frameworks.
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
            'contents' => collect($contentMap)
                ->mapWithKeys(function ($value, $label) {
                    return [$this->contentDisplayName($label) => (string) $value];
                })
                ->toArray(),
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
                            'phone' => $professor->phone,
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
                            'phone' => $student->phone,
                        ];
                    })
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Sanitizes the snapshot payload so it can always be stored as valid UTF-8 JSON.
     */
    protected function sanitizeSnapshot(array $snapshot): array
    {
        return $this->sanitizeSnapshotValue($snapshot);
    }

    /**
     * Recursively normalizes snapshot keys and values before JSON encoding.
     */
    protected function sanitizeSnapshotValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                // Normalize both keys and values because the snapshot mixes catalog labels,
                // user input, and section names in the same payload.
                $sanitizedKey = is_string($key)
                    ? $this->sanitizeSnapshotString($key)
                    : $key;

                $sanitized[$sanitizedKey] = $this->sanitizeSnapshotValue($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return $this->sanitizeSnapshotString($value);
        }

        return $value;
    }

    /**
     * Normalizes strings that may contain mixed encodings before snapshot storage.
     */
    protected function sanitizeSnapshotString(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $sanitized = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);

        if ($sanitized !== false && $sanitized !== '') {
            return $sanitized;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * Stores each section value in the content_version table.
     */
    protected function storeContentValues(Version $version, array $contentMap): void
    {
        foreach ($contentMap as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // Each row represents one submitted section, keeping history immutable while
            // still allowing section-level reads without rewriting old versions.
            ContentVersion::create([
                'content_id' => $this->contentId($name),
                'version_id' => $version->id,
                'value' => (string) $value,
            ]);
        }
    }
}



