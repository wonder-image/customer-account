<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\Consent\Repository\UserConsentStateRepository;
use Wonder\Plugin\CustomerAccount\Models\Contact;

/**
 * Il record di una persona (vedi `PartyRecord`), con in più la data di
 * nascita, l'utente collegato e lo stato dei suoi consensi.
 */
final class ContactRecord extends PartyRecord
{
    public const ENTITY = 'contact';

    /** @var null|callable(int): array<string, string> */
    private static $consents = null;

    public static function entity(): string
    {
        return self::ENTITY;
    }

    protected static function model(): string
    {
        return Contact::class;
    }

    public static function fields(): array
    {
        return [...ContactExport::PERSON_FIELDS, 'birth_date'];
    }

    protected static function side(): string
    {
        return ContactRelations::PERSON;
    }

    protected static function requiredErrors(array $record): array
    {
        return trim((string) ($record['name'] ?? '').($record['surname'] ?? '')) === '' ? ['name' => 'required'] : [];
    }

    protected static function extra(array $record, array $row): array
    {
        $userId = (int) ($row['user_id'] ?? 0);
        $record['user_id'] = $userId > 0 ? $userId : null;
        $record['consents'] = self::consents($userId);

        return $record;
    }

    /** Solo per i test: sostituisce la lettura dei consensi (`null` torna al repository). */
    public static function useConsents(?callable $reader): void
    {
        self::$consents = $reader;
    }

    /** @return array<string, string> tipo di consenso → stato corrente */
    private static function consents(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            if (self::$consents !== null) {
                return (self::$consents)($userId);
            }

            $out = [];
            foreach ((new UserConsentStateRepository())->getByUserId($userId) as $row) {
                $out[(string) $row['consent_type']] = (string) $row['current_status'];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
