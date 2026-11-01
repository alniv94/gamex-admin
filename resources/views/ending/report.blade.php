<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — Ending Reports</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #182539; }
        h1 { font-size: 18px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px; border: 1px solid #dbe1e8; text-align: left; overflow-wrap: break-word; }
        th { background: #edf4ff; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>Ending Reports / {{ $title }}</h1>
    <p>{{ $filters['date_from'] }} to {{ $filters['date_to'] }} · Philippine time · Page {{ $filters['page'] }} · {{ $filters['filter_by'] === 'scheduled' ? 'Scheduled date' : 'Activity date' }}
        @if($filters['search']) · Search: {{ $filters['search'] }} @endif
    </p>
    <table>
        <thead><tr>@foreach($columns as $column)<th>{{ $column['title'] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($columns) }}">No matching records.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
