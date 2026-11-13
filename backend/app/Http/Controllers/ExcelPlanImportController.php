<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePlanningAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class ExcelPlanImportController extends Controller
{
    private const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const YEAR = 2026;

    public function options(Request $request): JsonResponse
    {
        abort_unless(RequirePlanningAccess::canEdit($request->user()), 403);
        $input = $request->validate(['department_id' => ['nullable', 'integer', 'min:1']]);
        if (isset($input['department_id'])) {
            abort_unless(RequirePlanningAccess::departmentIds($request->user())->contains((int) $input['department_id']), 403);

            return response()->json([
                'employees' => $this->departmentEmployees((int) $input['department_id'])->orderBy('u.name')->orderBy('u.id')
                    ->get()->map(fn (object $person): array => [
                        'id' => (int) $person->id,
                        'name' => $person->name,
                        'employee_number' => $person->employee_number,
                        'designation' => $person->designation,
                        'responsibility' => $person->responsibility,
                        'responsibility_title' => $person->responsibility_title,
                        'import_block_reason' => $this->importBlockReason($person),
                    ]),
            ]);
        }

        return response()->json([
            'years' => [self::YEAR],
            'departments' => DB::table('departments')->whereIn('id', RequirePlanningAccess::departmentIds($request->user()))->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function import(Request $request, PerformancePlanController $plans): JsonResponse
    {
        abort_unless(RequirePlanningAccess::canEdit($request->user()), 403);
        $input = $request->validate([
            'workbook' => ['required', 'file', 'max:5120', 'extensions:xlsx'],
            'sheet' => ['nullable', 'string', 'max:31'],
            'year' => ['required', 'integer', 'in:'.self::YEAR],
            'department_id' => ['required', 'integer', 'min:1'],
            'owner_id' => ['required', 'integer', 'min:1'],
            'save' => ['nullable', 'in:1'],
        ]);
        $departmentId = (int) $input['department_id'];
        abort_unless(RequirePlanningAccess::departmentIds($request->user())->contains($departmentId), 403);
        $employee = $this->departmentEmployees($departmentId)->where('u.id', $input['owner_id'])->first();
        if (! $employee) {
            $this->invalid('Choose a person assigned to the selected department.');
        }
        $blockReason = $this->importBlockReason($employee);
        if ($blockReason !== null) {
            $this->invalid($blockReason);
        }
        $file = $request->file('workbook');
        $zip = new ZipArchive;
        if ($zip->open($file->getPathname(), ZipArchive::RDONLY) !== true) {
            $this->invalid('Select a readable Excel .xlsx workbook.');
        }
        try {
            $sheets = $this->sheets($zip);
            if (! isset($input['sheet']) && ! isset($input['save'])) {
                return response()->json(['sheets' => array_keys($sheets)]);
            }
            $sheet = $input['sheet'] ?? '';
            if (! isset($sheets[$sheet])) {
                $this->invalid('Choose a visible worksheet from this workbook.');
            }
            $preview = $this->preview($zip, $sheets[$sheet]);
        } finally {
            $zip->close();
        }

        if ($preview['year'] !== (int) $input['year'] || $preview['epf'] !== $employee->employee_number) {
            $this->invalid('The worksheet year and EPF must match the selected year and employee.');
        }
        $existingPlan = DB::table('performance_plans')->where('owner_id', $employee->id)
            ->where('year', $preview['year'])->first(['id', 'code', 'title']);

        if (! isset($input['save'])) {
            return response()->json([...$preview, 'employee' => [
                'id' => (int) $employee->id, 'name' => $employee->name,
                'employee_number' => $employee->employee_number, 'designation' => $employee->designation,
            ], 'existing_plan' => $existingPlan]);
        }

        if ($existingPlan) {
            $this->invalid('A plan already exists for this employee and year. Open it from Performance Plans.');
        }

        return $plans->createDraft($request, [
            'owner_id' => (int) $employee->id,
            'code' => 'EXCEL-'.$preview['year'].'-'.$employee->id,
            'title' => mb_substr($preview['year'].' KPI plan — '.$employee->name, 0, 180),
            'year' => $preview['year'],
            'kpas' => array_map(fn (array $row): array => $this->toKpa($row, $departmentId), $preview['rows']),
        ], [
            'filename' => mb_substr($file->getClientOriginalName(), 0, 200),
            'sheet' => $sheet,
            'sha256' => hash_file('sha256', $file->getPathname()),
        ]);
    }

    private function departmentEmployees(int $departmentId): Builder
    {
        return DB::table('users as u')->join('department_memberships as m', 'm.user_id', '=', 'u.id')
            ->join('departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.department_id', $departmentId)->where('d.is_active', true)->where('u.system_role', 'member')
            ->select('u.id', 'u.name', 'u.employee_number', 'u.designation', 'u.is_active', 'u.system_role',
                'm.responsibility', 'm.responsibility_title');
    }

    private function importBlockReason(object $person): ?string
    {
        if (! $person->is_active) {
            return 'Inactive account';
        }
        if ($person->system_role !== 'member' || ! in_array($person->responsibility, ['hof', 'manager', 'employee'], true)) {
            return 'Team member access required';
        }
        if ($person->employee_number === null || trim($person->employee_number) === '') {
            return 'EPF number missing';
        }

        return null;
    }

    private function sheets(ZipArchive $zip): array
    {
        $paths = [];
        foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->children() as $relationship) {
            if ((string) $relationship['Type'] === self::RELATIONSHIPS.'/worksheet' && (string) $relationship['TargetMode'] !== 'External') {
                $target = (string) $relationship['Target'];
                $path = str_starts_with($target, '/') ? substr($target, 1) : 'xl/'.$target;
                if (preg_match('~^xl/worksheets/[^/]+\.xml$~D', $path)) {
                    $paths[(string) $relationship['Id']] = $path;
                }
            }
        }
        $sheets = [];
        foreach ($this->xml($zip, 'xl/workbook.xml')->sheets->sheet as $sheet) {
            $id = (string) $sheet->attributes(self::RELATIONSHIPS)['id'];
            if (isset($paths[$id]) && in_array((string) $sheet['state'], ['', 'visible'], true)) {
                $sheets[(string) $sheet['name']] = $paths[$id];
            }
        }
        if ($sheets === []) {
            $this->invalid('The workbook does not contain any visible worksheets.');
        }

        return $sheets;
    }

    private function preview(ZipArchive $zip, string $path): array
    {
        $strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $entry) {
                $strings[] = $this->cellText($entry);
            }
        }
        $styles = $this->percentageStyles($zip);
        $cells = [];
        foreach ($this->xml($zip, $path)->sheetData->row as $row) {
            foreach ($row->c as $cell) {
                $address = (string) $cell['r'];
                if (! preg_match('/^[B-I](?:[1-9]|[1-9][0-9]+)$/D', $address)) {
                    continue;
                }
                $value = match ((string) $cell['t']) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => $this->cellText($cell->is),
                    default => (string) $cell->v,
                };
                $cells[$address] = ['text' => trim($value), 'percent' => isset($styles[(int) $cell['s']]),
                    'numeric' => in_array((string) $cell['t'], ['', 'n'], true), 'formula' => isset($cell->f), 'error' => (string) $cell['t'] === 'e'];
            }
        }
        $text = fn (string $address): string => $cells[$address]['text'] ?? '';
        if (! preg_match('/\b2026\b/', $text('B3')) || $text('F4') !== 'Target for 2026'
            || strcasecmp($text('D2'), 'EPF') !== 0 || strcasecmp($text('I4'), 'KPI Measures') !== 0) {
            $this->invalid('Use a 2026 individual KPI worksheet with EPF and the KPI table in columns B–I.');
        }
        if (! preg_match('/^([0-9]+)(?:\s|$)/u', $text('E2'), $epf)) {
            $this->invalid('Enter the employee EPF at the start of cell E2.');
        }

        $rows = [];
        $references = [];
        $total = 0;
        foreach ($cells as $address => $cell) {
            if (! preg_match('/^([CD])(\d+)$/D', $address, $match) || (int) $match[2] < 5 || $cell['text'] === '') {
                continue;
            }
            $line = (int) $match[2];
            if ($match[1] === 'C') {
                if ($text('D'.$line) === '' && ($text('G'.$line) !== '' || $text('H'.$line) !== '' || $text('I'.$line) !== '')) {
                    $this->invalid("Enter the KPI reference in cell D{$line}.");
                }

                continue;
            }
            $reference = $cell['text'];
            if (! ctype_digit($reference) || isset($references[(int) $reference]) || count($rows) >= 20) {
                $this->invalid("Row {$line} needs a unique numeric KPI reference. The pilot supports up to 20 KPI rows.");
            }
            $references[(int) $reference] = true;
            foreach (range('B', 'I') as $column) {
                $source = $cells[$column.$line] ?? null;
                if (! $source || $source['text'] === '' || $source['formula'] || $source['error']) {
                    $this->invalid("Cell {$column}{$line} needs a source value.");
                }
            }
            $weight = $text('I'.$line);
            if (! $cells['I'.$line]['numeric'] || ! is_numeric($weight) || (float) $weight < 0 || (float) $weight > 1) {
                $this->invalid("Cell I{$line} needs a numeric KPI weight between 0% and 100%.");
            }
            $weight = (int) round((float) $weight * 10000);
            $total += $weight;
            $target = $cells['F'.$line];
            $targetValue = $target['percent'] && $target['numeric'] && is_numeric($target['text']) ? round((float) $target['text'] * 100, 4) : null;
            $activities = preg_split('/(?:^|\R|\h{2,})\h*\d+[.)](?!\d)\h*/u', $text('G'.$line), -1, PREG_SPLIT_NO_EMPTY);
            $activities = array_values(array_filter(array_map('trim', $activities), fn (string $activity): bool => $activity !== ''));
            if ($activities === [] || count($activities) > 50) {
                $this->invalid("Cell G{$line} must contain between 1 and 50 activities.");
            }
            $rows[] = [
                'row' => $line, 'reference' => $reference, 'category' => $text('B'.$line), 'title' => $text('C'.$line),
                'measures' => preg_replace('/\h{2,}/u', ' ', $text('E'.$line)), 'target' => $targetValue === null ? $target['text'] : $targetValue.'%',
                'target_value' => $targetValue, 'monitoring' => $text('H'.$line), 'weight' => $weight / 10000,
                'activities' => $activities,
            ];
        }
        if ($rows === [] || $total !== 10000) {
            $this->invalid('The KPI rows must allocate exactly 100% after rounding weights to two percentage decimal places. Found '.($total / 100).'%.');
        }
        usort($rows, fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return ['year' => self::YEAR, 'epf' => $epf[1], 'excel_name' => $text('E1'), 'rows' => $rows, 'weight_total' => $total / 10000];
    }

    private function toKpa(array $row, int $department): array
    {
        $reference = $row['reference'];
        $notes = "Excel reference {$reference} (row {$row['row']})\n2026 target: {$row['target']}\nMonitoring: {$row['monitoring']}\nMeasures: {$row['measures']}";

        return [
            'code' => 'KPA-'.$reference, 'title' => $row['title'], 'category' => $row['category'],
            'department_id' => $department, 'weight' => $row['weight'], 'kpis' => [[
                'code' => 'KPI-'.$reference, 'title' => $row['title'], 'weight' => 1, 'notes' => $notes,
                'target_value' => $row['target_value'], 'target_unit' => $row['target_value'] === null ? null : 'percent',
                'activities' => array_map(fn (string $title, int $index): array => [
                    'code' => 'ACT-'.$reference.'-'.($index + 1), 'title' => $title,
                ], $row['activities'], array_keys($row['activities'])),
            ]],
        ];
    }

    private function percentageStyles(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/styles.xml') === false) {
            return [];
        }
        $styles = $this->xml($zip, 'xl/styles.xml');
        $formats = [9 => true, 10 => true];
        foreach ($styles->numFmts?->numFmt ?? [] as $format) {
            if (preg_match('/^[#0.,]+%$/D', (string) $format['formatCode'])) {
                $formats[(int) $format['numFmtId']] = true;
            }
        }
        $percentages = [];
        foreach ($styles->cellXfs?->xf ?? [] as $format) {
            $percentages[] = isset($formats[(int) $format['numFmtId']]) ? true : null;
        }

        return $percentages;
    }

    private function cellText(SimpleXMLElement $node): string
    {
        return (string) $node->t.implode('', array_map(fn (SimpleXMLElement $run): string => (string) $run->t, iterator_to_array($node->r, false)));
    }

    private function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $info = $zip->statName($path);
        if (! $info || $info['size'] > 8 * 1024 * 1024) {
            $this->invalid('The workbook is incomplete or exceeds the import size limit.');
        }
        $xml = $zip->getFromName($path);
        if ($xml === false || str_contains($xml, "\0") || str_contains($xml, '<!DOCTYPE')) {
            $this->invalid('The workbook contains unsupported XML.');
        }
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($document === false) {
            $this->invalid('The workbook contains invalid XML.');
        }

        return $document;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['workbook' => $message]);
    }
}
