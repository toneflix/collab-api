<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArtistVoiceCollection;
use App\Http\Resources\ArtistVoiceResource;
use App\Models\Artist;
use App\Models\ArtistVoice;
use App\Services\Artists\ArtistVoices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The voices of the user's artists for AI music: a description, a sample
 * they upload, record or have the AI make, and the phrase they record to
 * prove an uploaded voice is theirs.
 */
class ArtistVoiceController extends Controller
{
    /**
     * The user's artists that have a voice sample.
     */
    public function index(Request $request)
    {
        $voices = ArtistVoice::query()
            ->ready()
            ->whereHas('artist', fn(Builder $q) => $q->where('user_id', $this->user->id))
            ->with('artist')
            ->latest('updated_at')
            ->paginate($request->perPage(20, 50));

        return (new ArtistVoiceCollection($voices))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function show(ArtistVoices $voices, Artist $artist)
    {
        $this->authorize('voice', $artist);

        $voice = $voices->for($artist);

        return $this->voice($voice, HttpStatus::message(HttpStatus::OK), HttpStatus::OK);
    }

    /**
     * Set the words used for the artist's voice in music prompts.
     */
    public function update(Request $request, ArtistVoices $voices, Artist $artist)
    {
        $this->authorize('voice', $artist);

        $valid = $request->validate([
            'description' => ['nullable', 'string', 'max:300'],
        ]);

        $voice = $voices->describe($artist, $valid['description'] ?? null);

        return $this->voice($voice, 'Voice saved.', HttpStatus::ACCEPTED);
    }

    /**
     * Keep a sample of the artist singing, uploaded or recorded.
     */
    public function sample(Request $request, ArtistVoices $voices, Artist $artist)
    {
        $this->authorize('voice', $artist);

        $request->validate([
            'sample' => ['required', 'file', 'mimes:mp3,wav,webm,ogg,oga,m4a,mp4,aac', 'max:20480'],
        ]);

        $voice = $voices->upload($artist, $request->file('sample'));

        return $this->voice($voice, 'Voice sample saved.', HttpStatus::ACCEPTED);
    }

    /**
     * Have the AI make a sample from the voice's description, paid with the
     * user's credits.
     */
    public function generate(ArtistVoices $voices, Artist $artist)
    {
        $this->authorize('voice', $artist);

        $voices->generate($artist, $this->user);
        $voice = $voices->for($artist);

        return $this->voice($voice, 'Making a voice sample.', HttpStatus::ACCEPTED);
    }

    /**
     * Take the recording of the phrase that proves the voice is the user's.
     */
    public function check(Request $request, ArtistVoices $voices, Artist $artist)
    {
        $this->authorize('voice', $artist);

        $request->validate([
            'recording' => ['required', 'file', 'mimes:mp3,wav,webm,ogg,oga,m4a,mp4,aac', 'max:10240'],
        ]);

        $voice = $voices->submitCheck($artist, $request->file('recording'));

        return $this->voice($voice, 'Checking your recording.', HttpStatus::ACCEPTED);
    }

    /**
     * Get a new phrase to record.
     */
    public function restart(ArtistVoices $voices, Artist $artist)
    {
        $this->authorize('voice', $artist);

        $voice = $voices->for($artist);
        $voices->restartCheck($voice);

        return $this->voice($voice, 'Getting a new phrase.', HttpStatus::ACCEPTED);
    }

    public function destroy(ArtistVoices $voices, Artist $artist)
    {
        $this->authorize('voice', $artist);

        $voice = $voices->forgetSample($artist);

        return $this->voice($voice, 'Voice sample removed.', HttpStatus::ACCEPTED);
    }

    /**
     * The sample, from the signed link on the voice.
     */
    public function audio(ArtistVoices $voices, Artist $artist)
    {
        return $voices->stream($artist);
    }

    protected function voice(ArtistVoice $voice, string $message, HttpStatus $status)
    {
        return (new ArtistVoiceResource($voice))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
