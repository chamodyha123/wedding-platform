<?php

namespace App;

enum AccountSecurityOtpPurpose: string
{
    case Registration = 'registration';
    case PasswordReset = 'password_reset';
}
