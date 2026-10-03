<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\RosterImporter;
use App\Support\CurrentClass;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function show(SchoolClass $class): View
    {
        return view('import.index', $this->shared($class));
    }

    public function preview(Request $request, SchoolClass $class, RosterImporter $importer): View
    {
        $pasted = $request->input('pasted');
        $result = $importer->preview($class, $request->file('file'), is_string($pasted) ? $pasted : null);

        return view('import.preview', $this->shared($class) + [
            'token' => $result['token'],
            'rows' => $result['rows'],
            'capacity' => $result['capacity'],
            'hasValid' => count(array_filter($result['rows'], fn (array $row) => $row['status'] !== 'ERROR')) > 0,
        ]);
    }

    public function commit(Request $request, SchoolClass $class, RosterImporter $importer): RedirectResponse
    {
        $token = $request->input('token');
        $rows = $request->input('rows', []);

        try {
            $created = $importer->commit(
                $class,
                is_string($token) ? $token : '',
                is_array($rows) ? array_values(array_filter($rows, 'is_scalar')) : [],
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect(url('/roster?class='.$class->id))->with('status', 'Imported '.$created.' students.');
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(SchoolClass $class): array
    {
        return [
            'classes' => SchoolClass::query()->orderBy('name')->get(),
            'currentClass' => CurrentClass::resolve($class->id),
        ];
    }
}
