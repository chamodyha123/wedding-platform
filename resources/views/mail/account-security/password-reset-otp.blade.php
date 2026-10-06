<x-mail::message>
# Your password reset code

Use the code below to reset your password:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

This code expires in {{ config('account_security.password_reset_otp.expiry_minutes') }} minutes. Do not share it with anyone. If you did not request a password reset, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
