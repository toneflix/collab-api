<?php

namespace App\Http\Resources;

use App\Models\Song;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A Composer song. Lists carry the first lines instead of the full text.
 *
 * @mixin Song
 */
class SongResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $listed = $request->routeIs('creator.songs.index');
        $settings = $this->editorSettings();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'imageUrl' => $this->images['image'],
            'hasArtwork' => (bool) $this->image,
            'publishedAt' => $this->published_at,
            'allowForks' => (bool) $this->allow_forks,
            'allowProjects' => (bool) $this->allow_projects,
            'forkedFrom' => $this->when(
                $this->relationLoaded('forkedFrom'),
                fn() => $this->forkedFrom ? [
                    'slug' => $this->forkedFrom->isPublished() ? $this->forkedFrom->slug : null,
                    'title' => $this->forkedFrom->title,
                ] : null,
            ),
            'excerpt' => $this->when($listed, fn() => array_slice($this->lyricLines(), 0, 2)),
            'body' => $this->when(! $listed, fn() => (string) $this->body),
            'scratchpad' => $this->when(! $listed, fn() => (string) $this->scratchpad),
            'notes' => $this->when(! $listed, fn() => (string) $this->notes),
            'bpm' => $this->bpm,
            'key' => $this->key?->value,
            'beat' => $this->when(! $listed && $this->relationLoaded('beat') && $this->beat, fn() => new SoundResource($this->beat)),
            'settings' => [
                'autoSuggest' => (bool) $settings['auto_suggest'],
                'rhymeColours' => (bool) $settings['rhyme_colours'],
                'mood' => $settings['mood'],
                'language' => $settings['language'],
                'explicit' => (bool) $settings['explicit'],
            ],
            'project' => $this->when(
                $this->relationLoaded('track'),
                fn() => $this->track?->project ? [
                    'id' => $this->track->project->id,
                    'slug' => $this->track->project->slug,
                    'title' => $this->track->project->title,
                    'trackId' => $this->track->id,
                ] : null,
            ),
            'revision' => $this->revision,
            'lineCount' => $this->line_count,
            'wordCount' => $this->word_count,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
