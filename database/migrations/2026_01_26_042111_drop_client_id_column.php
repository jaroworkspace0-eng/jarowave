<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $blueprint) {
            if (Schema::hasColumn('employees', 'client_id')) {

                // Drop foreign key safely
                $foreignKeys = Schema::getConnection()
                    ->getDoctrineSchemaManager()
                    ->listTableForeignKeys('employees');

                foreach ($foreignKeys as $fk) {
                    if (in_array('client_id', $fk->getLocalColumns())) {
                        $blueprint->dropForeign($fk->getName());
                    }
                }

                // Finally, drop the column
                $blueprint->dropColumn('client_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('client_id')->nullable();
            
            // Re-add the foreign key if you roll back
            $blueprint->foreign('client_id')
                      ->references('id')
                      ->on('clients')
                      ->onDelete('cascade');
        });
    }
};
