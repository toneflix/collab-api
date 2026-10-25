<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Enums\MusicalKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\AudioTagsResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Track;
use App\Services\Audio\AudioTagger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets creators correct the tempo and key detected in a project track or a
 * released track. Sending neither asks for them to be detected again.
 */
class AudioTagController extends Controller
{
    public function update(Request $request, AudioTagger $tagger, Project $project, ProjectTrack $track)
    {
        $this->authorize('update', $track);

        return $this->save($request, $tagger, $track);
    }

    public function updateTrack(Request $request, AudioTagger $tagger, Track $track)
    {
        abort_unless($track->release?->isOwnedBy($request->user()), HttpStatus::NOT_FOUND->value, 'Track not found.');

        return $this->save($request, $tagger, $track);
    }

    protected function save(Request $request, AudioTagger $tagger, Track|ProjectTrack $owner)
    {
        $valid = $request->validate([
            'bpm' => ['nullable', 'numeric', 'between:20,300'],
            'key' => ['nullable', 'string', Rule::in(array_column(MusicalKey::cases(), 'value'))],
        ]);

        $bpm = isset($valid['bpm']) ? (float) $valid['bpm'] : null;
        $key = MusicalKey::tryFrom((string) ($valid['key'] ?? ''));

        $tagger->set($owner, $bpm, $key);

        return (new AudioTagsResource($owner))
            ->additional([
                'message' => $bpm === null && $key === null ? 'Detecting the tempo and key again.' : 'Tempo and key saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
