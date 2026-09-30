<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give each menu link what the AMV-style sidebar shows in front of it, as AMV's t3012menu does:
     * a Material icon name for level 1 links (f3012menuicon), and a short label for level 2 and 3 links
     * (f3012menulabel). Both are optional; the sidebar falls back to a default icon and the label's initials.
     */
    public function up(): void
    {
        Schema::table('MenuItems', function (Blueprint $table) {
            $table->string('Icon', 50)->nullable();
            $table->string('ShortLabel', 5)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('MenuItems', function (Blueprint $table) {
            $table->dropColumn(['Icon', 'ShortLabel']);
        });
    }
};
