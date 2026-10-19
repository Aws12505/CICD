<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The live log of anything long-running: server setup, builds, releases,
 * restores. Every action that kicks one off redirects here.
 */
class TaskController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('tasks/index', [
            'tasks' => Task::query()
                ->with('user')
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (Task $task) => self::summarise($task)),
        ]);
    }

    public function show(Task $task): Response
    {
        return Inertia::render('tasks/show', [
            'task' => self::summarise($task) + [
                'error' => $task->error,
                'meta' => $task->meta ?? [],
            ],
            'log' => $task->contents(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summarise(Task $task): array
    {
        return [
            'id' => $task->id,
            'type' => $task->type,
            'title' => $task->title,
            'status' => $task->status,
            'user' => $task->user?->name,
            'started_at' => $task->started_at?->toIso8601String(),
            'finished_at' => $task->finished_at?->toIso8601String(),
            'created_at' => $task->created_at?->toIso8601String(),
        ];
    }
}
