<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Professor;
use App\Models\ProjectStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Handles the committee evaluation workflow for project ideas.
 *
 * The committee leader reviews the latest submitted version, updates the project status,
 * and, when returning the idea for correction, stores feedback linked to that reviewed version.
 */
class ProjectEvaluationController extends Controller
{
    /**
     * Lists the pending ideas that belong to the authenticated committee leader's program.
     */
    public function index()
    {
        // Resolve the professor record attached to the authenticated user acting as committee leader.
        $professor = Professor::where('user_id', Auth::id())
            ->where('committee_leader', true)
            ->whereNull('deleted_at')
            ->first();

        // The committee can only evaluate ideas inside its own city-program scope.
        if (! $professor || ! $professor->city_program_id) {
            abort(403, 'No se pudo determinar el programa del lÃ­der de comitÃ©.');
        }

        $cityProgramId = $professor->city_program_id;

        // Only pending ideas from the same city-program are visible, whether they
        // were proposed by students or by professors from that campus/program.
        $projects = Project::whereHas('projectStatus', function ($query) {
                $query->where('name', 'Pendiente de aprobaciÃ³n');
            })
            ->where(function ($query) use ($cityProgramId) {
                $query->whereHas('students', function ($sub) use ($cityProgramId) {
                    $sub->where('city_program_id', $cityProgramId);
                })
                ->orWhereHas('professors', function ($sub) use ($cityProgramId) {
                    $sub->where('city_program_id', $cityProgramId);
                });
            })
            ->with([
                'projectStatus',
                'thematicArea.investigationLine',
                'versions.contentVersions.content',
                'contentFrameworkProjects.contentFramework.framework',
                'students',
                'professors'
            ])
            ->get();

        // Send the collection fully hydrated so the evaluation list can show status,
        // participants, frameworks, and versioned details without extra view logic.
        return view('projects.evaluation.index', compact('projects'));
    }

    /**
     * Shows the evaluable detail of a project idea using its latest submitted version.
     */
    public function show(Project $project)
    {
        // Load every relation the committee detail screen needs before rendering.
        $project->load([
            'thematicArea.investigationLine',
            'projectStatus',
            'professors.user', // Use the related user as the most reliable source of contact email.
            'professors.cityProgram.program', // Show academic context without extra queries from Blade.
            'students',
            'contentFrameworks.framework',
            'versions' => static fn ($relation) => $relation
                ->with(['contentVersions.content'])
                ->orderByDesc('created_at'),
        ]);

        // Evaluations are always performed against the most recent delivery made by the author team.
        $latestVersion = $project->versions()->latest('created_at')->first();

        // Flatten the versioned section rows into a simple label => value array so the
        // committee screen can render each section without extra transformation logic.
        $contentValues = [];

        if ($latestVersion) {
            foreach ($latestVersion->contentVersions as $cv) {
                // Prefer a user-facing label when available because committee members
                // should read business terms instead of technical catalog names.
                $label = $cv->content->label ?? $cv->content->name ?? 'Campo';
                $contentValues[$label] = $cv->value ?? '-';
            }
        }

        // Frameworks come from the current project relation because they are part of the
        // methodological context the committee needs to review.
        $frameworksSelected = $project->contentFrameworks;

        return view('projects.evaluation.show', compact('project', 'latestVersion', 'contentValues', 'frameworksSelected'));
    }


    /**
     * Stores the committee decision and updates the project's current workflow status.
     */
    public function evaluate(Request $request, Project $project)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:Aprobado,Rechazado,Devuelto para correcciÃ³n',
            'comments' => 'nullable|string',
        ]);

        $statusName = $validated['status'];

        // A final approval means different things depending on authorship: student ideas
        // move to "Assigned", while professor ideas stay in the "Approved" status.
        $isProfessorProject = $project->professors()->exists();
        $isStudentProject = ! $isProfessorProject;

        // Apply the business rule before resolving the status from the catalog.
        if ($statusName === 'Aprobado' && $isStudentProject) {
            $statusName = 'Asignado';
        }

        // Resolve the status dynamically so the workflow is not tied to hard-coded ids.
        $status = ProjectStatus::where('name', $statusName)->first();
        if (! $status) {
            return back()->with('error', "No se encontrÃ³ el estado '$statusName'.");
        }

        // Update the current project state without altering any previously stored versions.
        $project->update(['project_status_id' => $status->id]);

        // When the committee returns an idea for correction, store the feedback as a new
        // content row inside the latest reviewed version so the observation remains tied
        // to the exact delivery that was evaluated.
        if ($validated['status'] === 'Devuelto para correcciÃ³n') {
            $latestVersion = $project->versions()->latest('created_at')->first();

            if ($latestVersion) {
                $commentContent = Content::where('name', 'Comentarios')
                    ->whereJsonContains('roles', 'committee_leader')
                    ->first();

                if ($commentContent) {
                    // Committee feedback becomes another section of the evaluated version,
                    // preserving traceability without modifying older historical records.
                    ContentVersion::create([
                        'version_id' => $latestVersion->id,
                        'content_id' => $commentContent->id,
                        'value' => $validated['comments'] ?? 'Sin comentarios',
                    ]);
                }
            }
        }

        return redirect()
            ->route('projects.evaluation.index')
            ->with('success', "EvaluaciÃ³n del proyecto '{$project->title}' enviada correctamente con estado: $statusName.");
    }

}
