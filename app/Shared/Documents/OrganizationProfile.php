<?php

namespace App\Shared\Documents;

use Illuminate\Support\Facades\DB;

/**
 * Coordonnées de l'organisation affichées sur les documents (R-DOC-01), lues dans
 * `system_settings` (groupe `organization`). Valeurs vides = ligne non affichée.
 */
final class OrganizationProfile
{
    private const KEYS = ['org_name', 'org_address', 'org_phone', 'org_email', 'org_document_footer'];

    public function __construct(
        public readonly string  $name,
        public readonly ?string $address,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $footer,
    ) {}

    public static function current(): self
    {
        $values = DB::table('system_settings')->whereIn('key', self::KEYS)->pluck('value', 'key');
        $get    = fn (string $key) => ($v = trim((string) ($values[$key] ?? ''))) === '' ? null : $v;

        return new self(
            name:    $get('org_name') ?? 'Kafo',
            address: $get('org_address'),
            phone:   $get('org_phone'),
            email:   $get('org_email'),
            footer:  $get('org_document_footer'),
        );
    }

    /** Ligne de contact compacte : « Adresse · Tél. … · e-mail ». */
    public function contactLine(): ?string
    {
        $parts = array_filter([
            $this->address,
            $this->phone ? "Tél. {$this->phone}" : null,
            $this->email,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }
}
