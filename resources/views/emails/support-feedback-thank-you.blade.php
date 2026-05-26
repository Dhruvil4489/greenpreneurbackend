<table style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 30px;" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td align="center">
<table style="background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);" width="600" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding: 14px 14px; background-color: #240e5c; text-align: center;"><img style="vertical-align: middle;" src="https://unity.peersglobal.com/wp-content/uploads/2025/08/peersglobal_white-removebg-preview.png" alt="Peers Global" width="135" /></td>
</tr>
<tr>
<td style="padding: 18px 20px; font-size: 16px; color: #333333;">
Hello <strong>{{ $user->display_name ?: $user->first_name ?: 'User' }}</strong>,<br /><br />
Thank you for submitting your question/feedback.<br /><br />
We have received your request with the following details:<br /><br />
<strong>Subject:</strong> {{ $feedback->subject }}<br />
<strong>Category:</strong> {{ $feedback->category }}<br />
<strong>Question:</strong> {{ $feedback->question }}<br /><br />
Our support team will review it and get back to you soon.<br /><br />
Regards,<br />
<strong>Peers Global Unity Team</strong>
</td>
</tr>
<tr>
<td style="padding: 10px 14px; background-color: #240e5c; text-align: center; border-bottom-left-radius: 10px; border-bottom-right-radius: 10px;">
<p style="font-size: 14px; font-weight: bold; color: #ffffff; margin: 4px 0;">Peers are partners in business and friends in life.</p>
</td>
</tr>
</tbody>
</table>
</td>
</tr>
</tbody>
</table>
