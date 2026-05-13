@php
    $userName = $recipient->display_name ?: trim(($recipient->first_name ?? '') . ' ' . ($recipient->last_name ?? ''));
@endphp

<p>Hello {{ $userName !== '' ? $userName : 'Peer' }},</p>

<p>This collaboration has been marked as completed.</p>

<table cellpadding="6" cellspacing="0" border="0">
    <tr>
        <td><strong>Collaboration title</strong></td>
        <td>{{ $collaboration->title }}</td>
    </tr>
    <tr>
        <td><strong>Completion date</strong></td>
        <td>{{ optional($collaboration->completed_at)->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i') }}</td>
    </tr>
</table>

<p>Thank you,<br>Unity Peers</p>
