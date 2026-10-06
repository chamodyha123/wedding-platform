<x-mail::message>
<x-mail::message>
# Verify your email

Your verification code is: **{{ $code }}**

This code expires in 10 minutes. Do not share it with anyone.
</x-mail::message>

<x-mail::button :url="''">
Button Text
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
