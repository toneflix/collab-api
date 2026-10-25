<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Resources\InteractionCollection;
use Illuminate\Http\Request;

class SocialInteractionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, string $pid, ?string $interaction_id = null)
    {
        [$interactable] = $this->resolver($request, $pid, $interaction_id);

        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $interactable->socialInteractions();

        if ($request->interaction === 'prop') {
            $query->where('votes', '>', 0);
        } else {
            $query->where($request->interaction === 'save' ? 'saved' : 'liked', true);
        }

        $interactions = $query->paginate($request->perPage(45));

        return (new InteractionCollection($interactions))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $pid)
    {
        $this->validate($request, [
            'interaction' => 'required|string|in:like,prop,save',
            'state' => 'nullable|boolean',
            'public' => 'nullable|boolean|prohibited_unless:interaction,save',
            'list' => 'nullable|string|prohibited_unless:interaction,save',
        ]);

        [$interactable, $interaction, $name] = $this->resolver($request, $pid);

        if ($request->interaction === 'like') {
            $interaction = $interactable->react($request->user(), 'like');
        } elseif ($request->interaction === 'prop') {
            $interaction = $interactable->giveVote($request->user(), true);
            $request->interaction = 'prope';
        } elseif ($request->list) {
            $interaction = $interactable->toggleSaveToList(
                $request->user(),
                $request->boolean('state'),
                $request->list,
                $request->boolean('public'),
            );
        } elseif ($request->interaction === 'save') {
            $interaction = $interactable->toggleSave($request->user(), $request->boolean('state'));
        }

        $action = $interaction->interactionData["{$request->interaction}d"];

        return Providers::response()->success([
            'interaction_id' => $interaction->id,
            'data' => $interaction->interactionData,
            'message' => __("You have :0{$request->interaction}d this $name", [! $action ? 'un' : '']),
        ], HttpStatus::ACCEPTED);
    }

    /**
     * Resolve the interaction interface
     *
     * @return array{
     *  0:\App\Models\Project|\App\Models\Track|\App\Models\Release,
     *  1:\Toneflix\SocialInteractions\Models\SocialInteraction|null,
     *  2:string
     * }
     */
    public function resolver(Request $request, string $interactable_id, ?string $interaction_id = null)
    {
        // Guess class name
        $name = str($request->route()->getAction('prefix'))
            ->after('{')
            ->before('}');

        $className = $name
            ->studly()
            ->prepend('\\App\\Models\\')
            ->toString();

        /** @var \App\Models\Project|\App\Models\Track|\App\Models\Release $interactable */
        $interactable = (new $className())->resolveRouteBinding($interactable_id);

        $interaction = null;

        if ($interactable) {
            if ($interaction_id) {
                /** @var \SocialInteractions\Models\SocialInteraction $interaction */
                $interaction = $interactable->socialInteractions()->findOrFail($interaction_id);
            }
        }

        return [$interactable, $interaction, $name->toString()];
    }
}
