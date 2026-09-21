param(
    [string]$BaseUrl = "http://127.0.0.1:8000/api",
    [string]$CustomerEmail = "customer2@test.com",
    [string]$ProviderEmail = "kasun@example.com",
    [int]$BookingId = 4,
    [int]$ReviewId = 1,
    [int]$ServiceId = 2,
    [string]$ServiceSlug = "wedding-photography"
)

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "=========================================="
Write-Host " Wedding Marketplace - Review API Test"
Write-Host "=========================================="
Write-Host ""

$results = @()

function Add-Result {
    param(
        [string]$Test,
        [bool]$Passed,
        [string]$Expected,
        [string]$Actual
    )

    $script:results += [PSCustomObject]@{
        Test     = $Test
        Result   = if ($Passed) { "PASS" } else { "FAIL" }
        Expected = $Expected
        Actual   = $Actual
    }
}

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Url,
        [string]$Token = "",
        [object]$Body = $null
    )

    $headers = @{
        Accept = "application/json"
    }

    if ($Token) {
        $headers.Authorization = "Bearer $Token"
    }

    $params = @{
        Uri         = $Url
        Method      = $Method
        Headers     = $headers
        ContentType = "application/json"
    }

    if ($null -ne $Body) {
        $params.Body = $Body | ConvertTo-Json -Depth 10 -Compress
    }

    try {
        $response = Invoke-WebRequest @params

        $json = $null

        if ($response.Content) {
            try {
                $json = $response.Content | ConvertFrom-Json
            }
            catch {
                $json = $response.Content
            }
        }

        return [PSCustomObject]@{
            Status = [int]$response.StatusCode
            Body   = $json
        }
    }
    catch {
        $status = 0
        $body = $null

        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode

            try {
                $stream = $_.Exception.Response.GetResponseStream()
                $reader = New-Object System.IO.StreamReader($stream)
                $content = $reader.ReadToEnd()

                if ($content) {
                    try {
                        $body = $content | ConvertFrom-Json
                    }
                    catch {
                        $body = $content
                    }
                }
            }
            catch {
            }
        }

        return [PSCustomObject]@{
            Status = $status
            Body   = $body
        }
    }
}

function Test-Status {
    param(
        [string]$Name,
        [object]$Response,
        [int]$Expected
    )

    Add-Result `
        -Test $Name `
        -Passed ($Response.Status -eq $Expected) `
        -Expected "$Expected" `
        -Actual "$($Response.Status)"
}

# -------------------------------------------------
# PASSWORDS
# -------------------------------------------------

$CustomerPasswordSecure = Read-Host `
    "Customer password for $CustomerEmail" `
    -AsSecureString

$ProviderPasswordSecure = Read-Host `
    "Provider password for $ProviderEmail" `
    -AsSecureString

$CustomerPassword = [System.Net.NetworkCredential]::new(
    "",
    $CustomerPasswordSecure
).Password

$ProviderPassword = [System.Net.NetworkCredential]::new(
    "",
    $ProviderPasswordSecure
).Password

# -------------------------------------------------
# SERVER CHECK
# -------------------------------------------------

Write-Host ""
Write-Host "[1] Checking Laravel API..."

$serverCheck = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/marketplace/services"

if ($serverCheck.Status -ne 200) {
    Write-Host ""
    Write-Host "ERROR: Laravel API is not responding correctly."
    Write-Host "Start it with:"
    Write-Host ""
    Write-Host "php artisan serve"
    Write-Host ""
    exit 1
}

Add-Result "Laravel API available" $true "200" "$($serverCheck.Status)"

# -------------------------------------------------
# CUSTOMER LOGIN
# -------------------------------------------------

Write-Host "[2] Logging in customer..."

$customerLogin = Invoke-Api `
    -Method "POST" `
    -Url "$BaseUrl/auth/login" `
    -Body @{
        email    = $CustomerEmail
        password = $CustomerPassword
    }

Test-Status "Customer login" $customerLogin 200

$CustomerToken = $customerLogin.Body.token

if (-not $CustomerToken) {
    $CustomerToken = $customerLogin.Body.access_token
}

if (-not $CustomerToken) {
    Write-Host ""
    Write-Host "ERROR: Customer login succeeded but token was not found."
    Write-Host "Check the login response field name."
    exit 1
}

# -------------------------------------------------
# PROVIDER LOGIN
# -------------------------------------------------

Write-Host "[3] Logging in provider..."

$providerLogin = Invoke-Api `
    -Method "POST" `
    -Url "$BaseUrl/auth/login" `
    -Body @{
        email    = $ProviderEmail
        password = $ProviderPassword
    }

Test-Status "Provider login" $providerLogin 200

$ProviderToken = $providerLogin.Body.token

if (-not $ProviderToken) {
    $ProviderToken = $providerLogin.Body.access_token
}

if (-not $ProviderToken) {
    Write-Host ""
    Write-Host "ERROR: Provider login succeeded but token was not found."
    Write-Host "Check the login response field name."
    exit 1
}

# -------------------------------------------------
# CUSTOMER ENDPOINTS
# -------------------------------------------------

Write-Host "[4] Testing customer review endpoints..."

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/customer/reviews" `
    -Token $CustomerToken

Test-Status "Customer review list" $response 200

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/customer/reviews/$ReviewId" `
    -Token $CustomerToken

Test-Status "Customer review detail" $response 200

# -------------------------------------------------
# DUPLICATE REVIEW
# -------------------------------------------------

Write-Host "[5] Testing duplicate protection..."

$response = Invoke-Api `
    -Method "POST" `
    -Url "$BaseUrl/customer/bookings/$BookingId/reviews" `
    -Token $CustomerToken `
    -Body @{
        rating  = 5
        comment = "Duplicate review API verification."
    }

Test-Status "Duplicate review rejected" $response 409

# -------------------------------------------------
# PUBLIC REVIEWS
# -------------------------------------------------

Write-Host "[6] Testing public reviews..."

$publicReviews = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/marketplace/services/$ServiceSlug/reviews"

Test-Status "Public service reviews" $publicReviews 200

# -------------------------------------------------
# PUBLIC PRIVACY
# -------------------------------------------------

if ($publicReviews.Status -eq 200 -and
    $publicReviews.Body.reviews.data.Count -gt 0) {

    $firstReview = $publicReviews.Body.reviews.data[0]

    $properties = @(
        $firstReview.PSObject.Properties.Name
    )

    $sensitive = @(
        "booking_id",
        "payment_status",
        "provider_notes",
        "customer_notes",
        "transaction_id",
        "gateway_transaction_id"
    )

    $foundSensitive = @(
        $sensitive | Where-Object {
            $properties -contains $_
        }
    )

    Add-Result `
        "Public review privacy" `
        ($foundSensitive.Count -eq 0) `
        "No sensitive fields" `
        $(if ($foundSensitive.Count -eq 0) {
            "Safe"
        } else {
            $foundSensitive -join ", "
        })
}

# -------------------------------------------------
# DETECT CURRENT RATING
# -------------------------------------------------

$currentRating = $null

if ($publicReviews.Status -eq 200 -and
    $publicReviews.Body.reviews.data.Count -gt 0) {

    $currentRating = [int]$publicReviews.Body.reviews.data[0].rating
}

if ($currentRating) {

    $response = Invoke-Api `
        -Method "GET" `
        -Url "$BaseUrl/marketplace/services/$ServiceSlug/reviews?rating=$currentRating"

    Test-Status "Public rating filter" $response 200
}

# -------------------------------------------------
# INVALID PUBLIC RATINGS
# -------------------------------------------------

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/marketplace/services/$ServiceSlug/reviews?rating=6"

Test-Status "Public rating 6 rejected" $response 422

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/marketplace/services/$ServiceSlug/reviews?rating=0"

Test-Status "Public rating 0 rejected" $response 422

# -------------------------------------------------
# PAGINATION
# -------------------------------------------------

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/marketplace/services/$ServiceSlug/reviews?per_page=1"

Test-Status "Public review pagination" $response 200

# -------------------------------------------------
# PUBLIC SERVICE DETAIL
# -------------------------------------------------

Write-Host "[7] Testing service rating summary..."

$serviceDetail = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/marketplace/services/$ServiceSlug"

Test-Status "Public service detail" $serviceDetail 200

# -------------------------------------------------
# PUBLIC SERVICE LIST
# -------------------------------------------------

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/marketplace/services"

Test-Status "Public service list" $response 200

# -------------------------------------------------
# FIND PROVIDER SLUG FROM DATABASE
# -------------------------------------------------

Write-Host "[8] Finding provider slug..."

$ProviderSlug = (
    php artisan tinker --execute="echo App\Models\ServiceProvider::findOrFail(2)->business_slug;"
).Trim()

if (-not $ProviderSlug) {
    Write-Host "ERROR: Provider slug not found."
    exit 1
}

# -------------------------------------------------
# PUBLIC PROVIDER
# -------------------------------------------------

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/marketplace/providers/$ProviderSlug"

Test-Status "Public provider detail" $response 200

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/marketplace/providers"

Test-Status "Public provider list" $response 200

# -------------------------------------------------
# PROVIDER REVIEWS
# -------------------------------------------------

Write-Host "[9] Testing provider review endpoints..."

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/provider/reviews" `
    -Token $ProviderToken

Test-Status "Provider review list" $response 200

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/provider/reviews/$ReviewId" `
    -Token $ProviderToken

Test-Status "Provider review detail" $response 200

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/provider/reviews?service_id=$ServiceId" `
    -Token $ProviderToken

Test-Status "Provider service filter" $response 200

if ($currentRating) {

    $response = Invoke-Api `
        -Method "GET" `
        -Url "$BaseUrl/provider/reviews?rating=$currentRating" `
        -Token $ProviderToken

    Test-Status "Provider rating filter" $response 200
}

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/provider/reviews?rating=6" `
    -Token $ProviderToken

Test-Status "Provider invalid rating rejected" $response 422

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/provider/reviews?per_page=101" `
    -Token $ProviderToken

Test-Status "Provider invalid per_page rejected" $response 422

# -------------------------------------------------
# PROVIDER DASHBOARD
# -------------------------------------------------

Write-Host "[10] Testing provider dashboard..."

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/provider/dashboard" `
    -Token $ProviderToken

Test-Status "Provider dashboard" $response 200

# -------------------------------------------------
# AUTHORIZATION
# -------------------------------------------------

Write-Host "[11] Testing authorization..."

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/provider/reviews"

Test-Status "Unauthenticated provider reviews" $response 401

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/provider/reviews" `
    -Token $CustomerToken

Test-Status "Customer blocked from provider reviews" $response 403

$response = Invoke-Api `
    -Method "GET" `
    -Url "$BaseUrl/customer/reviews" `
    -Token $ProviderToken

Test-Status "Provider blocked from customer reviews" $response 403

# -------------------------------------------------
# PROVIDER CANNOT MUTATE
# -------------------------------------------------

Write-Host "[12] Testing provider read-only protection..."

$response = Invoke-Api `
    -Method "PUT" `
    -Url "$BaseUrl/provider/reviews/$ReviewId" `
    -Token $ProviderToken `
    -Body @{
        rating = 1
    }

$mutationBlocked = $response.Status -notin @(200, 201, 204)

Add-Result `
    "Provider cannot update review" `
    $mutationBlocked `
    "Non-success HTTP status" `
    "$($response.Status)"

# -------------------------------------------------
# VERIFY DUPLICATE COUNT
# -------------------------------------------------

Write-Host "[13] Checking database duplicate count..."

$bookingReviewCount = (
    php artisan tinker --execute="echo App\Models\Review::where('booking_id',$BookingId)->count();"
).Trim()

Add-Result `
    "One review per booking" `
    ($bookingReviewCount -eq "1") `
    "1" `
    "$bookingReviewCount"

# -------------------------------------------------
# RESULTS
# -------------------------------------------------

Write-Host ""
Write-Host "=========================================="
Write-Host " RESULTS"
Write-Host "=========================================="
Write-Host ""

$results | Format-Table -AutoSize

$passed = @($results | Where-Object Result -eq "PASS").Count
$failed = @($results | Where-Object Result -eq "FAIL").Count

Write-Host ""
Write-Host "Passed: $passed"
Write-Host "Failed: $failed"
Write-Host ""

if ($failed -gt 0) {

    Write-Host "One or more API checks failed."
    exit 1
}

Write-Host "All Review API checks passed."
exit 0
