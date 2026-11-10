<?php

namespace App\Services\Imports;

use App\Support\CsvReader;

/**
 * The Loft fields each kind of import fills, and the column names other systems use for them
 * (Mindbody, Momence, Punchpass, WellnessLiving, Glofox, Jackrabbit and plain spreadsheets), so a
 * file's columns are matched automatically. Staff can change any match before importing.
 */
final class Fields
{
    /** @var array<string, array{label: string, hint?: string, aliases: list<string>}> */
    public const PEOPLE = [
        'first_name' => ['label' => 'First name', 'aliases' => ['firstname', 'first', 'givenname', 'forename', 'fname', 'clientfirstname', 'studentfirstname', 'memberfirstname', 'customerfirstname', 'participantfirstname', 'prenom', 'nombre', 'vorname']],
        'last_name' => ['label' => 'Last name', 'aliases' => ['lastname', 'last', 'surname', 'familyname', 'lname', 'clientlastname', 'studentlastname', 'memberlastname', 'customerlastname', 'participantlastname', 'nom', 'nomdefamille', 'apellido', 'apellidos', 'nachname']],
        'full_name' => ['label' => 'Full name', 'hint' => 'Only if there are no separate first and last name columns', 'aliases' => ['name', 'fullname', 'clientname', 'client', 'studentname', 'student', 'membername', 'member', 'customername', 'customer', 'contactname', 'participant', 'participantname', 'attendee', 'attendeename']],
        'email' => ['label' => 'Email', 'aliases' => ['email', 'emailaddress', 'clientemail', 'memberemail', 'customeremail', 'primaryemail', 'studentemail', 'contactemail', 'mail', 'courriel', 'correo', 'correoelectronico']],
        'phone' => ['label' => 'Phone', 'aliases' => ['phone', 'mobile', 'mobilephone', 'mobilenumber', 'cell', 'cellphone', 'cellnumber', 'phonenumber', 'primaryphone', 'homephone', 'telephone', 'tel', 'contactnumber', 'phone1', 'telefono', 'portable', 'mobil', 'telefon']],
        'dob' => ['label' => 'Birthday', 'aliases' => ['birthday', 'birthdate', 'dateofbirth', 'dob', 'bday', 'born', 'datedenaissance', 'fechadenacimiento', 'geburtsdatum']],
        'parent_email' => ['label' => 'Parent’s email', 'hint' => 'For children and others booked by someone else', 'aliases' => ['parentemail', 'guardianemail', 'parentguardianemail', 'accountholderemail', 'payeremail', 'responsiblepartyemail', 'familyemail']],
        'parent_name' => ['label' => 'Parent’s name', 'aliases' => ['parentname', 'guardianname', 'parentguardianname', 'parentguardian', 'accountholder', 'accountholdername', 'payername', 'responsibleparty', 'responsiblepartyname']],
        'medical_notes' => ['label' => 'Medical notes', 'aliases' => ['medicalnotes', 'medical', 'medicalinfo', 'medicalinformation', 'medicalconditions', 'healthnotes', 'health', 'healthinfo', 'allergies', 'injuries', 'medicalallergies', 'conditions', 'healthconditions', 'medicalhistory', 'notesmedicales']],
        'emergency_name' => ['label' => 'Emergency contact', 'aliases' => ['emergencycontact', 'emergencycontactname', 'emergencyname', 'emergencycontactperson', 'incaseofemergency', 'ice', 'icename']],
        'emergency_phone' => ['label' => 'Emergency contact phone', 'aliases' => ['emergencycontactphone', 'emergencyphone', 'emergencynumber', 'emergencycontactnumber', 'emergencycontactphonenumber', 'icephone']],
        'marketing' => ['label' => 'Agreed to marketing email', 'hint' => 'Yes / no. Only “yes” opts them in to your news and offers', 'aliases' => ['emailoptin', 'marketingoptin', 'acceptsmarketing', 'marketingemails', 'emailmarketing', 'newsletter', 'subscribed', 'promotionalemails', 'sendpromotionalemails', 'optin', 'optedin', 'marketingconsent', 'emailconsent', 'emailsubscribed', 'mailinglist', 'receivepromotions']],
    ];

    /** @var array<string, array{label: string, hint?: string, aliases: list<string>}> */
    public const PLANS = [
        'email' => ['label' => 'Member’s email', 'aliases' => ['email', 'emailaddress', 'clientemail', 'memberemail', 'customeremail', 'primaryemail', 'studentemail']],
        'full_name' => ['label' => 'Member’s name', 'hint' => 'Shown in the results; members are found by email', 'aliases' => ['name', 'fullname', 'clientname', 'client', 'membername', 'member', 'customername', 'customer', 'studentname', 'student']],
        'plan' => ['label' => 'Plan', 'hint' => 'The membership, pack or pass name', 'aliases' => ['plan', 'planname', 'pricingoption', 'pricingoptionname', 'membership', 'membershipname', 'package', 'packagename', 'product', 'productname', 'pass', 'passname', 'series', 'seriesname', 'item', 'itemname', 'contract', 'contractname', 'service', 'servicename', 'punchcard', 'punchpass', 'classpack', 'description']],
        'credits_left' => ['label' => 'Classes left', 'hint' => 'For packs', 'aliases' => ['remaining', 'remainingcredits', 'creditsremaining', 'creditsleft', 'sessionsremaining', 'remainingsessions', 'classesremaining', 'remainingclasses', 'visitsremaining', 'remainingvisits', 'punchesremaining', 'remainingpunches', 'passesremaining', 'balance', 'credits', 'sessionsleft', 'classesleft', 'visitsleft', 'punchesleft']],
        'expires' => ['label' => 'Expires or paid through', 'hint' => 'When a pack expires, or the date a membership is paid up to', 'aliases' => ['expires', 'expiration', 'expirationdate', 'expiry', 'expirydate', 'expireson', 'expdate', 'enddate', 'ends', 'endson', 'validuntil', 'validthrough', 'paidthrough', 'paidthroughdate', 'paiduntil', 'nextbillingdate', 'nextpaymentdate', 'renewaldate', 'renews', 'renewsdate', 'nextrenewal', 'activethrough', 'activeuntil']],
    ];

    /** @return array<string, array{label: string, hint?: string, aliases: list<string>}> */
    public static function for(string $kind): array
    {
        return $kind === 'plans' ? self::PLANS : self::PEOPLE;
    }

    /**
     * Match a file's headers to fields: exact alias matches first, then a few looser rules
     * ("Client Email Address" is an email). Each column is used once.
     *
     * @param  list<string>  $headers
     * @return array<string, int> field => column index
     */
    public static function match(string $kind, array $headers): array
    {
        $normalized = array_map(fn ($h) => CsvReader::normalize($h), $headers);
        $mapping = [];
        $used = [];

        foreach (self::for($kind) as $field => $definition) {
            foreach ($normalized as $index => $header) {
                if (! isset($used[$index]) && in_array($header, $definition['aliases'], true)) {
                    $mapping[$field] = $index;
                    $used[$index] = true;

                    break;
                }
            }
        }

        foreach (self::looseRules($kind) as $field => $rule) {
            if (isset($mapping[$field]) || ! array_key_exists($field, self::for($kind))) {
                continue;
            }
            foreach ($normalized as $index => $header) {
                if (! isset($used[$index]) && $rule($header)) {
                    $mapping[$field] = $index;
                    $used[$index] = true;

                    break;
                }
            }
        }

        // A full name only when there's no first and last name.
        if (isset($mapping['first_name'], $mapping['full_name']) && $kind === 'people') {
            unset($mapping['full_name']);
        }

        return $mapping;
    }

    /** @return array<string, callable(string): bool> in priority order */
    private static function looseRules(string $kind): array
    {
        $has = fn (string $header, string ...$parts) => array_reduce($parts, fn ($carry, $part) => $carry || str_contains($header, $part), false);
        $notAbout = fn (string $header) => ! $has($header, 'visit', 'class', 'login', 'modified', 'updated', 'purchase', 'payment', 'attend', 'created', 'date');

        return [
            'parent_email' => fn ($h) => $has($h, 'parent', 'guardian', 'accountholder', 'payer', 'family') && str_contains($h, 'email'),
            'emergency_phone' => fn ($h) => str_contains($h, 'emergency') && $has($h, 'phone', 'number', 'tel', 'mobile'),
            'emergency_name' => fn ($h) => str_contains($h, 'emergency'),
            'email' => fn ($h) => str_contains($h, 'email') && ! $has($h, 'parent', 'guardian', 'emergency', 'optin', 'consent', 'marketing', 'subscri'),
            'phone' => fn ($h) => $has($h, 'phone', 'mobile', 'cell') && ! str_contains($h, 'emergency'),
            'dob' => fn ($h) => str_contains($h, 'birth'),
            'first_name' => fn ($h) => str_contains($h, 'first') && $notAbout($h),
            'last_name' => fn ($h) => str_contains($h, 'last') && $notAbout($h),
            'medical_notes' => fn ($h) => $has($h, 'medical', 'allerg', 'injur', 'health'),
            'credits_left' => fn ($h) => $has($h, 'remaining', 'left') && ! $has($h, 'day'),
            'expires' => fn ($h) => $has($h, 'expir', 'paidthrough', 'validuntil', 'nextbill', 'renew'),
            'plan' => fn ($h) => $has($h, 'pricingoption', 'membership', 'package', 'pass', 'plan', 'series') && ! $has($h, 'remaining', 'expir', 'date', 'status', 'id'),
        ];
    }
}
