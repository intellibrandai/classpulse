{{-- Class switcher disclosure (shared). The whole summary is the click/tap target; the panel is a list of real links. See public/js/shell.js. --}}
<details class="class-menu class-menu-{{ $variant }}" data-class-menu>
    <summary class="class-menu-summary" aria-haspopup="true" aria-expanded="false" aria-label="{{ $accessibleName }}">
        @if ($variant === 'chip')
            <span class="sem-course-dot" aria-hidden="true"></span>
            <span class="class-menu-text">
                <span class="ui-eyebrow" aria-hidden="true">Current course</span>
                <span class="class-menu-name">{{ $display }}</span>
            </span>
            <x-icon name="chevron-down" class="class-menu-caret icon-18" />
        @else
            <span class="pill-eyebrow" aria-hidden="true">Active</span>
            <span class="dot" aria-hidden="true"></span>
            <span class="class-menu-name">{{ $label }}@if ($current && filled($current->period_label)) <span class="class-menu-period">· {{ $current->period_label }}</span>@endif</span>
            <x-icon name="chevron-down" class="class-menu-caret pill-caret icon-16" />
        @endif
    </summary>
    <div class="class-menu-panel ui-popover glass-strong" role="group" aria-label="Choose a class">
        @foreach ($items as $item)
            <a class="class-menu-item @if ($item['current']) is-current @endif" href="{{ $item['href'] }}" @if ($item['current']) aria-current="true" @endif>
                <span class="class-menu-item-text">
                    <span class="class-menu-item-code">{{ $item['class']->name }}</span>
                    <span class="class-menu-item-sub">@if ($item['detail'] !== ''){{ $item['detail'] }} · @endif{{ $item['students'] }}</span>
                </span>
                @if ($item['current'])
                    <x-icon name="check" class="class-menu-check icon-18" />
                    <span class="visually-hidden">(current class)</span>
                @endif
            </a>
        @endforeach
    </div>
</details>
