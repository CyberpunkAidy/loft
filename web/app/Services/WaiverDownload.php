<?php

namespace App\Services;

use App\Models\Studio;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * A studio's signed waivers as a ZIP, the studio's own copy of its liability records (decision 20):
 * a CSV of every signature, the text of every version, and the drawn signatures. Owners download it
 * before closing a studio, since closing deletes everything.
 */
class WaiverDownload
{
    /** Builds the ZIP in a temporary file and returns its path. The caller deletes it. */
    public function build(Studio $studio): string
    {
        $path = tempnam(sys_get_temp_dir(), 'loft-waivers-');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the waiver download.');
        }

        $zip->addFromString('README.txt', $this->readme($studio));

        foreach (WaiverVersion::where('studio_id', $studio->id)->orderBy('waiver_id')->orderBy('version')->get() as $version) {
            $zip->addFromString($this->textPath($version), $version->title."\n\n".$version->body."\n");
        }

        $csv = fopen('php://temp', 'w+');
        fputcsv($csv, [
            'Signed at', 'Time zone', 'Waiver', 'Version', 'Text file', 'Text SHA-256', 'Attendee', 'Signed by (typed name)',
            'Relationship', 'Account email', 'IP address', 'Browser', 'Drawn signature',
        ]);
        $disk = Storage::disk('local');
        $signatures = WaiverSignature::where('studio_id', $studio->id)->with(['waiver', 'version', 'signedBy', 'attendee'])->orderBy('signed_at')->orderBy('id')->lazy();
        foreach ($signatures as $signature) {
            $drawing = null;
            if ($signature->signature_path && $disk->exists($signature->signature_path)) {
                $drawing = "drawings/signature-{$signature->id}.png";
                $zip->addFromString($drawing, (string) $disk->get($signature->signature_path));
            }
            fputcsv($csv, array_map($this->cell(...), [
                $studio->local($signature->signed_at)->format('Y-m-d H:i'),
                $studio->timezone,
                $signature->version?->title ?? $signature->waiver?->title,
                $signature->waiver_version,
                $signature->version ? $this->textPath($signature->version) : '',
                $signature->body_sha256 ?? '',
                $signature->attendee_name ?? $signature->attendee?->name,
                $signature->signed_name,
                $signature->signer_relationship,
                $signature->signedBy?->email ?? '(account deleted)',
                $signature->ip,
                $signature->user_agent,
                $drawing ?? '',
            ]));
        }
        rewind($csv);
        $zip->addFromString('signatures.csv', (string) stream_get_contents($csv));
        fclose($csv);

        $zip->close();

        return $path;
    }

    private function textPath(WaiverVersion $version): string
    {
        return sprintf('waiver-texts/%d-%s-v%d.txt', $version->waiver_id, Str::slug($version->title) ?: 'waiver', $version->version);
    }

    /** Stop spreadsheet apps from treating a value as a formula. */
    private function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function readme(Studio $studio): string
    {
        return <<<TXT
        Signed waivers for {$studio->name}, downloaded {$studio->localNow()->format('Y-m-d H:i')} ({$studio->timezone}).

        signatures.csv   One row per signature: when, which waiver and version, who it was for, who signed
                         and how they're related, the account email (if the account still exists), IP
                         address and browser, and the drawn signature's file, if they drew one.
        waiver-texts/    The exact text of each published version. "Text SHA-256" in the CSV is the
                         SHA-256 hash of that text as signed; signatures from before Loft kept versions
                         have no hash.
        drawings/        Drawn signatures, as PNG images.

        Keep this file somewhere safe: these are your records of who agreed to your waivers.
        TXT;
    }
}
