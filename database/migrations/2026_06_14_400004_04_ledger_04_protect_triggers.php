<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Contrainte CHECK sur amount_minor (Laravel Blueprint ne l'expose pas directement)
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_amount_minor_positive CHECK (amount_minor > 0)');

        // Fonction trigger commune : bloque tout UPDATE et DELETE
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_immutable: UPDATE and DELETE are forbidden on this table (use a reversal entry)';
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_ledger_entries_immutable
            BEFORE UPDATE OR DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_ledger_transactions_immutable
            BEFORE UPDATE OR DELETE ON ledger_transactions
            FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ledger_entries_immutable ON ledger_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ledger_transactions_immutable ON ledger_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_immutable');
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS chk_amount_minor_positive');
    }
};
