param(
    [string] $BaseUrl = 'http://127.0.0.1:8000/api'
)

$results = [System.Collections.Generic.List[object]]::new()
$dataLimited = [System.Collections.Generic.List[string]]::new()

function Add-Result([string] $Test, [int] $Expected, [int] $Actual) {
    $results.Add([pscustomobject]@{ Test = $Test; Expected = $Expected; Actual = $Actual; Result = if ($Expected -eq $Actual) { 'PASS' } else { 'FAIL' } })
}

function Add-Check([string] $Test, [bool] $Passed) {
    $results.Add([pscustomobject]@{ Test = $Test; Expected = 'PASS'; Actual = if ($Passed) { 'PASS' } else { 'FAIL' }; Result = if ($Passed) { 'PASS' } else { 'FAIL' } })
}

function Get-SearchTerm([string] $Value) {
    $match = [regex]::Match($Value, '[\p{L}\p{N}]+')

    if ($match.Success) {
        return $match.Value
    }

    return $null
}

function Invoke-Request([string] $Path) {
    try {
        return [pscustomobject]@{ Status = 200; Data = (Invoke-RestMethod -Uri "$BaseUrl/$Path" -Headers @{ Accept = 'application/json' }) }
    } catch {
        if ($null -eq $_.Exception.Response) { throw }
        return [pscustomobject]@{ Status = [int] $_.Exception.Response.StatusCode; Data = $null }
    }
}

function Find-ProhibitedFields([object] $Value, [string] $Endpoint) {
    $prohibited = '^(password|remember_token|plain_text_token|access_token|token|gateway_secret|metadata|gateway_transaction_id|verification_notes)$'
    if ($null -eq $Value -or $Value -is [string] -or $Value -is [ValueType]) { return }
    if ($Value -is [System.Collections.IDictionary]) {
        foreach ($key in $Value.Keys) {
            if ([string] $key -match $prohibited) { [pscustomobject]@{ Endpoint = $Endpoint; Field = [string] $key } }
            Find-ProhibitedFields $Value[$key] $Endpoint
        }
        return
    }
    if ($Value -is [System.Collections.IEnumerable]) { foreach ($item in $Value) { Find-ProhibitedFields $item $Endpoint }; return }
    foreach ($property in $Value.PSObject.Properties) {
        if ($property.Name -match $prohibited) { [pscustomobject]@{ Endpoint = $Endpoint; Field = $property.Name } }
        Find-ProhibitedFields $property.Value $Endpoint
    }
}

$services = Invoke-Request 'marketplace/services?per_page=5'
$providers = Invoke-Request 'marketplace/providers?per_page=5'
$categories = Invoke-Request 'marketplace/categories'
Add-Result 'services base' 200 $services.Status
Add-Result 'providers base' 200 $providers.Status
Add-Result 'categories base' 200 $categories.Status

if ($services.Status -eq 200) {
    Add-Check 'service pagination structure' ($null -ne $services.Data.data -and $services.Data.current_page -eq 1 -and $services.Data.per_page -eq 5 -and @($services.Data.data).Count -le 5)
}

if ($providers.Status -eq 200) {
    Add-Check 'provider pagination structure' ($null -ne $providers.Data.data -and $providers.Data.current_page -eq 1 -and $providers.Data.per_page -eq 5 -and @($providers.Data.data).Count -le 5)
}

if ($categories.Status -eq 200 -and @($categories.Data.categories).Count -gt 0) {
    $category = $categories.Data.categories[0]
    Add-Result 'active category detail' 200 (Invoke-Request "marketplace/categories/$($category.slug)").Status
} else { $dataLimited.Add('No active category was available for category-detail/filter semantic checks.') }

$serviceItems = @($services.Data.data)

if ($services.Status -eq 200 -and $serviceItems.Count -gt 0) {
    $service = $serviceItems[0]
    $serviceSearch = Get-SearchTerm $service.name

    if ($null -ne $serviceSearch) {
        $serviceSearchResponse = Invoke-Request "marketplace/services?search=$([uri]::EscapeDataString($serviceSearch))&per_page=5"
        Add-Result 'service search' 200 $serviceSearchResponse.Status
        if ($serviceSearchResponse.Status -eq 200) { Add-Check 'service search returns a result' (@($serviceSearchResponse.Data.data).Count -gt 0) }
    } else { $dataLimited.Add('No searchable public service name was available.') }

    if ($null -ne $service.category -and $null -ne $service.category.slug) {
        $serviceCategoryResponse = Invoke-Request "marketplace/services?category=$([uri]::EscapeDataString($service.category.slug))&per_page=5"
        Add-Result 'service category filter' 200 $serviceCategoryResponse.Status
        if ($serviceCategoryResponse.Status -eq 200) { Add-Check 'service category filter returns only the selected category' (@($serviceCategoryResponse.Data.data | Where-Object { $_.service_category_id -ne $service.service_category_id }).Count -eq 0) }

        $compositionResponse = Invoke-Request "marketplace/services?category=$([uri]::EscapeDataString($service.category.slug))&sort=name_asc&per_page=5"
        Add-Result 'service category plus sort' 200 $compositionResponse.Status
        if ($compositionResponse.Status -eq 200) { Add-Check 'service category plus sort returns a result' (@($compositionResponse.Data.data).Count -gt 0) }
    } else { $dataLimited.Add('No public service category relationship was available for semantic filtering checks.') }

    if ($null -ne $service.provider -and $null -ne $service.provider.business_slug) {
        $serviceProviderResponse = Invoke-Request "marketplace/services?provider=$([uri]::EscapeDataString($service.provider.business_slug))&per_page=5"
        Add-Result 'service provider filter' 200 $serviceProviderResponse.Status
        if ($serviceProviderResponse.Status -eq 200) { Add-Check 'service provider filter returns only the selected provider' (@($serviceProviderResponse.Data.data | Where-Object { $_.service_provider_id -ne $service.service_provider_id }).Count -eq 0) }
    } else { $dataLimited.Add('No public service provider relationship was available for semantic filtering checks.') }
} else { $dataLimited.Add('No public service was available for search/filter composition checks.') }

$providerItems = @($providers.Data.data)

if ($providers.Status -eq 200 -and $providerItems.Count -gt 0) {
    $provider = $providerItems[0]
    $providerSearch = Get-SearchTerm $provider.business_name

    if ($null -ne $providerSearch) {
        $providerSearchResponse = Invoke-Request "marketplace/providers?search=$([uri]::EscapeDataString($providerSearch))&per_page=5"
        Add-Result 'provider search' 200 $providerSearchResponse.Status
        if ($providerSearchResponse.Status -eq 200) { Add-Check 'provider search returns a result' (@($providerSearchResponse.Data.data).Count -gt 0) }
    } else { $dataLimited.Add('No searchable public provider name was available.') }

    if ($null -ne $provider.categories -and @($provider.categories).Count -gt 0) {
        $providerCategory = $provider.categories[0]
        $providerCategoryResponse = Invoke-Request "marketplace/providers?category=$([uri]::EscapeDataString($providerCategory.slug))&per_page=5"
        Add-Result 'provider category filter' 200 $providerCategoryResponse.Status
        if ($providerCategoryResponse.Status -eq 200) { Add-Check 'provider category filter returns a result' (@($providerCategoryResponse.Data.data).Count -gt 0) }
    } else { $dataLimited.Add('No public provider category relationship was available for semantic filtering checks.') }
} else { $dataLimited.Add('No public provider was available for search/filter checks.') }

foreach ($sort in @('newest', 'oldest', 'name_asc', 'name_desc', 'rating_high', 'rating_low')) {
    Add-Result "service sort $sort" 200 (Invoke-Request "marketplace/services?sort=$sort&per_page=5").Status
}

foreach ($sort in @('newest', 'oldest', 'name_asc', 'name_desc', 'rating_high', 'rating_low')) {
    Add-Result "provider sort $sort" 200 (Invoke-Request "marketplace/providers?sort=$sort&per_page=5").Status
}

foreach ($path in @('marketplace/services?min_price=0&per_page=5', 'marketplace/services?max_price=999999999&per_page=5', 'marketplace/services?min_price=0&max_price=999999999&per_page=5', 'marketplace/services?min_rating=1&per_page=5', 'marketplace/services?per_page=1&page=1')) {
    Add-Result $path 200 (Invoke-Request $path).Status
}

foreach ($check in @(
    @{ Test = 'invalid service sort'; Path = 'marketplace/services?sort=password' },
    @{ Test = 'invalid provider sort'; Path = 'marketplace/providers?sort=password' },
    @{ Test = 'negative min price'; Path = 'marketplace/services?min_price=-1' },
    @{ Test = 'negative max price'; Path = 'marketplace/services?max_price=-1' },
    @{ Test = 'reversed price range'; Path = 'marketplace/services?min_price=2&max_price=1' },
    @{ Test = 'rating below range'; Path = 'marketplace/services?min_rating=0' },
    @{ Test = 'rating above range'; Path = 'marketplace/services?min_rating=6' },
    @{ Test = 'invalid pagination'; Path = 'marketplace/services?per_page=0' },
    @{ Test = 'excessive pagination'; Path = 'marketplace/services?per_page=101' }
)) { Add-Result $check.Test 422 (Invoke-Request $check.Path).Status }

Add-Result 'missing category' 404 (Invoke-Request 'marketplace/categories/marketplace-category-that-does-not-exist').Status
$prohibitedFields = @(Find-ProhibitedFields $services.Data 'services'; Find-ProhibitedFields $providers.Data 'providers'; Find-ProhibitedFields $categories.Data 'categories')
foreach ($field in $prohibitedFields) { Write-Host "Prohibited field detected: $($field.Endpoint) -> $($field.Field)" }
$results.Add([pscustomobject]@{ Test = 'sensitive fields absent'; Expected = 'PASS'; Actual = if ($prohibitedFields.Count -eq 0) { 'PASS' } else { 'FAIL' }; Result = if ($prohibitedFields.Count -eq 0) { 'PASS' } else { 'FAIL' } })
$results | Format-Table -AutoSize
Write-Host "Total checks: $($results.Count); Passed: $(@($results | Where-Object Result -eq 'PASS').Count); Failed: $(@($results | Where-Object Result -eq 'FAIL').Count); Data-limited: $($dataLimited.Count)"
foreach ($limitation in $dataLimited) { Write-Host "DATA-LIMITED: $limitation" }
if ($results.Result -contains 'FAIL') { exit 1 }
