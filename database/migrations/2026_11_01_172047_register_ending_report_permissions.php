<?php

use App\Services\Ending\EndingReports;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (EndingReports::DEFINITIONS as $definition) {
            Permission::findOrCreate($definition[1], 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Existing role grants and imported permissions are retained on rollback.
    }
};
