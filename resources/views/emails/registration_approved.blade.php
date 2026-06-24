<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Account Approved</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.6;">
<p>Hello {{ $user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Peer' }},</p>

<p>We are pleased to inform you that your registration request on Greenpreneur has been approved!</p>

<p>Your account is now active, and you can log in to the app to access our community, explore circles, and connect with other peers.</p>

<p>Thank you for your patience during the review process. We look forward to seeing your contributions to the community.</p>

<p>Warm regards,<br>Greenpreneur Team</p>
</body>
</html>
