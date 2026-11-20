# Run in the project folder AFTER the patch files are extracted:  .\apply-teacher-step.ps1
$ErrorActionPreference = 'Stop'
php artisan migrate
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=DepartmentHeadSeeder
php artisan optimize:clear
php artisan test --filter=AdviserStepTest
php artisan test --filter=ApprovalChainTest
Write-Host "Done. Teacher role, adviser step and Department Head accounts are ready."
