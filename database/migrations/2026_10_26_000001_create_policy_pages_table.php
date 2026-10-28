<?php

use App\Models\PolicyPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public policy documents (Privacy, Refund & Cancellation, Shipping &
     * Delivery). One row per policy, addressed by slug.
     *
     * Payment gateways require these to be readable WITHOUT logging in, so
     * the read endpoints are public and the same content is rendered by both
     * the website and the app — one source of truth the admin can edit.
     *
     * Terms & Conditions deliberately stays in its own `terms_and_conditions`
     * table: it is wired into the promoter acceptance flow, not just a page.
     *
     * Seeded with DRAFT content that the business must review and approve.
     */
    public function up(): void
    {
        Schema::create('policy_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60);
            $table->string('title', 150);
            $table->longText('content')->nullable();
            // 0 while the draft is being reviewed, 1 once approved/published.
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['slug', 'is_deleted'], 'policy_pages_slug_unique');
        });

        $now = now();
        foreach (PolicyPage::defaults() as $slug => $page) {
            DB::table('policy_pages')->insert([
                'slug'       => $slug,
                'title'      => $page['title'],
                'content'    => $page['content'],
                'is_active'  => 1,
                'is_deleted' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_pages');
    }
};
