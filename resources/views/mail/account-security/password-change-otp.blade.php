<x-mail::message>
# Your password change code

Use the code below to change your password:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

This code expires in {{ config('account_security.password_change_otp.expiry_minutes') }} minutes. Do not share it with anyone. If you did not request this change, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
