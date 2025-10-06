<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('original_url');
            $table->string('code')->unique(); // ubah dari short_code -> code
            $table->string('title')->nullable();
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->decimal('earn_per_click', 8, 2)->default(0.00);
            $table->uuid('token')->nullable();
            $table->timestamp('token_created_at')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
