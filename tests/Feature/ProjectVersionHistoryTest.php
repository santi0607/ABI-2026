<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\CityProgram;
use App\Models\Content;
use App\Models\ContentFramework;
use App\Models\Department;
use App\Models\Framework;
use App\Models\InvestigationLine;
use App\Models\Professor;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ResearchGroup;
use App\Models\Student;
use App\Models\ThematicArea;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectVersionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposing_student_can_view_project_version_history(): void
    {
        $catalog = $this->createProjectCatalog();
        $student = $this->createStudent($catalog['cityProgram']->id, 'student_a@example.com');

        $project = Project::create([
            'title' => 'Proyecto historico',
            'thematic_area_id' => $catalog['thematicArea']->id,
            'project_status_id' => $catalog['status']->id,
        ]);
        $project->students()->sync([$student->id]);

        Version::create([
            'project_id' => $project->id,
            'created_by_user_id' => $student->user_id,
            'snapshot' => [
                'title' => 'Proyecto historico',
                'project_status' => ['name' => 'Pendiente'],
                'contents' => ['Titulo' => 'Proyecto historico'],
                'frameworks' => [],
                'participants' => ['professors' => [], 'students' => []],
            ],
        ]);

        $response = $this->actingAs($student->user)->get(route('projects.versions.index', $project));

        $response->assertOk();
        $response->assertSee('Historial de versiones');
        $response->assertSee('Proyecto Historico');
    }

    public function test_teammate_can_view_project_version_history(): void
    {
        $catalog = $this->createProjectCatalog();
        $proposer = $this->createStudent($catalog['cityProgram']->id, 'student_b@example.com');
        $teammate = $this->createStudent($catalog['cityProgram']->id, 'student_c@example.com');

        $project = Project::create([
            'title' => 'Proyecto restringido',
            'thematic_area_id' => $catalog['thematicArea']->id,
            'project_status_id' => $catalog['status']->id,
        ]);
        $project->students()->sync([$proposer->id, $teammate->id]);

        Version::create([
            'project_id' => $project->id,
            'created_by_user_id' => $proposer->user_id,
            'snapshot' => [
                'title' => 'Proyecto restringido',
                'project_status' => ['name' => 'Pendiente'],
                'contents' => ['Titulo' => 'Proyecto restringido'],
                'frameworks' => [],
                'participants' => ['professors' => [], 'students' => []],
            ],
        ]);

        $response = $this->actingAs($teammate->user)->get(route('projects.versions.index', $project));

        $response->assertOk();
        $response->assertSee('Historial de versiones');
    }

    public function test_assigned_professor_can_view_project_version_history(): void
    {
        $catalog = $this->createProjectCatalog();
        $student = $this->createStudent($catalog['cityProgram']->id, 'student_d@example.com');
        $professor = $this->createProfessor($catalog['cityProgram']->id, 'professor_a@example.com');

        $project = Project::create([
            'title' => 'Proyecto con profesor',
            'thematic_area_id' => $catalog['thematicArea']->id,
            'project_status_id' => $catalog['status']->id,
        ]);
        $project->students()->sync([$student->id]);
        $project->professors()->sync([$professor->id]);

        $version = Version::create([
            'project_id' => $project->id,
            'created_by_user_id' => $student->user_id,
            'snapshot' => [
                'title' => 'Proyecto con profesor',
                'project_status' => ['name' => 'Pendiente'],
                'contents' => ['Titulo' => 'Proyecto con profesor'],
                'frameworks' => [],
                'participants' => ['professors' => [], 'students' => []],
            ],
        ]);

        $response = $this->actingAs($professor->user)->get(route('projects.versions.show', [$project, $version]));

        $response->assertOk();
        $response->assertSee('Version 1 de 1');
    }

    public function test_student_can_edit_and_resubmit_project_returned_for_correction(): void
    {
        $catalog = $this->createProjectCatalog();
        $student = $this->createStudent($catalog['cityProgram']->id, 'student_edit@example.com');
        $returnedStatus = $this->createStatus('Devuelto para correccion', 'Requiere ajustes.');
        $waitingStatus = $this->createStatus('waiting evaluation', 'Pendiente de aprobación.');
        $contentFramework = $this->createContentFramework();
        $this->seedProjectContents();

        $project = Project::create([
            'title' => 'Proyecto por corregir',
            'thematic_area_id' => $catalog['thematicArea']->id,
            'project_status_id' => $returnedStatus->id,
        ]);
        $project->students()->sync([$student->id]);
        $project->contentFrameworks()->sync([$contentFramework->id]);

        $editResponse = $this->actingAs($student->user)->get(route('projects.edit', $project));
        $editResponse->assertOk();

        $response = $this->actingAs($student->user)->put(route('projects.update', $project), [
            'city_id' => $catalog['cityProgram']->city_id,
            'investigation_line_id' => $catalog['thematicArea']->investigation_line_id,
            'thematic_area_id' => $catalog['thematicArea']->id,
            'title' => 'Proyecto corregido',
            'general_objective' => 'Objetivo corregido para reenviar.',
            'description' => 'Descripcion corregida del proyecto para una nueva revisión.',
            'teammate_ids' => [],
            'student_first_name' => $student->name,
            'student_last_name' => $student->last_name,
            'student_card_id' => $student->card_id,
            'student_email' => $student->user->email,
            'student_phone' => $student->phone,
            'content_frameworks' => [$contentFramework->id],
        ]);

        $response->assertRedirect(route('projects.index'));
        $response->assertSessionHas('success', 'Project idea updated and set to waiting evaluation');

        $project->refresh();
        $project->load('projectStatus');

        $this->assertSame('Proyecto Corregido', $project->title);
        $this->assertSame($waitingStatus->id, $project->project_status_id);
        $this->assertSame(1, Version::query()->where('project_id', $project->id)->count());
    }

    private function createProjectCatalog(): array
    {
        $department = Department::create(['name' => 'Antioquia']);
        $city = City::create([
            'name' => 'Medellin',
            'department_id' => $department->id,
        ]);

        $researchGroup = ResearchGroup::create([
            'name' => 'Grupo Base',
            'initials' => 'GB',
            'description' => 'Grupo de apoyo para pruebas.',
        ]);

        $program = Program::create([
            'code' => random_int(1000, 9999),
            'name' => 'Programa Base',
            'research_group_id' => $researchGroup->id,
        ]);

        $cityProgram = CityProgram::create([
            'city_id' => $city->id,
            'program_id' => $program->id,
        ]);

        $investigationLine = InvestigationLine::create([
            'name' => 'Linea Base',
            'description' => 'Linea de investigacion.',
            'research_group_id' => $researchGroup->id,
        ]);

        $thematicArea = ThematicArea::create([
            'name' => 'Area Base',
            'description' => 'Area tematica.',
            'investigation_line_id' => $investigationLine->id,
        ]);

        $status = $this->createStatus('Pendiente', 'Pendiente de revision.');

        return [
            'cityProgram' => $cityProgram,
            'thematicArea' => $thematicArea,
            'status' => $status,
        ];
    }

    private function createStatus(string $name, string $description): ProjectStatus
    {
        $status = new ProjectStatus();
        $status->name = $name;
        $status->description = $description;
        $status->save();

        return $status;
    }

    private function seedProjectContents(): void
    {
        foreach ([
            'Titulo',
            'Objetivo general del proyecto',
            'Descripcion del proyecto de investigacion',
            'Comentarios',
        ] as $name) {
            Content::create([
                'name' => $name,
                'description' => 'Contenido de prueba: ' . $name,
                'roles' => ['student', 'professor', 'research_staff', 'committee_leader'],
            ]);
        }
    }

    private function createContentFramework(): ContentFramework
    {
        $framework = Framework::create([
            'name' => 'Framework Base',
            'description' => 'Framework de prueba para proyectos.',
            'link' => 'https://example.com/framework',
            'start_year' => 2020,
            'end_year' => 2030,
        ]);

        return ContentFramework::create([
            'framework_id' => $framework->id,
            'name' => 'Contenido Base',
            'description' => 'Contenido del framework para las pruebas.',
        ]);
    }

    private function createStudent(int $cityProgramId, string $email): Student
    {
        $user = User::create([
            'email' => $email,
            'password' => Hash::make('secret123'),
            'role' => 'student',
        ]);

        return Student::create([
            'card_id' => uniqid('STD'),
            'name' => 'Ana',
            'last_name' => 'Lopez',
            'phone' => '3001112233',
            'semester' => 7,
            'city_program_id' => $cityProgramId,
            'user_id' => $user->id,
        ]);
    }

    private function createProfessor(int $cityProgramId, string $email): Professor
    {
        $user = User::create([
            'email' => $email,
            'password' => Hash::make('secret123'),
            'role' => 'professor',
        ]);

        $professor = new Professor();
        $professor->card_id = uniqid('PRF');
        $professor->name = 'Luis';
        $professor->last_name = 'Gomez';
        $professor->phone = '3004445566';
        $professor->committee_leader = false;
        $professor->city_program_id = $cityProgramId;
        $professor->user_id = $user->id;
        $professor->save();

        return $professor;
    }
}


