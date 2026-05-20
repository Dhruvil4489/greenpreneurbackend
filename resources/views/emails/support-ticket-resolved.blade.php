<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Support Ticket Resolved</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
<p>Hello {{ $ticket->contact_name }},</p>

<p>Your support ticket has been resolved.</p>

<p><strong>Ticket Number:</strong> {{ $ticket->ticket_number }}</p>
<p><strong>Subject:</strong> {{ $ticket->subject }}</p>
<p><strong>Status:</strong> Resolved</p>

<p><strong>Admin Note:</strong><br>{{ $ticket->admin_note ?: '-' }}</p>

<p>If you still need help, please contact our support team again.</p>

<p>Thank you,<br>Peers Unity Team</p>
</body>
</html>
