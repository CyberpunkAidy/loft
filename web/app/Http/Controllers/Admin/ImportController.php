<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\Imports\Fields;
use App\Services\Imports\Imports;
use App\Services\Imports\PlansImporter;
use App\Services\MemberInvitations;
use App\Support\Csv;
use App\Support\ImportException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Members → Import (decision 37): bring members, families, credits and memberships over
 * from another system as CSV files, check the preview, import, and invite members when ready.
 */
class ImportController extends Controller
{
    public function index(Studio $studio, MemberInvitations $invitations): View
    {
        return view('admin.imports.index', [
            'imports' => $studio->imports()->with('user')->latest('id')->limit(20)->get(),
            'uninvited' => $invitations->uninvited($studio)->count(),
            'waiting' => $invitations->waiting($studio)->count(),
            'remindable' => $invitations->remindable($studio)->count(),
            'accepted' => StudioUser::where('studio_id', $studio->id)->whereNotNull('invitation_accepted_at')->count(),
            'hasPlans' => $studio->plans()->exists(),
            'hasMembers' => $studio->imports()->where('kind', 'people')->where('status', 'done')->exists(),
        ]);
    }

    public function store(Request $request, Studio $studio, Imports $imports): RedirectResponse
    {
        $kind = $request->input('kind');
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Import::KINDS))],
            "file_{$kind}" => ['required', 'file', 'max:10240'],
        ], [
            "file_{$kind}.required" => 'Choose the CSV file to import.',
            "file_{$kind}.max" => 'That file is larger than 10 MB. Split it into smaller files and import each one.',
        ]);
        $file = $request->file("file_{$data['kind']}");
        try {
            $import = $imports->start($studio, $request->user()->id, $data['kind'], (string) $file->getClientOriginalName(), (string) file_get_contents((string) $file->getRealPath()));
        } catch (ImportException $e) {
            return back()->withErrors(["file_{$data['kind']}" => $e->getMessage()]);
        }

        return redirect()->route('studio.admin.imports.show', ['import' => $import]);
    }

    public function show(Studio $studio, Import $import, Imports $imports): View
    {
        $data = ['import' => $import, 'fields' => Fields::for($import->kind), 'editable' => $this->editable($import)];
        if ($data['editable']) {
            $table = $imports->table($import);
            $samples = [];
            foreach ($table['headers'] as $index => $header) {
                $samples[$index] = collect($table['rows'])->take(50)->pluck($index)->first(fn ($v) => trim((string) $v) !== '') ?? '';
            }
            $data += [
                'headers' => $table['headers'],
                'samples' => $samples,
                'plan' => $imports->plan($import, $table),
                'problem' => $imports->problem($import),
                'planNames' => $import->kind === 'plans' ? PlansImporter::planNames($table['rows'], $import->mapping ?? []) : [],
                'studioPlans' => $studio->plans()->orderBy('name')->get(),
            ];
        }

        return view('admin.imports.show', $data);
    }

    public function update(Request $request, Studio $studio, Import $import, Imports $imports): RedirectResponse
    {
        if ($this->editable($import)) {
            $imports->choose($import, (array) $request->input('mapping', []), (string) $request->input('date_order', 'mdy'), (array) $request->input('plans', []));
        }

        return redirect()->route('studio.admin.imports.show', ['import' => $import])->with('success', 'Preview updated.');
    }

    public function run(Studio $studio, Import $import, Imports $imports): RedirectResponse
    {
        if (! $this->editable($import)) {
            return redirect()->route('studio.admin.imports.show', ['import' => $import]);
        }
        if ($problem = $imports->problem($import)) {
            return back()->with('error', $problem);
        }
        $imports->queue($import);

        return redirect()->route('studio.admin.imports.show', ['import' => $import]);
    }

    public function undo(Studio $studio, Import $import, Imports $imports): RedirectResponse
    {
        if (! $import->canUndo()) {
            return back()->with('error', 'This import can no longer be undone.');
        }
        $counts = $imports->undo($import);

        return redirect()->route('studio.admin.imports.show', ['import' => $import])->with('success', 'Import undone.'
            .($counts['kept'] ? " {$counts['kept']} ".($counts['kept'] === 1 ? 'record was' : 'records were').' kept because '.($counts['kept'] === 1 ? 'it’s' : 'they’re').' in use (booked, accepted an invitation, or a plan was used).' : ''));
    }

    public function destroy(Studio $studio, Import $import): RedirectResponse
    {
        if (in_array($import->status, ['draft', 'failed'], true)) {
            $import->delete();
        }

        return redirect()->route('studio.admin.imports.index')->with('success', 'Discarded.');
    }

    /** A CSV to fill in, with the columns Loft reads and two example rows. */
    public function template(Studio $studio, string $kind): StreamedResponse
    {
        $rows = $kind === 'plans' ? [
            ['Email', 'Name', 'Plan', 'Classes left', 'Expires or paid through'],
            ['priya@example.com', 'Priya Lee', '10-class pack', '6', '3/31/2027'],
            ['sam@example.com', 'Sam Ortiz', 'Unlimited monthly', '', '12/1/2026'],
        ] : [
            ['First name', 'Last name', 'Email', 'Phone', 'Birthday', 'Parent email', 'Medical notes', 'Emergency contact', 'Emergency phone', 'Marketing opt-in'],
            ['Priya', 'Lee', 'priya@example.com', '303-555-0101', '6/2/1988', '', '', 'Arjun Lee', '303-555-0102', 'yes'],
            ['Maya', 'Lee', '', '', '3/14/2017', 'priya@example.com', 'Peanut allergy', 'Arjun Lee', '303-555-0102', ''],
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            Csv::write($out, $rows);
            fclose($out);
        }, "loft-import-{$kind}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function invite(Request $request, Studio $studio, MemberInvitations $invitations): RedirectResponse
    {
        $remind = $request->input('which') === 'remind';
        $memberships = ($remind ? $invitations->remindable($studio) : $invitations->uninvited($studio))->with('user')->get();
        $sent = $invitations->send($studio, $memberships);

        return back()->with('success', match (true) {
            $sent === 0 => 'There was nobody to '.($remind ? 'remind.' : 'invite.'),
            $remind => "Sent a reminder to {$sent} ".($sent === 1 ? 'member.' : 'members.'),
            default => "Sent {$sent} ".($sent === 1 ? 'invitation.' : 'invitations.'),
        });
    }

    /** Staff can change how a file is read until it's imported (and again if importing failed). */
    private function editable(Import $import): bool
    {
        return $import->isDraft() || ($import->status === 'failed' && $import->csv !== null);
    }
}
