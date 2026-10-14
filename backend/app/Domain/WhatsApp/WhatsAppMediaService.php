<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class WhatsAppMediaService
{
    /** @return array<string,mixed> */
    public function storeOutbound(UploadedFile $file, int $conversationId): array
    {
        $mimeType = strtolower(trim((string) ($file->getMimeType() ?: $file->getClientMimeType())));
        $mediaType = $this->mediaTypeForMime($mimeType);
        if ($mediaType === null) {
            throw ValidationException::withMessages([
                'attachment' => ['Unsupported WhatsApp attachment type.'],
            ]);
        }

        $maxBytes = $this->maxOutboundBytes();
        $size = (int) ($file->getSize() ?: 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw ValidationException::withMessages([
                'attachment' => ['WhatsApp attachments must be smaller than '.(int) floor($maxBytes / 1024 / 1024).' MB.'],
            ]);
        }

        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin'));
        $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';
        $storedName = (string) Str::uuid().'.'.$extension;
        $path = $file->storeAs(
            'whatsapp-media/outbound/'.$conversationId,
            $storedName,
            'local',
        );

        if (! is_string($path) || trim($path) === '') {
            throw ValidationException::withMessages([
                'attachment' => ['The WhatsApp attachment could not be stored.'],
            ]);
        }

        return [
            'media_type' => $mediaType,
            'mime_type' => $mimeType,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_size' => $size,
            'local_disk' => 'local',
            'local_path' => $path,
        ];
    }

    /** @param array<string,mixed> $metadata */
    public function uploadToMeta(
        WhatsAppConnectionProfile $profile,
        array $metadata,
    ): string {
        $disk = (string) ($metadata['local_disk'] ?? 'local');
        $path = trim((string) ($metadata['local_path'] ?? ''));
        $mimeType = trim((string) ($metadata['mime_type'] ?? 'application/octet-stream'));
        $filename = $this->safeFilename(
            (string) ($metadata['filename'] ?? basename($path)),
            'attachment',
        );

        if ($disk !== 'local' || $path === '' || ! Storage::disk('local')->exists($path)) {
            throw new ProviderTransportException('Outbound WhatsApp attachment is unavailable.', false);
        }

        $size = (int) Storage::disk('local')->size($path);
        if ($size <= 0 || $size > $this->maxOutboundBytes()) {
            throw new ProviderTransportException('Outbound WhatsApp attachment exceeds the configured size limit.', false);
        }

        $token = $this->accessToken($profile);
        $phoneId = trim((string) ($profile->provider_phone_number_id ?? ''));
        if ($token === '' || $phoneId === '') {
            throw new ProviderTransportException('Meta connection is not configured for media upload.', false);
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($this->timeout())
                ->attach(
                    'file',
                    Storage::disk('local')->get($path),
                    $filename,
                    ['Content-Type' => $mimeType],
                )
                ->post($this->graphUrl($profile, $phoneId).'/media', [
                    'messaging_product' => 'whatsapp',
                    'type' => $mimeType,
                ]);
        } catch (ConnectionException) {
            throw new ProviderTransportException('Meta media upload failed because the provider is unreachable.', true);
        } catch (ProviderTransportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ProviderTransportException('Meta media upload failed before confirmation.', true);
        }

        if (! $response->successful()) {
            throw new ProviderTransportException(
                'Meta media upload returned HTTP '.$response->status().'.',
                $response->status() === 429 || $response->serverError(),
            );
        }

        $mediaId = trim((string) $response->json('id', ''));
        if ($mediaId === '') {
            throw new ProviderTransportException('Meta media upload returned no media ID.', false);
        }

        return $mediaId;
    }

    /** @return array{disk:string,path:string,filename:string,mime_type:string,media_type:string} */
    public function materialize(object $message): array
    {
        $metadata = $this->decodeMetadata($message->attachment_metadata ?? null);
        $mediaType = strtolower(trim((string) ($metadata['media_type'] ?? $message->content_type ?? '')));
        if (! in_array($mediaType, ['image', 'document', 'video', 'audio', 'sticker', 'media'], true)) {
            throw new ProviderTransportException('This WhatsApp message has no downloadable attachment.', false);
        }

        $disk = (string) ($metadata['local_disk'] ?? 'local');
        $localPath = trim((string) ($metadata['local_path'] ?? ''));
        if ($disk === 'local' && $localPath !== '' && Storage::disk('local')->exists($localPath)) {
            return $this->asset(
                $localPath,
                (string) ($metadata['filename'] ?? ''),
                (string) ($metadata['mime_type'] ?? ''),
                $mediaType,
            );
        }

        $mediaId = trim((string) ($metadata['media_id'] ?? $metadata['id'] ?? ''));
        $profileId = (int) ($message->connection_profile_id ?? 0);
        if ($mediaId === '' || $profileId <= 0 || strtolower((string) ($message->direction ?? '')) !== 'inbound') {
            throw new ProviderTransportException('This WhatsApp attachment is no longer available.', false);
        }

        $profile = WhatsAppConnectionProfile::query()->find($profileId);
        if (! $profile instanceof WhatsAppConnectionProfile
            || ! in_array(strtolower((string) $profile->provider_type), ['meta_cloud', 'meta', 'whatsapp_cloud'], true)) {
            throw new ProviderTransportException('Unsupported WhatsApp attachment provider.', false);
        }

        $token = $this->accessToken($profile);
        if ($token === '') {
            throw new ProviderTransportException('WhatsApp attachment credentials are unavailable.', false);
        }

        try {
            $info = Http::withToken($token)
                ->acceptJson()
                ->timeout($this->timeout())
                ->get($this->graphUrl($profile, $mediaId));
        } catch (ConnectionException) {
            throw new ProviderTransportException('WhatsApp attachment metadata is temporarily unavailable.', true);
        } catch (Throwable) {
            throw new ProviderTransportException('WhatsApp attachment metadata could not be loaded.', true);
        }

        if (! $info->successful()) {
            throw new ProviderTransportException(
                'WhatsApp attachment metadata returned HTTP '.$info->status().'.',
                $info->status() === 429 || $info->serverError(),
            );
        }

        $downloadUrl = trim((string) $info->json('url', ''));
        $providerSize = (int) $info->json('file_size', 0);
        if ($downloadUrl === '') {
            throw new ProviderTransportException('WhatsApp attachment URL is missing.', false);
        }
        if ($providerSize > 0 && $providerSize > $this->maxDownloadBytes()) {
            throw new ProviderTransportException('WhatsApp attachment exceeds the configured download size limit.', false);
        }

        try {
            $download = Http::withToken($token)
                ->timeout(max(15, $this->timeout()))
                ->get($downloadUrl);
        } catch (ConnectionException) {
            throw new ProviderTransportException('WhatsApp attachment download is temporarily unavailable.', true);
        } catch (Throwable) {
            throw new ProviderTransportException('WhatsApp attachment download failed.', true);
        }

        if (! $download->successful()) {
            throw new ProviderTransportException(
                'WhatsApp attachment download returned HTTP '.$download->status().'.',
                $download->status() === 429 || $download->serverError(),
            );
        }

        $contents = $download->body();
        if ($contents === '' || strlen($contents) > $this->maxDownloadBytes()) {
            throw new ProviderTransportException('WhatsApp attachment is empty or exceeds the configured download size limit.', false);
        }

        $mimeType = trim((string) ($metadata['mime_type'] ?? $info->json('mime_type', '') ?? ''));
        if ($mimeType === '') {
            $mimeType = trim((string) $download->header('Content-Type'));
        }
        if ($mimeType === '') {
            $mimeType = 'application/octet-stream';
        }

        $filename = $this->safeFilename(
            (string) ($metadata['filename'] ?? ''),
            'whatsapp-'.$message->id.'.'.$this->extensionForMime($mimeType),
        );
        $path = 'whatsapp-media/inbound/'.$message->id.'/'.Str::uuid().'-'.$filename;
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new ProviderTransportException('WhatsApp attachment could not be cached securely.', true);
        }

        $metadata['media_type'] = $mediaType;
        $metadata['mime_type'] = $mimeType;
        $metadata['filename'] = $filename;
        $metadata['file_size'] = strlen($contents);
        $metadata['local_disk'] = 'local';
        $metadata['local_path'] = $path;

        DB::table('whats_app_messages')->where('id', $message->id)->update([
            'attachment_metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        return $this->asset($path, $filename, $mimeType, $mediaType);
    }

    private function mediaTypeForMime(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/png' => 'image',
            'video/mp4', 'video/3gpp' => 'video',
            'audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/opus' => 'audio',
            'application/pdf',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'document',
            default => null,
        };
    }

    /** @return array{disk:string,path:string,filename:string,mime_type:string,media_type:string} */
    private function asset(
        string $path,
        string $filename,
        string $mimeType,
        string $mediaType,
    ): array {
        return [
            'disk' => 'local',
            'path' => $path,
            'filename' => $this->safeFilename($filename, basename($path)),
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'media_type' => $mediaType,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function safeFilename(string $filename, string $fallback): string
    {
        $filename = trim(str_replace(["\0", '/', '\\'], '', $filename));
        if ($filename === '') {
            $filename = $fallback;
        }

        return mb_substr($filename, 0, 255);
    }

    private function extensionForMime(string $mimeType): string
    {
        return match (strtolower(trim(explode(';', $mimeType, 2)[0]))) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/aac' => 'aac',
            'audio/amr' => 'amr',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/opus' => 'opus',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            default => 'bin',
        };
    }

    private function graphUrl(WhatsAppConnectionProfile $profile, string $resource): string
    {
        $base = rtrim((string) config('whatsapp.meta.base_url', 'https://graph.facebook.com'), '/');
        $metadata = $profile->provider_metadata;
        $metadata = is_array($metadata) ? $metadata : [];
        $version = trim((string) ($metadata['graph_version'] ?? config('whatsapp.meta.graph_version', 'v26.0')));
        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            $version = 'v26.0';
        }

        return $base.'/'.$version.'/'.rawurlencode($resource);
    }

    private function accessToken(WhatsAppConnectionProfile $profile): string
    {
        $token = $profile->credential_secret;

        return is_string($token) ? trim($token) : '';
    }

    private function timeout(): int
    {
        return max(1, min(30, (int) config('whatsapp.meta.timeout_seconds', 10)));
    }

    private function maxOutboundBytes(): int
    {
        return max(1, (int) config('whatsapp.media.max_outbound_mb', 16)) * 1024 * 1024;
    }

    private function maxDownloadBytes(): int
    {
        return max(1, (int) config('whatsapp.media.max_download_mb', 32)) * 1024 * 1024;
    }
}
