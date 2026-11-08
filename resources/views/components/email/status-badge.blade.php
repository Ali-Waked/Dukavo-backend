@props(['label', 'tone' => 'info'])

@php
    $palette = [
        'info' => ['background' => '#E0F2FE', 'color' => '#075985', 'border' => '#BAE6FD'],
        'success' => ['background' => '#DCFCE7', 'color' => '#166534', 'border' => '#BBF7D0'],
        'warning' => ['background' => '#FEF3C7', 'color' => '#92400E', 'border' => '#FDE68A'],
        'critical' => ['background' => '#FEE2E2', 'color' => '#991B1B', 'border' => '#FECACA'],
    ];
    $style = $palette[$tone] ?? $palette['info'];
@endphp

<span style="display:inline-block;padding:5px 11px;border-radius:999px;border:1px solid {{ $style['border'] }};background-color:{{ $style['background'] }};color:{{ $style['color'] }};font-family:'Segoe UI',Arial,sans-serif;font-size:12px;font-weight:700;line-height:1.3;">
    {{ $label }}
</span>
