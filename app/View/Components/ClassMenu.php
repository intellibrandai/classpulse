<?php

namespace App\View\Components;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * The class switcher: a <details>/<summary> disclosure whose whole summary is the click target and whose panel lists every
 * class as a real link (?class=ID). Shared by the header pill (variant "header") and the Semester chip (variant "chip").
 */
class ClassMenu extends Component
{
    /** @var Collection<int, array{class: SchoolClass, href: string, current: bool, detail: string, students: string}> */
    public Collection $items;

    public string $label;

    public string $display;

    public string $accessibleName;

    public function __construct(
        public Collection $classes,
        public ?SchoolClass $current = null,
        public string $variant = 'header',
        public ?string $baseUrl = null,
    ) {
        $base = $baseUrl ?? url()->current();
        $counts = Student::query()
            ->whereIn('school_class_id', $classes->pluck('id'))
            ->whereNull('archived_at')
            ->selectRaw('school_class_id, count(*) as total')
            ->groupBy('school_class_id')
            ->pluck('total', 'school_class_id');

        $this->items = $classes->map(function (SchoolClass $class) use ($base, $counts, $current): array {
            $n = (int) ($counts[$class->id] ?? 0);
            $parts = array_filter([
                $class->period_label,
                filled($class->title) ? $class->title : $class->subject_description,
            ], fn ($part) => filled($part));

            return [
                'class' => $class,
                'href' => $base.'?class='.$class->id,
                'current' => $current !== null && $current->id === $class->id,
                'detail' => implode(' · ', $parts),
                'students' => $n.' '.($n === 1 ? 'student' : 'students'),
            ];
        })->values();

        $this->label = $current?->name ?? 'No class selected';
        $subtitle = $current === null ? '' : (filled($current->title) ? $current->title : (string) $current->subject_description);
        $this->display = $variant === 'chip' && $subtitle !== '' ? $this->label.' • '.$subtitle : $this->label;
        $prefix = $variant === 'chip' ? 'Current course' : 'Active class';
        $this->accessibleName = $prefix.': '.$this->label.'. Choose a class';
    }

    public function render(): View
    {
        return view('components.class-menu');
    }
}
