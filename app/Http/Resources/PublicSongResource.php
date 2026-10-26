<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\Song;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A published song as anyone sees it: lyrics, artwork, writer and what the
 * writer lets others do with it. Notes and the scratchpad stay private.
 * Lists carry the first lines instead of the full lyrics.
 *
 * @mixin Song
 */
class PublicSongResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user('sanctum');
        $page = $request->routeIs('songs.show');
        $settings = $this->editorSettings();
        $owner = $user && $user->id === $this->user_id;
        $source = $this->relationLoaded('forkedFrom') && $this->forkedFrom?->isPublished() ? $this->forkedFrom : null;
        $project = $page && $this->relationLoaded('track') ? $this->track?->project : null;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'imageUrl' => $this->images['image'],
            'excerpt' => $this->when(! $page, fn() => array_slice($this->lyricLines(), 0, 2)),
            'body' => $this->when($page, fn() => (string) $this->body),
            'bpm' => $this->bpm,
            'key' => $this->key?->value,
            'mood' => $settings['mood'],
            'language' => $settings['language'],
            'explicit' => (bool) $settings['explicit'],
            'writer' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->fullname,
                'username' => $this->user->username,
                'imageUrl' => $this->user->images['image'],
            ]),
            'forkedFrom' => $this->when($source !== null, fn() => [
                'slug' => $source->slug,
                'title' => $source->title,
                'writer' => $source->user?->fullname,
            ]),
            'project' => $this->when(
                $project && in_array($project->privacy, Project::OPEN_PRIVACIES, true),
                fn() => [
                    'slug' => $project->slug,
                    'title' => $project->title,
                ],
            ),
            'forksCount' => $this->whenCounted('forks'),
            'allowForks' => (bool) $this->allow_forks,
            'allowProjects' => (bool) $this->allow_projects,
            'isOwner' => (bool) $owner,
            'lineCount' => $this->line_count,
            'wordCount' => $this->word_count,
            'publishedAt' => $this->published_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
