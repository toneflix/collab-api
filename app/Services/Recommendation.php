<?php

namespace App\Services;

use App\Enums\PeriodDays;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Recommendation
{
    public int $max = 0;

    public int $limit = 0;

    /**
     * Create a new class instance.
     *
     * @param  array<int,class-string>  $recommendables
     */
    public function __construct(
        public ?User $user = null,
        public ?array $recommendables = null
    ) {
        $this->max = config('settings.max_daily_recommendation', 2) *
            config('settings.recommendation_expiry', PeriodDays::DAY)->value;

        $this->limit = (int) floor($recommendables ? $this->max / count($recommendables) : $this->max / 2);
    }

    protected function recommend(?string $search, Model $related, callable $callbackQuery = null): void
    {
        // Initialize an empty recommended array
        $recommended = [];

        if ($search) {

            // Find Related items
            $items = $related->newModelInstance()
                ->whereNot('id', $related->id)
                ->when(
                    $this->user && $related instanceof User,
                    fn (Builder $q) => $q->whereNot('id', $this->user->id)
                )
                ->where(function (Builder $query) use ($search, $related, $callbackQuery) {
                    [$nfield, $dfield] = $related instanceof User || $related instanceof Artist
                        ? [$related instanceof Artist ? 'name' : "CONCAT(firstname, ' ', lastname)", 'about']
                        : ['title', 'description'];

                    $query
                        ->whereRaw("$nfield like ?", ["%{$search}%"])
                        ->when($callbackQuery, fn (Builder $q) => $q->orWhere($callbackQuery))
                        ->orWhereFullText($dfield, $search);
                })
                ->inRandomOrder()
                ->limit($this->limit)
                ->cursor();

            foreach ($items as $item) {
                $recommended[] = [
                    'keywords' => $search,
                    'recommendable_id' => $item->id,
                    'recommendable_type' => $related->getMorphClass(),
                ];
            }
        }

        if (count($recommended)) {
            $this->user->recommendations()->createMany($recommended);
        }
    }

    public function __destruct()
    {
        $max = $this->limit;

        $this->user->socialInteracts()
            ->when($this->recommendables, fn (Builder $q) => $q->whereIn('interactable_type', $this->recommendables))
            ->limit($max)
            ->inRandomOrder()
            ->chunk($max / 2, function (\Illuminate\Database\Eloquent\Collection $models) {
                /** @var \ToneflixCode\SocialInteractions\Models\SocialInteraction $model */
                foreach ($models as $model) {
                    if ($model->interactable) {
                        $search = $model->interactable->title ?? $model->interactable->name ?? null;

                        $this->recommend($search, $model->interactable);
                    }
                }
            });

        $this->user->viewingHistory()
            ->when($this->recommendables, fn (Builder $q) => $q->whereIn('visitable_type', $this->recommendables))
            ->limit($max)
            ->inRandomOrder()
            ->chunk($max / 2, function (\Illuminate\Database\Eloquent\Collection $models) {
                /** @var \Coderflex\Laravisit\Models\Visit $model */
                foreach ($models as $model) {
                    if ($model->visitable) {
                        $search = $model->visitable->title ?? $model->visitable->name;

                        $this->recommend($search, $model->visitable);
                    }
                }
            });

        $this->user->followings()
            ->when($this->recommendables, fn (Builder $q) => $q->whereIn('followable_type', $this->recommendables))
            ->limit($max)
            ->inRandomOrder()
            ->chunk($max / 2, function (\Illuminate\Database\Eloquent\Collection $models) {
                /** @var \Overtrue\LaravelFollow\Followable $model */
                foreach ($models as $model) {

                    if ($model->followable) {
                        $search = $model->followable->fullname ?? $model->followable->name;

                        $this->recommend(
                            $search,
                            $model->followable,
                            fn (Builder $q) => $q->when(
                                $model->followable_type === User::class,
                                fn (Builder $q) => $q
                                    ->whereRaw('country IS NOT NULL AND country like?', "%{$this->user->country}%")
                                    ->orWhereRaw('state IS NOT NULL AND state like?', "%{$this->user->state}%")
                                    ->orWhereRaw('city IS NOT NULL AND city like?', "%{$this->user->city}%")
                            )
                        );
                    }
                }
            });
    }
}
