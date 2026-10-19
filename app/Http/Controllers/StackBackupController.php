<?php

namespace App\Http\Controllers;

use App\Jobs\RestoreBackupJob;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\Stack;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A stack's backups: Back up now, and Restore.
 *
 * Restoring discards whatever was written since the backup, so it is
 * admin-only, needs the stack's name typed out and a reason, and every one
 * is kept on record with who did it.
 */
class StackBackupController extends Controller
{
    public function index(Stack $stack): Response
    {
        $stack->load('client', 'server');

        return Inertia::render('stacks/backups', [
            'stack' => [
                'id' => $stack->id,
                'label' => $stack->label(),
                'name' => $stack->compose_project,
                'client_slug' => $stack->client->slug,
            ],
            'backups' => Backup::query()
                ->with('service')
                ->where('stack_id', $stack->id)
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (Backup $b) => [
                    'id' => $b->id,
                    'kind' => $b->kind,
                    'service' => $b->service?->name,
                    'status' => $b->status,
                    'triggered_by' => $b->triggered_by,
                    'tables' => $b->tables ?? [],
                    'size_bytes' => $b->size_bytes,
                    'finished_at' => $b->finished_at?->toIso8601String(),
                    'error' => $b->error,
                ]),
            'restores' => BackupRestore::query()
                ->with('performer', 'backup.service')
                ->where('stack_id', $stack->id)
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (BackupRestore $r) => [
                    'id' => $r->id,
                    'backup_id' => $r->backup_id,
                    'what' => ($r->backup?->service?->name ?? $r->backup?->kind).' from '.$r->backup?->finished_at?->toDayDateTimeString(),
                    'by' => $r->performer?->name,
                    'reason' => $r->reason,
                    'status' => $r->status,
                    'error' => $r->error,
                    'created_at' => $r->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /** Back up now: every database, or the file volumes. */
    public function store(Request $request, Stack $stack): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in([Backup::KIND_DATABASE, Backup::KIND_FILES])]]);

        $stack->load('client');
        $task = Task::begin(Task::TYPE_BACKUP, "Back up {$stack->label()} ({$data['kind']})", $stack, $request->user());

        RunBackupJob::dispatch($data['kind'], $stack->id, $task->id);

        return redirect()->route('tasks.show', $task);
    }

    public function restore(Request $request, Backup $backup): RedirectResponse
    {
        $stack = $backup->stack()->with('client')->firstOrFail();

        $data = $request->validate([
            'confirm' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        if ($data['confirm'] !== $stack->compose_project) {
            return back()->withErrors(['confirm' => "Type {$stack->compose_project} to confirm."]);
        }

        if ($backup->status !== 'success') {
            return back()->with('error', 'Only a successful backup can be restored.');
        }

        $restore = BackupRestore::create([
            'backup_id' => $backup->id,
            'stack_id' => $stack->id,
            'performed_by' => $request->user()->id,
            'reason' => $data['reason'],
            'status' => 'running',
        ]);

        $task = Task::begin(Task::TYPE_RESTORE, "Restore {$stack->label()} from backup #{$backup->id}", $restore, $request->user(), ['reason' => $data['reason']]);
        $restore->update(['log_path' => $task->log_path]);

        RestoreBackupJob::dispatch($restore->id, $task->id);

        return redirect()->route('tasks.show', $task);
    }
}
