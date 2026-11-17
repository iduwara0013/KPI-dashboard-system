$documentPath = 'D:\KPI dashboard system\outputs\2027 IT Network Plan Copy Paste Guide.docx'
$pdfPath = 'D:\KPI dashboard system\outputs\network-plan-qa\word-layout.pdf'
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $pdfPath) | Out-Null
$word = $null
$document = $null
try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0
    $document = $word.Documents.Open($documentPath, $false, $true, $false)
    $document.Repaginate()
    $document.ExportAsFixedFormat($pdfPath, 17)
    Write-Output $pdfPath
} finally {
    if ($null -ne $document) { $document.Close($false) }
    if ($null -ne $word -and $word.Documents.Count -eq 0) { $word.Quit() }
}

