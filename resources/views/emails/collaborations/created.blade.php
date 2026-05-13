@php
    $userName = $recipient->display_name ?: trim(($recipient->first_name ?? '') . ' ' . ($recipient->last_name ?? ''));
    $typeName = $collaboration->collaborationType?->name ?? $collaboration->collaboration_type ?? '—';
@endphp

<p>Hello {{ $userName !== '' ? $userName : 'Peer' }},</p>

<p>Your collaboration opportunity is now visible to peers.</p>

<table cellpadding="6" cellspacing="0" border="0">
    <tr>
        <td><strong>Collaboration title</strong></td>
        <td>{{ $collaboration->title }}</td>
    </tr>
    <tr>
        <td><strong>Collaboration type</strong></td>
        <td>{{ $typeName }}</td>
    </tr>
    <tr>
        <td><strong>Scope</strong></td>
        <td>{{ $collaboration->scope ?? '—' }}</td>
    </tr>
    <tr>
        <td><strong>Preferred model</strong></td>
        <td>{{ $collaboration->preferred_model ?? '—' }}</td>
    </tr>
    <tr>
        <td><strong>Posted date</strong></td>
        <td>{{ optional($collaboration->posted_at)->format('Y-m-d H:i') ?? '—' }}</td>
    </tr>
    <tr>
        <td><strong>Expiry date</strong></td>
        <td>{{ optional($collaboration->expires_at)->format('Y-m-d H:i') ?? '—' }}</td>
    </tr>
</table>

<p>Thank you,<br>Unity Peers</p>
