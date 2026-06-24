<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Registration Request Update</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.6;">
<p>Hello {{ $user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Peer' }},</p>

<p>Thank you for your interest in joining Greenpreneur.</p>

<p>After careful review of your registration request, we regret to inform you that we are unable to approve your account at this time.</p>

<p>If you believe this decision was made in error, or if you would like to provide additional information for reconsideration, please contact our support team.</p>

<p>Warm regards,<br>Greenpreneur Team</p>
</body>
</html>
