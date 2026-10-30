<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Enums\Mood;
use App\Http\Controllers\Controller;
use App\Http\Resources\StyleTagsResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Track;
use App\Services\Audio\StyleTagger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets creators set the moods of a project track or a released track.
 * Sending none hands them back to detection.
 */
class StyleTagController extends Controller
{
    public function update(Request $request, StyleTagger $tagger, Project $project, ProjectTrack $track)
    {
        $this->authorize('update', $track);

        return $this->save($request, $tagger, $track);
    }

    public function updateTrack(Request $request, StyleTagger $tagger, Track $track)
    {
        abort_unless($track->release?->isOwnedBy($request->user()), HttpStatus::NOT_FOUND->value, 'Track not found.');

        return $this->save($request, $tagger, $track);
    }

    protected function save(Request $request, StyleTagger $tagger, Track|ProjectTrack $owner)
    {
        $valid = $request->validate([
            'moods' => ['nullable', 'array', 'max:' . StyleTagger::MAX_MOODS],
            'moods.*' => ['string', Rule::in(array_column(Mood::cases(), 'value'))],
        ]);

        $moods = array_map(fn(string $mood) => Mood::from($mood), $valid['moods'] ?? []);

        $tagger->set($owner, $moods);

        return (new StyleTagsResource($owner))
            ->additional([
                'message' => $moods ? 'Moods saved.' : 'Moods handed back to detection.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
