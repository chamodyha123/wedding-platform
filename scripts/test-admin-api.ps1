param(
    [Parameter(Mandatory)]
    [string] $AdminEmail,
    [Parameter(Mandatory)]
    [string] $CustomerEmail,
    [Parameter(Mandatory)]
    [string] $ProviderEmail,
    [string] $BaseUrl = 'http://127.0.0.1:8000/api'
)

$results = [System.Collections.Generic.List[object]]::new()
$adminResponses = @{}

function Add-Result([string] $Test, [int] $Expected, [int] $Actual) {
    $results.Add([pscustomobject]@{
        Test = $Test
        Expected = $Expected
        Actual = $Actual
        Result = if ($Expected -eq $Actual) { 'PASS' } else { 'FAIL' }
    })
}

function Invoke-HttpRequest([scriptblock] $Request) {
    try {
        return [pscustomobject]@{ Status = 200; Data = (& $Request) }
    } catch {
        $response = $_.Exception.Response

        if ($null -eq $response) {
            throw
        }

        return [pscustomobject]@{ Status = [int] $response.StatusCode; Data = $null }
    }
}

function Invoke-Login([string] $Email, [string] $Label) {
    $securePassword = Read-Host "$Label password" -AsSecureString
    $plainPassword = $null
    $loginBody = $null

    try {
        $plainPassword = [System.Net.NetworkCredential]::new('', $securePassword).Password
        $loginBody = @{ email = $Email; password = $plainPassword } | ConvertTo-Json

        return Invoke-HttpRequest {
            Invoke-RestMethod -Method Post -Uri "$BaseUrl/auth/login" -ContentType 'application/json' -Body $loginBody
        }
    } finally {
        $loginBody = $null
        $plainPassword = $null
        $securePassword = $null
    }
}

function Find-ProhibitedFields([object] $Value, [string] $Endpoint) {
    $prohibitedNames = '^(password|remember_token|plain_text_token|access_token|token|authorization|bearer|api_key|secret|metadata|gateway_transaction_id|transaction_id)$'

    if ($null -eq $Value -or $Value -is [string] -or $Value -is [ValueType]) {
        return
    }

    if ($Value -is [System.Collections.IDictionary]) {
        foreach ($key in $Value.Keys) {
            $fieldName = [string] $key

            if ($fieldName -match $prohibitedNames) {
                [pscustomobject]@{ Endpoint = $Endpoint; Field = $fieldName }
            }

            Find-ProhibitedFields $Value[$key] $Endpoint
        }

        return
    }

    if ($Value -is [System.Collections.IEnumerable]) {
        foreach ($item in $Value) {
            Find-ProhibitedFields $item $Endpoint
        }

        return
    }

    foreach ($property in $Value.PSObject.Properties) {
        if ($property.Name -match $prohibitedNames) {
            [pscustomobject]@{ Endpoint = $Endpoint; Field = $property.Name }
        }

        Find-ProhibitedFields $property.Value $Endpoint
    }
}

try {
    $adminLogin = Invoke-Login $AdminEmail 'Admin'
    Add-Result 'admin login' 200 $adminLogin.Status
    $customerLogin = Invoke-Login $CustomerEmail 'Customer'
    Add-Result 'customer login' 200 $customerLogin.Status
    $providerLogin = Invoke-Login $ProviderEmail 'Provider'
    Add-Result 'provider login' 200 $providerLogin.Status

    if ($adminLogin.Status -ne 200 -or $customerLogin.Status -ne 200 -or $providerLogin.Status -ne 200) {
        throw 'One or more required logins failed; admin authorization checks were not run.'
    }

    $adminHeaders = @{ Authorization = "Bearer $($adminLogin.Data.token)"; Accept = 'application/json' }
    $customerHeaders = @{ Authorization = "Bearer $($customerLogin.Data.token)"; Accept = 'application/json' }
    $providerHeaders = @{ Authorization = "Bearer $($providerLogin.Data.token)"; Accept = 'application/json' }

    foreach ($path in @('admin/dashboard', 'admin/customers?per_page=100', 'admin/providers', 'admin/services', 'admin/categories', 'admin/bookings', 'admin/payments', 'admin/reviews', 'admin/reports/overview')) {
        $request = Invoke-HttpRequest { Invoke-RestMethod -Uri "$BaseUrl/$path" -Headers $adminHeaders }
        $testName = $path -replace '\?per_page=100', ''
        Add-Result $testName 200 $request.Status
        $adminResponses[$testName] = $request.Data
    }

    $unauthenticatedRequest = Invoke-HttpRequest {
        Invoke-RestMethod -Uri "$BaseUrl/admin/dashboard" -Headers @{ Accept = 'application/json' }
    }
    Add-Result 'unauthenticated dashboard' 401 $unauthenticatedRequest.Status

    $customerRequest = Invoke-HttpRequest {
        Invoke-RestMethod -Uri "$BaseUrl/admin/dashboard" -Headers $customerHeaders
    }
    Add-Result 'customer admin dashboard' 403 $customerRequest.Status

    $providerRequest = Invoke-HttpRequest {
        Invoke-RestMethod -Uri "$BaseUrl/admin/dashboard" -Headers $providerHeaders
    }
    Add-Result 'provider admin dashboard' 403 $providerRequest.Status

    foreach ($validationCheck in @(
        @{ Test = 'reviews rating 0'; Path = 'admin/reviews?rating=0' },
        @{ Test = 'reviews rating 6'; Path = 'admin/reviews?rating=6' },
        @{ Test = 'reports invalid from date'; Path = 'admin/reports/overview?from=not-a-date' },
        @{ Test = 'reports invalid date range'; Path = 'admin/reports/overview?from=2026-09-30&to=2026-09-01' }
    )) {
        $request = Invoke-HttpRequest {
            Invoke-RestMethod -Uri "$BaseUrl/$($validationCheck.Path)" -Headers $adminHeaders
        }
        Add-Result $validationCheck.Test 422 $request.Status
    }

    $customerIds = @($adminResponses['admin/customers'].customers | ForEach-Object { [int64] $_.id })
    $missingCustomerId = ([int64] ($customerIds | Measure-Object -Maximum).Maximum) + 1
    $missingCustomerRequest = Invoke-HttpRequest {
        Invoke-RestMethod -Uri "$BaseUrl/admin/customers/$missingCustomerId" -Headers $adminHeaders
    }
    Add-Result 'missing customer' 404 $missingCustomerRequest.Status

    $prohibitedFields = @(
        Find-ProhibitedFields $adminResponses['admin/customers'] 'admin/customers'
        Find-ProhibitedFields $adminResponses['admin/providers'] 'admin/providers'
        Find-ProhibitedFields $adminResponses['admin/payments'] 'admin/payments'
    )

    foreach ($prohibitedField in $prohibitedFields) {
        Write-Host "Prohibited field detected: $($prohibitedField.Endpoint) -> $($prohibitedField.Field)"
    }

    $sensitiveFieldsAreAbsent = $prohibitedFields.Count -eq 0
    $results.Add([pscustomobject]@{
        Test = 'sensitive fields absent'
        Expected = 'PASS'
        Actual = if ($sensitiveFieldsAreAbsent) { 'PASS' } else { 'FAIL' }
        Result = if ($sensitiveFieldsAreAbsent) { 'PASS' } else { 'FAIL' }
    })
} finally {
    $adminHeaders = $null
    $customerHeaders = $null
    $providerHeaders = $null
    $adminLogin = $null
    $customerLogin = $null
    $providerLogin = $null
}

$results | Format-Table -AutoSize

if ($results.Result -contains 'FAIL') {
    exit 1
}
