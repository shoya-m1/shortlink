<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained()->onDelete('cascade');
            $table->string('ip_address', 50);
            $table->string('country', 100)->nullable();
            $table->string('device', 50)->nullable();
            $table->string('browser', 50)->nullable();
            $table->text('referer')->nullable();
            $table->text('user_agent')->nullable(); // tambahan baru
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_valid')->default(false);
            $table->decimal('earned', 8, 2)->default(0);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('views');
    }
};
