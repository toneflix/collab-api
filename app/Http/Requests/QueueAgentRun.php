<?php

namespace App\Http\Requests;

use App\Services\Agent\AgentPlanner;
use App\Services\Ai\AiSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A prompt for the content agent and the options it runs with.
 */
class QueueAgentRun extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:10', 'max:4000'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'publish' => ['nullable', 'boolean'],
            'artwork' => ['nullable', 'boolean'],
            'max_tracks' => ['nullable', 'integer', 'between:1,' . AgentPlanner::MAX_TRACKS],
            'seconds' => ['nullable', 'integer', 'between:10,' . AiSettings::musicMaxSeconds()],
            'label' => ['nullable', 'string', 'max:100'],
            'project_privacy' => ['nullable', 'string', 'in:private,public,public_preview'],
        ];
    }

    /**
     * The run's options, with defaults filled in.
     *
     * @return array{publish: bool, artwork: bool, max_tracks: int, seconds: int, label: ?string, project_privacy: string}
     */
    public function options(): array
    {
        return [
            'publish' => $this->boolean('publish'),
            'artwork' => $this->boolean('artwork', true),
            'max_tracks' => (int) ($this->validated('max_tracks') ?? 12),
            'seconds' => (int) ($this->validated('seconds') ?? AgentPlanner::DEFAULT_SECONDS),
            'label' => $this->validated('label'),
            'project_privacy' => $this->validated('project_privacy') ?? 'private',
        ];
    }
}
