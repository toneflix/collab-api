<?php

namespace App\Http\Resources;

use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A device the user is signed in on, read from its access token.
 *
 * @mixin PersonalAccessToken
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dev = new DeviceDetector($this->name);
        $dev->parse();

        $os = $dev->getOs();
        $name = $os['name'] ?? 'Unknown Device';
        $version = $os['version'] ?? '0.00';
        $platform = match (true) {
            in_array($dev->getBrandName(), ['Apple', 'Microsoft']) => $dev->getBrandName(),
            in_array($dev->getOs('name'), ['Android', 'Ubuntu', 'Windows']) => $dev->getOs('name'),
            $dev->getClient('type') === 'browser' => $dev->getClient('family'),
            default => $dev->getBrandName(),
        };

        return [
            'id' => $this->id,
            'name' => collect([$dev->getBrandName(), $name, "(v{$version})"])->implode(' '),
            'platform' => $platform ?: 'Unknown Platform',
            'platform_id' => str($platform ?: 'question')->slug('-')->toString(),
            'current' => $this->id === $request->user()?->currentAccessToken()?->id,
            'last_used' => $this->last_used_at?->diffInHours() > 24
                ? $this->last_used_at?->format('d M Y')
                : $this->last_used_at?->diffForHumans(),
        ];
    }
}
