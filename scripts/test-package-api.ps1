param(
    [int]$ServiceId = 2,
    [string]$BaseUrl = "http://127.0.0.1:8000"
)

$ErrorActionPreference = "Stop"

function Get-ErrorResponseBody {
    param([System.Management.Automation.ErrorRecord]$ErrorRecord)

    try {
        $response = $ErrorRecord.Exception.Response
        if ($null -eq $response) {
            return $null
        }

        $stream = $response.GetResponseStream()
        if ($null -eq $stream) {
            return $null
        }

        $reader = New-Object System.IO.StreamReader($stream)
        return $reader.ReadToEnd()
    }
    catch {
        return $null
    }
}

function Invoke-ApiRequest {
    param(
        [Parameter(Mandatory = $true)][string]$Uri,
        [Parameter(Mandatory = $true)][string]$Method,
        [hashtable]$Headers = @{},
        [object]$Body = $null
    )

    try {
        $normalizedMethod = $Method.ToUpperInvariant()
        $params = @{
            Uri = $Uri
            Method = $normalizedMethod
            Headers = $Headers
            ErrorAction = "Stop"
        }

        if ($null -ne $Body -and $normalizedMethod -notin @("GET", "HEAD")) {
            $params["ContentType"] = "application/json"
            $params["Body"] = $Body
        }

        return Invoke-RestMethod @params
    }
    catch {
        Write-Host ""
        Write-Host "API request failed: $normalizedMethod $Uri" -ForegroundColor Red
        Write-Host $_.Exception.Message -ForegroundColor Red

        $response = $_.Exception.Response
        if ($null -ne $response) {
            Write-Host "HTTP status: $([int]$response.StatusCode) $($response.StatusDescription)" -ForegroundColor Red
        }

        $responseBody = Get-ErrorResponseBody -ErrorRecord $_
        if ($responseBody) {
            Write-Host "Response body:" -ForegroundColor Yellow
            Write-Host $responseBody
        }

        Write-Host ""
        Write-Host "Laravel log tail:" -ForegroundColor Yellow
        $projectRoot = Split-Path -Parent $PSScriptRoot
        $logFile = Join-Path $projectRoot "storage\logs\laravel.log"

        if (Test-Path $logFile) {
            Get-Content $logFile -Tail 60
        }
        else {
            Write-Host "No Laravel log found at $logFile"
        }

        throw
    }
}

Write-Host "Wedding Marketplace package API test" -ForegroundColor Cyan
Write-Host "Base URL: $BaseUrl"
Write-Host "Service ID: $ServiceId"
Write-Host ""

$email = Read-Host "Provider email"
$passwordSecure = Read-Host "Provider password" -AsSecureString
$password = [System.Net.NetworkCredential]::new("", $passwordSecure).Password

$loginBody = @{
    email = $email
    password = $password
} | ConvertTo-Json

Write-Host "Logging in..." -ForegroundColor Cyan
$loginResponse = Invoke-ApiRequest `
    -Uri "$BaseUrl/api/auth/login" `
    -Method "POST" `
    -Headers @{ Accept = "application/json" } `
    -Body $loginBody

if (-not $loginResponse.token) {
    Write-Host "Login response:" -ForegroundColor Yellow
    $loginResponse | ConvertTo-Json -Depth 8
    throw "Login completed, but the response did not contain a token property."
}

$token = [string]$loginResponse.token
$headers = @{
    Authorization = "Bearer $token"
    Accept = "application/json"
}

Write-Host "Login succeeded." -ForegroundColor Green
Write-Host "Checking authenticated user..." -ForegroundColor Cyan
$me = Invoke-ApiRequest `
    -Uri "$BaseUrl/api/auth/me" `
    -Method "GET" `
    -Headers $headers

Write-Host "Authenticated user confirmed." -ForegroundColor Green

$packageBody = @{
    name = "Gold Package"
    description = "Full wedding day photography with edited high-resolution images."
    price = 120000
    duration_minutes = 480
    status = "published"
} | ConvertTo-Json

Write-Host "Creating package..." -ForegroundColor Cyan
$packageResponse = Invoke-ApiRequest `
    -Uri "$BaseUrl/api/provider/services/$ServiceId/packages" `
    -Method "POST" `
    -Headers $headers `
    -Body $packageBody

Write-Host "Service package created successfully." -ForegroundColor Green
$packageResponse | ConvertTo-Json -Depth 8
